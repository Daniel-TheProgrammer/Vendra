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
        Schema::create('inventory_levels', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->uuid('variant_id')->unique();
            $table->integer('on_hand')->default(0);
            $table->integer('reserved')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'variant_id'])->references(['tenant_id', 'id'])->on('product_variants')->cascadeOnDelete();
        });

        // The invariants hold even if a code path forgets them:
        // nothing negative, and never more reserved than physically present.
        DB::statement('ALTER TABLE inventory_levels ADD CONSTRAINT inventory_levels_quantities_check CHECK (on_hand >= 0 AND reserved >= 0 AND reserved <= on_hand)');

        TenantRls::enable('inventory_levels');

        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->uuid('variant_id');
            $table->string('type', 16);
            // Adjustments are signed deltas of on_hand; reservations and
            // releases are positive quantities moved in or out of reserved.
            $table->integer('quantity');
            $table->string('reason', 32)->nullable();
            $table->string('reference', 100)->nullable();
            $table->string('note', 500)->nullable();
            $table->integer('on_hand_after');
            $table->integer('reserved_after');
            $table->uuid('actor_id')->nullable();
            $table->timestampTz('created_at');

            $table->foreign(['tenant_id', 'variant_id'])->references(['tenant_id', 'id'])->on('product_variants')->restrictOnDelete();
            $table->index(['variant_id', 'id']);
            $table->index(['tenant_id', 'reference']);
        });

        DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_type_check CHECK (type IN ('adjustment', 'reservation', 'release'))");
        DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_quantity_check CHECK (quantity <> 0 AND (type = 'adjustment' OR quantity > 0))");

        // Append-only: the movement history is the audit trail for every unit,
        // so the database refuses to rewrite or delete it. OR REPLACE because
        // migrate:fresh drops tables but leaves functions behind.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION stock_movements_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'stock_movements is append-only';
            END;
            $$;

            CREATE TRIGGER stock_movements_append_only
                BEFORE UPDATE OR DELETE ON stock_movements
                FOR EACH ROW EXECUTE FUNCTION stock_movements_append_only();
        SQL);

        TenantRls::enable('stock_movements');
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        DB::unprepared('DROP FUNCTION IF EXISTS stock_movements_append_only()');
        Schema::dropIfExists('inventory_levels');
    }
};
