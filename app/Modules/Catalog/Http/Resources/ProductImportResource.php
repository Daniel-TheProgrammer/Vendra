<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Application\Queries\FindProductImport;
use App\Modules\Catalog\Infrastructure\Models\ProductImport;
use App\Shared\Infrastructure\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * Progress of an import. Poll until status is "completed" or "failed".
 * errors lists the first problems in file order (each with its row number);
 * failed_rows counts all of them.
 */
final class ProductImportResource extends ApiResource
{
    public function __construct(ProductImport $import)
    {
        parent::__construct($import);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ProductImport $import */
        $import = $this->resource;

        $errors = [];

        if ($import->relationLoaded('groups')) {
            foreach ($import->groups as $group) {
                foreach ($group->errors ?? [] as $error) {
                    $errors[] = ['row' => $error['row'], 'handle' => $group->handle, 'message' => $error['message']];
                }
            }
        }

        return [
            'id' => $import->id,
            'status' => $import->status->value,
            'filename' => $import->filename,
            'total_rows' => $import->total_rows,
            'processed_rows' => $import->processed_rows,
            'failed_rows' => $import->failed_rows,
            'created_products' => $import->created_products,
            'percent_complete' => $import->total_rows > 0 ? intdiv($import->processed_rows * 100, $import->total_rows) : 100,
            'failure' => $import->failure,
            'errors' => array_slice($errors, 0, FindProductImport::MAX_ERRORS),
            'started_at' => $import->started_at?->toIso8601String(),
            'finished_at' => $import->finished_at?->toIso8601String(),
            'created_at' => $import->created_at?->toIso8601String(),
        ];
    }
}
