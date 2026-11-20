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
use App\Shared\Application\Transaction\TransactionRunner;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

final readonly class CreateTenant
{
    public function __construct(
        private TransactionRunner $transaction,
        private Clock $clock,
        private Dispatcher $events,
    ) {}

    public function __invoke(CreateTenantCommand $command): Tenant
    {
        $slug = $command->slug ?? Str::slug($command->name);

        if (preg_match(InvalidTenantSlug::PATTERN, $slug) !== 1 || strlen($slug) > InvalidTenantSlug::MAX_LENGTH) {
            throw InvalidTenantSlug::for($slug);
        }

        $tenant = $this->transaction->run(function () use ($command, $slug): Tenant {
            if (Tenant::query()->where('slug', $slug)->exists()) {
                throw TenantSlugTaken::for($slug);
            }

            try {
                return Tenant::query()->create([
                    'name' => $command->name,
                    'slug' => $slug,
                    'plan' => $command->plan,
                    'status' => TenantStatus::Active,
                    'settings' => [],
                ]);
            } catch (UniqueConstraintViolationException) {
                // Lost a race with a concurrent request for the same slug.
                throw TenantSlugTaken::for($slug);
            }
        });

        $this->events->dispatch(new TenantCreated($tenant->id, $tenant->slug, $this->clock->now()));

        return $tenant;
    }
}
