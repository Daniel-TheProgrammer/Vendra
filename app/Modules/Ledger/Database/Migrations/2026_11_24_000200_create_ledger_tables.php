<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The double-entry ledger (ADR 0008). The database enforces the rules
 * that make it a ledger, independently of the application:
 *
 *   - a line is a debit or a credit, never both, never zero;
 *   - a line's currency is its account's and its entry's (composite keys);
 *   - every entry balances, checked when the transaction commits;
 *   - entries and lines are never updated or deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('code', 40);
            $table->string('name', 120);
            $table->string('type', 16);
            $table->string('normal_balance', 6);
            $table->char('currency', 3);
            $table->boolean('is_system')->default(true);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'id', 'currency']);
            $table->unique(['tenant_id', 'code', 'currency']);
        });

        DB::statement("ALTER TABLE ledger_accounts ADD CONSTRAINT ledger_accounts_type_check CHECK (type IN ('asset', 'liability', 'equity', 'revenue', 'expense'))");
        DB::statement("ALTER TABLE ledger_accounts ADD CONSTRAINT ledger_accounts_normal_balance_check CHECK ((type IN ('asset', 'expense') AND normal_balance = 'debit') OR (type IN ('liability', 'equity', 'revenue') AND normal_balance = 'credit'))");

        Schema::create('journal_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->char('currency', 3);
            $table->string('description', 500);
            $table->string('reference_type', 40)->nullable();
            $table->string('reference_id', 100)->nullable();
            // Makes every automatic posting exactly-once: "order:{id}:placed".
            $table->string('idempotency_key', 150);
            $table->uuid('reverses_entry_id')->nullable();
            $table->string('kind', 12);
            $table->string('reason', 500)->nullable();
            $table->uuid('actor_id')->nullable();
            $table->timestampTz('posted_at');
            $table->timestampTz('created_at');

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'id', 'currency']);
            $table->unique(['tenant_id', 'idempotency_key']);
            // An entry is reversed at most once.
            $table->unique('reverses_entry_id');
            $table->foreign(['tenant_id', 'reverses_entry_id'])->references(['tenant_id', 'id'])->on('journal_entries');
            $table->index(['tenant_id', 'reference_type', 'reference_id']);
            $table->index(['tenant_id', 'posted_at']);
        });

        DB::statement("ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_kind_check CHECK (kind IN ('system', 'manual', 'reversal'))");
        DB::statement("ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_reversal_check CHECK ((kind = 'reversal') = (reverses_entry_id IS NOT NULL))");
        DB::statement("ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_reason_check CHECK (kind <> 'manual' OR (reason IS NOT NULL AND btrim(reason) <> ''))");

        Schema::create('journal_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->uuid('entry_id');
            $table->unsignedSmallInteger('position');
            $table->uuid('account_id');
            $table->char('currency', 3);
            $table->bigInteger('debit')->default(0);
            $table->bigInteger('credit')->default(0);
            $table->string('memo', 200)->nullable();

            $table->foreign(['tenant_id', 'entry_id', 'currency'])->references(['tenant_id', 'id', 'currency'])->on('journal_entries');
            $table->foreign(['tenant_id', 'account_id', 'currency'])->references(['tenant_id', 'id', 'currency'])->on('ledger_accounts');
            $table->unique(['entry_id', 'position']);
            $table->index(['account_id', 'entry_id']);
        });

        DB::statement('ALTER TABLE journal_lines ADD CONSTRAINT journal_lines_one_side_check CHECK ((debit > 0 AND credit = 0) OR (credit > 0 AND debit = 0))');

        // Running totals per account, maintained by the posting action in the
        // same transaction and checked nightly against the lines (ledger:reconcile).
        Schema::create('account_balances', function (Blueprint $table): void {
            $table->uuid('account_id')->primary();
            $table->tenantId();
            $table->bigInteger('debit_total')->default(0);
            $table->bigInteger('credit_total')->default(0);
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampTz('updated_at');

            $table->foreign(['tenant_id', 'account_id'])->references(['tenant_id', 'id'])->on('ledger_accounts')->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE account_balances ADD CONSTRAINT account_balances_totals_check CHECK (debit_total >= 0 AND credit_total >= 0)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION ledger_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION '% is append-only: % is not allowed; post a reversal instead', TG_TABLE_NAME, TG_OP
                    USING ERRCODE = 'insufficient_privilege';
            END;
            $$;

            CREATE TRIGGER journal_entries_append_only BEFORE UPDATE OR DELETE ON journal_entries
                FOR EACH ROW EXECUTE FUNCTION ledger_append_only();
            CREATE TRIGGER journal_lines_append_only BEFORE UPDATE OR DELETE ON journal_lines
                FOR EACH ROW EXECUTE FUNCTION ledger_append_only();

            -- Deferred to commit, so an entry's lines may be inserted one by one;
            -- by the time the transaction commits, every touched entry must have
            -- at least two lines and debits equal to credits.
            CREATE OR REPLACE FUNCTION journal_entry_assert_balanced(entry uuid) RETURNS void LANGUAGE plpgsql AS $$
            DECLARE
                debits bigint;
                credits bigint;
                line_count integer;
            BEGIN
                SELECT coalesce(sum(debit), 0), coalesce(sum(credit), 0), count(*)
                  INTO debits, credits, line_count
                  FROM journal_lines WHERE entry_id = entry;

                IF line_count < 2 OR debits <> credits THEN
                    RAISE EXCEPTION 'journal entry % does not balance: % debit vs % credit over % line(s)', entry, debits, credits, line_count
                        USING ERRCODE = 'check_violation';
                END IF;
            END;
            $$;

            CREATE OR REPLACE FUNCTION journal_entries_must_balance() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                PERFORM journal_entry_assert_balanced(NEW.id);
                RETURN NULL;
            END;
            $$;

            CREATE OR REPLACE FUNCTION journal_lines_must_balance() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                PERFORM journal_entry_assert_balanced(NEW.entry_id);
                RETURN NULL;
            END;
            $$;

            CREATE CONSTRAINT TRIGGER journal_entries_balance AFTER INSERT ON journal_entries
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION journal_entries_must_balance();
            CREATE CONSTRAINT TRIGGER journal_lines_balance AFTER INSERT ON journal_lines
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION journal_lines_must_balance();
            SQL);

        foreach (['ledger_accounts', 'journal_entries', 'journal_lines', 'account_balances'] as $table) {
            TenantRls::enable($table);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('account_balances');
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('ledger_accounts');
        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS journal_entries_must_balance();
            DROP FUNCTION IF EXISTS journal_lines_must_balance();
            DROP FUNCTION IF EXISTS journal_entry_assert_balanced(uuid);
            DROP FUNCTION IF EXISTS ledger_append_only();
            SQL);
    }
};
