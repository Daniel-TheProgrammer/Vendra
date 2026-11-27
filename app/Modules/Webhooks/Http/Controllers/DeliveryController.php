<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Http\Controllers;

use App\Modules\Webhooks\Application\Delivery\Redeliver;
use App\Modules\Webhooks\Application\Delivery\SendTestDelivery;
use App\Modules\Webhooks\Application\Queries\ListDeliveries;
use App\Modules\Webhooks\Application\Queries\ListEvents;
use App\Modules\Webhooks\Domain\DeliveryStatus;
use App\Modules\Webhooks\Http\Resources\DeliveryResource;
use App\Modules\Webhooks\Http\Resources\EventResource;
use App\Modules\Webhooks\Infrastructure\Jobs\DeliverWebhook;
use App\Modules\Webhooks\Infrastructure\Models\WebhookDelivery;
use App\Shared\Infrastructure\Outbox\OutboxMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class DeliveryController
{
    /**
     * GET /webhooks/deliveries?status=dead&subscription_id=…&event_type=…&per_page=25&cursor=…
     */
    public function index(Request $request, ListDeliveries $list): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', 'string', Rule::enum(DeliveryStatus::class)],
            'subscription_id' => ['sometimes', 'uuid'],
            'event_type' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.ListDeliveries::MAX_PER_PAGE],
            'cursor' => ['sometimes', 'string', 'max:512'],
        ]);

        $page = $list(
            status: $request->enum('status', DeliveryStatus::class),
            subscriptionId: isset($filters['subscription_id']) ? $request->string('subscription_id')->value() : null,
            eventType: isset($filters['event_type']) ? $request->string('event_type')->value() : null,
            perPage: $request->integer('per_page', 25),
            cursor: isset($filters['cursor']) ? $request->string('cursor')->value() : null,
        );

        return DeliveryResource::collection($page->through(static fn (WebhookDelivery $d): DeliveryResource => new DeliveryResource($d)))->response($request);
    }

    public function show(Request $request, string $delivery, ListDeliveries $list): JsonResponse
    {
        return (new DeliveryResource($list->find($delivery), withBody: true))->response($request);
    }

    /**
     * POST /webhooks/deliveries/{id}/redeliver: 202, the attempt is queued.
     */
    public function redeliver(Request $request, string $delivery, Redeliver $redeliver): JsonResponse
    {
        $pending = $redeliver($delivery);
        DeliverWebhook::dispatch($pending->id, $pending->tenant_id)->afterCommit();

        return (new DeliveryResource($pending))->toResponse($request)->setStatusCode(202);
    }

    /**
     * POST /webhooks/subscriptions/{id}/test: sent now; the response says
     * how the receiver answered.
     */
    public function test(Request $request, string $subscription, SendTestDelivery $send): JsonResponse
    {
        return (new DeliveryResource($send($subscription), withBody: true))->response($request);
    }

    /**
     * GET /events?type=orders.order_placed&per_page=25&cursor=…
     */
    public function events(Request $request, ListEvents $list): JsonResponse
    {
        $filters = $request->validate([
            'type' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.ListEvents::MAX_PER_PAGE],
            'cursor' => ['sometimes', 'string', 'max:512'],
        ]);

        $page = $list(
            type: isset($filters['type']) ? $request->string('type')->value() : null,
            perPage: $request->integer('per_page', 25),
            cursor: isset($filters['cursor']) ? $request->string('cursor')->value() : null,
        );

        return EventResource::collection($page->through(static fn (OutboxMessage $m): EventResource => new EventResource($m)))->response($request);
    }
}
