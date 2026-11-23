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
        Schema::create('product_imports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('status', 16);
            $table->string('filename', 255);
            $table->string('disk', 32);
            $table->string('path', 255);
            // Known at upload time; the counters below only ever move towards it.
            $table->unsignedInteger('total_rows');
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);
            $table->unsignedInteger('created_products')->default(0);
            $table->text('failure')->nullable();
            $table->uuid('actor_id')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'id']);
        });

        DB::statement("ALTER TABLE product_imports ADD CONSTRAINT product_imports_status_check CHECK (status IN ('pending', 'processing', 'completed', 'failed'))");
        DB::statement('ALTER TABLE product_imports ADD CONSTRAINT product_imports_progress_check CHECK (processed_rows <= total_rows AND failed_rows <= processed_rows)');

        // One row per product group (all CSV rows sharing a handle), written
        // in the same transaction as the product it created. It is what
        // makes the job resumable: after a crash, groups found here are done
        // and are never imported twice.
        Schema::create('product_import_groups', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->uuid('import_id');
            $table->string('handle', 100);
            $table->unsignedInteger('first_row');
            $table->unsignedInteger('row_count');
            $table->string('outcome', 16);
            $table->uuid('product_id')->nullable();
            $table->jsonb('errors')->nullable();
            $table->timestampTz('created_at');

            $table->foreign(['tenant_id', 'import_id'])->references(['tenant_id', 'id'])->on('product_imports')->cascadeOnDelete();
            $table->unique(['import_id', 'handle']);
            $table->index(['import_id', 'outcome', 'first_row']);
        });

        DB::statement("ALTER TABLE product_import_groups ADD CONSTRAINT product_import_groups_outcome_check CHECK ((outcome = 'created' AND product_id IS NOT NULL) OR (outcome = 'failed' AND product_id IS NULL AND errors IS NOT NULL))");

        TenantRls::enable('product_imports');
        TenantRls::enable('product_import_groups');
    }

    public function down(): void
    {
        Schema::dropIfExists('product_import_groups');
        Schema::dropIfExists('product_imports');
    }
};
