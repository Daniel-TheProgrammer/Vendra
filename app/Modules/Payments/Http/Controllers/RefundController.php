<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers;

use App\Modules\Payments\Application\Actions\RefundPayment;
use App\Modules\Payments\Application\Queries\FindPaymentIntent;
use App\Modules\Payments\Application\Queries\FindRefund;
use App\Modules\Payments\Application\Queries\ListRefunds;
use App\Modules\Payments\Domain\RefundStatus;
use App\Modules\Payments\Http\Resources\RefundResource;
use App\Modules\Payments\Infrastructure\Models\Refund;
use App\Shared\Domain\Money\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class RefundController
{
    /**
     * GET /refunds?payment_intent_id=…&status=succeeded&per_page=25&cursor=…
     */
    public function index(Request $request, ListRefunds $list): JsonResponse
    {
        $filters = $request->validate([
            'payment_intent_id' => ['sometimes', 'uuid'],
            'status' => ['sometimes', 'string', Rule::enum(RefundStatus::class)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.ListRefunds::MAX_PER_PAGE],
            'cursor' => ['sometimes', 'string', 'max:512'],
        ]);

        $page = $list(
            paymentIntentId: isset($filters['payment_intent_id']) ? $request->string('payment_intent_id')->value() : null,
            status: $request->enum('status', RefundStatus::class),
            perPage: $request->integer('per_page', 25),
            cursor: isset($filters['cursor']) ? $request->string('cursor')->value() : null,
        );

        return RefundResource::collection($page->through(static fn (Refund $refund): RefundResource => new RefundResource($refund)))->response($request);
    }

    public function show(Request $request, string $refund, FindRefund $find): JsonResponse
    {
        return $this->respond($request, $find($refund));
    }

    /**
     * POST /payment-intents/{id}/refunds {"amount": "25.00", "reason": "Damaged in transit"}
     *
     * Without an amount, everything not yet refunded is refunded.
     */
    public function store(Request $request, string $paymentIntent, FindPaymentIntent $find, RefundPayment $refund): JsonResponse
    {
        $request->validate([
            'amount' => ['sometimes', 'string', 'regex:/^\d{1,13}(\.\d{1,3})?$/'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $intent = $find($paymentIntent);
        $amount = null;

        if ($request->has('amount')) {
            try {
                $amount = Money::fromDecimal($request->string('amount')->value(), $intent->currency);
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages(['amount' => sprintf('%s amounts have at most %d decimal places.', $intent->currency->value, $intent->currency->minorUnit())]);
            }
        }

        $actor = $request->user()?->getAuthIdentifier();
        $issued = $refund(
            $intent->id,
            $amount,
            $request->filled('reason') ? $request->string('reason')->value() : null,
            is_string($actor) ? $actor : null,
        );

        return $this->respond($request, $issued, 201)
            ->header('Location', route('api.v1.refunds.show', ['refund' => $issued->id]));
    }

    private function respond(Request $request, Refund $refund, int $status = 200): JsonResponse
    {
        return (new RefundResource($refund))
            ->toResponse($request)
            ->setStatusCode($status)
            ->header('ETag', sprintf('"%d"', $refund->version));
    }
}
