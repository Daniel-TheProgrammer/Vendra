<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Services;

use App\Shared\Tenancy\TenantContext;
use Illuminate\Database\ConnectionInterface;

/**
 * Per-tenant order numbers, starting at 1001.
 *
 * A single upsert increments the tenant's counter row and returns the new
 * value. The row stays locked until the placing transaction ends, so numbers
 * are unique and, because a failed placement rolls the increment back,
 * gap-free. The price is that placements within one tenant serialise on that
 * row: PlaceOrder therefore takes its number last, after the slow parts
 * (pricing, stock locks), keeping the window to the final inserts.
 */
final readonly class OrderNumbers
{
    public const FIRST = 1001;

    public function __construct(
        private ConnectionInterface $db,
        private TenantContext $tenant,
    ) {}

    public function next(): int
    {
        $row = $this->db->selectOne(
            'INSERT INTO order_number_sequences (tenant_id, last_number) VALUES (?, ?)
             ON CONFLICT (tenant_id) DO UPDATE SET last_number = order_number_sequences.last_number + 1
             RETURNING last_number',
            [$this->tenant->id(), self::FIRST],
        );

        return (int) $row->last_number;
    }
}
