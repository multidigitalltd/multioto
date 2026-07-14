<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Services\Billing\DemandDispatcher;
use App\Services\Billing\ManualChargeService;
use App\Services\Notifications\TemplateEngine;
use App\Services\Waha\WahaClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Send a payment demand to a customer over WhatsApp / email. A demand can offer
 * a secure card link (a hosted Cardcom page reached through our own cancelable
 * gateway), bank-transfer details, or both, and — when a proforma document type
 * is configured — issues a Linet "חשבונית עסקה" up front. When paid, the
 * existing webhook finalises the charge and issues the fiscal tax invoice, so a
 * link payment behaves like any other one-off charge. Runs on the queue:
 * creating the page and issuing documents are network calls.
 */
class SendPaymentLinkJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public array $backoff = [30];

    /**
     * @param  array<int, array{name: string, qty: int, unit_price_agorot: int}>  $lines
     * @param  array<int, string>  $methods  subset of ['link', 'transfer']
     */
    public function __construct(
        public int $customerId,
        public int $totalAgorot,
        public string $description,
        public string $channel, // whatsapp | email
        public array $lines = [],
        public array $methods = ['link'],
    ) {}

    public function handle(ManualChargeService $service, TemplateEngine $templates, WahaClient $waha): void
    {
        $customer = Customer::find($this->customerId);

        if (! $customer) {
            return;
        }

        $wantsLink = in_array('link', $this->methods, true);
        $wantsTransfer = in_array('transfer', $this->methods, true);

        // A demand always creates a pending charge to track it and hang a
        // proforma off. With a card link, that charge also gets a hosted Cardcom
        // page reached through our cancelable gateway; a transfer-only demand
        // has no page. Line items ride along so the Linet documents itemise
        // identically to the breakdown the customer sees.
        $charge = $wantsLink
            ? $service->createHostedPage($customer, $this->totalAgorot, $this->description, null, $this->lines)['charge']
            : $service->createDemand($customer, $this->totalAgorot, $this->description, null, $this->lines);

        // Mark this charge as a SENT demand (distinguishes it from an immediate
        // charge) and record the channel, so an unpaid demand can be chased.
        $charge->update(['demand_sent_at' => now(), 'demand_channel' => $this->channel]);

        // Issue the proforma (חשבונית עסקה) up front — no-op when no proforma
        // document type is configured. Linet emails it to the customer directly.
        IssueProformaJob::dispatch($charge->id);

        (new DemandDispatcher($waha, $templates))->send($charge, 'payment.link', $this->channel, $wantsTransfer);
    }
}
