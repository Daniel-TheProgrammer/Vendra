<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Jobs;

use App\Modules\Catalog\Application\Import\RunProductImport;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * Queue entry point for an import. All the work, and everything that makes
 * it safe to interrupt and repeat, is in RunProductImport; this class only
 * decides the batch size, the retries and that one import never runs twice
 * at once.
 *
 * Each attempt imports one batch and queues a fresh job for the next, so an
 * attempt stays well inside the queue's retry_after (90 s) whatever the
 * file size, and a deploy that stops the worker loses at most one batch of
 * in-flight work, which the next job simply redoes.
 */
final class ProcessProductImport implements ShouldQueue
{
    use Queueable;

    public const GROUPS_PER_BATCH = 250;

    public int $tries = 5;

    /** Seconds one attempt may run; below the queue's retry_after. */
    public int $timeout = 75;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $importId,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        // A duplicate (a retry racing a slow attempt) waits its turn instead of
        // importing the same groups concurrently. The lock expires on its own
        // if a worker dies holding it.
        return [(new WithoutOverlapping('product-import:'.$this->importId))->releaseAfter(15)->expireAfter($this->timeout + 15)];
    }

    public function handle(TenantContext $context, RunProductImport $run, Dispatcher $bus): void
    {
        $finished = $context->runAs($this->tenantId, fn (): bool => $run($this->importId, self::GROUPS_PER_BATCH));

        if (! $finished) {
            $bus->dispatch(new self($this->tenantId, $this->importId));
        }
    }

    /**
     * Retries are exhausted: say so on the import instead of leaving it
     * "processing" forever.
     */
    public function failed(?Throwable $exception): void
    {
        app(TenantContext::class)->runAs(
            $this->tenantId,
            fn () => app(RunProductImport::class)->fail($this->importId, 'The import stopped after repeated errors: '.($exception?->getMessage() ?? 'unknown error')),
        );
    }
}
