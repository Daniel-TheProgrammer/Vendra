<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Scheduled prices. A row with neither bound is the variant's base price for
 * that currency; a row with at least one bound applies only within
 * [valid_from, valid_to). See Domain\PriceList for which price wins.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Trusted since PostgreSQL 13: the database owner may install it, no
        // superuser needed. Required for "=" on uuid/char inside a GiST index.
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        Schema::table('variant_prices', function (Blueprint $table): void {
            $table->timestampTz('valid_from')->nullable();
            $table->timestampTz('valid_to')->nullable();
            $table->dropUnique(['variant_id', 'currency']);
        });

        DB::statement('ALTER TABLE variant_prices ADD CONSTRAINT variant_prices_window_check CHECK (valid_from IS NULL OR valid_to IS NULL OR valid_from < valid_to)');

        // At most one base price per variant and currency.
        DB::statement('CREATE UNIQUE INDEX variant_prices_base_unique ON variant_prices (variant_id, currency) WHERE valid_from IS NULL AND valid_to IS NULL');

        // Scheduled windows of one variant and currency never overlap. '[)'
        // matches the domain's half-open windows, so back-to-back is allowed;
        // NULL bounds are infinite.
        DB::statement(<<<'SQL'
            ALTER TABLE variant_prices ADD CONSTRAINT variant_prices_no_overlap
            EXCLUDE USING gist (variant_id WITH =, currency WITH =, tstzrange(valid_from, valid_to, '[)') WITH &&)
            WHERE (valid_from IS NOT NULL OR valid_to IS NOT NULL)
            SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE variant_prices DROP CONSTRAINT IF EXISTS variant_prices_no_overlap');
        DB::statement('DROP INDEX IF EXISTS variant_prices_base_unique');
        DB::statement('ALTER TABLE variant_prices DROP CONSTRAINT IF EXISTS variant_prices_window_check');

        // Only base prices fit the old one-price-per-currency shape.
        DB::table('variant_prices')->whereNotNull('valid_from')->orWhereNotNull('valid_to')->delete();

        Schema::table('variant_prices', function (Blueprint $table): void {
            $table->unique(['variant_id', 'currency']);
            $table->dropColumn(['valid_from', 'valid_to']);
        });
    }
};
