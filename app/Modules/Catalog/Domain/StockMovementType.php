<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

enum StockMovementType: string
{
    case Adjustment = 'adjustment';
    case Reservation = 'reservation';
    case Release = 'release';
}
