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
        Schema::create('variant_prices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->uuid('variant_id');
            $table->char('currency', 3);
            // Minor units of the currency (cents for USD, yen for JPY). Never a float.
            $table->bigInteger('amount');
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'variant_id'])->references(['tenant_id', 'id'])->on('product_variants')->cascadeOnDelete();
            $table->unique(['variant_id', 'currency']);
        });

        DB::statement('ALTER TABLE variant_prices ADD CONSTRAINT variant_prices_amount_check CHECK (amount >= 0)');

        TenantRls::enable('variant_prices');
    }

    public function down(): void
    {
        Schema::dropIfExists('variant_prices');
    }
};
