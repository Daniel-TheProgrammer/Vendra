<?php

declare(strict_types=1);

namespace Tests\Feature\Outbox;

use App\Modules\Catalog\Domain\Events\PriceChanged;
use App\Modules\Catalog\Domain\Events\ProductArchived;
use App\Modules\Catalog\Domain\Events\ProductCreated;
use App\Modules\Catalog\Domain\Events\ProductUpdated;
use App\Modules\Catalog\Domain\Events\StockAdjusted;
use App\Modules\Orders\Domain\Events\OrderCancelled;
use App\Modules\Orders\Domain\Events\OrderFulfilled;
use App\Modules\Orders\Domain\Events\OrderPaid;
use App\Modules\Orders\Domain\Events\OrderPlaced;
use App\Modules\Orders\Domain\Events\OrderRefunded;
use App\Modules\Payments\Domain\Events\PaymentCaptured;
use App\Modules\Payments\Domain\Events\PaymentDisputed;
use App\Modules\Payments\Domain\Events\PaymentFailed;
use App\Modules\Payments\Domain\Events\RefundIssued;
use App\Modules\Payments\Domain\Settlement;
use App\Modules\Tenancy\Domain\Events\TenantCreated;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use App\Shared\Application\Event\EventRecorder;
use App\Shared\Application\Transaction\TransactionRunner;
use App\Shared\Domain\Event\DomainEvent;
use App\Shared\Domain\Money\Currency;
use App\Shared\Domain\Money\Money;
use App\Shared\Infrastructure\Outbox\EventRegistry;
use App\Shared\Infrastructure\Outbox\OutboxMessage;
use App\Shared\Infrastructure\Outbox\OutboxRelay;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;

/**
 * The transactional outbox (ADR 0010): recorded with the business change,
 * published by the relay.
 */
