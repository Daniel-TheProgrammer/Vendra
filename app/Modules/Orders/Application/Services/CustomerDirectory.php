<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Services;

use App\Modules\Orders\Infrastructure\Models\Customer;
use App\Shared\Application\Transaction\TransactionRunner;
use App\Shared\Domain\Exception\EntityNotFound;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Customers are identified by e-mail address within a tenant. Ordering with
 * an address seen before attaches the order to that customer.
 */
final readonly class CustomerDirectory
{
    public function __construct(private TransactionRunner $transaction) {}

    public function findOrCreate(string $email, ?string $name): Customer
    {
        $email = mb_strtolower(trim($email));
        $existing = Customer::query()->where('email', $email)->first();

        if ($existing instanceof Customer) {
            return $existing;
        }

        try {
            // Its own savepoint: in PostgreSQL a failed INSERT poisons the
            // surrounding transaction unless rolled back to one.
            return $this->transaction->run(static fn (): Customer => Customer::query()->create(['email' => $email, 'name' => $name]));
        } catch (UniqueConstraintViolationException) {
            // A concurrent order created the same customer first.
            return Customer::query()->where('email', $email)->firstOrFail();
        }
    }

    /**
     * @throws EntityNotFound
     */
    public function find(string $customerId): Customer
    {
        $customer = Customer::query()->whereKey($customerId)->first();

        return $customer instanceof Customer ? $customer : throw EntityNotFound::withId('Customer', $customerId);
    }
}
