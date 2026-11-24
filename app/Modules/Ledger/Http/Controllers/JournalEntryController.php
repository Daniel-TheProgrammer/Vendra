<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Http\Controllers;

use App\Modules\Ledger\Application\Actions\PostManualEntry;
use App\Modules\Ledger\Application\Actions\ReverseJournalEntry;
use App\Modules\Ledger\Application\Queries\FindEntry;
use App\Modules\Ledger\Application\Queries\ListEntries;
use App\Modules\Ledger\Http\Requests\ManualEntryRequest;
use App\Modules\Ledger\Http\Resources\JournalEntryResource;
use App\Modules\Ledger\Infrastructure\Models\JournalEntryRecord;
use App\Modules\Ledger\Infrastructure\Models\JournalLineRecord;
use App\Shared\Domain\Money\Money;
use App\Shared\Infrastructure\Http\Rfc3339;
use App\Shared\Tenancy\TenantContext;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class JournalEntryController
{
    /**
     * GET /ledger/entries?reference_type=order&reference_id=…&posted_from=…&posted_to=…
     */
    public function index(Request $request, ListEntries $list): JsonResponse
    {
        $filters = $this->filters($request, ['per_page' => ['sometimes', 'integer', 'min:1', 'max:'.ListEntries::MAX_PER_PAGE], 'cursor' => ['sometimes', 'string', 'max:512']]);

        $page = $list(
            referenceType: $filters['reference_type'],
            referenceId: $filters['reference_id'],
            postedFrom: $filters['posted_from'],
            postedTo: $filters['posted_to'],
            perPage: $request->integer('per_page', 25),
            cursor: $request->filled('cursor') ? $request->string('cursor')->value() : null,
        );

        return JournalEntryResource::collection($page->through(static fn (JournalEntryRecord $entry): JournalEntryResource => new JournalEntryResource($entry)))
            ->response($request);
    }

    public function show(Request $request, string $entry, FindEntry $find): JsonResponse
    {
        return (new JournalEntryResource($find($entry)))->toResponse($request);
    }

    /**
     * Owners only (ledger.post): a manual adjustment, with a reason.
     */
    public function store(ManualEntryRequest $request, PostManualEntry $post): JsonResponse
    {
        $entry = $post($request->toCommand($this->actorId($request)));
        $entry->load('lines.account', 'reversedBy');

        return (new JournalEntryResource($entry))
            ->toResponse($request)
            ->setStatusCode(201)
            ->header('Location', route('api.v1.ledger.entries.show', ['entry' => $entry->id]));
    }

    public function reverse(Request $request, string $entry, ReverseJournalEntry $reverse): JsonResponse
    {
        $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $reversal = $reverse($entry, $request->string('reason')->trim()->value(), $this->actorId($request));
        $reversal->load('lines.account', 'reversedBy');

        return (new JournalEntryResource($reversal))
            ->toResponse($request)
            ->setStatusCode(201)
            ->header('Location', route('api.v1.ledger.entries.show', ['entry' => $reversal->id]));
    }

    /**
     * GET /ledger/entries/export?format=csv (same filters as the list).
     *
     * One CSV row per journal line, streamed: entries are read 500 at a time
     * with their lines, so memory stays flat however large the ledger is.
     * The body is produced after the middleware stack has returned (and has
     * cleared the tenant), so the stream re-enters the tenant explicitly.
     */
    public function export(Request $request, TenantContext $tenant): StreamedResponse
    {
        $filters = $this->filters($request, ['format' => ['sometimes', 'string', 'in:csv']]);
        $tenantId = $tenant->id();

        return response()->streamDownload(function () use ($tenant, $tenantId, $filters): void {
            $tenant->runAs($tenantId, static function () use ($filters): void {
                $out = fopen('php://output', 'wb');

                if ($out === false) {
                    return;
                }

                fputcsv($out, ['entry_id', 'posted_at', 'kind', 'reference_type', 'reference_id', 'description', 'reason', 'line', 'account_code', 'account_name', 'currency', 'debit', 'credit', 'memo'], escape: '');

                $entries = ListEntries::filtered($filters['reference_type'], $filters['reference_id'], $filters['posted_from'], $filters['posted_to'])
                    ->with('lines.account')
                    ->lazyById(500);

                foreach ($entries as $entry) {
                    foreach ($entry->lines as $line) {
                        fputcsv($out, self::row($entry, $line), escape: '');
                    }
                }

                fclose($out);
            });
        }, 'ledger-entries.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return list<string|int|null>
     */
    private static function row(JournalEntryRecord $entry, JournalLineRecord $line): array
    {
        return [
            $entry->id,
            $entry->posted_at->toIso8601String(),
            $entry->kind->value,
            $entry->reference_type,
            $entry->reference_id,
            $entry->description,
            $entry->reason,
            $line->position,
            $line->account->code,
            $line->account->name,
            $line->currency->value,
            $line->debit > 0 ? Money::of($line->debit, $line->currency)->toDecimal() : '',
            $line->credit > 0 ? Money::of($line->credit, $line->currency)->toDecimal() : '',
            $line->memo,
        ];
    }

    /**
     * @param  array<string, list<string>>  $extra
     * @return array{reference_type: string|null, reference_id: string|null, posted_from: DateTimeInterface|null, posted_to: DateTimeInterface|null}
     */
    private function filters(Request $request, array $extra = []): array
    {
        $request->validate([
            'reference_type' => ['sometimes', 'string', 'max:40'],
            'reference_id' => ['sometimes', 'string', 'max:100'],
            'posted_from' => ['sometimes', ...Rfc3339::rules()],
            'posted_to' => ['sometimes', ...Rfc3339::rules()],
            ...$extra,
        ]);

        return [
            'reference_type' => $request->filled('reference_type') ? $request->string('reference_type')->value() : null,
            'reference_id' => $request->filled('reference_id') ? $request->string('reference_id')->value() : null,
            'posted_from' => $request->filled('posted_from') ? Rfc3339::parse($request->string('posted_from')->value()) : null,
            'posted_to' => $request->filled('posted_to') ? Rfc3339::parse($request->string('posted_to')->value()) : null,
        ];
    }

    private function actorId(Request $request): ?string
    {
        $actor = $request->user()?->getAuthIdentifier();

        return is_string($actor) ? $actor : null;
    }
}
