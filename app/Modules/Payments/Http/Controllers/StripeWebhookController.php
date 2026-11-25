<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers;

use App\Modules\Payments\Domain\WebhookRejected;
use App\Modules\Payments\Infrastructure\Gateway\Stripe\StripeSignature;
use App\Modules\Payments\Infrastructure\Webhooks\ReceiveStripeEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /webhooks/stripe. Unauthenticated: the signature is the credential.
 */
final class StripeWebhookController
{
    /** Stripe events are a few kilobytes; anything far larger is not one. */
    private const MAX_BYTES = 512 * 1024;

    public function __invoke(Request $request, ReceiveStripeEvent $receive): JsonResponse
    {
        $payload = $request->getContent();

        if (strlen($payload) > self::MAX_BYTES) {
            throw WebhookRejected::malformed('too large');
        }

        $new = $receive($payload, $request->headers->get(StripeSignature::HEADER));

        return new JsonResponse(['received' => true, 'duplicate' => ! $new]);
    }
}
