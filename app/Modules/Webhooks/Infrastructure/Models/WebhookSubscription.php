<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Infrastructure\Models;

use App\Modules\Webhooks\Domain\PauseReason;
use App\Modules\Webhooks\Domain\SubscriptionStatus;
use App\Shared\Infrastructure\Persistence\Model;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;

/**
 * Where a tenant wants its events delivered.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $url
 * @property string|null $description
 * @property list<string> $event_types
 * @property string $secret decrypted on read
 * @property string|null $previous_secret decrypted on read
 * @property CarbonImmutable|null $previous_secret_expires_at
 * @property SubscriptionStatus $status
 * @property PauseReason|null $paused_reason
 * @property CarbonImmutable|null $paused_at
 * @property int $failure_count
 * @property int $version
 * @property string|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class WebhookSubscription extends Model
{
    use BelongsToTenant;

    public const ALL_EVENTS = '*';

    protected $fillable = [
        'url',
        'description',
        'event_types',
        'secret',
        'status',
        'created_by',
    ];

    protected $hidden = [
        'secret',
        'previous_secret',
    ];

    protected $attributes = [
        'version' => 1,
        'failure_count' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'event_types' => 'array',
            'secret' => 'encrypted',
            'previous_secret' => 'encrypted',
            'previous_secret_expires_at' => 'immutable_datetime',
            'status' => SubscriptionStatus::class,
            'paused_reason' => PauseReason::class,
            'paused_at' => 'immutable_datetime',
            'failure_count' => 'integer',
            'version' => 'integer',
        ];
    }

    public function wants(string $eventType): bool
    {
        return in_array(self::ALL_EVENTS, $this->event_types, true) || in_array($eventType, $this->event_types, true);
    }

    /**
     * The secrets a delivery is signed with now: the current one, and during
     * a rotation the previous one too, so a receiver that has not switched
     * yet still verifies it.
     *
     * @return non-empty-list<string>
     */
    public function signingSecrets(CarbonImmutable $now): array
    {
        $secrets = [$this->secret];

        if ($this->previous_secret !== null && $this->previous_secret_expires_at?->isAfter($now) === true) {
            $secrets[] = $this->previous_secret;
        }

        return $secrets;
    }

    public function pause(PauseReason $reason, CarbonImmutable $at): void
    {
        $this->status = SubscriptionStatus::Paused;
        $this->paused_reason = $reason;
        $this->paused_at = $at;
        $this->version++;
    }

    public function resume(): void
    {
        $this->status = SubscriptionStatus::Active;
        $this->paused_reason = null;
        $this->paused_at = null;
        $this->failure_count = 0;
        $this->version++;
    }
}
