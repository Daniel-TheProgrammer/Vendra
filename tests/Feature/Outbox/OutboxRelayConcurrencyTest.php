<?php

declare(strict_types=1);

namespace Tests\Feature\Outbox;

use App\Modules\Tenancy\Domain\Events\TenantCreated;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use App\Shared\Application\Event\EventRecorder;
use App\Shared\Application\Transaction\TransactionRunner;
use App\Shared\Infrastructure\Outbox\OutboxMessage;
use App\Shared\Infrastructure\Outbox\OutboxRelay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RacesWorkers;
use Tests\TestCase;

/**
 * Two relays draining one outbox at once, committed for real: SKIP LOCKED
 * must hand every message to exactly one of them.
 */
#[Group('database')]
#[Group('concurrency')]
#[RequiresPhpExtension('pcntl')]
#[RequiresPhpExtension('posix')]
final class OutboxRelayConcurrencyTest extends TestCase
{
    use RacesWorkers;

    private const MESSAGES = 300;

    private const RELAYS = 2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepareRace();
        config(['outbox.after_commit' => 'off', 'outbox.batch_size' => 20]);
    }

    protected function tearDown(): void
    {
        $this->endRace();

        parent::tearDown();
    }

    #[Test]
    public function two_concurrent_relays_publish_every_message_exactly_once(): void
    {
        $tenant = Tenant::factory()->create()->id;
        $events = array_map(static fn (int $n): TenantCreated => new TenantCreated($tenant, 'slug-'.$n, CarbonImmutable::now()), range(1, self::MESSAGES));
        $this->app->make(TransactionRunner::class)->run(fn () => $this->app->make(EventRecorder::class)->record(...$events));

        $receipts = sys_get_temp_dir().'/vendra-outbox-receipts-'.bin2hex(random_bytes(4));

        $results = $this->race(self::RELAYS, function () use ($receipts): string {
            // Each listener call appends one line: duplicates would show.
            Event::listen(TenantCreated::class, static function (TenantCreated $event) use ($receipts): void {
                file_put_contents($receipts, $event->slug."\n", FILE_APPEND | LOCK_EX);
            });

            return (string) $this->app->make(OutboxRelay::class)->drain();
        });

        $lines = file($receipts, FILE_IGNORE_NEW_LINES) ?: [];
        unlink($receipts);

        self::assertSame(self::MESSAGES, array_sum(array_map(intval(...), $results)), 'published counts: '.implode(', ', $results));
        self::assertCount(self::MESSAGES, $lines, 'each message reached the listener once');
        self::assertCount(self::MESSAGES, array_unique($lines));
        self::assertSame(0, OutboxMessage::query()->whereNull('published_at')->count());
        self::assertNotContains('0', $results, 'both relays did some of the work');
    }
}
