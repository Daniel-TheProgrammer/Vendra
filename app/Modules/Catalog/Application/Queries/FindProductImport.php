<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Queries;

use App\Modules\Catalog\Infrastructure\Models\ProductImport;
use App\Modules\Catalog\Infrastructure\Models\ProductImportGroup;
use App\Shared\Domain\Exception\EntityNotFound;
use Illuminate\Database\Eloquent\Relations\HasMany;

final readonly class FindProductImport
{
    /** Row errors returned with the import; the counters cover the rest. */
    public const MAX_ERRORS = 100;

    /**
     * @throws EntityNotFound
     */
    public function __invoke(string $importId): ProductImport
    {
        $import = ProductImport::query()
            ->with(['groups' => static fn (HasMany $groups) => $groups
                ->where('outcome', ProductImportGroup::FAILED)
                ->orderBy('first_row')
                ->limit(self::MAX_ERRORS)])
            ->whereKey($importId)
            ->first();

        return $import instanceof ProductImport ? $import : throw EntityNotFound::withId('Import', $importId);
    }
}
