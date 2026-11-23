<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use Carbon\CarbonImmutable;

/**
 * The only timestamp format the API accepts: RFC 3339 with a mandatory
 * offset, e.g. 2026-11-23T08:00:00Z or 2026-11-23T10:00:00.5+02:00. A
 * timestamp without an offset names no instant, so it is rejected rather
 * than guessed.
 */
final class Rfc3339
{
    public const PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})$/';

    /**
     * Validation rules for an RFC 3339 timestamp field.
     *
     * @return list<string>
     */
    public static function rules(): array
    {
        return ['string', 'regex:'.self::PATTERN, 'date'];
    }

    /**
     * Parses an already validated value, keeping its offset.
     */
    public static function parse(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value);
    }
}
