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
        // A tenant's connected gateway account. The account id is stored
        // encrypted; account_lookup (an HMAC of it) is what an inbound webhook
        // is matched on, before any tenant is known.
        Schema::create('payment_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->tenantId()->unique();
            $table->string('gateway', 16);
            $table->text('account_id');
            $table->char('account_lookup', 64)->unique();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE payment_accounts ADD CONSTRAINT payment_accounts_gateway_check CHECK (gateway IN ('stripe', 'fake'))");
        TenantRls::markPlatformTable('payment_accounts', 'resolves the tenant of an inbound gateway webhook from the account it names, before the tenant is known');

        Schema::create('payment_intents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->uuid('order_id');
            $table->string('gateway', 16);
            $table->string('gateway_ref', 255)->nullable();
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('status', 24);
            $table->unsignedInteger('version')->default(1);
            $table->bigInteger('fee_minor')->nullable();
            // Where captured money went: the order, or the customer's credit
            // when the order could no longer take it (cancelled meanwhile).
            $table->string('settlement', 24)->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->string('failure_message', 500)->nullable();
            // When the gateway last reported on this intent: an older report
            // arriving late can never move the intent back.
            $table->timestampTz('gateway_updated_at')->nullable();
            $table->timestampTz('captured_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'order_id'])->references(['tenant_id', 'id'])->on('orders');
            $table->unique(['tenant_id', 'id']);
            $table->unique(['gateway', 'gateway_ref']);
            $table->index(['tenant_id', 'order_id']);
        });

        DB::statement("ALTER TABLE payment_intents ADD CONSTRAINT payment_intents_gateway_check CHECK (gateway IN ('stripe', 'fake'))");
        DB::statement("ALTER TABLE payment_intents ADD CONSTRAINT payment_intents_status_check CHECK (status IN ('requires_payment', 'processing', 'succeeded', 'failed', 'cancelled'))");
        DB::statement("ALTER TABLE payment_intents ADD CONSTRAINT payment_intents_settlement_check CHECK (settlement IS NULL OR settlement IN ('order', 'customer_credit'))");
        DB::statement('ALTER TABLE payment_intents ADD CONSTRAINT payment_intents_amount_check CHECK (amount_minor > 0 AND (fee_minor IS NULL OR fee_minor BETWEEN 0 AND amount_minor))');
        DB::statement("ALTER TABLE payment_intents ADD CONSTRAINT payment_intents_settled_check CHECK ((status = 'succeeded') = (settlement IS NOT NULL))");

        // At most one payment in flight per order: two concurrent checkouts
        // cannot both charge the customer.
        DB::statement("CREATE UNIQUE INDEX payment_intents_one_open_per_order ON payment_intents (order_id) WHERE status IN ('requires_payment', 'processing')");
        // And an order is paid once.
        DB::statement("CREATE UNIQUE INDEX payment_intents_one_success_per_order ON payment_intents (order_id) WHERE status = 'succeeded'");

        TenantRls::enable('payment_intents');
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_intents');
        Schema::dropIfExists('payment_accounts');
    }
};
