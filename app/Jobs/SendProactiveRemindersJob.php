<?php

namespace App\Jobs;

use App\Enums\SubscriptionStatus;
use App\Enums\TokenStatus;
use App\Jobs\Concerns\PausesForShabbat;
use App\Models\PaymentToken;
use App\Models\Subscription;
use App\Services\Notifications\TeamNotifier;
use App\Support\Money;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

/**
 * Daily proactive digest to the team: renewals about to charge, saved cards
 * about to expire, and money already owed — so the owner sees what needs
 * attention before it becomes a failed charge or a lost customer.
 *
 * Internal only. It never contacts a customer: reaching out is the owner's
 * call (honouring the "no customer message without human approval" rule); this
 * job just surfaces the list. Sends nothing when there is nothing to report.
 */
class SendProactiveRemindersJob implements ShouldQueue
{
    use PausesForShabbat;
    use Queueable;

    public int $tries = 1;

    /** Short labels for the non-card payment methods in the digest. */
    private const MANUAL_METHOD_LABELS = [
        'standing_order' => 'הוראת קבע',
        'bank_transfer' => 'העברה',
        'checks' => 'צ׳קים',
    ];

    public function handle(TeamNotifier $team): void
    {
        if ($this->rescheduledForShabbat()) {
            return;
        }

        $renewals = $this->upcomingRenewals();
        $manual = $this->manualCollectionDue();
        $expiring = $this->expiringCards();
        $debt = $this->openDebt();

        $sections = array_filter([$renewals, $manual, $expiring, $debt]);

        if ($sections === []) {
            return; // Nothing to nag about today.
        }

        $team->alert('🔔 תזכורות יומיות', implode("\n\n", $sections));
    }

    /** Active subscriptions charging within the renewal window. */
    private function upcomingRenewals(): ?string
    {
        $days = (int) config('billing.reminders.renewal_days', 3);

        $subs = Subscription::query()
            ->where('status', SubscriptionStatus::Active)
            // Only auto-charged (card) subscriptions renew on their own; manual
            // ones are surfaced separately in "לגבייה ידנית".
            ->whereNotNull('token_id')
            ->whereNotNull('next_charge_at')
            ->whereBetween('next_charge_at', [now(), now()->addDays($days)])
            ->with(['customer', 'plan'])
            ->orderBy('next_charge_at')
            ->get();

        if ($subs->isEmpty()) {
            return null;
        }

        $lines = $subs->map(fn (Subscription $s): string => sprintf('• %s — %s ב-%s (%s)',
            $s->customer?->name ?? 'לקוח',
            $s->planName(),
            $s->next_charge_at->format('d/m'),
            Money::ils($s->totalChargeAgorot()),
        ));

        return "🔄 חידושים בקרוב ({$subs->count()}):\n".$lines->implode("\n");
    }

    /**
     * Manually-collected subscriptions (bank transfer / standing order / cheques)
     * whose payment is now due — so a hand-collected payment can't be forgotten.
     */
    private function manualCollectionDue(): ?string
    {
        $subs = Subscription::query()
            ->dueForManualCollection()
            ->with(['customer', 'plan'])
            ->orderBy('next_charge_at')
            ->get();

        if ($subs->isEmpty()) {
            return null;
        }

        $lines = $subs->take(10)->map(fn (Subscription $s): string => sprintf('• %s — %s (%s, מ-%s)',
            $s->customer?->name ?? 'לקוח',
            Money::ils($s->totalChargeAgorot()),
            self::MANUAL_METHOD_LABELS[$s->customer?->payment_method] ?? 'ידני',
            $s->next_charge_at->format('d/m'),
        ));

        $more = $subs->count() > 10 ? "\n… ועוד ".($subs->count() - 10) : '';

        return "🧾 לגבייה ידנית ({$subs->count()}) — לרשום תשלום ולהפיק חשבונית:\n".$lines->implode("\n").$more;
    }

    /** Active saved cards expiring this month or within the configured window. */
    private function expiringCards(): ?string
    {
        $months = (int) config('billing.reminders.card_expiry_months', 1);
        $cutoff = Carbon::now()->startOfMonth()->addMonths($months);

        $tokens = PaymentToken::query()
            ->where('status', TokenStatus::Active)
            ->whereNotNull('expiry_year')
            ->whereNotNull('expiry_month')
            ->with('customer')
            ->get()
            // A card expires at the end of its month; flag it once the end of
            // its month is at or before the window's end.
            ->filter(fn (PaymentToken $t): bool => Carbon::create((int) $t->expiry_year, (int) $t->expiry_month, 1)
                ->endOfMonth()->lessThanOrEqualTo($cutoff->copy()->endOfMonth()))
            ->sortBy(fn (PaymentToken $t): int => (int) $t->expiry_year * 12 + (int) $t->expiry_month)
            ->values();

        if ($tokens->isEmpty()) {
            return null;
        }

        $lines = $tokens->map(fn (PaymentToken $t): string => sprintf('• %s — כרטיס ...%s בתוקף עד %02d/%02d',
            $t->customer?->name ?? 'לקוח',
            $t->card_last4 ?? '????',
            (int) $t->expiry_month,
            (int) $t->expiry_year % 100,
        ));

        return "💳 כרטיסים שעומדים לפוג ({$tokens->count()}):\n".$lines->implode("\n");
    }

    /** Subscriptions in arrears (past-due / suspended) and the total owed. */
    private function openDebt(): ?string
    {
        $subs = Subscription::query()
            ->whereIn('status', [SubscriptionStatus::PastDue, SubscriptionStatus::Suspended])
            ->with(['customer', 'plan'])
            ->get();

        if ($subs->isEmpty()) {
            return null;
        }

        $total = $subs->sum(fn (Subscription $s): int => $s->totalChargeAgorot());

        $lines = $subs->take(10)->map(fn (Subscription $s): string => sprintf('• %s — %s (%s)',
            $s->customer?->name ?? 'לקוח',
            $s->status->getLabel(),
            Money::ils($s->totalChargeAgorot()),
        ));

        $more = $subs->count() > 10 ? "\n… ועוד ".($subs->count() - 10) : '';

        return sprintf("💰 חוב פתוח — %d לקוחות, סה\"כ %s:\n%s%s",
            $subs->count(), Money::ils($total), $lines->implode("\n"), $more);
    }
}
