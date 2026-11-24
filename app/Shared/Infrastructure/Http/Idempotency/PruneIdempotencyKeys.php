<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http\Idempotency;

use App\Shared\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;

/**
 * Deletes every tenant's expired idempotency keys. Scheduled nightly.
 *
 * Runs as the ordinary application role. The table's prune policy allows
 * DELETE of expired rows in any tenant, and only that, so the statement has
 * no WHERE clause: with no tenant set, the expiry policy alone decides which
 * rows go. (A WHERE clause would need read access, which the tenant policy
 * withholds across tenants.) With a tenant set, the tenant policy would also
 * match that tenant's live keys, so the command refuses to run that way.
 */
final class PruneIdempotencyKeys extends Command
{
    protected $signature = 'idempotency:prune';

    protected $description = 'Delete expired idempotency keys for all tenants';

    public function handle(TenantContext $tenant, DatabaseManager $db): int
    {
        if ($tenant->has()) {
            $this->components->error('Run without a tenant context: with one, live keys of that tenant would be deleted too.');

            return self::FAILURE;
        }

        $deleted = $db->connection()->delete('DELETE FROM idempotency_keys');

        $this->components->info(sprintf('Deleted %d expired idempotency key(s).', $deleted));

        return self::SUCCESS;
    }
}
