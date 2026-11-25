<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain;

/**
 * Where the money of a succeeded payment went. Normally the order. An order
 * cancelled while its payment was in flight cannot take it any more, so the
 * money is owed back to the customer until it is refunded.
 */
enum Settlement: string
{
    case Order = 'order';
    case CustomerCredit = 'customer_credit';
}
