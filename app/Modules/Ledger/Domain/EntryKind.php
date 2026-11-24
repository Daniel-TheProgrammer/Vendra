<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Domain;

enum EntryKind: string
{
    /** Posted automatically from a business event. */
    case System = 'system';

    /** An adjustment a person posted, with a reason. */
    case Manual = 'manual';

    /** Cancels an earlier entry. */
    case Reversal = 'reversal';
}
