<?php

declare(strict_types=1);

namespace App\Shared\Tenancy;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Log\Context\Repository as Context;

/**
 * The tenant the current unit of work belongs to.
 *
 * Backed by Laravel's Context so the tenant id is written into every log line
 * and travels automatically with queued jobs: whatever is set here when a job
 * is dispatched is restored on the worker before the job runs.
 */
final readonly class TenantContext
{
    public const KEY = 'tenant_id';

    public function __construct(
        private Context $context,
        private Dispatcher $events,
    ) {}

    public function set(string $tenantId): void
    {
        $this->context->add(self::KEY, $tenantId);
        $this->events->dispatch(new TenantContextChanged($tenantId));
    }

    public function clear(): void
    {
        $this->context->forget(self::KEY);
        $this->events->dispatch(new TenantContextChanged(null));
    }

    public function has(): bool
    {
        return $this->idOrNull() !== null;
    }

    public function idOrNull(): ?string
    {
        $id = $this->context->get(self::KEY);

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * @throws TenantNotResolved when no tenant has been set
     */
    public function id(): string
    {
        return $this->idOrNull() ?? throw TenantNotResolved::create();
    }

    /**
     * Runs the operation as the given tenant and restores the previous state afterwards.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $operation
     * @return TReturn
     */
    public function runAs(string $tenantId, callable $operation): mixed
    {
        $previous = $this->idOrNull();
        $this->set($tenantId);

        try {
            return $operation();
        } finally {
            if ($previous === null) {
                $this->clear();
            } else {
                $this->set($previous);
            }
        }
    }
}
