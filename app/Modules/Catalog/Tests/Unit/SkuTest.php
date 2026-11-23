<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Tests\Unit;

use App\Modules\Catalog\Application\Commands\VariantData;
use App\Modules\Catalog\Domain\Sku;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SkuTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function spellings(): iterable
    {
        yield 'already canonical' => ['TEE-RED-M', 'TEE-RED-M'];
        yield 'lower case' => ['tee-red-m', 'TEE-RED-M'];
        yield 'mixed case' => ['Tee-Red-m', 'TEE-RED-M'];
        yield 'surrounding whitespace' => ["  mug.1_a \t", 'MUG.1_A'];
        yield 'digits only' => ['000123', '000123'];
    }

    #[Test]
    #[DataProvider('spellings')]
    public function every_spelling_of_a_sku_normalises_to_one_form(string $input, string $canonical): void
    {
        self::assertSame($canonical, Sku::normalise($input));
        self::assertSame($canonical, Sku::normalise(Sku::normalise($input)), 'normalising twice changes nothing');
    }

    #[Test]
    public function variant_data_cannot_carry_a_non_canonical_sku(): void
    {
        self::assertSame('TEE-1', (new VariantData(' tee-1 '))->sku);
    }

    #[Test]
    public function the_pattern_describes_the_accepted_raw_shape(): void
    {
        foreach (['a', 'TEE-RED-M', 'mug.1_a', str_repeat('A', 64)] as $valid) {
            self::assertMatchesRegularExpression(Sku::PATTERN, $valid);
        }

        foreach (['', '-LEADING-DASH', 'HAS SPACE', 'ÜMLAUT', str_repeat('A', 65)] as $invalid) {
            self::assertDoesNotMatchRegularExpression(Sku::PATTERN, $invalid);
        }
    }
}
