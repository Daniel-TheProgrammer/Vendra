<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * The period a price applies to, half-open: [from, to). A price valid to
 * midnight stops applying at midnight, so back-to-back windows meet without
 * overlapping and without a gap. Either end may be open.
 *
 * A window with both ends open is "always": that is a variant's base price,
 * which applies whenever no scheduled price does.
 */
final readonly class PriceWindow
{
    public ?DateTimeImmutable $from;

    public ?DateTimeImmutable $to;

    public function __construct(?DateTimeInterface $from = null, ?DateTimeInterface $to = null)
    {
        if ($from instanceof DateTimeInterface && $to instanceof DateTimeInterface && $from >= $to) {
            throw new InvalidArgumentException('A price window must end after it starts.');
        }

        $this->from = $from instanceof DateTimeInterface ? DateTimeImmutable::createFromInterface($from) : null;
        $this->to = $to instanceof DateTimeInterface ? DateTimeImmutable::createFromInterface($to) : null;
    }

    public static function always(): self
    {
        return new self;
    }

    public function isAlways(): bool
    {
        return ! $this->from instanceof DateTimeImmutable && ! $this->to instanceof DateTimeImmutable;
    }

    public function contains(DateTimeInterface $instant): bool
    {
        return (! $this->from instanceof DateTimeImmutable || $this->from <= $instant)
            && (! $this->to instanceof DateTimeImmutable || $instant < $this->to);
    }

    public function overlaps(self $other): bool
    {
        $startsBeforeOtherEnds = ! $this->from instanceof DateTimeImmutable || ! $other->to instanceof DateTimeImmutable || $this->from < $other->to;
        $otherStartsBeforeThisEnds = ! $other->from instanceof DateTimeImmutable || ! $this->to instanceof DateTimeImmutable || $other->from < $this->to;

        return $startsBeforeOtherEnds && $otherStartsBeforeThisEnds;
    }

    public function hasEndedBy(DateTimeInterface $instant): bool
    {
        return $this->to instanceof DateTimeImmutable && $this->to <= $instant;
    }
}
