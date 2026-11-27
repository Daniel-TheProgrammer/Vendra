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
        // One row per event per subscription: the unique pair makes the
        // fan-out idempotent, so a republished event is not sent twice.
        Schema::create('webhook_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->uuid('subscription_id');
            // The outbox message id; the receiver's deduplication key.
            $table->uuid('event_id');
            $table->string('event_type', 100);
            // The exact body sent, so every attempt and redelivery is identical.
            $table->text('body');
            $table->string('status', 16);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('next_attempt_at', 6)->nullable();
            $table->timestampTz('last_attempt_at', 6)->nullable();
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->unsignedInteger('response_ms')->nullable();
            $table->string('response_excerpt', 500)->nullable();
            $table->string('error', 500)->nullable();
            $table->timestampTz('delivered_at', 6)->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'subscription_id'])->references(['tenant_id', 'id'])->on('webhook_subscriptions')->cascadeOnDelete();
            $table->unique(['subscription_id', 'event_id']);
            $table->index(['tenant_id', 'status', 'id']);
            $table->index(['tenant_id', 'subscription_id', 'id']);
        });

        DB::statement("ALTER TABLE webhook_deliveries ADD CONSTRAINT webhook_deliveries_status_check CHECK (status IN ('pending', 'succeeded', 'failed', 'dead'))");
        DB::statement('CREATE INDEX webhook_deliveries_due ON webhook_deliveries (next_attempt_at) WHERE status IN (\'pending\', \'failed\')');

        TenantRls::enable('webhook_deliveries');
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
    }
};
