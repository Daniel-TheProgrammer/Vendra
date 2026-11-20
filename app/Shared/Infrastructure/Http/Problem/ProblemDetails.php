<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http\Problem;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;

/**
 * RFC 9457 Problem Details: the single error shape every API response uses.
 */
final readonly class ProblemDetails implements Responsable
{
    public const TYPE_PREFIX = 'urn:problem-type:';

    public const CONTENT_TYPE = 'application/problem+json';

    /**
     * @param  array<string, mixed>  $extensions
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public int $status,
        public string $type,
        public string $title,
        public ?string $detail = null,
        public ?string $instance = null,
        public array $extensions = [],
        public array $headers = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $body = [
            'type' => self::TYPE_PREFIX.$this->type,
            'title' => $this->title,
            'status' => $this->status,
        ];

        if ($this->detail !== null) {
            $body['detail'] = $this->detail;
        }

        if ($this->instance !== null) {
            $body['instance'] = $this->instance;
        }

        return [...$body, ...$this->extensions];
    }

    public function toResponse($request): JsonResponse
    {
        return new JsonResponse(
            $this->toArray(),
            $this->status,
            [...$this->headers, 'Content-Type' => self::CONTENT_TYPE],
        );
    }
}
