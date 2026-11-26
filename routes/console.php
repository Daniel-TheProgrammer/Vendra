<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled tasks
|--------------------------------------------------------------------------
|
| Run by the "scheduler" container (php artisan schedule:work). Every task is
| idempotent and guarded against overlapping runs and multiple servers.
|
*/

Schedule::command('idempotency:prune')
    ->dailyAt('03:15')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('ledger:reconcile')
    ->dailyAt('02:30')
    ->withoutOverlapping()
    ->onOneServer();

// After ledger:reconcile, so a payments discrepancy is never a ledger drift.
Schedule::command('payments:reconcile')
    ->dailyAt('02:45')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('outbox:prune')
    ->dailyAt('03:30')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('api-keys:notify-expiring')
    ->dailyAt('08:00')
    ->withoutOverlapping()
    ->onOneServer();
