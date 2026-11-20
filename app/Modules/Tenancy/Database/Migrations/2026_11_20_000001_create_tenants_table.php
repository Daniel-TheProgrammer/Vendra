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
        Schema::create('tenants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 120);
            $table->string('slug', 63)->unique();
            $table->string('plan', 32)->default('free');
            $table->string('status', 16)->default('active');
            $table->jsonb('settings')->default('{}');
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE tenants ADD CONSTRAINT tenants_plan_check CHECK (plan IN ('free', 'pro', 'enterprise'))");
        DB::statement("ALTER TABLE tenants ADD CONSTRAINT tenants_status_check CHECK (status IN ('active', 'suspended'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
