<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers;

use App\Modules\Orders\Application\Actions\CancelOrder;
use App\Modules\Orders\Application\Actions\FulfilOrder;
use App\Modules\Orders\Application\Actions\PlaceOrder;
use App\Modules\Orders\Application\Actions\UpdateShippingAddress;
use App\Modules\Orders\Application\Queries\FindOrder;
use App\Modules\Orders\Application\Queries\ListOrderEvents;
use App\Modules\Orders\Application\Queries\ListOrders;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Http\Requests\PlaceOrderRequest;
use App\Modules\Orders\Http\Requests\ShippingAddressRequest;
use App\Modules\Orders\Http\Resources\OrderEventResource;
use App\Modules\Orders\Http\Resources\OrderResource;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Orders\Infrastructure\Models\OrderEventRecord;
use App\Shared\Infrastructure\Http\IfMatch;
use App\Shared\Infrastructure\Http\Rfc3339;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class OrderController
{
    /**
     * GET /orders?status=pending&customer_id=…&placed_from=…&placed_to=…&per_page=25&cursor=…
     */
    public function index(Request $request, ListOrders $list): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', 'string', Rule::enum(OrderStatus::class)],
            'customer_id' => ['sometimes', 'uuid'],
            'placed_from' => ['sometimes', ...Rfc3339::rules()],
            'placed_to' => ['sometimes', ...Rfc3339::rules()],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.ListOrders::MAX_PER_PAGE],
            'cursor' => ['sometimes', 'string', 'max:512'],
        ]);

        $page = $list(
            status: $request->enum('status', OrderStatus::class),
            customerId: isset($filters['customer_id']) ? $request->string('customer_id')->value() : null,
            placedFrom: isset($filters['placed_from']) ? Rfc3339::parse($request->string('placed_from')->value()) : null,
            placedTo: isset($filters['placed_to']) ? Rfc3339::parse($request->string('placed_to')->value()) : null,
            perPage: $request->integer('per_page', 25),
            cursor: isset($filters['cursor']) ? $request->string('cursor')->value() : null,
        );

        return OrderResource::collection($page)->response($request);
    }

    public function show(Request $request, string $order, FindOrder $find): JsonResponse
    {
        return $this->orderResponse($request, $find($order));
    }

    public function store(PlaceOrderRequest $request, PlaceOrder $place): JsonResponse
    {
        $order = $place($request->toCommand($this->actorId($request)));

        return $this->orderResponse($request, $order, 201)
            ->header('Location', route('api.v1.orders.show', ['order' => $order->id]));
    }

    public function cancel(Request $request, string $order, CancelOrder $cancel): JsonResponse
    {
        $input = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:500']]);

        return $this->orderResponse($request, $cancel(
            $order,
            isset($input['reason']) ? $request->string('reason')->value() : null,
            $this->actorId($request),
            IfMatch::expectedVersion($request),
        ));
    }

    public function fulfil(Request $request, string $order, FulfilOrder $fulfil): JsonResponse
    {
        $input = $request->validate(['tracking_reference' => ['sometimes', 'nullable', 'string', 'max:100']]);

        return $this->orderResponse($request, $fulfil(
            $order,
            isset($input['tracking_reference']) ? $request->string('tracking_reference')->value() : null,
            $this->actorId($request),
            IfMatch::expectedVersion($request),
        ));
    }

    public function updateShippingAddress(ShippingAddressRequest $request, string $order, UpdateShippingAddress $update): JsonResponse
    {
        return $this->orderResponse($request, $update($order, $request->address(), $this->actorId($request), IfMatch::expectedVersion($request)));
    }

    public function events(Request $request, string $order, ListOrderEvents $events): JsonResponse
    {
        return OrderEventResource::collection($events($order)->map(static fn (OrderEventRecord $event): OrderEventResource => new OrderEventResource($event)))
            ->response($request);
    }

    private function orderResponse(Request $request, Order $order, int $status = 200): JsonResponse
    {
        $order->loadMissing('customer', 'lines');

        return (new OrderResource($order))
            ->toResponse($request)
            ->setStatusCode($status)
            ->header('ETag', sprintf('"%d"', $order->version));
    }

    private function actorId(Request $request): ?string
    {
        $actor = $request->user()?->getAuthIdentifier();

        return is_string($actor) ? $actor : null;
    }
}
