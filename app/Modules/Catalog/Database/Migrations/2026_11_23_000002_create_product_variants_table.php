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
        Schema::create('product_variants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->uuid('product_id');
            $table->string('sku', 64);
            $table->string('name', 200)->nullable();
            $table->jsonb('options')->default('{}');
            $table->timestampsTz();

            // Including tenant_id in the key makes a variant pointing at another
            // tenant's product impossible, whatever the application does.
            $table->foreign(['tenant_id', 'product_id'])->references(['tenant_id', 'id'])->on('products')->cascadeOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'sku']);
            $table->index('product_id');
        });

        TenantRls::enable('product_variants');
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
