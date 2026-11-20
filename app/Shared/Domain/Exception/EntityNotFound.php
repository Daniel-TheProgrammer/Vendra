<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/**
 * @phpstan-consistent-constructor
 */
class EntityNotFound extends DomainException
{
    public static function withId(string $entity, string $id): static
    {
        return new static(sprintf('%s %s was not found.', $entity, $id));
    }

    public function status(): int
    {
        return 404;
    }

    public function type(): string
    {
        return 'not-found';
    }

    public function title(): string
    {
        return 'Not found';
    }
}
