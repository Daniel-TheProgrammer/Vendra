<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('name', 120);
            // Public lookup part of the key ("vendra_sk_" + 8 chars); safe to display.
            $table->string('prefix', 32)->unique();
            // SHA-256 of the full key. The key itself is never stored.
            $table->char('secret_hash', 64);
            $table->jsonb('scopes');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('rotated_from_id')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('expires_at')->nullable()->index();
            $table->timestampTz('expiry_notified_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'created_at']);
        });

        // Self-reference in a second step: inside create() Laravel would emit this
        // constraint before the primary key it points at exists.
        Schema::table('api_keys', function (Blueprint $table): void {
            $table->foreign('rotated_from_id')->references('id')->on('api_keys')->nullOnDelete();
        });

        TenantRls::markPlatformTable('api_keys', 'looked up by prefix to authenticate a request, before the tenant is known');
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
