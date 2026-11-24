<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Http\Controllers;

use App\Modules\Ledger\Application\Queries\FindAccountBalance;
use App\Modules\Ledger\Application\Queries\ListAccounts;
use App\Modules\Ledger\Http\Resources\LedgerAccountResource;
use App\Modules\Ledger\Infrastructure\Models\LedgerAccount;
use App\Shared\Infrastructure\Http\Rfc3339;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LedgerAccountController
{
    /**
     * GET /ledger/accounts: the whole chart, every currency, with current balances.
     */
    public function index(Request $request, ListAccounts $list): JsonResponse
    {
        return LedgerAccountResource::collection($list()->map(static fn (LedgerAccount $account): LedgerAccountResource => new LedgerAccountResource($account)))
            ->response($request);
    }

    /**
     * GET /ledger/accounts/{account}/balance?as_of=2026-11-30T23:59:59Z
     */
    public function balance(Request $request, string $account, FindAccountBalance $find): JsonResponse
    {
        $request->validate(['as_of' => ['sometimes', ...Rfc3339::rules()]]);
        $asOf = $request->filled('as_of') ? Rfc3339::parse($request->string('as_of')->value()) : null;

        return new JsonResponse(['data' => [
            'account_id' => $account,
            'as_of' => $asOf?->toIso8601String(),
            ...LedgerAccountResource::balance($find($account, $asOf)),
        ]]);
    }
}
