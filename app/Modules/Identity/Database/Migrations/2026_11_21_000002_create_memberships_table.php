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
        Schema::create('memberships', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'user_id']);
            $table->index('user_id');
        });

        DB::statement("ALTER TABLE memberships ADD CONSTRAINT memberships_role_check CHECK (role IN ('owner', 'admin', 'staff', 'read_only'))");

        TenantRls::markPlatformTable('memberships', 'read before a tenant is chosen at login and across tenants to list a user\'s workspaces');
    }

    public function down(): void
    {
        Schema::dropIfExists('memberships');
    }
};
