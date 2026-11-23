<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Import;

use RuntimeException;

/**
 * A product group that cannot be imported, with every problem found in it
 * so the merchant can fix the file in one pass.
 */
final class InvalidProductGroup extends RuntimeException
{
    /**
     * @param  non-empty-list<array{row: int, message: string}>  $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct($errors[0]['message']);
    }

    public static function at(int $row, string $message): self
    {
        return new self([['row' => $row, 'message' => $message]]);
    }
}
