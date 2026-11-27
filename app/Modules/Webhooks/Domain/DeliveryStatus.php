<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Domain;

/**
 *   pending ──► succeeded
 *      │
 *      └──► failed ──► (retried on schedule) ──► succeeded
 *                └──► dead (after the last retry; redelivery puts it back to pending)
 */
enum DeliveryStatus: string
{
    /** Not attempted yet (or redelivery requested). */
    case Pending = 'pending';

    /** The receiver answered 2xx. */
    case Succeeded = 'succeeded';

    /** The last attempt failed; another is scheduled at next_attempt_at. */
    case Failed = 'failed';

    /** Every retry failed. Only a redelivery sends it again. */
    case Dead = 'dead';

    /**
     * @return list<string>
     */
    public static function dueValues(): array
    {
        return [self::Pending->value, self::Failed->value];
    }
}
