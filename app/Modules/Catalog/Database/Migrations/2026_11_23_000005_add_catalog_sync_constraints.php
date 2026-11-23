<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Serves GET /products?updated_since=..., the incremental sync query.
        Schema::table('products', function (Blueprint $table): void {
            $table->index(['tenant_id', 'updated_at']);
        });

        // SKUs are stored in canonical form only (see Domain\Sku). NOT VALID
        // enforces the rule for every new or changed row without a full scan
        // under an exclusive lock; rows written before it existed are left as
        // they are.
        DB::statement('ALTER TABLE product_variants ADD CONSTRAINT product_variants_sku_canonical_check CHECK (sku = upper(btrim(sku))) NOT VALID');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE product_variants DROP CONSTRAINT IF EXISTS product_variants_sku_canonical_check');

        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'updated_at']);
        });
    }
};
