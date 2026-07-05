<?php

use App\Enums\BroadcastStatus;
use App\Jobs\ChargeSubscriptionJob;
use App\Jobs\MonitorSiteJob;
use App\Jobs\SendBroadcastJob;
use App\Models\Broadcast;
use App\Models\Site;
use App\Models\Subscription;
use Illuminate\Support\Facades\Schedule;

/*
 | All background work is dispatched from here as queued jobs — the scheduler
 | itself only enqueues (§1). Run with `php artisan schedule:work` (dev) or a
 | single system cron entry (prod).
 */

// Billing: enqueue a charge for every subscription that is due. The job holds
// a per-subscription lock and re-checks the due date, so double dispatch is safe.
Schedule::call(function () {
    Subscription::query()
        ->dueForCharge()
        ->pluck('id')
        ->each(fn (int $id) => ChargeSubscriptionJob::dispatch($id));
})->everyFifteenMinutes()->name('billing:dispatch-due-charges')->onOneServer();

// Uptime monitoring.
Schedule::call(function () {
    Site::query()
        ->where('monitor_enabled', true)
        ->pluck('id')
        ->each(fn (int $id) => MonitorSiteJob::dispatch($id));
})->cron('*/'.(int) config('billing.monitoring.interval_minutes').' * * * *')
    ->name('monitoring:dispatch-checks')->onOneServer();

// Scheduled broadcasts.
Schedule::call(function () {
    Broadcast::query()
        ->where('status', BroadcastStatus::Scheduled)
        ->where('scheduled_at', '<=', now())
        ->pluck('id')
        ->each(fn (int $id) => SendBroadcastJob::dispatch($id));
})->everyFiveMinutes()->name('broadcasts:dispatch-scheduled')->onOneServer();

// Horizon metrics snapshot.
Schedule::command('horizon:snapshot')->everyFiveMinutes();
