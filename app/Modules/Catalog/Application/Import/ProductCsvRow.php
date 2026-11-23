<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Import;

/**
 * One data row. $line is the spreadsheet row number (the header is row 1),
 * so error messages point at the row the merchant sees.
 */
final readonly class ProductCsvRow
{
    /**
     * @param  array<string, string>  $values  column name => trimmed cell; empty when $error is set
     */
    public function __construct(
        public int $line,
        public array $values,
        public ?string $error = null,
    ) {}

    public function value(string $column): ?string
    {
        $value = $this->values[$column] ?? '';

        return $value === '' ? null : $value;
    }
}
