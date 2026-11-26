<?php

declare(strict_types=1);

namespace App\Shared\Application\Event;

use App\Shared\Domain\Event\DomainEvent;
use LogicException;

/**
 * How application code raises domain events: they are recorded in the
 * transaction that caused them and published after it commits (ADR 0010).
 * An event therefore exists if and only if its cause was committed, and
 * nothing is lost if the process dies before listeners run.
 *
 * Never dispatch a domain event on the event bus directly.
 */
interface EventRecorder
{
    /**
     * @throws LogicException outside a database transaction: the event would
     *                        not be atomic with its cause
     */
    public function record(DomainEvent ...$events): void;
}
