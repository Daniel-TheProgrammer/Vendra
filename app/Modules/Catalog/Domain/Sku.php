<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

/**
 * Stock keeping units are identifiers, not prose: "tee-red-m" and "TEE-RED-M"
 * on two different variants would only ever be a typo that splits stock and
 * order history. Every SKU is therefore stored, compared and looked up in one
 * canonical form, upper case with surrounding whitespace removed, and is
 * unique per tenant in that form.
 */
final class Sku
{
    /**
     * Shape of an acceptable SKU before normalisation.
     */
    public const PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._\-]{0,63}$/';

    public static function normalise(string $sku): string
    {
        return strtoupper(trim($sku));
    }
}
