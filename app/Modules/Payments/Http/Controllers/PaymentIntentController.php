<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers;

use App\Modules\Payments\Application\Actions\CapturePaymentIntent;
use App\Modules\Payments\Application\Actions\CreatePaymentIntent;
use App\Modules\Payments\Application\Queries\FindPaymentIntent;
use App\Modules\Payments\Http\Resources\PaymentIntentResource;
use App\Modules\Payments\Infrastructure\Models\PaymentIntent;
use App\Shared\Infrastructure\Http\IfMatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PaymentIntentController
{
    /**
     * POST /orders/{order}/payment-intents
     */
    public function store(Request $request, string $order, CreatePaymentIntent $create): JsonResponse
    {
        $actor = $request->user()?->getAuthIdentifier();
        $started = $create($order, is_string($actor) ? $actor : null);

        return $this->respond($request, $started->intent, 201, $started->clientSecret)
            ->header('Location', route('api.v1.payment-intents.show', ['paymentIntent' => $started->intent->id]));
    }

    public function show(Request $request, string $paymentIntent, FindPaymentIntent $find): JsonResponse
    {
        return $this->respond($request, $find($paymentIntent));
    }

    public function capture(Request $request, string $paymentIntent, CapturePaymentIntent $capture): JsonResponse
    {
        return $this->respond($request, $capture($paymentIntent, IfMatch::expectedVersion($request)));
    }

    private function respond(Request $request, PaymentIntent $intent, int $status = 200, ?string $clientSecret = null): JsonResponse
    {
        return (new PaymentIntentResource($intent, $clientSecret))
            ->toResponse($request)
            ->setStatusCode($status)
            ->header('ETag', sprintf('"%d"', $intent->version))
            // The client secret confirms a payment; no cache keeps it.
            ->header('Cache-Control', 'no-store');
    }
}
