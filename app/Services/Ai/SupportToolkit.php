<?php

namespace App\Services\Ai;

use App\Enums\ChargeStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Charge;
use App\Models\Customer;
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
                $amount = number_format($sub->totalChargeAgorot() / 100, 2);

                return "תוכנית {$sub->plan->name}: סטטוס {$sub->status->getLabel()}, חיוב הבא {$next} (₪{$amount})";
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
        $amount = number_format($lastCharge->total_agorot / 100, 2);
        $status = $lastCharge->status->getLabel();
        $out[] = "חיוב אחרון: ₪{$amount} בתאריך {$when} — {$status}";

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

    /** @return array<int, string> one line per site */
    public function siteStatus(Customer $customer): array
    {
        return $customer->sites()
            ->with(['openIncident'])
            ->get()
            ->map(function ($site): string {
                $lastCheck = $site->monitorChecks()->latest('checked_at')->first();

                if ($site->openIncident) {
                    $since = $site->openIncident->started_at?->format('d/m/Y H:i') ?? '';

                    return "{$site->domain}: ⚠️ למטה (תקלה פתוחה מאז {$since})";
                }

                if ($lastCheck) {
                    return "{$site->domain}: ".($lastCheck->is_up ? '✅ פעיל' : '⚠️ לא זמין בבדיקה האחרונה');
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

        $total = number_format($invoice->total_agorot / 100, 2);
        $date = $invoice->issued_at?->format('d/m/Y') ?? '';
        $pdf = $invoice->pdf_url ? " (קובץ: {$invoice->pdf_url})" : '';

        return "מסמך {$invoice->linet_document_id}: ₪{$total} בתאריך {$date}{$pdf}";
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
