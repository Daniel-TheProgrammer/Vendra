<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Audit;

use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Clock\Clock;
use App\Shared\Infrastructure\Http\Middleware\AssignRequestId;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Http\Request;
use Illuminate\Log\Context\Repository as Context;
use Illuminate\Support\Str;

final readonly class DatabaseAuditTrail implements AuditTrail
{
    public function __construct(
        private ConnectionResolverInterface $db,
        private Container $container,
        private Context $context,
        private Clock $clock,
    ) {}

    public function record(string $action, ?string $actorId = null, ?string $tenantId = null, array $metadata = []): void
    {
        $request = $this->container->bound('request') ? $this->container->make('request') : null;
        $request = $request instanceof Request ? $request : null;
        $requestId = $this->context->get(AssignRequestId::ATTRIBUTE);

        $this->db->connection()->table('audit_logs')->insert([
            'id' => Str::uuid7()->toString(),
            'action' => $action,
            'actor_id' => $actorId,
            'tenant_id' => $tenantId,
            'ip_address' => $request?->ip(),
            'user_agent' => $request instanceof Request ? Str::limit((string) $request->userAgent(), 500, '') : null,
            'request_id' => is_string($requestId) ? $requestId : null,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'created_at' => $this->clock->now(),
        ]);
    }
}
