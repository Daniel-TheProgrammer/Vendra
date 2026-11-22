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
        Schema::create('invitations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('email', 254);
            $table->string('role', 16);
            // SHA-256 of the token mailed to the invitee; the token is never stored.
            $table->char('token_hash', 64)->unique();
            $table->foreignUuid('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE invitations ADD CONSTRAINT invitations_role_check CHECK (role IN ('owner', 'admin', 'staff', 'read_only'))");

        // At most one open invitation per address and tenant.
        DB::statement('CREATE UNIQUE INDEX invitations_open_unique ON invitations (tenant_id, email) WHERE accepted_at IS NULL AND revoked_at IS NULL');

        TenantRls::markPlatformTable('invitations', 'looked up by token hash when an invitee accepts, before any tenant context exists');
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
