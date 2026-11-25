<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Resources;

use App\Modules\Payments\Infrastructure\Models\Refund;
use App\Shared\Infrastructure\Http\Resources\ApiResource;
use Illuminate\Http\Request;

final class RefundResource extends ApiResource
{
    public function __construct(Refund $refund)
    {
        parent::__construct($refund);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Refund $refund */
        $refund = $this->resource;

        return [
            'id' => $refund->id,
            'payment_intent_id' => $refund->payment_intent_id,
            'status' => $refund->status->value,
            'gateway_ref' => $refund->gateway_ref,
            'currency' => $refund->currency->value,
            'amount' => $refund->amount()->toDecimal(),
            'amount_minor' => $refund->amount_minor,
            'reason' => $refund->reason,
            'failure_code' => $refund->failure_code,
            'requested_by' => $refund->requested_by,
            'version' => $refund->version,
            'succeeded_at' => $refund->succeeded_at?->toIso8601String(),
            'failed_at' => $refund->failed_at?->toIso8601String(),
            'created_at' => $refund->created_at?->toIso8601String(),
        ];
    }
}
