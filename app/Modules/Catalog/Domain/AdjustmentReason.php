<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

/**
 * Why on-hand stock changed outside of order fulfilment. A fixed list keeps
 * shrinkage and receiving reports meaningful.
 */
enum AdjustmentReason: string
{
    case Received = 'received';
    case Returned = 'returned';
    case Damaged = 'damaged';
    case Lost = 'lost';
    case Recount = 'recount';
}
