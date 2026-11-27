<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Application\Delivery;

/**
 * What one attempt got back: a status code, or the error that stopped it
 * before one arrived (timeout, refused connection, TLS failure).
 */
final readonly class TransportResult
{
    public function __construct(
        public ?int $status,
        public int $milliseconds,
        public ?string $excerpt = null,
        public ?string $error = null,
    ) {}

    public function succeeded(): bool
    {
        return $this->status !== null && $this->status >= 200 && $this->status < 300;
    }
}
