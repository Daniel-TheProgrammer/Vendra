<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Persistence;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;

/**
 * Schema vocabulary shared by every module's migrations.
 */
final class BlueprintMacros
{
    public static function register(): void
    {
        /*
         * $table->tenantId();
         *
         * Adds the tenant_id column, its foreign key to tenants and an index.
         * Deleting a tenant with data is refused at the database level; tenant
         * off-boarding is an explicit, audited process.
         */
        Blueprint::macro('tenantId', function (): ColumnDefinition {
            /** @var Blueprint $this */
            $column = $this->uuid('tenant_id');

            $this->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $this->index('tenant_id');

            return $column;
        });
    }
}
