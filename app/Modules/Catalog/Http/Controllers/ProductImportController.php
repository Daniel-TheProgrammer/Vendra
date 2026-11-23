<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Application\Import\InvalidProductCsv;
use App\Modules\Catalog\Application\Import\StartProductImport;
use App\Modules\Catalog\Application\Queries\FindProductImport;
use App\Modules\Catalog\Http\Resources\ProductImportResource;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

final class ProductImportController
{
    /**
     * POST /products/import (multipart/form-data, field "file")
     *
     * 202 Accepted: the file is valid and queued. Follow Location to watch progress.
     */
    public function store(Request $request, TenantContext $context, StartProductImport $start): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'extensions:csv,txt', 'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel', 'max:'.config()->integer('catalog.imports.max_kilobytes', 5120)],
        ]);

        /** @var UploadedFile $file */
        $file = $request->file('file');
        $actor = $request->user()?->getAuthIdentifier();

        try {
            $import = $start($context->id(), is_string($actor) ? $actor : null, $file->getRealPath(), $file->getClientOriginalName());
        } catch (InvalidProductCsv $invalid) {
            throw ValidationException::withMessages(['file' => $invalid->getMessage()]);
        }

        return (new ProductImportResource($import))
            ->toResponse($request)
            ->setStatusCode(202)
            ->header('Location', route('api.v1.imports.show', ['import' => $import->id]));
    }

    public function show(Request $request, string $import, FindProductImport $find): JsonResponse
    {
        return (new ProductImportResource($find($import)))->toResponse($request);
    }
}