#[Group('database')]
final class OutboxTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        // Publish only when the test says so.
        config(['outbox.after_commit' => 'off', 'outbox.backoff' => [5, 30]]);
        $this->tenant = Tenant::factory()->create();
    }

    private function event(string $slug = 'acme'): TenantCreated
    {
        return new TenantCreated($this->tenant->id, $slug, CarbonImmutable::parse('2026-11-26T09:00:00Z'));
    }

    private function record(DomainEvent ...$events): void
    {
        $this->app->make(TransactionRunner::class)->run(fn () => $this->app->make(EventRecorder::class)->record(...$events));
    }

    private function relay(): OutboxRelay
    {
        return $this->app->make(OutboxRelay::class);
    }

    #[Test]
    public function a_committed_transaction_leaves_exactly_one_message_per_event(): void
    {
        $this->record($this->event());

        $message = OutboxMessage::query()->sole();
        self::assertSame([$this->tenant->id, 'tenancy.tenant_created', null, 0], [$message->tenant_id, $message->event_type, $message->published_at, $message->attempts]);
        // jsonb does not keep key order.
        self::assertEquals(['tenant_id' => $this->tenant->id, 'slug' => 'acme'], $message->payload);
        self::assertTrue($message->occurred_at->equalTo(CarbonImmutable::parse('2026-11-26T09:00:00Z')));
    }

    #[Test]
    public function a_rolled_back_transaction_leaves_none(): void
    {
        try {
            $this->app->make(TransactionRunner::class)->run(function (): never {
                $this->app->make(EventRecorder::class)->record($this->event());

                throw new RuntimeException('the business change failed');
            });
        } catch (RuntimeException) {
        }

        self::assertSame(0, OutboxMessage::query()->count());
    }

    #[Test]
    public function the_relay_publishes_each_message_once_in_its_tenant(): void
    {
        $seen = [];
        Event::listen(TenantCreated::class, function (TenantCreated $event) use (&$seen): void {
            $seen[] = [$event->slug, $this->app->make(TenantContext::class)->idOrNull()];
        });

        $this->record($this->event('first'), $this->event('second'));

        self::assertSame(2, $this->relay()->drain());
        self::assertSame(0, $this->relay()->drain());
        self::assertSame([['first', $this->tenant->id], ['second', $this->tenant->id]], $seen);
        self::assertSame(0, OutboxMessage::query()->whereNull('published_at')->count());
        self::assertNull($this->app->make(TenantContext::class)->idOrNull(), 'the tenant is only set while publishing');
    }

    #[Test]
    public function a_failing_listener_is_retried_with_backoff_then_given_up_on(): void
    {
        $calls = 0;
        Event::listen(TenantCreated::class, static function () use (&$calls): never {
            $calls++;

            throw new RuntimeException('search is down');
        });
        $log = Log::spy();
        $this->travelTo('2026-11-26T10:00:00Z');
        $this->record($this->event());

        self::assertSame(0, $this->relay()->relay());
        $message = OutboxMessage::query()->sole();
        self::assertSame([1, null, null], [$message->attempts, $message->published_at, $message->dead_at]);
        self::assertSame('RuntimeException: search is down', $message->last_error);
        self::assertTrue($message->available_at->equalTo(CarbonImmutable::parse('2026-11-26T10:00:05Z')));

        // Not due yet: untouched.
        self::assertSame(0, $this->relay()->relay());
        self::assertSame(1, $calls);

        $this->travel(5)->seconds();
        $this->relay()->relay();
        $this->travel(30)->seconds();
        $this->relay()->relay();

        $message->refresh();
        self::assertSame([3, 3], [$calls, $message->attempts]);
        self::assertNotNull($message->dead_at);
        $log->shouldHaveReceived('critical')->once();

        $this->travel(1)->days();
        self::assertSame(0, $this->relay()->relay(), 'dead messages are not retried');
        self::assertSame(3, $calls);
    }

    #[Test]
    public function one_failing_message_does_not_hold_back_the_others(): void
    {
        Event::listen(TenantCreated::class, static function (TenantCreated $event): void {
            if ($event->slug === 'broken') {
                throw new RuntimeException('no');
            }
        });

        $this->record($this->event('broken'), $this->event('fine'));

        self::assertSame(1, $this->relay()->drain());
        self::assertSame(['fine'], OutboxMessage::query()->whereNotNull('published_at')->get()->map(static fn (OutboxMessage $m): mixed => $m->payload['slug'])->all());
    }

    #[Test]
    public function a_listeners_writes_roll_back_with_its_failure(): void
    {
        Event::listen(TenantCreated::class, function (): never {
            Tenant::factory()->create(['slug' => 'written-by-listener']);

            throw new RuntimeException('after writing');
        });

        $this->record($this->event());
        $this->relay()->drain();

        self::assertFalse(Tenant::query()->where('slug', 'written-by-listener')->exists());
    }

    #[Test]
    public function an_unregistered_event_is_refused_when_it_is_recorded(): void
    {
        $this->expectExceptionMessage('No event is registered as "test.unknown"');

        $this->record(new class($this->tenant->id) implements DomainEvent
        {
            public function __construct(private readonly string $tenant) {}

            public function eventName(): string
            {
                return 'test.unknown';
            }

            public function tenantId(): string
            {
                return $this->tenant;
            }

            public function occurredAt(): CarbonImmutable
            {
                return CarbonImmutable::now();
            }

            public function payload(): array
            {
                return [];
            }

            public static function fromStored(string $tenantId, CarbonImmutable $occurredAt, array $payload): static
            {
                return new self($tenantId);
            }
        });
    }

    #[Test]
    public function inline_mode_publishes_as_soon_as_the_transaction_commits(): void
    {
        config(['outbox.after_commit' => 'inline']);
        $published = 0;
        Event::listen(TenantCreated::class, static function () use (&$published): void {
            $published++;
        });

        $this->app->make(TransactionRunner::class)->run(function () use (&$published): void {
            $this->app->make(EventRecorder::class)->record($this->event());
            self::assertSame(0, $published, 'nothing is published before the commit');
        });

        self::assertSame(1, $published);
    }

    #[Test]
    public function the_relay_command_drains_the_outbox(): void
    {
        $this->record($this->event('a'), $this->event('b'));

        $this->runArtisan('outbox:relay', ['--once' => true])->expectsOutputToContain('2 event(s) published.')->assertSuccessful();
    }

    #[Test]
    public function published_messages_are_pruned_after_their_retention_and_dead_ones_kept(): void
    {
        $this->travelTo('2026-10-01T00:00:00Z');
        $this->record($this->event('old'), $this->event('dead'));
        $this->relay()->drain();
        OutboxMessage::query()->where('payload->slug', 'dead')->update(['published_at' => null, 'dead_at' => now()]);
        $this->travelTo('2026-11-26T00:00:00Z');
        $this->record($this->event('recent'));
        $this->relay()->drain();

        $this->runArtisan('outbox:prune')->expectsOutputToContain('1 published event(s) pruned.')->assertSuccessful();
        self::assertEqualsCanonicalizing(['dead', 'recent'], OutboxMessage::query()->get()->map(static fn (OutboxMessage $m): mixed => $m->payload['slug'])->all());
    }

    /**
     * Every event a module raises survives the trip through the outbox:
     * registered under its name, and rebuilt equal to what was recorded.
     */
    #[Test]
    public function every_domain_event_round_trips_through_its_stored_payload(): void
    {
        $at = CarbonImmutable::parse('2026-11-26T09:00:00.123456Z');
        $t = $this->tenant->id;
        $usd = static fn (int $minor): Money => Money::of($minor, Currency::USD);
        $samples = [
            new ProductCreated($t, 'p-1', 1, $at),
            new ProductUpdated($t, 'p-1', 2, $at),
            new ProductArchived($t, 'p-1', 3, $at),
            new PriceChanged($t, 'p-1', 4, $at, 'v-1', Currency::EUR),
            new StockAdjusted($t, 'v-1', -2, 8, 5, 'damaged', $at),
            new OrderPlaced($t, 'o-1', 1, $at),
            new OrderPaid($t, 'o-1', 2, $at),
            new OrderFulfilled($t, 'o-1', 3, $at),
            new OrderCancelled($t, 'o-1', 2, $at),
            new OrderRefunded($t, 'o-1', 4, $at),
            new PaymentCaptured($t, 'pi-1', 'o-1', $usd(10000), $usd(320), Settlement::Order, $at),
            new PaymentFailed($t, 'pi-1', 'o-1', 'card_declined', $at),
            new PaymentFailed($t, 'pi-1', 'o-1', null, $at),
            new PaymentDisputed($t, 'pi-1', 'o-1', $usd(10000), null, $at),
            new RefundIssued($t, 'pi-1', 'o-1', 're-1', $usd(2500), $usd(500), Settlement::CustomerCredit, $at),
            new TenantCreated($t, 'acme', $at),
        ];

        $registry = $this->app->make(EventRegistry::class);
        $classes = [];

        foreach ($samples as $event) {
            self::assertSame($event::class, $registry->classOf($event->eventName()));
            $stored = json_decode((string) json_encode($event->payload()), true);
            self::assertIsArray($stored);
            self::assertEquals($event, $this->relay()->rebuild($t, $event->eventName(), $at->toIso8601ZuluString('microsecond'), $stored));
            $classes[] = $event::class;
        }

        self::assertEqualsCanonicalizing(array_values(array_unique($classes)), array_values($registry->all()), 'every registered event has a sample here');
        self::assertEqualsCanonicalizing(array_values($registry->all()), $this->domainEventClasses(), 'every domain event class is registered');
    }

    /**
     * @return list<class-string>
     */
    private function domainEventClasses(): array
    {
        $classes = [];

        foreach (glob(base_path('app/Modules/*/Domain/Events/*.php')) ?: [] as $file) {
            $class = 'App\\Modules\\'.str_replace(['/', '.php'], ['\\', ''], substr($file, strlen(base_path('app/Modules/'))));

            if (class_exists($class) && is_subclass_of($class, DomainEvent::class) && (new ReflectionClass($class))->isInstantiable()) {
                $classes[] = $class;
            }
        }

        return $classes;
    }
}
