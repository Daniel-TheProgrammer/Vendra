<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Actions;

use App\Modules\Identity\Infrastructure\Models\ApiKey;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Identity\Infrastructure\Notifications\ApiKeyExpiringSoon;
use App\Shared\Application\Clock\Clock;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Warns each key's creator once, a configurable number of days before the
 * key expires. Safe to run repeatedly: notified keys are marked.
 */
final readonly class NotifyExpiringApiKeys
{
    public function __construct(
        private Clock $clock,
        private Config $config,
    ) {}

    /**
     * @return int number of keys whose creator was notified
     */
    public function __invoke(): int
    {
        $now = $this->clock->now();
        $days = $this->config->get('identity.api_keys.expiry_warning_days', 7);
        $horizon = $now->addDays(is_numeric($days) ? (int) $days : 7);
        $notified = 0;

        ApiKey::query()
            ->with('creator')
            ->whereNull('revoked_at')
            ->whereNull('expiry_notified_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', $now)
            ->where('expires_at', '<=', $horizon)
            ->orderBy('id')
            ->chunkById(200, function ($keys) use ($now, &$notified): void {
                foreach ($keys as $key) {
                    $creator = $key->creator;

                    if ($creator instanceof User) {
                        $creator->notify(new ApiKeyExpiringSoon($key->name, $key->prefix, $key->expires_at ?? $now));
                        $notified++;
                    }

                    $key->forceFill(['expiry_notified_at' => $now])->save();
                }
            });

        return $notified;
    }
}
