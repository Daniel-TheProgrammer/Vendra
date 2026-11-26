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
        Schema::create('webhook_subscriptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('url', 2048);
            $table->string('description', 255)->nullable();
            // Event names (e.g. "orders.order_placed"), or ["*"] for all.
            $table->jsonb('event_types');
            // The signing secret, encrypted. While it is rotated, deliveries
            // are signed with the previous one too until it expires.
            $table->text('secret');
            $table->text('previous_secret')->nullable();
            $table->timestampTz('previous_secret_expires_at')->nullable();
            $table->string('status', 16);
            $table->string('paused_reason', 16)->nullable();
            $table->timestampTz('paused_at')->nullable();
            // Consecutive failed deliveries; reset by any success.
            $table->unsignedInteger('failure_count')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'status']);
        });

        DB::statement("ALTER TABLE webhook_subscriptions ADD CONSTRAINT webhook_subscriptions_status_check CHECK (status IN ('active', 'paused'))");
        DB::statement("ALTER TABLE webhook_subscriptions ADD CONSTRAINT webhook_subscriptions_paused_check CHECK ((status = 'paused') = (paused_reason IS NOT NULL) AND (paused_reason IS NULL OR paused_reason IN ('manual', 'failing')))");
        DB::statement("ALTER TABLE webhook_subscriptions ADD CONSTRAINT webhook_subscriptions_https_check CHECK (url LIKE 'https://%')");
        DB::statement("ALTER TABLE webhook_subscriptions ADD CONSTRAINT webhook_subscriptions_event_types_check CHECK (jsonb_typeof(event_types) = 'array' AND jsonb_array_length(event_types) > 0)");

        TenantRls::enable('webhook_subscriptions');
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_subscriptions');
    }
};
