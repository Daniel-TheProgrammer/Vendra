<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Resources;

use App\Modules\Payments\Infrastructure\Models\PaymentIntent;
use App\Shared\Infrastructure\Http\Resources\ApiResource;
use Illuminate\Http\Request;

final class PaymentIntentResource extends ApiResource
{
    public function __construct(
        PaymentIntent $intent,
        /** Only in the response that creates the intent; never stored, never shown again. */
        private readonly ?string $clientSecret = null,
    ) {
        parent::__construct($intent);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PaymentIntent $intent */
        $intent = $this->resource;
        $fee = $intent->fee();

        return [
            'id' => $intent->id,
            'order_id' => $intent->order_id,
            'status' => $intent->status->value,
            'gateway' => $intent->gateway,
            'gateway_ref' => $intent->gateway_ref,
            'currency' => $intent->currency->value,
            'amount' => $intent->amount()->toDecimal(),
            'amount_minor' => $intent->amount_minor,
            'fee' => $fee?->toDecimal(),
            'fee_minor' => $fee?->amount,
            'settlement' => $intent->settlement?->value,
            'failure' => $intent->failure_code !== null || $intent->failure_message !== null
                ? ['code' => $intent->failure_code, 'message' => $intent->failure_message]
                : null,
            ...($this->clientSecret !== null ? ['client_secret' => $this->clientSecret] : []),
            'version' => $intent->version,
            'captured_at' => $intent->captured_at?->toIso8601String(),
            'failed_at' => $intent->failed_at?->toIso8601String(),
            'cancelled_at' => $intent->cancelled_at?->toIso8601String(),
            'created_at' => $intent->created_at?->toIso8601String(),
        ];
    }
}
