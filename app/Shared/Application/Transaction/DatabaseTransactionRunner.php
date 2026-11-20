<?php

declare(strict_types=1);

namespace App\Shared\Application\Transaction;

use Illuminate\Database\ConnectionResolverInterface;

final readonly class DatabaseTransactionRunner implements TransactionRunner
{
    public function __construct(
        private ConnectionResolverInterface $connections,
    ) {}

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $operation
     * @return TReturn
     */
    public function run(callable $operation): mixed
    {
        return $this->connections->connection()->transaction(static fn () => $operation());
    }
}
