<?php

declare(strict_types=1);

namespace App\Modules\Payments\Tests\Unit;

use App\Modules\Payments\Infrastructure\Gateway\Stripe\StripeSignature;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StripeSignatureTest extends TestCase
{
    private const SECRET = 'whsec_current0000000000000000';

    private const OLD = 'whsec_previous000000000000000';

    private const NOW = 1764061200;

    private const BODY = '{"id":"evt_1","object":"event"}';

    #[Test]
    public function a_payload_signed_with_an_accepted_secret_within_the_tolerance_verifies(): void
    {
        $header = StripeSignature::sign(self::BODY, self::SECRET, self::NOW - 299);

        self::assertTrue(StripeSignature::verify(self::BODY, $header, [self::SECRET], self::NOW));
        self::assertTrue(StripeSignature::verify(self::BODY, $header, [self::OLD, self::SECRET], self::NOW), 'any accepted secret');
    }

    #[Test]
    public function anything_else_does_not(): void
    {
        $header = StripeSignature::sign(self::BODY, self::SECRET, self::NOW);

        self::assertFalse(StripeSignature::verify(self::BODY, $header, [self::OLD], self::NOW), 'other secret');
        self::assertFalse(StripeSignature::verify(self::BODY.' ', $header, [self::SECRET], self::NOW), 'body changed by one byte');
        self::assertFalse(StripeSignature::verify(self::BODY, $header, [self::SECRET], self::NOW + 301), 'replayed after the tolerance');
        self::assertFalse(StripeSignature::verify(self::BODY, $header, [self::SECRET], self::NOW - 301), 'from the future');
        self::assertFalse(StripeSignature::verify(self::BODY, $header, [], self::NOW), 'no secret configured');
        self::assertFalse(StripeSignature::verify(self::BODY, null, [self::SECRET], self::NOW), 'no header');
        self::assertFalse(StripeSignature::verify(self::BODY, 'v1='.hash_hmac('sha256', self::BODY, self::SECRET), [self::SECRET], self::NOW), 'no timestamp');
        self::assertFalse(StripeSignature::verify(self::BODY, str_replace('v1=', 'v0=', $header), [self::SECRET], self::NOW), 'only the legacy scheme');
        self::assertFalse(StripeSignature::verify(self::BODY, 't=abc,v1=00', [self::SECRET], self::NOW), 'malformed');
    }

    #[Test]
    public function while_stripe_rolls_a_secret_it_sends_one_signature_per_secret(): void
    {
        $old = StripeSignature::sign(self::BODY, self::OLD, self::NOW);
        $new = StripeSignature::sign(self::BODY, self::SECRET, self::NOW);
        $header = $old.','.substr($new, strpos($new, 'v1=') ?: 0);

        self::assertTrue(StripeSignature::verify(self::BODY, $header, [self::SECRET], self::NOW), 'we already switched');
        self::assertTrue(StripeSignature::verify(self::BODY, $header, [self::OLD], self::NOW), 'we have not yet');
    }
}
