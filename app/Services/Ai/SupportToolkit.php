<?php

namespace App\Services\Ai;

use App\Enums\ChargeStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Charge;
use App\Models\Customer;
use App\Support\Money;
use Illuminate\Support\Facades\URL;

/**
 * Read-only facts about a customer, gathered so the AI can answer support
 * questions with real data instead of guessing — account status, billing,
 * uptime and the latest invoice, plus a ready card-update link.
 *
 * Everything here is a plain database read: no charges, no messages, no state
 * changes. The card-update link is a short-lived signed URL — it is only ever
 * placed in an internal draft, and reaches the customer only if a human agent
 * approves and sends that draft (change actions stay human-approved).
 */
class SupportToolkit
{
    /**
     * A compact Hebrew "facts sheet" for the customer, embedded into the AI
     * draft prompt. Returns an empty string when there's nothing useful.
     */
    public function factsFor(Customer $customer): string
    {
        $lines = [];

        $account = $this->accountSummary($customer);
        if ($account !== []) {
            $lines[] = '— מנויים —';
            foreach ($account as $row) {
                $lines[] = $row;
            }
        }

        $billing = $this->billingInfo($customer);
        if ($billing !== []) {
            $lines[] = '— חיוב —';
            foreach ($billing as $row) {
                $lines[] = $row;
            }
        }

        $sites = $this->siteStatus($customer);
        if ($sites !== []) {
            $lines[] = '— אתרים —';
            foreach ($sites as $row) {
                $lines[] = $row;
            }
        }

        $invoice = $this->latestInvoice($customer);
        if ($invoice !== null) {
            $lines[] = '— חשבונית אחרונה —';
            $lines[] = $invoice;
        }

        // Card-update link — for use ONLY if the customer asked about payment.
        $lines[] = '— קישור לעדכון כרטיס (רק אם הלקוח ביקש) —';
        $lines[] = $this->cardUpdateLink($customer);

        return implode("\n", $lines);
    }

    /** @return array<int, string> one line per subscription */
    public function accountSummary(Customer $customer): array
    {
        return $customer->subscriptions()
            ->with('plan')
            ->whereNot('status', SubscriptionStatus::Canceled)
            ->get()
            ->map(function ($sub): string {
                $next = $sub->next_charge_at?->format('d/m/Y') ?? '—';
                $amount = Money::ils($sub->totalChargeAgorot());

                return "תוכנית {$sub->planName()}: סטטוס {$sub->status->getLabel()}, חיוב הבא {$next} ({$amount})";
            })
            ->all();
    }

    /** @return array<int, string> */
    public function billingInfo(Customer $customer): array
    {
        $lastCharge = Charge::query()
            ->whereIn('subscription_id', $customer->subscriptions()->select('id'))
            ->latest('id')
            ->first();

        if (! $lastCharge) {
            return [];
        }

        $out = [];
        $when = $lastCharge->charged_at?->format('d/m/Y') ?? $lastCharge->created_at->format('d/m/Y');
        $amount = Money::ils($lastCharge->total_agorot);
        $status = $lastCharge->status->getLabel();
        $out[] = "חיוב אחרון: {$amount} בתאריך {$when} — {$status}";

        if ($lastCharge->status === ChargeStatus::Failed) {
            $out[] = 'שים לב: החיוב האחרון נכשל.';
        }

        $pastDue = $customer->subscriptions()
            ->where('status', SubscriptionStatus::PastDue)
            ->max('dunning_stage');

        if ($pastDue) {
            $out[] = "יש מנוי בפיגור תשלום (שלב גבייה {$pastDue}).";
        }

        return $out;
    }

    /**
     * One line per site, enriched with the real last-observed diagnostics the
     * monitor already recorded (the actual HTTP code / error, SSL days-left, a
     * slow-response flag) — so the AI answers with the concrete symptom instead
     * of a vague "the site is down". All from the database: no live probe runs
     * in the draft path.
     *
     * @return array<int, string> one line per site
     */
    public function siteStatus(Customer $customer): array
    {
        $warnDays = (int) config('billing.monitoring.ssl_warn_days', 14);

        return $customer->sites()
            ->with(['openIncident'])
            ->get()
            ->map(function ($site) use ($warnDays): string {
                $lastCheck = $site->monitorChecks()->latest('checked_at')->first();
                $isDown = $site->openIncident !== null || ($lastCheck && ! $lastCheck->is_up);

                // The concrete failure the monitor last saw.
                $details = [];
                if ($isDown && $lastCheck) {
                    if ($lastCheck->status_code) {
                        $details[] = "HTTP {$lastCheck->status_code}";
                    }
                    if (filled($lastCheck->error)) {
                        $details[] = $lastCheck->error;
                    }
                }

                // TLS certificate about to expire / already expired.
                if ($site->ssl_days_left !== null) {
                    if ($site->ssl_days_left <= 0) {
                        $details[] = 'תעודת SSL פגה';
                    } elseif ($site->ssl_days_left <= $warnDays) {
                        $details[] = "תעודת SSL בתוקף עוד {$site->ssl_days_left} ימים";
                    }
                }

                // Up, but responding slowly.
                if (! $isDown && $site->slow_alerted_at !== null) {
                    $details[] = 'זמן תגובה איטי';
                }

                if ($site->openIncident) {
                    $since = $site->openIncident->started_at?->format('d/m/Y H:i') ?? '';
                    array_unshift($details, "תקלה פתוחה מאז {$since}");

                    return "{$site->domain}: ⚠️ למטה (".implode(', ', $details).')';
                }

                $suffix = $details !== [] ? ' ('.implode(', ', $details).')' : '';

                if ($lastCheck) {
                    return "{$site->domain}: ".($lastCheck->is_up ? '✅ פעיל' : '⚠️ לא זמין בבדיקה האחרונה').$suffix;
                }

                return "{$site->domain}: אין נתוני ניטור עדיין";
            })
            ->all();
    }

    public function latestInvoice(Customer $customer): ?string
    {
        $invoice = $customer->invoices()->latest('issued_at')->first();

        if (! $invoice) {
            return null;
        }

        $total = Money::ils($invoice->total_agorot);
        $date = $invoice->issued_at?->format('d/m/Y') ?? '';
        $pdf = $invoice->pdf_url ? " (קובץ: {$invoice->pdf_url})" : '';

        return "מסמך {$invoice->linet_document_id}: {$total} בתאריך {$date}{$pdf}";
    }

    /**
     * A short-lived signed card-update link for the customer. Kept identical to
     * the dunning/onboarding link so the same hosted Cardcom page handles it.
     */
    public function cardUpdateLink(Customer $customer): string
    {
        return URL::temporarySignedRoute(
            'billing.update-card',
            now()->addHours((int) config('billing.card_update_link_ttl_hours')),
            ['customer' => $customer->id],
        );
    }
}
