<?php

namespace App\Jobs;

use App\Enums\ChargeStatus;
use App\Models\Charge;
use App\Services\Linet\InvoiceIssuer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

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

        // Linet emails the legal tax invoice straight to the customer — the team
        // deliberately does NOT receive a copy (it would be odd for the business
        // owner to get a duplicate of every customer's invoice).
    }
}
