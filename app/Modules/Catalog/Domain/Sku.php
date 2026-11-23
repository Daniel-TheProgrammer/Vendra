<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

/**
 * Stock keeping units are unique per tenant and matched case-sensitively,
 * exactly as the merchant wrote them.
 */
final class Sku
{
    public const PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._\-]{0,63}$/';
}
