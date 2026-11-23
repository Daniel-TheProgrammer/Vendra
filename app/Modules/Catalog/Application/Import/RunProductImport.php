<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Import;

use App\Modules\Catalog\Application\Actions\CreateProduct;
use App\Modules\Catalog\Domain\ImportStatus;
use App\Modules\Catalog\Domain\SkuAlreadyExists;
use App\Modules\Catalog\Infrastructure\Models\ProductImport;
use App\Modules\Catalog\Infrastructure\Models\ProductImportGroup;
use App\Shared\Application\Clock\Clock;
use App\Shared\Application\Transaction\TransactionRunner;
use App\Shared\Domain\Money\Currency;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;

/**
 * Works through an import one product group at a time. Each group is one
 * transaction that creates the product, records the outcome in
 * product_import_groups and advances the counters, so the database is
 * always in a state where every group is either fully done or not started.
 *
 * Running it again (a retry, or a fresh worker after a crash or deploy)
 * skips the groups already recorded and carries on: an import can be
 * interrupted at any point without creating a product twice or losing count.
 * Must run inside the import's tenant context.
 */
final readonly class RunProductImport
{
    public function __construct(
        private TransactionRunner $transaction,
        private CreateProduct $create,
        private ProductGroupMapper $mapper,
        private Filesystems $filesystems,
        private Clock $clock,
    ) {}

    /**
     * Imports up to $maxGroups not-yet-imported groups and reports whether
     * the import is now finished. Callers on a queue pass a limit and come
     * back for the rest, so no single attempt outlives the queue's
     * retry_after and gets picked up a second time while still running.
     */
    public function __invoke(string $importId, ?int $maxGroups = null): bool
    {
        $import = ProductImport::query()->whereKey($importId)->first();

        if (! $import instanceof ProductImport || $import->status->isFinished()) {
            return true;
        }

        if ($import->status === ImportStatus::Pending) {
            $import->status = ImportStatus::Processing;
            $import->started_at = $this->clock->now();
            $import->save();
        }

        try {
            $csv = $this->open($import);
        } catch (InvalidProductCsv $invalid) {
            $this->fail($importId, $invalid->getMessage());

            return true;
        }

        $done = array_flip(ProductImportGroup::query()->where('import_id', $import->id)->pluck('handle')->all());
        $currencies = $csv->priceCurrencies();
        $budget = $maxGroups;

        foreach ($csv->groups() as $group) {
            if (isset($done[$group->handle])) {
                continue;
            }

            if ($budget !== null && $budget-- === 0) {
                return false;
            }

            $this->transaction->run(fn () => $this->importGroup($import, $group, $currencies));
        }

        $import->refresh();
        $import->status = ImportStatus::Completed;
        $import->finished_at = $this->clock->now();
        $import->save();

        return true;
    }

    /**
     * Records an import that cannot continue. Groups already imported stay.
     */
    public function fail(string $importId, string $reason): void
    {
        ProductImport::query()
            ->whereKey($importId)
            ->whereIn('status', [ImportStatus::Pending->value, ImportStatus::Processing->value])
            ->update([
                'status' => ImportStatus::Failed->value,
                'failure' => mb_substr($reason, 0, 1000),
                'finished_at' => $this->clock->now(),
            ]);
    }

    /**
     * @param  list<Currency>  $currencies
     */
    private function importGroup(ProductImport $import, ProductCsvGroup $group, array $currencies): void
    {
        $productId = null;
        $errors = null;

        try {
            $productId = ($this->create)($this->mapper->toCommand($import->tenant_id, $group, $currencies))->id;
        } catch (InvalidProductGroup $invalid) {
            $errors = $invalid->errors;
        } catch (SkuAlreadyExists $taken) {
            $errors = [['row' => $this->rowOfSku($group, $taken), 'message' => $taken->getMessage()]];
        }

        ProductImportGroup::query()->create([
            'import_id' => $import->id,
            'handle' => $group->handle,
            'first_row' => $group->firstRow(),
            'row_count' => count($group->rows),
            'outcome' => $productId !== null ? ProductImportGroup::CREATED : ProductImportGroup::FAILED,
            'product_id' => $productId,
            'errors' => $errors,
        ]);

        ProductImport::query()->whereKey($import->id)->incrementEach([
            'processed_rows' => count($group->rows),
            'failed_rows' => $productId !== null ? 0 : count($group->rows),
            'created_products' => $productId !== null ? 1 : 0,
        ]);
    }

    private function rowOfSku(ProductCsvGroup $group, SkuAlreadyExists $taken): int
    {
        $sku = $taken->extensions()['sku'];

        foreach ($group->rows as $row) {
            if (strtoupper((string) $row->value('sku')) === $sku) {
                return $row->line;
            }
        }

        return $group->firstRow();
    }

    /**
     * @throws InvalidProductCsv
     */
    private function open(ProductImport $import): ProductCsv
    {
        $stream = $this->filesystems->disk($import->disk)->readStream($import->path);

        if (! is_resource($stream)) {
            throw new InvalidProductCsv('The uploaded file is no longer available.');
        }

        try {
            return ProductCsv::read($stream);
        } finally {
            fclose($stream);
        }
    }
}
