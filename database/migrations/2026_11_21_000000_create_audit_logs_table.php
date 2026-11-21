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
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('action', 64);
            // No foreign keys: the trail must outlive the users and tenants it mentions.
            $table->uuid('actor_id')->nullable();
            $table->uuid('tenant_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('request_id', 64)->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampTz('created_at');

            $table->index(['tenant_id', 'created_at']);
            $table->index(['actor_id', 'created_at']);
            $table->index('action');
        });

        TenantRls::markPlatformTable('audit_logs', 'security trail written before a tenant is known (failed logins) and read by platform operators');
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
