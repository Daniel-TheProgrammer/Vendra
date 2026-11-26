<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Shared\Application\Event\EventRecorder;
use App\Shared\Domain\Event\DomainEvent;
use App\Shared\Infrastructure\Outbox\OutboxRecorder;
use Throwable;

/**
 * Fault injection inside a business transaction. Listeners run from the
 * outbox after the commit (ADR 0010), so a throwing listener no longer
 * fails the change that raised the event; recording the event is the last
 * thing every use case does inside its transaction, so failing there
 * simulates a crash after the writes but before the commit.
 */
trait FailsWhenRecording
{
    /**
     * @param  callable(DomainEvent): bool  $when
     */
    protected function failWhenRecording(callable $when, Throwable $failure): void
    {
        $this->app->instance(EventRecorder::class, new class($this->app->make(OutboxRecorder::class), $when, $failure) implements EventRecorder
        {
            /**
             * @param  callable(DomainEvent): bool  $when
             */
            public function __construct(
                private readonly EventRecorder $recorder,
                private readonly mixed $when,
                private readonly Throwable $failure,
            ) {}

            public function record(DomainEvent ...$events): void
            {
                foreach ($events as $event) {
                    if (($this->when)($event)) {
                        throw $this->failure;
                    }
                }

                $this->recorder->record(...$events);
            }
        });
    }

    protected function recordNormally(): void
    {
        $this->app->forgetInstance(EventRecorder::class);
    }
}
