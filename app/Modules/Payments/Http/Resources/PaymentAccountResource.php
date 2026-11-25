<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Resources;

use App\Modules\Payments\Infrastructure\Models\PaymentAccount;
use App\Shared\Infrastructure\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * The account id is shown masked: enough to recognise it, not to copy it.
 */
final class PaymentAccountResource extends ApiResource
{
    public function __construct(PaymentAccount $account)
    {
        parent::__construct($account);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PaymentAccount $account */
        $account = $this->resource;

        return [
            'gateway' => $account->gateway,
            'account' => self::mask($account->account_id),
            // Secrets are never shown, only whether one is set.
            'webhook_secret_configured' => $account->webhook_secret !== null,
            'previous_webhook_secret_expires_at' => $account->previous_webhook_secret_expires_at?->toIso8601String(),
            'connected_at' => $account->created_at?->toIso8601String(),
            'updated_at' => $account->updated_at?->toIso8601String(),
        ];
    }

    public static function mask(string $accountId): string
    {
        $prefix = str_contains($accountId, '_') ? strstr($accountId, '_', true).'_' : '';

        return $prefix.'…'.substr($accountId, -4);
    }
}
