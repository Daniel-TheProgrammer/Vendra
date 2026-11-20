<?php

declare(strict_types=1);

namespace App\Shared\Application\Transaction;

/**
 * Runs a unit of work atomically. Actions depend on this abstraction rather
 * than on the database facade so the boundary is explicit and mockable.
 */
interface TransactionRunner
{
    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $operation
     * @return TReturn
     */
    public function run(callable $operation): mixed;
}
