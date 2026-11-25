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
 * @property string|null $webhook_secret decrypted on read
 * @property string|null $previous_webhook_secret decrypted on read
 * @property CarbonImmutable|null $previous_webhook_secret_expires_at
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
        'webhook_secret',
        'previous_webhook_secret',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'account_id' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'previous_webhook_secret' => 'encrypted',
            'previous_webhook_secret_expires_at' => 'immutable_datetime',
        ];
    }

    /**
     * The signing secrets a webhook may be signed with right now: the
     * current one, and during a rotation the previous one until it expires.
     *
     * @return list<string>
     */
    public function acceptedWebhookSecrets(CarbonImmutable $now): array
    {
        $secrets = [];

        if ($this->webhook_secret !== null) {
            $secrets[] = $this->webhook_secret;
        }

        if ($this->previous_webhook_secret !== null && $this->previous_webhook_secret_expires_at?->isAfter($now) === true) {
            $secrets[] = $this->previous_webhook_secret;
        }

        return $secrets;
    }

    /**
     * Replaces the signing secret. The old one keeps working for the overlap,
     * so events the provider signed just before the switch still verify.
     */
    public function rotateWebhookSecret(string $secret, CarbonImmutable $now, int $overlapHours): void
    {
        $keepPrevious = $this->webhook_secret !== null && $this->webhook_secret !== $secret && $overlapHours > 0;

        $this->previous_webhook_secret = $keepPrevious ? $this->webhook_secret : null;
        $this->previous_webhook_secret_expires_at = $keepPrevious ? $now->addHours($overlapHours) : null;
        $this->webhook_secret = $secret;
    }
}
