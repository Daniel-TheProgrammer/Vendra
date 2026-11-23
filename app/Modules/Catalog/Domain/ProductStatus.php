<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

/**
 * draft    - being prepared; not sellable.
 * active   - sellable.
 * archived - retired for good. Kept because orders reference its variants,
 *            and read-only from then on.
 */
enum ProductStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Archived = 'archived';

    /**
     * Statuses a client may set directly; archiving has its own endpoint.
     *
     * @return list<string>
     */
    public static function editableValues(): array
    {
        return [self::Draft->value, self::Active->value];
    }
}
