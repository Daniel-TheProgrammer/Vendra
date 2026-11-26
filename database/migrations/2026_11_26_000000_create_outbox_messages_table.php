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
        // Every domain event, written in the transaction that caused it and
        // published from here by the relay (ADR 0010). The id is the event's
        // id everywhere it travels: listeners, webhook deliveries, GET /events.
        Schema::create('outbox_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('event_type', 100);
            $table->jsonb('payload');
            // Microseconds: a message must be due the instant it is recorded,
            // and the default (seconds) rounds up into the future.
            $table->timestampTz('occurred_at', 6);
            $table->timestampTz('recorded_at', 6);
            // Not before: a failed message waits out its backoff here.
            $table->timestampTz('available_at', 6);
            $table->timestampTz('published_at', 6)->nullable();
            // Gave up after the last attempt; kept for a human to look at.
            $table->timestampTz('dead_at', 6)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'id']);
            $table->index(['tenant_id', 'event_type', 'id']);
        });

        // The relay's queue: small however large the table grows.
        DB::statement('CREATE INDEX outbox_messages_pending ON outbox_messages (available_at, id) WHERE published_at IS NULL AND dead_at IS NULL');
        DB::statement('ALTER TABLE outbox_messages ADD CONSTRAINT outbox_messages_settled_check CHECK (published_at IS NULL OR dead_at IS NULL)');

        TenantRls::markPlatformTable('outbox_messages', 'the relay publishes every tenant\'s events from one queue; tenant reads (GET /events) filter by tenant_id explicitly');
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_messages');
    }
};
