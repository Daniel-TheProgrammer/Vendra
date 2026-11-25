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
        // The signing secret of the tenant's webhook endpoint, encrypted. While
        // it is rotated, the previous one is still accepted until it expires.
        Schema::table('payment_accounts', function (Blueprint $table): void {
            $table->text('webhook_secret')->nullable();
            $table->text('previous_webhook_secret')->nullable();
            $table->timestampTz('previous_webhook_secret_expires_at')->nullable();
        });

        // Every inbound gateway event, verbatim. The unique gateway event id
        // makes a redelivery a no-op; the stored payload lets an event be
        // processed again after a fix, without asking the provider.
        Schema::create('payment_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('gateway', 16);
            $table->string('gateway_event_id', 255);
            $table->string('type', 100);
            $table->jsonb('payload');
            // The provider's own timestamp: the order events happened in.
            $table->timestampTz('gateway_created_at');
            $table->timestampTz('received_at');
            $table->timestampTz('processed_at')->nullable();
            $table->string('outcome', 16)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();

            $table->unique(['gateway', 'gateway_event_id']);
            $table->index(['tenant_id', 'received_at']);
        });

        DB::statement("ALTER TABLE payment_events ADD CONSTRAINT payment_events_outcome_check CHECK (outcome IS NULL OR outcome IN ('applied', 'ignored', 'unmatched'))");
        DB::statement('ALTER TABLE payment_events ADD CONSTRAINT payment_events_processed_check CHECK ((processed_at IS NULL) = (outcome IS NULL))');
        DB::statement('CREATE INDEX payment_events_unprocessed ON payment_events (received_at) WHERE processed_at IS NULL');
        TenantRls::enable('payment_events');

        Schema::table('payment_intents', function (Blueprint $table): void {
            $table->timestampTz('disputed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payment_intents', function (Blueprint $table): void {
            $table->dropColumn('disputed_at');
        });

        Schema::dropIfExists('payment_events');

        Schema::table('payment_accounts', function (Blueprint $table): void {
            $table->dropColumn(['webhook_secret', 'previous_webhook_secret', 'previous_webhook_secret_expires_at']);
        });
    }
};
