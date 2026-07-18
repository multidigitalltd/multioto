<?php

use App\Enums\BroadcastStatus;
use App\Enums\ChargeStatus;
use App\Jobs\ChargeSubscriptionJob;
use App\Jobs\CheckDomainExpiryJob;
use App\Jobs\CheckSslExpiryJob;
use App\Jobs\FollowUpPendingTicketsJob;
use App\Jobs\MonitorSiteJob;
use App\Jobs\ReconcileChargeJob;
use App\Jobs\SendBroadcastJob;
use App\Jobs\SendDemandRemindersJob;
use App\Jobs\SendProactiveRemindersJob;
use App\Jobs\SendTaskRemindersJob;
use App\Models\Broadcast;
use App\Models\Charge;
use App\Models\Site;
use App\Models\Subscription;
use App\Models\SystemLog;
use App\Services\Calendar\ShabbatClock;
use Illuminate\Support\Facades\Schedule;

/*
 | All background work is dispatched from here as queued jobs — the scheduler
 | itself only enqueues (§1). Run with `php artisan schedule:work` (dev) or a
 | single system cron entry (prod).
 */

// Outward automations (charges, dunning, reminders, broadcasts, digests) pause
// over Shabbat and Yom Tov and resume the day after — attach ->when($awake) to
// each. Monitoring and internal safety jobs keep running.
$awake = fn (): bool => ! app(ShabbatClock::class)->isBlocked();

// Billing: enqueue a charge for every subscription that is due. The job holds
// a per-subscription lock and re-checks the due date, so double dispatch is safe.
Schedule::call(function () {
    Subscription::query()
        ->dueForCharge()
        ->pluck('id')
        ->each(fn (int $id) => ChargeSubscriptionJob::dispatch($id));
})->everyFifteenMinutes()->name('billing:dispatch-due-charges')->when($awake)->onOneServer();

// Reconcile manual charges left "pending": if Cardcom actually charged the card
// but we never recorded the result (lost webhook / crashed job), finalise the
// charge and issue its invoice. Cardcom is the source of truth; a card is never
// re-charged. Covers saved-token and hosted (walk-in) charges/demands. A bounded
// age window gives the webhook a moment first and stops chasing an abandoned
// (e.g. never-paid) demand forever.
Schedule::call(function () {
    Charge::query()
        ->where('status', ChargeStatus::Pending)
        ->whereNull('subscription_id')       // manual/one-off charges only
        ->whereNotNull('customer_id')
        ->where('created_at', '<=', now()->subMinutes((int) config('billing.cardcom.reconcile_after_minutes', 15)))
        ->where('created_at', '>=', now()->subDays((int) config('billing.cardcom.reconcile_max_age_days', 14)))
        ->pluck('id')
        ->each(fn (int $id) => ReconcileChargeJob::dispatch($id));
})->everyThreeMinutes()->name('billing:reconcile-pending-charges')->onOneServer();

// Uptime monitoring.
Schedule::call(function () {
    Site::query()
        ->where('monitor_enabled', true)
        ->pluck('id')
        ->each(fn (int $id) => MonitorSiteJob::dispatch($id));
})->cron('*/'.(int) config('billing.monitoring.interval_minutes').' * * * *')
    ->name('monitoring:dispatch-checks')->onOneServer();

// Daily TLS-certificate expiry check for every monitored site.
Schedule::call(function () {
    Site::query()
        ->where('monitor_enabled', true)
        ->pluck('id')
        ->each(fn (int $id) => CheckSslExpiryJob::dispatch($id));
})->dailyAt('07:00')->name('monitoring:ssl-expiry')->onOneServer();

// Daily domain-registration expiry check (RDAP) for every monitored site.
Schedule::call(function () {
    Site::query()
        ->where('monitor_enabled', true)
        ->pluck('id')
        ->each(fn (int $id) => CheckDomainExpiryJob::dispatch($id));
})->dailyAt('07:15')->name('monitoring:domain-expiry')->onOneServer();

// Proactive reminders: a once-a-day internal digest (renewals due, cards
// expiring, open debt) so the owner can act before anything slips.
Schedule::job(new SendProactiveRemindersJob)
    ->dailyAt('08:00')->name('reminders:daily-digest')->when($awake)->onOneServer();

// Chase tickets stuck "waiting for customer": remind once after reminder_days
// of silence, then auto-close after close_days. Timings in config/billing.php.
Schedule::job(new FollowUpPendingTicketsJob)
    ->dailyAt('09:00')->name('support:pending-followup')->when($awake)->onOneServer();

// Chase unpaid payment demands: after the quiet interval, resend the request
// (link/transfer) up to the configured maximum, then stop.
Schedule::job(new SendDemandRemindersJob)
    ->dailyAt('10:00')->name('billing:demand-reminders')->when($awake)->onOneServer();

// Daily task reminders: email each team member their open tasks due today or
// overdue (once per task; the clock resets on reschedule/reopen).
Schedule::job(new SendTaskRemindersJob)
    ->dailyAt((string) config('billing.support.task_reminders.time', '08:30'))
    ->name('support:task-reminders')->when($awake)->onOneServer();

// Scheduled broadcasts.
Schedule::call(function () {
    Broadcast::query()
        ->where('status', BroadcastStatus::Scheduled)
        ->where('scheduled_at', '<=', now())
        ->pluck('id')
        ->each(fn (int $id) => SendBroadcastJob::dispatch($id));
})->everyFiveMinutes()->name('broadcasts:dispatch-scheduled')->when($awake)->onOneServer();

// Horizon metrics snapshot.
Schedule::command('horizon:snapshot')->everyFiveMinutes();

// Prune the in-panel system log ("מערכת ועדכונים") so it self-cleans.
Schedule::call(fn () => SystemLog::prune((int) config('billing.system.log_retention_days', 30)))
    ->dailyAt('03:00')->name('system:prune-logs')->onOneServer();
