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
        Schema::create('products', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->string('status', 16);
            // Bumped on every change to the product, its variants or prices.
            // Exposed as the ETag and checked against If-Match on writes.
            $table->unsignedInteger('version')->default(1);
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            // Target of the composite foreign keys that keep children in the same tenant.
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'status', 'id']);
        });

        DB::statement("ALTER TABLE products ADD CONSTRAINT products_status_check CHECK (status IN ('draft', 'active', 'archived'))");
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_archived_check CHECK ((status = 'archived') = (archived_at IS NOT NULL))");

        TenantRls::enable('products');
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
