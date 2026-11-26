<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Application\Security;

use App\Modules\Webhooks\Domain\IpAddress;
use App\Modules\Webhooks\Domain\UnsafeWebhookUrl;

/**
 * Decides whether a webhook may be sent to a URL (SSRF protection). Run when
 * a subscription is created or changed, and again before every delivery,
 * because what a name resolves to can change.
 *
 * A URL must be https, without credentials, on port 443 or an unprivileged
 * port, to a fully qualified name that is not an internal one, and every
 * address the name resolves to must be public (IpAddress). One internal
 * address among public ones is enough to refuse: a resolver may hand out
 * any of them.
 */
final readonly class DestinationGuard
{
    /** Names that only mean something inside a network. */
    private const INTERNAL_NAMES = ['localhost', 'metadata', 'metadata.google.internal', 'instance-data', 'kubernetes.default'];

    private const INTERNAL_SUFFIXES = ['.localhost', '.local', '.internal', '.localdomain', '.home.arpa', '.lan', '.cluster.local'];

    public function __construct(
        private HostResolver $resolver,
    ) {}

    /**
     * @throws UnsafeWebhookUrl
     */
    public function check(string $url): SafeDestination
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw UnsafeWebhookUrl::because('malformed', 'The webhook URL is not a valid absolute URL.');
        }

        if (strtolower($parts['scheme']) !== 'https') {
            throw UnsafeWebhookUrl::because('not_https', 'Webhook URLs must use https.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw UnsafeWebhookUrl::because('credentials', 'Webhook URLs must not contain credentials; authenticate with the signature instead.');
        }

        $port = $parts['port'] ?? 443;

        if ($port !== 443 && $port < 1024) {
            throw UnsafeWebhookUrl::because('port', 'Webhook URLs must use port 443 or a port from 1024 up.');
        }

        $host = strtolower(rtrim(trim($parts['host'], '[]'), '.'));

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (! IpAddress::isPublic($host)) {
                throw UnsafeWebhookUrl::because('private_address', 'Webhook URLs must point to a public address.');
            }

            return new SafeDestination($url, $host, $port, [$host]);
        }

        $this->assertPublicName($host);

        $addresses = array_values(array_unique($this->resolver->resolve($host)));

        if ($addresses === []) {
            throw UnsafeWebhookUrl::because('unresolvable', sprintf('The webhook host %s does not resolve.', $host));
        }

        foreach ($addresses as $address) {
            if (! IpAddress::isPublic($address)) {
                throw UnsafeWebhookUrl::because('private_address', sprintf('The webhook host %s resolves to a non-public address.', $host));
            }
        }

        return new SafeDestination($url, $host, $port, $addresses);
    }

    private function assertPublicName(string $host): void
    {
        if (! str_contains($host, '.') || preg_match('/^[a-z0-9.-]+$/', $host) !== 1) {
            throw UnsafeWebhookUrl::because('internal_name', 'Webhook URLs must use a fully qualified public host name.');
        }

        if (in_array($host, self::INTERNAL_NAMES, true)) {
            throw UnsafeWebhookUrl::because('internal_name', sprintf('%s is an internal host name.', $host));
        }

        foreach (self::INTERNAL_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                throw UnsafeWebhookUrl::because('internal_name', sprintf('%s is an internal host name.', $host));
            }
        }
    }
}
