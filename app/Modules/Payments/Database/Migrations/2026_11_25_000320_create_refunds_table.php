<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->uuid('payment_intent_id');
            $table->string('gateway', 16);
            $table->string('gateway_ref', 255)->nullable();
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('status', 16);
            $table->unsignedInteger('version')->default(1);
            $table->string('reason', 500)->nullable();
            $table->string('failure_code', 64)->nullable();
            // Null for a refund made at the provider (dashboard) and learnt
            // from its webhook.
            $table->uuid('requested_by')->nullable();
            $table->timestampTz('succeeded_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'payment_intent_id'])->references(['tenant_id', 'id'])->on('payment_intents');
            $table->unique(['gateway', 'gateway_ref']);
            $table->index(['tenant_id', 'payment_intent_id']);
            $table->index(['tenant_id', 'status', 'id']);
        });

        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_status_check CHECK (status IN ('pending', 'succeeded', 'failed'))");
        DB::statement('ALTER TABLE refunds ADD CONSTRAINT refunds_amount_check CHECK (amount_minor > 0)');

        // Never more back than was paid: the last line of defence behind the
        // application's check, which runs under the intent's row lock.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION refunds_within_payment() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                paid bigint;
                refunded bigint;
            BEGIN
                SELECT amount_minor INTO paid FROM payment_intents WHERE id = NEW.payment_intent_id;
                SELECT COALESCE(SUM(amount_minor), 0) INTO refunded
                    FROM refunds WHERE payment_intent_id = NEW.payment_intent_id AND status <> 'failed';

                IF refunded > paid THEN
                    RAISE EXCEPTION 'Refunds of payment % (%) exceed the amount paid (%)', NEW.payment_intent_id, refunded, paid
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END
            $$
            SQL);
        DB::statement('CREATE CONSTRAINT TRIGGER refunds_within_payment AFTER INSERT OR UPDATE OF amount_minor, status ON refunds FOR EACH ROW EXECUTE FUNCTION refunds_within_payment()');

        TenantRls::enable('refunds');
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
        DB::statement('DROP FUNCTION IF EXISTS refunds_within_payment()');
    }
};
