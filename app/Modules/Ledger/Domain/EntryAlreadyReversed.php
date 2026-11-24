<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Domain;

use App\Shared\Domain\Exception\ConflictException;

final class EntryAlreadyReversed extends ConflictException
{
    private string $reversalId = '';

    public static function by(string $entryId, string $reversalId): self
    {
        $exception = new self(sprintf('Journal entry %s was already reversed by %s.', $entryId, $reversalId));
        $exception->reversalId = $reversalId;

        return $exception;
    }

    public function type(): string
    {
        return 'entry-already-reversed';
    }

    public function title(): string
    {
        return 'Entry already reversed';
    }

    /**
     * @return array{reversed_by: string}
     */
    public function extensions(): array
    {
        return ['reversed_by' => $this->reversalId];
    }
}
