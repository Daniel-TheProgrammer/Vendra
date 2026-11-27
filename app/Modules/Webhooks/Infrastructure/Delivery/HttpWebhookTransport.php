<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Infrastructure\Delivery;

use App\Modules\Webhooks\Application\Delivery\TransportResult;
use App\Modules\Webhooks\Application\Delivery\WebhookTransport;
use App\Modules\Webhooks\Application\Security\SafeDestination;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Throwable;

/**
 * Webhook requests over Laravel's HTTP client.
 *
 * The connection is pinned to an address the SSRF guard approved
 * (CURLOPT_RESOLVE), so DNS cannot be re-pointed at an internal address
 * between the check and the connection. Redirects are not followed, and the
 * whole request, connection included, must finish within the timeout.
 */
final readonly class HttpWebhookTransport implements WebhookTransport
{
    private const EXCERPT_BYTES = 500;

    public function __construct(
        private Http $http,
    ) {}

    public function send(SafeDestination $destination, string $body, array $headers, int $timeoutSeconds): TransportResult
    {
        $started = hrtime(true);
        $options = ['allow_redirects' => false];

        if (filter_var($destination->host, FILTER_VALIDATE_IP) === false) {
            $address = $destination->addresses[0];
            $pinned = str_contains($address, ':') ? '['.$address.']' : $address;
            $options['curl'] = [CURLOPT_RESOLVE => [sprintf('%s:%d:%s', $destination->host, $destination->port, $pinned)]];
        }

        try {
            $response = $this->http
                ->withOptions($options)
                ->withHeaders($headers)
                ->withBody($body, 'application/json')
                ->connectTimeout(min(3, $timeoutSeconds))
                ->timeout($timeoutSeconds)
                ->post($destination->url);
        } catch (ConnectionException $failed) {
            return new TransportResult(null, $this->since($started), error: mb_substr($failed->getMessage(), 0, 500));
        } catch (Throwable $failed) {
            return new TransportResult(null, $this->since($started), error: mb_substr($failed::class.': '.$failed->getMessage(), 0, 500));
        }

        return new TransportResult(
            status: $response->status(),
            milliseconds: $this->since($started),
            excerpt: mb_strcut($response->body(), 0, self::EXCERPT_BYTES),
        );
    }

    private function since(int|float $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
