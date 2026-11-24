<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Shipments: reserved units leaving with a fulfilled order.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT stock_movements_type_check');
        DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_type_check CHECK (type IN ('adjustment', 'reservation', 'release', 'shipment'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT stock_movements_type_check');
        DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_type_check CHECK (type IN ('adjustment', 'reservation', 'release'))");
    }
};
