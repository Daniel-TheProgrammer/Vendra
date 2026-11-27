<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Application\Delivery;

use App\Modules\Webhooks\Application\Security\SafeDestination;

/**
 * Sends one webhook request. The implementation must connect only to the
 * destination's checked addresses, must not follow redirects (a redirect
 * could lead anywhere) and must give up after the timeout.
 */
interface WebhookTransport
{
    /**
     * @param  array<string, string>  $headers
     */
    public function send(SafeDestination $destination, string $body, array $headers, int $timeoutSeconds): TransportResult;
}
