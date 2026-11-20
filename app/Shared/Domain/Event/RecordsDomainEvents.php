<?php

declare(strict_types=1);

namespace App\Shared\Domain\Event;

/**
 * Lets an aggregate collect the events it produced during one operation.
 * The application layer releases them after the surrounding transaction commits.
 */
trait RecordsDomainEvents
{
    /** @var list<DomainEvent> */
    private array $recordedEvents = [];

    protected function recordThat(DomainEvent $event): void
    {
        $this->recordedEvents[] = $event;
    }

    /**
     * Returns the recorded events and clears the buffer.
     *
     * @return list<DomainEvent>
     */
    public function releaseEvents(): array
    {
        $events = $this->recordedEvents;
        $this->recordedEvents = [];

        return $events;
    }
}
