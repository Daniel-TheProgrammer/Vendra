<?php

declare(strict_types=1);

namespace App\Modules\Ledger;

use App\Modules\Ledger\Console\ReconcileLedgerCommand;
use App\Modules\Ledger\Infrastructure\Listeners\BookOrderLifecycle;
use App\Modules\Ledger\Infrastructure\Listeners\BookPayments;
use App\Modules\Ledger\Infrastructure\Listeners\OpenChartOfAccounts;
use App\Modules\Orders\Domain\Events\OrderCancelled;
use App\Modules\Orders\Domain\Events\OrderPlaced;
use App\Modules\Payments\Domain\Events\PaymentCaptured;
use App\Modules\Tenancy\Domain\Events\TenantCreated;
use App\Shared\Infrastructure\Module\ModuleServiceProvider;
use Illuminate\Support\Facades\Event;

final class LedgerServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Ledger';
    }

    protected function moduleCommands(): array
    {
        return [ReconcileLedgerCommand::class];
    }

    public function boot(): void
    {
        parent::boot();

        Event::listen(TenantCreated::class, OpenChartOfAccounts::class);
        Event::listen([OrderPlaced::class, OrderCancelled::class], BookOrderLifecycle::class);
        Event::listen(PaymentCaptured::class, BookPayments::class);
    }
}
