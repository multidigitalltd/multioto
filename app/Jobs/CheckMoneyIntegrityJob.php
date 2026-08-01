<?php

namespace App\Jobs;

use App\Enums\ChargeStatus;
use App\Mail\NotificationMail;
use App\Models\Charge;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\SystemLog;
use App\Services\Calendar\ShabbatClock;
use App\Support\EmailList;
use App\Support\Money;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

/**
 * The daily "does the money still add up" pass.
 *
 * Every check here is one of the invariants the business rests on, asked from
 * the outside instead of trusted: a charge that took money has an invoice, an
 * invoice belongs to a charge that actually succeeded, the two agree on the
 * amount, a subscription whose date has passed was charged, and a charge is
 * either finished or failed rather than sitting in between.
 *
 * Each of those holds by construction — until a webhook is lost, a worker is
 * killed mid-flow, or Linet is down at the wrong moment. That is precisely when
 * nothing raises an error, because every individual step believed it had
 * succeeded.
 *
 * NOTHING is repaired here. The report says what looks wrong and a person
 * decides — automatic repair of money is how one bad assumption becomes a
 * second charge on a customer's card.
 */
class CheckMoneyIntegrityJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function handle(): void
    {
        $findings = array_values(array_filter([
            $this->chargedWithoutInvoice(),
            $this->invoicedWithoutSuccess(),
            $this->amountMismatch(),
            $this->overdueSubscriptions(),
            $this->stuckPendingCharges(),
        ]));

        if ($findings === []) {
            // Silence is the normal outcome. A daily "all clear" is a message
            // people stop reading, and then they stop reading the other one too.
            return;
        }

        // The mail carries a readable sample; the log below carries everything.
        $report = collect($findings)->map(
            fn (array $finding): string => "• {$finding['title']}\n  {$finding['preview']}"
        )->implode("\n\n");

        // The rows themselves, not just the headings: the mail is best-effort
        // (no team address configured, or a delivery that failed silently), so
        // this entry is often the only surviving copy — and a report that says
        // "3 charges without an invoice" without saying WHICH three sends the
        // reader looking for a needle it never handed them.
        SystemLog::record('error', 'billing', 'בדיקת שלמות כספית מצאה חריגות', [
            'findings' => $findings,
        ]);

        $this->email($report);
    }

    /**
     * Money taken, no document. The customer was charged and the business has
     * no invoice for it — a tax exposure that grows quietly.
     */
    private function chargedWithoutInvoice(): ?array
    {
        $grace = now()->subMinutes((int) config('health.money.invoice_grace_minutes', 120));
        $since = now()->subDays(max(1, (int) config('health.money.window_days', 14)));

        // BOTH bounds run off the moment the money actually came in, not off
        // the day the row was opened. A payment demand can sit unpaid for a
        // fortnight: the invoice job starts when it is PAID, so the opening
        // date would report every demand paid this morning — and would then
        // hide the one paid today whose invoice failed, because the row itself
        // is older than the lookback window.
        $rows = Charge::query()
            ->where('status', ChargeStatus::Succeeded)
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $paid) => $paid
                    ->whereNotNull('charged_at')
                    ->whereBetween('charged_at', [$since, $grace]))
                ->orWhere(fn (Builder $legacy) => $legacy
                    ->whereNull('charged_at')
                    ->whereBetween('created_at', [$since, $grace])))
            ->whereDoesntHave('invoice')
            ->orderBy('id')
            ->get(['id', 'customer_id', 'total_agorot', 'created_at']);

        return $this->finding(
            $rows,
            'חיובים שהצליחו ללא חשבונית',
            fn (Charge $charge): string => "חיוב #{$charge->id} · ".Money::ils($charge->total_agorot)
                .' · '.$charge->created_at->format('d/m/Y'),
        );
    }

    /**
     * A document for a charge that never went through. An invoice is issued only
     * after a successful charge, so this is a document the customer can hold
     * against money that was never taken.
     */
    private function invoicedWithoutSuccess(): ?array
    {
        $rows = $this->recent(Invoice::query())
            ->whereHas('charge', fn (Builder $query) => $query->whereNot('status', ChargeStatus::Succeeded))
            ->orderBy('id')
            ->get(['id', 'charge_id', 'linet_document_id', 'created_at']);

        return $this->finding(
            $rows,
            'חשבוניות שהונפקו על חיוב שלא הצליח',
            fn (Invoice $invoice): string => "חשבונית #{$invoice->id} (מסמך {$invoice->linet_document_id}) · חיוב #{$invoice->charge_id}",
        );
    }

    /** The document and the charge disagree on the amount. One of them is wrong. */
    private function amountMismatch(): ?array
    {
        $rows = $this->recent(Invoice::query())
            ->join('charges', 'charges.id', '=', 'invoices.charge_id')
            ->whereColumn('invoices.total_agorot', '!=', 'charges.total_agorot')
            ->orderBy('invoices.id')
            ->get(['invoices.id', 'invoices.charge_id', 'invoices.total_agorot', 'charges.total_agorot as charge_total']);

        return $this->finding(
            $rows,
            'פערי סכום בין חיוב לחשבונית',
            fn (Invoice $invoice): string => "חשבונית #{$invoice->id}: ".Money::ils((int) $invoice->total_agorot)
                ." · חיוב #{$invoice->charge_id}: ".Money::ils((int) $invoice->charge_total),
        );
    }

    /**
     * The date passed and nothing was charged. The dispatcher runs every
     * fifteen minutes, so a subscription still waiting hours later means the
     * pipeline — not the calendar — is stuck.
     */
    private function overdueSubscriptions(): ?array
    {
        // Outward automations pause for Shabbat and Yom Tov, and the charge
        // dispatcher is one of them. A renewal due at midnight is then hours
        // "late" by design, every rest day — and a report that cries wolf every
        // Saturday is a report nobody opens on Monday. The question is asked
        // again once the automations are awake.
        if (app(ShabbatClock::class)->isBlocked()) {
            return null;
        }

        $hours = (int) config('health.money.overdue_charge_hours', 6);

        $rows = Subscription::query()
            ->dueForCharge()
            ->where('next_charge_at', '<=', now()->subHours($hours))
            ->orderBy('next_charge_at')
            ->get(['id', 'customer_id', 'next_charge_at']);

        return $this->finding(
            $rows,
            'מנויים שעבר מועד החיוב שלהם ולא חויבו',
            fn (Subscription $subscription): string => "מנוי #{$subscription->id} · מועד: "
                .$subscription->next_charge_at->format('d/m/Y H:i'),
        );
    }

    /**
     * Neither confirmed nor failed. Reconciliation asks Cardcom about these
     * within its own window; one that is still pending a day later was missed.
     *
     * A payment demand is deliberately left "pending" while the customer takes
     * their time — that is what the due date is for, and unpaid demands are
     * chased by the reminders and shown in the aging report. Including them
     * here would list every ordinary open demand as a fault every morning, so
     * only charges nobody is waiting on a customer for are considered.
     */
    private function stuckPendingCharges(): ?array
    {
        $hours = (int) config('health.money.pending_charge_hours', 24);

        $rows = $this->recent(Charge::query())
            ->where('status', ChargeStatus::Pending)
            ->whereNull('demand_sent_at')
            ->whereNotNull('cardcom_low_profile_id')
            ->where('created_at', '<=', now()->subHours($hours))
            ->orderBy('id')
            ->get(['id', 'customer_id', 'total_agorot', 'created_at']);

        return $this->finding(
            $rows,
            'חיובים שנתקעו במצב "ממתין"',
            fn (Charge $charge): string => "חיוב #{$charge->id} · ".Money::ils($charge->total_agorot)
                .' · נפתח '.$charge->created_at->format('d/m/Y H:i'),
        );
    }

    /**
     * Only the recent past. An anomaly from six months ago was either handled
     * or accepted long ago, and repeating it every morning is how a report
     * teaches people to stop reading it.
     */
    private function recent(Builder $query): Builder
    {
        $days = (int) config('health.money.window_days', 14);
        $table = $query->getModel()->getTable();

        return $query->where("{$table}.created_at", '>=', now()->subDays(max(1, $days)));
    }

    /**
     * One finding, or null when there is nothing to say.
     *
     * Two renderings of the same rows: a short PREVIEW for the mail, which
     * nobody reads past a screenful, and the full DETAIL for the log — the
     * copy that survives when there is no team address or the delivery fails,
     * and the one the mail sends the reader to. A list capped at ten is a list
     * whose eleventh row nobody can ever find.
     *
     * The stored copy is bounded too, generously, and says so when it cuts:
     * silence about truncation reads as "that was all of them".
     *
     * @param  Collection<int, covariant \Illuminate\Database\Eloquent\Model>  $rows
     * @param  callable(mixed): string  $describe
     * @return array{title: string, detail: string, preview: string}|null
     */
    private function finding($rows, string $title, callable $describe): ?array
    {
        if ($rows->isEmpty()) {
            return null;
        }

        $described = $rows->map($describe);

        return [
            'title' => $title.' ('.$described->count().')',
            'detail' => $this->lines($described, (int) config('health.money.log_max_rows', 500), ' שורות נוספות לא נשמרו'),
            'preview' => $this->lines($described, (int) config('health.money.max_examples', 10), '…'),
        ];
    }

    /**
     * The rows as text, cut at $max and saying so.
     *
     * @param  Collection<int, string>  $described
     */
    private function lines($described, int $max, string $suffix): string
    {
        $shown = $described->take($max)->implode("\n  ");

        return $described->count() > $max
            ? $shown."\n  ועוד ".($described->count() - $max).$suffix
            : $shown;
    }

    /** Best-effort: a mail failure must not hide the log entry that is already recorded. */
    private function email(string $report): void
    {
        $to = EmailList::parse(config('billing.notifications.team_email'));

        if ($to === []) {
            return;
        }

        rescue(fn () => Mail::to($to)->send(new NotificationMail(
            'בדיקת שלמות כספית — נמצאו חריגות',
            "הבדיקה היומית מצאה פערים שדורשים בדיקה ידנית. שום דבר לא תוקן אוטומטית.\n\n"
                .$report
                ."\n\nהפרטים המלאים במסך \"מערכת ועדכונים\".",
        )), report: false);
    }
}
