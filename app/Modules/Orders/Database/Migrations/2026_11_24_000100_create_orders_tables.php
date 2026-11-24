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
        Schema::create('customers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->tenantId();
            // Stored lower-cased: one customer per address, however it is typed.
            $table->string('email', 254);
            $table->string('name', 200)->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'email']);
        });

        DB::statement('ALTER TABLE customers ADD CONSTRAINT customers_email_lower_check CHECK (email = lower(email))');

        // One counter per tenant. Order numbers are unique per tenant and,
        // because the increment rolls back with a failed placement, gap-free.
        Schema::create('order_number_sequences', function (Blueprint $table): void {
            $table->uuid('tenant_id')->primary();
            $table->unsignedBigInteger('last_number');

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        Schema::create('orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->unsignedBigInteger('number');
            $table->uuid('customer_id');
            $table->string('status', 24);
            $table->char('currency', 3);
            $table->bigInteger('subtotal');
            $table->bigInteger('tax');
            $table->bigInteger('total');
            $table->jsonb('shipping_address')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->uuid('placed_by')->nullable();
            $table->timestampTz('placed_at');
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampTz('fulfilled_at')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('customers');
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status', 'id']);
            $table->index(['tenant_id', 'customer_id', 'id']);
            $table->index(['tenant_id', 'placed_at']);
        });

        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN ('pending', 'awaiting_payment', 'paid', 'fulfilled', 'cancelled', 'refunded'))");
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_amounts_check CHECK (subtotal >= 0 AND tax >= 0 AND total = subtotal + tax)');

        // A snapshot of what was sold, at the price it was sold for. Later
        // catalog changes never rewrite an order.
        Schema::create('order_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->uuid('order_id');
            $table->unsignedSmallInteger('position');
            $table->uuid('variant_id');
            $table->string('sku', 64);
            $table->string('name', 401);
            $table->bigInteger('unit_price');
            $table->unsignedInteger('quantity');
            $table->bigInteger('line_total');

            $table->foreign(['tenant_id', 'order_id'])->references(['tenant_id', 'id'])->on('orders')->cascadeOnDelete();
            // No foreign key to the variant: the line must survive the catalog.
            $table->unique(['order_id', 'position']);
        });

        DB::statement('ALTER TABLE order_lines ADD CONSTRAINT order_lines_amounts_check CHECK (quantity > 0 AND unit_price >= 0 AND line_total = unit_price * quantity)');

        Schema::create('order_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->uuid('order_id');
            $table->string('type', 40);
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->jsonb('data')->default('{}');
            $table->uuid('actor_id')->nullable();
            $table->timestampTz('created_at');

            $table->foreign(['tenant_id', 'order_id'])->references(['tenant_id', 'id'])->on('orders')->restrictOnDelete();
            $table->index(['order_id', 'id']);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION order_events_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'order_events is append-only: % is not allowed', TG_OP USING ERRCODE = 'insufficient_privilege';
            END;
            $$;
            CREATE TRIGGER order_events_append_only BEFORE UPDATE OR DELETE ON order_events
                FOR EACH ROW EXECUTE FUNCTION order_events_append_only();
            SQL);

        foreach (['customers', 'order_number_sequences', 'orders', 'order_lines', 'order_events'] as $table) {
            TenantRls::enable($table);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('order_events');
        DB::unprepared('DROP FUNCTION IF EXISTS order_events_append_only()');
        Schema::dropIfExists('order_lines');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('order_number_sequences');
        Schema::dropIfExists('customers');
    }
};
