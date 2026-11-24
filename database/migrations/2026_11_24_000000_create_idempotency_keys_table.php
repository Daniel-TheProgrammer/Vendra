<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stored outcomes of requests sent with an Idempotency-Key (ADR 0007).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('key', 255);
            // SHA-256 of the request fingerprint: method, path, query, principal and body.
            $table->char('request_hash', 64);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->jsonb('response_headers')->nullable();
            $table->text('response_body')->nullable();
            $table->timestampTz('locked_at');
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('created_at');

            $table->unique(['tenant_id', 'key']);
            $table->index('expires_at');
        });

        DB::statement('ALTER TABLE idempotency_keys ADD CONSTRAINT idempotency_keys_completion_check CHECK ((completed_at IS NULL) = (response_status IS NULL))');

        TenantRls::enable('idempotency_keys');

        // The nightly prune runs without a tenant and must clear every tenant's
        // expired keys. This policy grants exactly that, as the application
        // role: DELETE of rows whose expiry has passed. Reading them still
        // requires the tenant policy, so nothing becomes visible across tenants.
        DB::statement('CREATE POLICY idempotency_keys_prune_expired ON idempotency_keys FOR DELETE USING (expires_at < now())');
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
