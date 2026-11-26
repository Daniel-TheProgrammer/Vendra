<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Application\Actions;

use App\Modules\Webhooks\Application\Security\DestinationGuard;
use App\Modules\Webhooks\Application\Services\SigningSecrets;
use App\Modules\Webhooks\Domain\SubscriptionLimitReached;
use App\Modules\Webhooks\Domain\SubscriptionStatus;
use App\Modules\Webhooks\Domain\UnsafeWebhookUrl;
use App\Modules\Webhooks\Infrastructure\Models\WebhookSubscription;
use App\Shared\Application\Transaction\TransactionRunner;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Subscribes the tenant to events at a URL that passed the SSRF guard. The
 * subscription starts active with a fresh signing secret.
 */
final readonly class CreateSubscription
{
    public function __construct(
        private TransactionRunner $transaction,
        private DestinationGuard $guard,
        private TenantContext $tenant,
        private Config $config,
    ) {}

    /**
     * @throws UnsafeWebhookUrl
     * @throws SubscriptionLimitReached
     */
    public function __invoke(SubscriptionData $data, ?string $actorId = null): WebhookSubscription
    {
        if ($data->url === null || $data->eventTypes === null || $data->eventTypes === []) {
            throw new InvalidArgumentException('A subscription needs a URL and at least one event type.');
        }

        $this->guard->check($data->url);
        $limit = $this->config->integer('webhooks.max_subscriptions', 25);

        return $this->transaction->run(function () use ($data, $actorId, $limit): WebhookSubscription {
            // Serialises concurrent creates of one tenant, so the limit holds.
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['webhook-subscriptions:'.$this->tenant->id()]);

            if (WebhookSubscription::query()->count() >= $limit) {
                throw SubscriptionLimitReached::of($limit);
            }

            return WebhookSubscription::query()->create([
                'url' => $data->url,
                'description' => $data->description,
                'event_types' => array_values(array_unique($data->eventTypes)),
                'secret' => SigningSecrets::generate(),
                'status' => SubscriptionStatus::Active,
                'created_by' => $actorId,
            ]);
        });
    }
}
