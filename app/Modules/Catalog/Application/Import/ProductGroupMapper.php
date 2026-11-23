<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Import;

use App\Modules\Catalog\Application\Commands\CreateProductCommand;
use App\Modules\Catalog\Application\Commands\VariantData;
use App\Modules\Catalog\Domain\ProductStatus;
use App\Modules\Catalog\Domain\Sku;
use App\Shared\Domain\Money\Currency;
use App\Shared\Domain\Money\Money;
use InvalidArgumentException;

/**
 * Turns one product group into the same command the API's POST /products
 * builds, applying the same limits, so an imported product is
 * indistinguishable from one created by hand.
 */
final readonly class ProductGroupMapper
{
    public const MAX_VARIANTS = 100;

    private const MAX_OPTIONS = 10;

    /**
     * @param  list<Currency>  $currencies  the file's price_* columns
     *
     * @throws InvalidProductGroup
     */
    public function toCommand(string $tenantId, ProductCsvGroup $group, array $currencies): CreateProductCommand
    {
        /** @var list<array{row: int, message: string}> $errors */
        $errors = [];
        $first = $group->rows[0];

        foreach ($group->rows as $row) {
            if ($row->error !== null) {
                throw InvalidProductGroup::at($row->line, $row->error);
            }
        }

        $name = $first->value('name');
        $description = $first->value('description');
        $status = ProductStatus::tryFrom(strtolower($first->value('status') ?? ProductStatus::Draft->value));

        if ($first->value('handle') === null) {
            $errors[] = ['row' => $first->line, 'message' => 'handle is required.'];
        } elseif (mb_strlen((string) $first->value('handle')) > 100) {
            $errors[] = ['row' => $first->line, 'message' => 'handle may not be longer than 100 characters.'];
        }

        if ($name === null || mb_strlen($name) > 200) {
            $errors[] = ['row' => $first->line, 'message' => 'name is required on the first row of a product and may not be longer than 200 characters.'];
        }

        if ($description !== null && mb_strlen($description) > 5000) {
            $errors[] = ['row' => $first->line, 'message' => 'description may not be longer than 5000 characters.'];
        }

        if (! $status instanceof ProductStatus || ! in_array($status->value, ProductStatus::editableValues(), true)) {
            $errors[] = ['row' => $first->line, 'message' => sprintf('status must be one of: %s.', implode(', ', ProductStatus::editableValues()))];
        }

        if (count($group->rows) > self::MAX_VARIANTS) {
            $errors[] = ['row' => $first->line, 'message' => sprintf('A product may have at most %d variants.', self::MAX_VARIANTS)];
        }

        $variants = [];
        $skus = [];

        foreach ($group->rows as $row) {
            try {
                $variant = $this->variant($row, $currencies);
            } catch (InvalidProductGroup $invalid) {
                array_push($errors, ...$invalid->errors);

                continue;
            }

            if (isset($skus[$variant->sku])) {
                $errors[] = ['row' => $row->line, 'message' => sprintf('SKU %s already appears on row %d.', $variant->sku, $skus[$variant->sku])];

                continue;
            }

            $skus[$variant->sku] = $row->line;
            $variants[] = $variant;
        }

        if ($errors !== [] || $name === null || ! $status instanceof ProductStatus || $variants === []) {
            throw new InvalidProductGroup($errors !== [] ? $errors : [['row' => $first->line, 'message' => 'The product has no importable variants.']]);
        }

        return new CreateProductCommand($tenantId, $name, $description, $status, $variants);
    }

    /**
     * @param  list<Currency>  $currencies
     *
     * @throws InvalidProductGroup
     */
    private function variant(ProductCsvRow $row, array $currencies): VariantData
    {
        $errors = [];
        $sku = $row->value('sku');

        if ($sku === null || preg_match(Sku::PATTERN, $sku) !== 1) {
            $errors[] = 'sku is required: 1 to 64 letters, digits, ".", "_" or "-", starting with a letter or digit.';
        }

        $variantName = $row->value('variant_name');

        if ($variantName !== null && mb_strlen($variantName) > 200) {
            $errors[] = 'variant_name may not be longer than 200 characters.';
        }

        $options = $this->options($row->value('options'), $errors);
        $prices = [];

        foreach ($currencies as $currency) {
            $amount = $row->value(ProductCsv::PRICE_PREFIX.strtolower($currency->value));

            if ($amount === null) {
                continue;
            }

            try {
                if (preg_match('/^\d{1,13}(\.\d{1,3})?$/', $amount) !== 1) {
                    throw new InvalidArgumentException;
                }

                $prices[] = Money::fromDecimal($amount, $currency);
            } catch (InvalidArgumentException) {
                $errors[] = sprintf('price_%s must be a decimal amount with at most %d decimal places, e.g. "19.99".', strtolower($currency->value), $currency->minorUnit());
            }
        }

        if ($errors === [] && $sku !== null) {
            return new VariantData($sku, $variantName, $options, $prices);
        }

        throw new InvalidProductGroup(array_map(
            static fn (string $message): array => ['row' => $row->line, 'message' => $message],
            $errors === [] ? ['sku is required.'] : $errors,
        ));
    }

    /**
     * "colour=red;size=M" => ["colour" => "red", "size" => "M"]
     *
     * @param  list<string>  $errors
     * @return array<string, string>
     */
    private function options(?string $raw, array &$errors): array
    {
        if ($raw === null) {
            return [];
        }

        $options = [];

        foreach (explode(';', $raw) as $pair) {
            $parts = explode('=', $pair, 2);
            $name = trim($parts[0]);
            $value = trim($parts[1] ?? '');

            if ($name === '' || $value === '' || mb_strlen($name) > 100 || mb_strlen($value) > 100 || isset($options[$name])) {
                $errors[] = 'options must be name=value pairs separated by ";", each name used once, e.g. "colour=red;size=M".';

                return [];
            }

            $options[$name] = $value;
        }

        if (count($options) > self::MAX_OPTIONS) {
            $errors[] = sprintf('A variant may have at most %d options.', self::MAX_OPTIONS);
        }

        return $options;
    }
}
