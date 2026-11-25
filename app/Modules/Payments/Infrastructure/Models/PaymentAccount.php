<?php

declare(strict_types=1);

namespace App\Modules\Payments\Infrastructure\Models;

use App\Shared\Infrastructure\Persistence\Model;
use Carbon\CarbonImmutable;

/**
 * A tenant's account at the payment provider. Platform table, not under
 * row-level security: an inbound webhook is matched to its tenant through
 * account_lookup before any tenant is known. Every other read goes through
 * PaymentAccounts, which filters by the current tenant.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $gateway
 * @property string $account_id decrypted on read
 * @property string $account_lookup HMAC of account_id, for matching
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class PaymentAccount extends Model
{
    protected $fillable = [
        'tenant_id',
        'gateway',
        'account_id',
        'account_lookup',
    ];

    protected $hidden = [
        'account_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'account_id' => 'encrypted',
        ];
    }
}
