<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

use App\Shared\Domain\Exception\ConflictException;

final class SkuAlreadyExists extends ConflictException
{
    private string $sku = '';

    public static function for(string $sku): self
    {
        $exception = new self(sprintf('A variant with SKU "%s" already exists.', $sku));
        $exception->sku = $sku;

        return $exception;
    }

    public function type(): string
    {
        return 'sku-taken';
    }

    public function title(): string
    {
        return 'SKU already in use';
    }

    /**
     * @return array{sku: string}
     */
    public function extensions(): array
    {
        return ['sku' => $this->sku];
    }
}
