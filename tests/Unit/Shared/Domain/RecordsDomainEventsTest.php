<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Domain;

use App\Shared\Domain\Event\DomainEvent;
use App\Shared\Domain\Event\RecordsDomainEvents;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RecordsDomainEventsTest extends TestCase
{
    #[Test]
    public function recorded_events_are_released_once_in_order(): void
    {
        $aggregate = new class
        {
            use RecordsDomainEvents;

            public function doSomething(string $what): void
            {
                $this->recordThat($this->event($what));
            }

            private function event(string $what): DomainEvent
            {
                return new class($what) implements DomainEvent
                {
                    public function __construct(private readonly string $what) {}

                    public function eventName(): string
                    {
                        return 'test.'.$this->what;
                    }

                    public function tenantId(): string
                    {
                        return 'tenant-1';
                    }

                    public static function fromStored(string $tenantId, CarbonImmutable $occurredAt, array $payload): static
                    {
                        $what = $payload['what'] ?? '';

                        return new self(is_string($what) ? $what : '');
                    }

                    public function occurredAt(): CarbonImmutable
                    {
                        return CarbonImmutable::parse('2026-01-01T00:00:00Z');
                    }

                    /**
                     * @return array<string, mixed>
                     */
                    public function payload(): array
                    {
                        return ['what' => $this->what];
                    }
                };
            }
        };

        $aggregate->doSomething('first');
        $aggregate->doSomething('second');

        $released = $aggregate->releaseEvents();

        self::assertSame(['test.first', 'test.second'], array_map(fn (DomainEvent $e): string => $e->eventName(), $released));
        self::assertSame(['what' => 'second'], $released[1]->payload());
        self::assertSame([], $aggregate->releaseEvents());
    }
}
