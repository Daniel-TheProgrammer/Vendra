<?php

declare(strict_types=1);

namespace Tests\Feature\Idempotency;

use App\Modules\Catalog\Domain\Events\ProductCreated;
use App\Modules\Catalog\Infrastructure\Models\Product;
use App\Modules\Identity\Domain\MembershipRole;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use App\Shared\Domain\Event\DomainEvent;
use App\Shared\Infrastructure\Http\Idempotency\EnforceIdempotency;
use App\Shared\Infrastructure\Http\Idempotency\IdempotencyRecord;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\FailsWhenRecording;
use Tests\TestCase;

/**
 * The Idempotency-Key contract (ADR 0007), exercised through a real
 * endpoint: POST /products.
 */
#[Group('database')]
final class IdempotencyKeyTest extends TestCase
{
    use FailsWhenRecording;
    use RefreshDatabase;

    protected bool $sendIdempotencyKeys = false;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->actingAsMember($this->tenant, MembershipRole::Staff);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function product(array $overrides = []): array
    {
        return [
            'name' => 'Enamel Mug',
            'status' => 'active',
            'variants' => [['sku' => 'MUG-1', 'prices' => [['currency' => 'USD', 'amount' => '12.00']]]],
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return TestResponse<JsonResponse>
     */
    private function create(?string $key, array $payload): TestResponse
    {
        return $this->postJson('/api/v1/products', $payload, $key !== null ? [EnforceIdempotency::HEADER => $key] : []);
    }

    private function products(?Tenant $tenant = null): int
    {
        return $this->app->make(TenantContext::class)->runAs(($tenant ?? $this->tenant)->id, static fn (): int => Product::query()->count());
    }

    #[Test]
    public function a_post_without_a_key_is_refused_before_anything_runs(): void
    {
        $this->create(null, $this->product())
            ->assertStatus(400)
            ->assertJsonPath('type', 'urn:problem-type:idempotency-key-required');

        foreach (['', str_repeat('k', 256), 'has space', "caf\u{E9}"] as $malformed) {
            $this->create($malformed, $this->product())->assertStatus(400);
        }

        $this->create('has space', $this->product())->assertJsonPath('type', 'urn:problem-type:idempotency-key-invalid');
        self::assertSame(0, $this->products());
    }

    #[Test]
    public function a_retry_replays_the_original_response_without_running_again(): void
    {
        $first = $this->create('order-7f3a', $this->product())->assertCreated();

        // Same request, keys in a different order: still the same request.
        $payload = array_reverse($this->product(), true);
        $retry = $this->create('order-7f3a', $payload)
            ->assertCreated()
            ->assertHeader(EnforceIdempotency::REPLAYED_HEADER, 'true')
            ->assertHeader('Location', (string) $first->headers->get('Location'))
            ->assertHeader('ETag', '"1"');

        self::assertSame($first->getContent(), $retry->getContent());
        self::assertFalse($first->headers->has(EnforceIdempotency::REPLAYED_HEADER));
        self::assertSame(1, $this->products());
    }

    #[Test]
    public function reusing_a_key_for_a_different_request_is_rejected(): void
    {
        $this->create('k-1', $this->product())->assertCreated();

        $this->create('k-1', $this->product(['name' => 'Enamel Mug XL']))
            ->assertUnprocessable()
            ->assertJsonPath('type', 'urn:problem-type:idempotency-key-reused');

        // Another member of the same tenant replaying someone else's key.
        $this->actingAsMember($this->tenant, MembershipRole::Staff, User::factory()->create());
        $this->create('k-1', $this->product())->assertJsonPath('type', 'urn:problem-type:idempotency-key-reused');

        self::assertSame(1, $this->products());
    }

    #[Test]
    public function keys_are_scoped_to_the_tenant(): void
    {
        $this->create('shared-key', $this->product())->assertCreated();

        $other = Tenant::factory()->create();
        $this->actingAsMember($other, MembershipRole::Staff);
        $this->create('shared-key', $this->product())->assertCreated()->assertHeaderMissing(EnforceIdempotency::REPLAYED_HEADER);

        self::assertSame(1, $this->products($other));
    }

    #[Test]
    public function a_request_arriving_while_its_key_is_in_flight_is_told_to_retry(): void
    {
        $lock = Cache::lock('idempotency:'.$this->tenant->id.':'.hash('sha256', 'busy-key'), 60);
        self::assertTrue($lock->get(), 'stands in for the first request, still running');

        $this->create('busy-key', $this->product())
            ->assertConflict()
            ->assertHeader('Retry-After', '1')
            ->assertJsonPath('type', 'urn:problem-type:idempotency-key-in-flight');

        $lock->release();

        $this->create('busy-key', $this->product())->assertCreated();
        self::assertSame(1, $this->products());
    }

    #[Test]
    public function outcomes_that_did_not_run_to_completion_are_not_stored(): void
    {
        // Invalid input: fix it and retry under the same key.
        $this->create('k-2', $this->product(['name' => '']))->assertUnprocessable()->assertJsonPath('type', 'urn:problem-type:validation-failed');
        $this->create('k-2', $this->product())->assertCreated()->assertHeaderMissing(EnforceIdempotency::REPLAYED_HEADER);

        // A conflict with the current state: retried, it runs again.
        $this->create('k-3', $this->product())->assertConflict()->assertJsonPath('type', 'urn:problem-type:sku-taken');
        $this->create('k-3', $this->product())->assertConflict()->assertHeaderMissing(EnforceIdempotency::REPLAYED_HEADER);

        $this->app->make(TenantContext::class)->runAs($this->tenant->id, function (): void {
            self::assertSame(['k-2'], IdempotencyRecord::query()->pluck('key')->all());
        });
    }

    #[Test]
    public function a_failed_request_leaves_no_effects_and_frees_its_key(): void
    {
        // The product is written, then the request fails before it commits:
        // the whole request must roll back.
        $this->failWhenRecording(static fn (DomainEvent $event): bool => $event instanceof ProductCreated, new RuntimeException('downstream failure'));

        $this->create('k-4', $this->product())->assertStatus(500);
        self::assertSame(0, $this->products(), 'the effects rolled back with the failed request');

        $this->recordNormally();
        $this->create('k-4', $this->product())->assertCreated()->assertHeaderMissing(EnforceIdempotency::REPLAYED_HEADER);
        self::assertSame(1, $this->products());
    }

    #[Test]
    public function keys_are_optional_on_other_methods_and_honoured_when_sent(): void
    {
        $id = $this->create('k-5', $this->product())->json('data.id');

        $this->patchJson('/api/v1/products/'.$id, ['name' => 'Mug'])->assertOk()->assertHeader('ETag', '"2"');

        $this->patchJson('/api/v1/products/'.$id, ['name' => 'Big Mug'], [EnforceIdempotency::HEADER => 'k-6'])->assertHeader('ETag', '"3"');
        $this->patchJson('/api/v1/products/'.$id, ['name' => 'Big Mug'], [EnforceIdempotency::HEADER => 'k-6'])
            ->assertHeader('ETag', '"3"')
            ->assertHeader(EnforceIdempotency::REPLAYED_HEADER, 'true');
    }

    #[Test]
    public function the_nightly_prune_removes_expired_keys_of_every_tenant_and_nothing_else(): void
    {
        $other = Tenant::factory()->create();
        $context = $this->app->make(TenantContext::class);
        $now = CarbonImmutable::now();

        foreach ([$this->tenant, $other] as $tenant) {
            $context->runAs($tenant->id, static function () use ($now): void {
                foreach (['expired' => $now->subMinute(), 'live' => $now->addHour()] as $key => $expiresAt) {
                    IdempotencyRecord::query()->create(['key' => $key, 'request_hash' => str_repeat('0', 64), 'locked_at' => $now, 'expires_at' => $expiresAt]);
                }
            });
        }

        $context->set($this->tenant->id);
        $this->runArtisan('idempotency:prune')->assertFailed();
        $context->clear();

        $this->runArtisan('idempotency:prune')->expectsOutputToContain('Deleted 2 expired')->assertSuccessful();

        foreach ([$this->tenant, $other] as $tenant) {
            self::assertSame(['live'], $context->runAs($tenant->id, static fn (): array => IdempotencyRecord::query()->pluck('key')->all()));
        }
    }
}
