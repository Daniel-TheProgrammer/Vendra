<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Import;

/**
 * The rows of one product: every row sharing a handle, in file order.
 */
final readonly class ProductCsvGroup
{
    /**
     * @param  non-empty-list<ProductCsvRow>  $rows
     */
    public function __construct(
        public string $handle,
        public array $rows,
    ) {}

    public function firstRow(): int
    {
        return $this->rows[0]->line;
    }
}
