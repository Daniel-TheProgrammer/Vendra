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

    /**
     * PUT /payments/account/webhook-secret {"secret": "whsec_…", "previous_valid_hours": 24}
     *
     * The previous secret keeps verifying for previous_valid_hours (0 ends
     * it now), matching the overlap the provider gives when a secret is rolled.
     */
    public function rotateWebhookSecret(Request $request, PaymentAccounts $accounts): JsonResponse
    {
        $request->validate([
            'secret' => ['required', 'string', 'regex:/^whsec_[A-Za-z0-9+\/=]{16,128}$/'],
            'previous_valid_hours' => ['sometimes', 'integer', 'min:0', 'max:72'],
        ]);

        $account = $accounts->rotateWebhookSecret($request->string('secret')->value(), $request->integer('previous_valid_hours', 24));

        return (new PaymentAccountResource($account))->response($request);
    }
}
