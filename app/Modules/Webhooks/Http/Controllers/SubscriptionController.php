<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Http\Controllers;

use App\Modules\Webhooks\Application\Actions\CreateSubscription;
use App\Modules\Webhooks\Application\Actions\DeleteSubscription;
use App\Modules\Webhooks\Application\Actions\RotateSigningSecret;
use App\Modules\Webhooks\Application\Actions\UpdateSubscription;
use App\Modules\Webhooks\Application\Queries\FindSubscription;
use App\Modules\Webhooks\Application\Queries\ListSubscriptions;
use App\Modules\Webhooks\Application\Services\EventTypes;
use App\Modules\Webhooks\Domain\SubscriptionStatus;
use App\Modules\Webhooks\Http\Requests\SubscriptionRequest;
use App\Modules\Webhooks\Http\Resources\SubscriptionResource;
use App\Modules\Webhooks\Infrastructure\Models\WebhookSubscription;
use App\Shared\Application\Clock\Clock;
use App\Shared\Infrastructure\Http\IfMatch;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

final class SubscriptionController
{
    public function eventTypes(EventTypes $types): JsonResponse
    {
        return new JsonResponse(['data' => $types->all()]);
    }

    /**
     * GET /webhooks/subscriptions?status=paused&per_page=25&cursor=…
     */
    public function index(Request $request, ListSubscriptions $list): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', 'string', Rule::enum(SubscriptionStatus::class)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.ListSubscriptions::MAX_PER_PAGE],
            'cursor' => ['sometimes', 'string', 'max:512'],
        ]);

        $page = $list(
            status: $request->enum('status', SubscriptionStatus::class),
            perPage: $request->integer('per_page', 25),
            cursor: isset($filters['cursor']) ? $request->string('cursor')->value() : null,
        );

        return SubscriptionResource::collection($page->through(static fn (WebhookSubscription $s): SubscriptionResource => new SubscriptionResource($s)))->response($request);
    }

    public function store(SubscriptionRequest $request, CreateSubscription $create): JsonResponse
    {
        $actor = $request->user()?->getAuthIdentifier();
        $subscription = $create($request->subscriptionData(), is_string($actor) ? $actor : null);

        return $this->respond($request, $subscription, 201)
            ->header('Location', route('api.v1.webhooks.subscriptions.show', ['subscription' => $subscription->id]));
    }

    public function show(Request $request, string $subscription, FindSubscription $find): JsonResponse
    {
        return $this->respond($request, $find($subscription));
    }

    public function update(SubscriptionRequest $request, string $subscription, UpdateSubscription $update): JsonResponse
    {
        return $this->respond($request, $update($subscription, $request->subscriptionData(), $request->status(), IfMatch::expectedVersion($request)));
    }

    public function destroy(string $subscription, DeleteSubscription $delete): Response
    {
        $delete($subscription);

        return new Response(status: 204);
    }

    /**
     * GET /webhooks/subscriptions/{id}/secret: the only place the signing
     * secret is ever shown.
     */
    public function secret(string $subscription, FindSubscription $find, Clock $clock): JsonResponse
    {
        $found = $find($subscription);

        return (new JsonResponse(['data' => [
            'secret' => $found->secret,
            'previous_secret_valid' => count($found->signingSecrets($clock->now())) > 1,
            'previous_secret_expires_at' => $found->previous_secret_expires_at?->toIso8601String(),
        ]]))->header('Cache-Control', 'no-store');
    }

    /**
     * POST /webhooks/subscriptions/{id}/rotate-secret {"previous_valid_hours": 24}
     */
    public function rotateSecret(Request $request, string $subscription, RotateSigningSecret $rotate, Config $config): JsonResponse
    {
        $request->validate(['previous_valid_hours' => ['sometimes', 'integer', 'min:0', 'max:168']]);

        return $this->respond($request, $rotate($subscription, $request->integer('previous_valid_hours', $config->integer('webhooks.secret_overlap_hours', 24))));
    }

    private function respond(Request $request, WebhookSubscription $subscription, int $status = 200): JsonResponse
    {
        return (new SubscriptionResource($subscription))
            ->toResponse($request)
            ->setStatusCode($status)
            ->header('ETag', sprintf('"%d"', $subscription->version));
    }
}
