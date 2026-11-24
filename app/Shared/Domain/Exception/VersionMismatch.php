<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/**
 * Optimistic concurrency failure (ADR 0006): the client changed a stale copy.
 * If-Match named a version that is no longer current, so the client must
 * re-read the resource and decide again.
 */
final class VersionMismatch extends DomainException
{
    private int $currentVersion = 0;

    public static function of(string $entity, int $expected, int $current): self
    {
        $exception = new self(sprintf('The %s changed since version %d; it is now at version %d.', $entity, $expected, $current));
        $exception->currentVersion = $current;

        return $exception;
    }

    public function status(): int
    {
        return 412;
    }

    public function type(): string
    {
        return 'precondition-failed';
    }

    public function title(): string
    {
        return 'Precondition failed';
    }

    /**
     * @return array{current_version: int}
     */
    public function extensions(): array
    {
        return ['current_version' => $this->currentVersion];
    }
}
