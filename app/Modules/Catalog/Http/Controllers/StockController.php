<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Application\Actions\AdjustStock;
use App\Modules\Catalog\Application\Queries\FindStock;
use App\Modules\Catalog\Application\Queries\ListStockMovements;
use App\Modules\Catalog\Http\Requests\AdjustStockRequest;
use App\Modules\Catalog\Http\Resources\StockLevelResource;
use App\Modules\Catalog\Http\Resources\StockMovementResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Stock is read and adjusted here. Reservations are not exposed: they are
 * made by the order flow, in the same transaction as the order.
 */
final class StockController
{
    public function show(Request $request, string $variant, FindStock $find): JsonResponse
    {
        return (new StockLevelResource($find($variant)))->response($request);
    }

    public function movements(Request $request, string $variant, ListStockMovements $list): JsonResponse
    {
        $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.ListStockMovements::MAX_PER_PAGE],
            'cursor' => ['sometimes', 'string', 'max:512'],
        ]);

        $page = $list($variant, $request->integer('per_page', 50), $request->filled('cursor') ? $request->string('cursor')->value() : null);

        return StockMovementResource::collection($page)->response($request);
    }

    /**
     * The principal may be a person or an API key; either way its id is
     * recorded on the movement.
     */
    public function adjust(AdjustStockRequest $request, string $variant, AdjustStock $adjust): JsonResponse
    {
        $actor = $request->user()?->getAuthIdentifier();

        $level = $adjust($request->toCommand($variant, is_string($actor) ? $actor : null));

        return (new StockLevelResource($level->toStockLevel()))->response($request)->setStatusCode(201);
    }
}
