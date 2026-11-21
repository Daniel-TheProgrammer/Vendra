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
        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuidMorphs('tokenable');
            $table->tenantId();
            $table->string('name', 120);
            $table->string('token', 64)->unique();
            $table->jsonb('abilities')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('expires_at')->nullable()->index();
            $table->timestampsTz();
        });

        TenantRls::markPlatformTable('personal_access_tokens', 'looked up by hash to authenticate a request, before the tenant is known');
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
