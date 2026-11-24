<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http\Idempotency;

use App\Shared\Domain\Exception\DomainException;

/**
 * The ways a request can break the Idempotency-Key contract (ADR 0007).
 */
final class IdempotencyProblem extends DomainException
{
    /**
     * @param  array<string, string>  $headers
     */
    private function __construct(
        string $message,
        private readonly int $statusCode,
        private readonly string $problemType,
        private readonly string $problemTitle,
        private readonly array $headers = [],
    ) {
        parent::__construct($message);
    }

    public static function missing(): self
    {
        return new self(
            'This request creates something, so it must carry an Idempotency-Key header (any unique value, such as a UUID) that you reuse when retrying it.',
            400,
            'idempotency-key-required',
            'Idempotency-Key required',
        );
    }

    public static function malformed(): self
    {
        return new self(
            'The Idempotency-Key must be 1 to 255 visible ASCII characters.',
            400,
            'idempotency-key-invalid',
            'Invalid Idempotency-Key',
        );
    }

    public static function reusedWithDifferentRequest(): self
    {
        return new self(
            'This Idempotency-Key was already used for a different request. Use a new key for a new request.',
            422,
            'idempotency-key-reused',
            'Idempotency-Key reused',
        );
    }

    public static function inFlight(int $retryAfter): self
    {
        return new self(
            'A request with this Idempotency-Key is still being processed. Retry after it completes to receive its result.',
            409,
            'idempotency-key-in-flight',
            'Request in progress',
            ['Retry-After' => (string) $retryAfter],
        );
    }

    public function status(): int
    {
        return $this->statusCode;
    }

    public function type(): string
    {
        return $this->problemType;
    }

    public function title(): string
    {
        return $this->problemTitle;
    }

    public function headers(): array
    {
        return $this->headers;
    }
}
