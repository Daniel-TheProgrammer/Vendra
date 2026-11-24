<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Application\Actions;

use App\Modules\Ledger\Application\Commands\EntryMetadata;
use App\Modules\Ledger\Domain\InvalidJournalEntry;
use App\Modules\Ledger\Domain\JournalLine;
use App\Modules\Ledger\Domain\PostedJournalEntry;
use App\Modules\Ledger\Domain\Side;
use App\Modules\Ledger\Infrastructure\Models\AccountBalanceRecord;
use App\Modules\Ledger\Infrastructure\Models\JournalEntryRecord;
use App\Modules\Ledger\Infrastructure\Models\JournalLineRecord;
use App\Modules\Ledger\Infrastructure\Models\LedgerAccount;
use App\Shared\Application\Clock\Clock;
use App\Shared\Application\Transaction\TransactionRunner;
use App\Shared\Domain\Exception\EntityNotFound;
use App\Shared\Domain\Money\Currency;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use LogicException;

/**
 * Writes a balanced entry and moves the balances of its accounts, in one
 * transaction (joining the caller's).
 *
 * Exactly once: the idempotency key is unique per tenant. Posting a key that
 * exists returns the entry already posted under it and changes nothing, so a
 * listener that runs twice, or a retried job, cannot double-book.
 *
 * Balance rows are locked FOR UPDATE in account id order before they move.
 * Concurrent postings to the same accounts therefore queue rather than lose
 * updates, and never deadlock each other.
 */
final readonly class PostJournalEntry
{
    public function __construct(
        private TransactionRunner $transaction,
        private Clock $clock,
    ) {}

    public function __invoke(PostedJournalEntry $entry, EntryMetadata $meta): JournalEntryRecord
    {
        return $this->transaction->run(function () use ($entry, $meta): JournalEntryRecord {
            $existing = $this->find($meta->idempotencyKey);

            if ($existing instanceof JournalEntryRecord) {
                return $existing;
            }

            $this->assertAccounts($entry);

            try {
                // Own savepoint: a concurrent posting of the same key fails
                // here, and the surrounding transaction must survive that.
                $record = $this->transaction->run(fn (): JournalEntryRecord => JournalEntryRecord::query()->create([
                    'currency' => $entry->currency,
                    'description' => $entry->description,
                    'reference_type' => $meta->referenceType,
                    'reference_id' => $meta->referenceId,
                    'idempotency_key' => $meta->idempotencyKey,
                    'reverses_entry_id' => $meta->reversesEntryId,
                    'kind' => $meta->kind,
                    'reason' => $meta->reason,
                    'actor_id' => $meta->actorId,
                    'posted_at' => $meta->postedAt ?? $this->clock->now(),
                ]));
            } catch (UniqueConstraintViolationException) {
                return $this->find($meta->idempotencyKey) ?? throw new LogicException('An entry key collided but the entry is not visible.');
            }

            $this->writeLines($record, $entry);
            $this->moveBalances($entry);

            return $record;
        });
    }

    private function find(string $key): ?JournalEntryRecord
    {
        return JournalEntryRecord::query()->where('idempotency_key', $key)->first();
    }

    private function assertAccounts(PostedJournalEntry $entry): void
    {
        $ids = array_values(array_unique(array_map(static fn (JournalLine $line): string => $line->accountId, $entry->lines)));
        $currencies = LedgerAccount::query()->whereIn('id', $ids)->pluck('currency', 'id');

        foreach ($ids as $id) {
            if (! $currencies->has($id)) {
                throw EntityNotFound::withId('Ledger account', $id);
            }

            $accountCurrency = $currencies->get($id);

            if ($accountCurrency !== $entry->currency) {
                throw InvalidJournalEntry::mixedCurrencies($entry->currency->value, $accountCurrency instanceof Currency ? $accountCurrency->value : '?');
            }
        }
    }

    private function writeLines(JournalEntryRecord $record, PostedJournalEntry $entry): void
    {
        $rows = [];

        foreach ($entry->lines as $index => $line) {
            $rows[] = [
                'id' => (string) Str::uuid7(),
                'tenant_id' => $record->tenant_id,
                'entry_id' => $record->id,
                'position' => $index + 1,
                'account_id' => $line->accountId,
                'currency' => $entry->currency->value,
                'debit' => $line->side === Side::Debit ? $line->amount->amount : 0,
                'credit' => $line->side === Side::Credit ? $line->amount->amount : 0,
                'memo' => $line->memo,
            ];
        }

        JournalLineRecord::query()->insert($rows);
    }

    private function moveBalances(PostedJournalEntry $entry): void
    {
        /** @var array<string, array{debit: int, credit: int}> $deltas */
        $deltas = [];

        foreach ($entry->lines as $line) {
            $deltas[$line->accountId] ??= ['debit' => 0, 'credit' => 0];
            $deltas[$line->accountId][$line->side->value] += $line->amount->amount;
        }

        ksort($deltas);

        $balances = AccountBalanceRecord::query()
            ->whereIn('account_id', array_keys($deltas))
            ->orderBy('account_id')
            ->lockForUpdate()
            ->get()
            ->keyBy('account_id');

        foreach ($deltas as $accountId => $delta) {
            /** @var AccountBalanceRecord $balance */
            $balance = $balances->get($accountId) ?? throw new LogicException(sprintf('Account %s has no balance row.', $accountId));
            $balance->debit_total += $delta['debit'];
            $balance->credit_total += $delta['credit'];
            $balance->version++;
            $balance->save();
        }
    }
}
