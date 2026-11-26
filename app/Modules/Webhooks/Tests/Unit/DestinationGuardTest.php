<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Tests\Unit;

use App\Modules\Webhooks\Application\Security\DestinationGuard;
use App\Modules\Webhooks\Domain\IpAddress;
use App\Modules\Webhooks\Domain\UnsafeWebhookUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\FixedHostResolver;

/**
 * SSRF protection for webhook URLs.
 */
final class DestinationGuardTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function nonPublicAddresses(): iterable
    {
        foreach ([
            '127.0.0.1', '127.255.255.254',          // loopback
            '10.0.0.1', '10.255.255.255',            // 10/8
            '172.16.0.1', '172.31.255.255',          // 172.16/12
            '192.168.1.10',                          // 192.168/16
            '169.254.169.254', '169.254.0.1',        // link-local, cloud metadata
            '100.64.0.1', '100.127.255.254',         // carrier-grade NAT
            '0.0.0.0', '0.1.2.3',                    // "this" network
            '192.0.2.10', '198.51.100.7', '203.0.113.200', // documentation
            '198.18.0.1', '224.0.0.251', '240.0.0.1', '255.255.255.255',
            '::1', '::', 'fe80::1', 'fd00::1', 'fc00::abcd', 'ff02::1', '2001:db8::1',
            '::ffff:127.0.0.1', '::ffff:169.254.169.254', '::ffff:10.0.0.1', // IPv4-mapped
            '64:ff9b::a9fe:a9fe',                    // NAT64 of 169.254.169.254
            'not-an-ip',
        ] as $ip) {
            yield $ip => [$ip];
        }
    }

    #[Test]
    #[DataProvider('nonPublicAddresses')]
    public function non_public_addresses_are_refused(string $ip): void
    {
        self::assertFalse(IpAddress::isPublic($ip));
    }

    #[Test]
    public function public_addresses_are_allowed_including_the_neighbours_of_blocked_ranges(): void
    {
        foreach (['93.184.216.34', '8.8.8.8', '1.1.1.1', '172.15.255.255', '172.32.0.1', '100.63.255.255', '100.128.0.1', '169.253.255.255', '11.0.0.1', '2606:4700:4700::1111', '::ffff:8.8.8.8'] as $ip) {
            self::assertTrue(IpAddress::isPublic($ip), $ip);
        }
    }

    private function guard(): DestinationGuard
    {
        return new DestinationGuard(new FixedHostResolver([
            'hooks.example.com' => ['93.184.216.34', '2606:2800:220:1::1'],
            'sneaky.example.com' => ['93.184.216.34', '10.0.0.5'],
            'rebound.example.com' => ['127.0.0.1'],
            'metadata.example.com' => ['169.254.169.254'],
        ]));
    }

    #[Test]
    public function a_public_https_url_passes_with_the_addresses_to_connect_to(): void
    {
        $destination = $this->guard()->check('https://hooks.example.com:8443/vendra?x=1');

        self::assertSame(['hooks.example.com', 8443, ['93.184.216.34', '2606:2800:220:1::1']], [$destination->host, $destination->port, $destination->addresses]);
        self::assertSame(443, $this->guard()->check('https://HOOKS.example.com./in')->port);
        self::assertSame(['93.184.216.34'], $this->guard()->check('https://93.184.216.34/in')->addresses);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedUrls(): iterable
    {
        yield 'plain http' => ['http://hooks.example.com/in', 'not_https'];
        yield 'other scheme' => ['ftp://hooks.example.com/in', 'not_https'];
        yield 'relative' => ['/in', 'malformed'];
        yield 'credentials' => ['https://user:pass@hooks.example.com/in', 'credentials'];
        yield 'privileged port' => ['https://hooks.example.com:22/in', 'port'];
        yield 'loopback literal' => ['https://127.0.0.1/in', 'private_address'];
        yield 'metadata literal' => ['https://169.254.169.254/latest/meta-data', 'private_address'];
        yield 'private literal' => ['https://10.1.2.3/in', 'private_address'];
        yield 'ipv6 loopback literal' => ['https://[::1]/in', 'private_address'];
        yield 'mapped ipv6 literal' => ['https://[::ffff:127.0.0.1]/in', 'private_address'];
        yield 'localhost' => ['https://localhost/in', 'internal_name'];
        yield 'localhost subdomain' => ['https://api.localhost/in', 'internal_name'];
        yield 'gcp metadata' => ['https://metadata.google.internal/computeMetadata/v1/', 'internal_name'];
        yield 'internal suffix' => ['https://billing.corp.internal/in', 'internal_name'];
        yield 'single label' => ['https://redis/in', 'internal_name'];
        yield 'resolves to loopback' => ['https://rebound.example.com/in', 'private_address'];
        yield 'resolves to metadata' => ['https://metadata.example.com/in', 'private_address'];
        yield 'one private among public' => ['https://sneaky.example.com/in', 'private_address'];
        yield 'does not resolve' => ['https://nowhere.example.com/in', 'unresolvable'];
    }

    #[Test]
    #[DataProvider('refusedUrls')]
    public function unsafe_urls_are_refused_with_a_reason(string $url, string $reason): void
    {
        try {
            $this->guard()->check($url);
            self::fail($url.' must be refused');
        } catch (UnsafeWebhookUrl $refused) {
            self::assertSame($reason, $refused->reason());
            self::assertSame(422, $refused->status());
        }
    }
}
