<?php

namespace App\Jobs;

use App\Enums\ChargeStatus;
use App\Mail\NotificationMail;
use App\Models\Charge;
use App\Services\Linet\InvoiceIssuer;
use App\Support\EmailList;
use App\Support\Money;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Issue a Linet tax invoice/receipt for a successful charge — and only for a
 * successful charge. Safe to retry: skips if an invoice already exists. The
 * actual work lives in InvoiceIssuer, shared with the manual "issue invoice"
 * button so Linet errors are visible there too.
 */
class IssueInvoiceJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public function __construct(public int $chargeId) {}

    public function handle(InvoiceIssuer $issuer): void
    {
        $charge = Charge::find($this->chargeId);

        if (! $charge || $charge->status !== ChargeStatus::Succeeded || $charge->invoice()->exists()) {
            return;
        }

        $result = $issuer->issue($charge);

        // Throw so the job retries — a transient Linet error may clear. A
        // misconfiguration (wrong codes) will keep failing until fixed, which
        // is surfaced via the manual "issue invoice" button.
        if (! $result['ok']) {
            throw new \RuntimeException('Linet invoice failed: '.($result['error'] ?? 'unknown'));
        }

        // Linet emails the tax invoice to the customer. For a ONE-OFF (manual)
        // charge, also send the team a copy so they're in the loop.
        if ($charge->subscription_id === null) {
            $this->emailTeamCopy($charge);
        }
    }

    /** Email the management team a copy/summary of a one-off charge's invoice. */
    protected function emailTeamCopy(Charge $charge): void
    {
        $recipients = EmailList::parse(config('billing.notifications.team_email'));

        if ($recipients === []) {
            return;
        }

        $charge->loadMissing(['customer', 'invoice']);

        $body = implode("\n", array_filter([
            'הונפקה חשבונית עבור חיוב חד-פעמי (ללקוח נשלחה במייל מלינט).',
            'לקוח: '.($charge->customer?->name ?? '—'),
            'סכום: '.Money::ils($charge->total_agorot),
            filled($charge->description) ? 'עבור: '.$charge->description : null,
            filled($charge->invoice?->pdf_url) ? 'מסמך: '.$charge->invoice->pdf_url : null,
        ]));

        try {
            Mail::to($recipients)->send(new NotificationMail('העתק חשבונית — '.($charge->customer?->name ?? 'לקוח'), $body));
        } catch (\Throwable $e) {
            Log::warning('IssueInvoiceJob: team copy email failed', ['charge_id' => $charge->id, 'error' => $e->getMessage()]);
        }
    }
}
