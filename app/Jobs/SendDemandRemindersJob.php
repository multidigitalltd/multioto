<?php

namespace App\Jobs;

use App\Enums\ChargeStatus;
use App\Models\Charge;
use App\Services\Billing\DemandDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Chase unpaid payment demands. A demand that's still pending after the
 * configured quiet interval is re-sent (same itemised breakdown + payment
 * options) on the channel it originally went out on, up to a maximum number of
 * reminders, then left alone. `demand_sent_at` doubles as "last contacted": it
 * is bumped on every reminder so they space out; `demand_reminder_count` caps
 * them. A demand that gets paid or canceled leaves the pending set and stops.
 */
class SendDemandRemindersJob implements ShouldQueue
{
    use Queueable;

    public function handle(DemandDispatcher $dispatcher): void
    {
        $maxReminders = (int) config('billing.demands.max_reminders', 2);

        if ($maxReminders < 1) {
            return; // Reminders disabled.
        }

        $interval = max(1, (int) config('billing.demands.reminder_interval_days', 3));
        $maxAgeDays = (int) config('billing.cardcom.reconcile_max_age_days', 14);

        Charge::query()
            ->with('customer')
            ->where('status', ChargeStatus::Pending)
            ->whereNotNull('demand_sent_at')
            ->where('demand_reminder_count', '<', $maxReminders)
            // Quiet interval since the last contact (initial send or last reminder).
            ->where('demand_sent_at', '<=', now()->subDays($interval))
            // Stop chasing a clearly abandoned demand.
            ->where('created_at', '>=', now()->subDays($maxAgeDays))
            ->orderBy('id')
            ->limit(100)
            ->get()
            ->each(function (Charge $charge) use ($dispatcher): void {
                try {
                    $dispatcher->send($charge, 'payment.reminder', (string) ($charge->demand_channel ?: 'email'), offerTransfer: true);

                    $charge->update([
                        'demand_sent_at' => now(),
                        'demand_reminder_count' => $charge->demand_reminder_count + 1,
                    ]);
                } catch (\Throwable $e) {
                    // One failed reminder must not stop the sweep.
                    Log::warning('SendDemandRemindersJob: reminder failed', [
                        'charge_id' => $charge->id,
                        'error' => Str::limit($e->getMessage(), 200),
                    ]);
                }
            });
    }
}
