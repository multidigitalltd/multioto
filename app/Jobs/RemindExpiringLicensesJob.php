<?php

namespace App\Jobs;

use App\Models\License;
use App\Models\SystemLog;
use App\Services\Notifications\TeamNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Tell the team which licences are about to lapse.
 *
 * A licence that expires quietly does not look like an expiry to the customer.
 * It looks like the plugin stopped updating — so what arrives is a support
 * ticket about a broken plugin, days after the renewal conversation could have
 * happened. This is the reminder that turns the second into the first.
 *
 * Licences that renew on a subscription are left out: their expiry moves when
 * the charge succeeds, and if that charge fails the dunning machine is already
 * chasing it. Two alerts for one problem trains people to read neither.
 */
class RemindExpiringLicensesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(TeamNotifier $team): void
    {
        $days = collect((array) config('licensing.expiry_reminder_days', [30, 7, 1]))
            ->map(fn ($d): int => (int) $d)
            ->filter(fn (int $d): bool => $d > 0)
            ->unique();

        if ($days->isEmpty()) {
            return;
        }

        // Narrowed in SQL to the widest reminder window, then matched to the
        // exact days in PHP. The column is a date and different drivers store
        // and compare it differently; a date-equality list in SQL is how a
        // reminder silently stops firing after a database move.
        $due = License::query()
            ->with(['product', 'customer'])
            ->where('status', License::ACTIVE)
            ->whereNull('subscription_id')
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '>=', now()->toDateString())
            ->whereDate('expires_at', '<=', now()->addDays($days->max())->toDateString())
            ->get()
            ->filter(fn (License $license): bool => $days->contains(
                (int) now()->startOfDay()->diffInDays($license->expires_at->startOfDay(), absolute: false)
            ))
            ->values();

        if ($due->isEmpty()) {
            return;
        }

        $lines = $due->map(function (License $license): string {
            $who = $license->customer?->name ?? $license->email ?? 'לקוח לא מזוהה';
            $left = (int) now()->startOfDay()->diffInDays($license->expires_at->startOfDay(), absolute: false);

            return "• {$who} — {$license->product?->name} — פג בעוד {$left} ימים ({$license->expires_at->format('d/m/Y')})";
        })->implode("\n");

        $team->alert(
            '🔑 '.$due->count().' רישיונות תוספים לקראת פקיעה',
            "אלה רישיונות שאינם מתחדשים אוטומטית. בפקיעה העדכונים ייעצרו — התוסף עצמו ימשיך לעבוד אצל הלקוח.\n\n{$lines}",
            rtrim((string) config('app.url'), '/').'/admin/licenses',
        );

        SystemLog::record('info', 'licensing', $due->count().' רישיונות לקראת פקיעה — נשלחה תזכורת לצוות.');
    }
}
