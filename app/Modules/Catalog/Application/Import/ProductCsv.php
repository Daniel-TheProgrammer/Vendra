<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Import;

use App\Shared\Domain\Money\Currency;

/**
 * The import format: RFC 4180 CSV, UTF-8 (a BOM is tolerated), header row
 * first. One row per variant; rows sharing a handle form one product, whose
 * name, description and status are read from its first row.
 *
 *   handle,name,description,status,sku,variant_name,options,price_usd,price_eur
 *   tee,Organic Tee,Heavyweight,active,TEE-RED-M,Red / M,colour=red;size=M,19.99,18.50
 *   tee,,,,TEE-RED-L,Red / L,colour=red;size=L,21.00,
 *
 * Column names are case-insensitive. Unknown columns reject the whole file,
 * so a misspelt "prise_usd" never silently imports products without prices.
 */
final readonly class ProductCsv
{
    public const MAX_ROWS = 10_000;

    public const REQUIRED_COLUMNS = ['handle', 'name', 'sku'];

    public const OPTIONAL_COLUMNS = ['description', 'status', 'variant_name', 'options'];

    public const PRICE_PREFIX = 'price_';

    /**
     * @param  list<string>  $columns
     * @param  list<ProductCsvRow>  $rows
     */
    private function __construct(
        public array $columns,
        private array $rows,
    ) {}

    /**
     * @param  resource  $stream
     *
     * @throws InvalidProductCsv
     */
    public static function read($stream): self
    {
        $header = fgetcsv($stream, escape: '');

        if (! is_array($header) || $header === [null]) {
            throw new InvalidProductCsv('The file is empty; the first row must name the columns.');
        }

        $columns = self::columns($header);
        $rows = [];
        $line = 1;

        while (($cells = fgetcsv($stream, escape: '')) !== false) {
            $line++;

            if ($cells === [null] || implode('', array_map(static fn (?string $cell): string => trim((string) $cell), $cells)) === '') {
                continue;
            }

            if (count($rows) === self::MAX_ROWS) {
                throw new InvalidProductCsv(sprintf('The file has more than %d rows; split it into several imports.', self::MAX_ROWS));
            }

            $rows[] = count($cells) === count($columns)
                ? new ProductCsvRow($line, array_combine($columns, array_map(static fn (?string $cell): string => trim((string) $cell), $cells)))
                : new ProductCsvRow($line, [], sprintf('Expected %d columns, found %d.', count($columns), count($cells)));
        }

        if ($rows === []) {
            throw new InvalidProductCsv('The file has a header but no rows to import.');
        }

        return new self($columns, $rows);
    }

    public function rowCount(): int
    {
        return count($this->rows);
    }

    /**
     * @return list<Currency>
     */
    public function priceCurrencies(): array
    {
        $currencies = [];

        foreach ($this->columns as $column) {
            if (str_starts_with($column, self::PRICE_PREFIX)) {
                $currencies[] = Currency::from(strtoupper(substr($column, strlen(self::PRICE_PREFIX))));
            }
        }

        return $currencies;
    }

    /**
     * Product groups in the order their handles first appear. A row without
     * a handle, or one that could not be read, is a group of its own and
     * fails on its own.
     *
     * @return list<ProductCsvGroup>
     */
    public function groups(): array
    {
        /** @var array<string, non-empty-list<ProductCsvRow>> $groups */
        $groups = [];

        foreach ($this->rows as $row) {
            $handle = $row->value('handle');
            $key = $handle !== null && $row->error === null ? $handle : '#row-'.$row->line;
            $groups[$key][] = $row;
        }

        $result = [];

        foreach ($groups as $handle => $rows) {
            $result[] = new ProductCsvGroup((string) $handle, $rows);
        }

        return $result;
    }

    /**
     * @param  array<int, string|null>  $header
     * @return list<string>
     *
     * @throws InvalidProductCsv
     */
    private static function columns(array $header): array
    {
        $columns = [];

        foreach ($header as $index => $name) {
            $name = strtolower(trim((string) $name));

            if ($index === 0) {
                $name = ltrim($name, "\u{FEFF}");
            }

            $columns[] = $name;
        }

        $problems = [];

        if (count($columns) !== count(array_unique($columns))) {
            $problems[] = 'a column appears more than once';
        }

        $missing = array_diff(self::REQUIRED_COLUMNS, $columns);

        if ($missing !== []) {
            $problems[] = 'missing column(s): '.implode(', ', $missing);
        }

        $unknown = array_filter($columns, static fn (string $column): bool => ! in_array($column, [...self::REQUIRED_COLUMNS, ...self::OPTIONAL_COLUMNS], true)
            && (! str_starts_with($column, self::PRICE_PREFIX) || ! Currency::tryFrom(strtoupper(substr($column, strlen(self::PRICE_PREFIX)))) instanceof Currency));

        if ($unknown !== []) {
            $problems[] = 'unknown column(s): '.implode(', ', array_map(static fn (string $column): string => $column === '' ? '(blank)' : $column, $unknown));
        }

        if ($problems !== []) {
            throw new InvalidProductCsv('The header row is invalid: '.implode('; ', $problems).'.');
        }

        return $columns;
    }
}
