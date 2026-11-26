<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application\Actions;

use App\Modules\Tenancy\Application\Commands\CreateTenantCommand;
use App\Modules\Tenancy\Domain\Events\TenantCreated;
use App\Modules\Tenancy\Domain\InvalidTenantSlug;
use App\Modules\Tenancy\Domain\TenantSlugTaken;
use App\Modules\Tenancy\Domain\TenantStatus;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use App\Shared\Application\Clock\Clock;
use App\Shared\Application\Event\EventRecorder;
use App\Shared\Application\Transaction\TransactionRunner;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

final readonly class CreateTenant
{
    public function __construct(
        private TransactionRunner $transaction,
        private Clock $clock,
        private EventRecorder $events,
    ) {}

    public function __invoke(CreateTenantCommand $command): Tenant
    {
        $slug = $command->slug ?? Str::slug($command->name);

        if (preg_match(InvalidTenantSlug::PATTERN, $slug) !== 1 || strlen($slug) > InvalidTenantSlug::MAX_LENGTH) {
            throw InvalidTenantSlug::for($slug);
        }

        return $this->transaction->run(function () use ($command, $slug): Tenant {
            if (Tenant::query()->where('slug', $slug)->exists()) {
                throw TenantSlugTaken::for($slug);
            }

            try {
                // Own savepoint: a lost race must not abort the caller's transaction.
                $tenant = $this->transaction->run(static fn (): Tenant => Tenant::query()->create([
                    'name' => $command->name,
                    'slug' => $slug,
                    'plan' => $command->plan,
                    'status' => TenantStatus::Active,
                    'settings' => [],
                ]));
            } catch (UniqueConstraintViolationException) {
                // Lost a race with a concurrent request for the same slug.
                throw TenantSlugTaken::for($slug);
            }

            $this->events->record(new TenantCreated($tenant->id, $tenant->slug, $this->clock->now()));

            return $tenant;
        });
    }
}
