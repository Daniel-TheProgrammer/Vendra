<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http\Idempotency;

use App\Shared\Application\Clock\Clock;
use App\Shared\Tenancy\TenantContext;
use Closure;
use Illuminate\Contracts\Cache\Factory as Cache;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

/**
 * Makes retries of mutating requests safe (ADR 0007).
 *
 * POST requires an Idempotency-Key; PUT, PATCH and DELETE honour one when
 * sent. For each tenant and key:
 *
 *   first request          runs, and its outcome is stored for 24 hours
 *   same key, same request the stored outcome is replayed, with
 *                          Idempotent-Replayed: true, and nothing runs again
 *   same key, other request 422 idempotency-key-reused
 *   while the first runs   409 idempotency-key-in-flight with Retry-After
 *
 * Only outcomes of requests that actually ran are stored. Validation errors,
 * conflicts with concurrent writers (409, 412), rate limiting and server
 * errors are not: the key is released and a retry runs afresh, which is what
 * a client retrying after fixing its input or after an outage needs.
 *
 * A Redis lock (SET NX with a TTL) serialises requests sharing a key; the row
 * in idempotency_keys is the durable record, and its unique index is the
 * backstop should a lock ever expire early.
 *
 * Must run after authentication and tenant resolution.
 */
final readonly class EnforceIdempotency
{
    public const HEADER = 'Idempotency-Key';

    public const REPLAYED_HEADER = 'Idempotent-Replayed';

    /** Response headers that are part of the outcome and replayed with it. */
    private const REPLAYED_HEADERS = ['content-type', 'location', 'etag'];

    private const NOT_STORED_STATUSES = [408, 409, 412, 425, 429];

    public function __construct(
        private TenantContext $tenant,
        private Cache $cache,
        private Config $config,
        private Clock $clock,
        private DatabaseManager $db,
    ) {}

    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }

        $key = $request->headers->get(self::HEADER);

        if ($key === null || $key === '') {
            if ($request->isMethod('POST')) {
                throw IdempotencyProblem::missing();
            }

            return $next($request);
        }

        if (strlen($key) > 255 || preg_match('/^[\x21-\x7E]+$/', $key) !== 1) {
            throw IdempotencyProblem::malformed();
        }

        $fingerprint = RequestFingerprint::of($request);
        $locks = $this->cache->store($this->config->get('idempotency.lock_store'))->getStore();

        if (! $locks instanceof LockProvider) {
            throw new LogicException('The idempotency lock store must support atomic locks; configure Redis.');
        }

        $lock = $locks->lock('idempotency:'.$this->tenant->id().':'.hash('sha256', $key), $this->config->integer('idempotency.lock_seconds', 60));

        if (! $lock->get()) {
            throw IdempotencyProblem::inFlight($this->retryAfter());
        }

        try {
            $record = IdempotencyRecord::query()->where('key', $key)->first();

            if ($record instanceof IdempotencyRecord) {
                if (! hash_equals($record->request_hash, $fingerprint)) {
                    throw IdempotencyProblem::reusedWithDifferentRequest();
                }

                if ($record->isCompleted()) {
                    return $this->replay($record);
                }

                // An earlier attempt died before finishing (we hold the lock, so
                // it is not running). Nothing it did was committed as an
                // outcome; run the request again under the same record.
                $record->locked_at = $this->clock->now();
                $record->save();
            } else {
                $record = $this->begin($key, $fingerprint);
            }

            return $this->execute($request, $next, $record);
        } finally {
            $lock->release();
        }
    }

    /**
     * Runs the request and stores its outcome in one transaction, so the
     * effects and the record of them commit together or not at all. A crash
     * between the two can therefore never leave a request that took effect
     * but would run again on retry. The use case's own transactions become
     * savepoints inside this one; jobs dispatched "after commit" wait for it.
     */
    private function execute(Request $request, Closure $next, IdempotencyRecord $record): SymfonyResponse
    {
        $connection = $this->db->connection();
        $connection->beginTransaction();

        try {
            /** @var SymfonyResponse $response */
            $response = $next($request);
        } catch (Throwable $exception) {
            $connection->rollBack();
            $record->delete();

            throw $exception;
        }

        if (! $this->isStorable($response)) {
            // Undo anything a failed request wrote before failing, and free
            // the key for a clean retry.
            $connection->rollBack();
            $record->delete();

            return $response;
        }

        $this->complete($record, $response);
        $connection->commit();

        return $response;
    }

    private function begin(string $key, string $fingerprint): IdempotencyRecord
    {
        $now = $this->clock->now();

        try {
            return IdempotencyRecord::query()->create([
                'key' => $key,
                'request_hash' => $fingerprint,
                'locked_at' => $now,
                'expires_at' => $now->addHours($this->config->integer('idempotency.ttl_hours', 24)),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Another request with this key got in although we hold the lock:
            // the lock expired under a slow request. Treat it as in flight.
            throw IdempotencyProblem::inFlight($this->retryAfter());
        }
    }

    private function isStorable(SymfonyResponse $response): bool
    {
        $status = $response->getStatusCode();

        if ($status >= 500 || in_array($status, self::NOT_STORED_STATUSES, true)) {
            return false;
        }

        // Malformed input never reached the use case; let the client fix it
        // and retry under the same key.
        return $status !== 422 || ! str_contains((string) $response->getContent(), 'validation-failed');
    }

    private function complete(IdempotencyRecord $record, SymfonyResponse $response): void
    {
        $headers = [];

        foreach (self::REPLAYED_HEADERS as $name) {
            $value = $response->headers->get($name);

            if ($value !== null) {
                $headers[$name] = $value;
            }
        }

        $record->forceFill([
            'response_status' => $response->getStatusCode(),
            'response_headers' => $headers,
            'response_body' => (string) $response->getContent(),
            'completed_at' => $this->clock->now(),
        ])->save();
    }

    private function replay(IdempotencyRecord $record): Response
    {
        return new Response(
            $record->response_body ?? '',
            $record->response_status ?? 200,
            [...($record->response_headers ?? []), self::REPLAYED_HEADER => 'true'],
        );
    }

    private function retryAfter(): int
    {
        return $this->config->integer('idempotency.retry_after_seconds', 1);
    }
}
