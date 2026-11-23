<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

use App\Shared\Domain\Exception\DomainException;

/**
 * The client edited a stale copy: If-Match named a version that is no longer
 * current. The client must re-read the product and reapply its change.
 */
final class ProductVersionMismatch extends DomainException
{
    private int $currentVersion = 0;

    public static function expected(int $expected, int $current): self
    {
        $exception = new self(sprintf('The product changed since version %d; it is now at version %d.', $expected, $current));
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

    public function extensions(): array
    {
        return ['current_version' => $this->currentVersion];
    }
}
