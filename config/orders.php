<?php

declare(strict_types=1);

return [
    // Flat tax rate applied to every order's subtotal, in basis points
    // (2000 = 20%). 0 when prices are entered tax-inclusive. Replace the
    // TaxStrategy binding in OrdersServiceProvider for destination-based tax.
    'tax_rate_basis_points' => (int) env('ORDERS_TAX_RATE_BPS', 0),
];
