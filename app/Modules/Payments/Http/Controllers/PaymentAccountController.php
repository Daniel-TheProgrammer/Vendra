<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers;

use App\Modules\Payments\Application\Services\PaymentAccounts;
use App\Modules\Payments\Domain\PaymentAccountMissing;
use App\Modules\Payments\Http\Resources\PaymentAccountResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PaymentAccountController
{
    public function show(Request $request, PaymentAccounts $accounts): JsonResponse
    {
        $account = $accounts->find() ?? throw PaymentAccountMissing::forTenant();

        return (new PaymentAccountResource($account))->response($request);
    }

    /**
     * PUT /payments/account {"account_id": "acct_…"}
     */
    public function update(Request $request, PaymentAccounts $accounts): JsonResponse
    {
        $request->validate([
            'account_id' => ['required', 'string', 'regex:/^acct_[A-Za-z0-9]{6,64}$/'],
        ]);

        return (new PaymentAccountResource($accounts->connect($request->string('account_id')->value())))->response($request);
    }
}
