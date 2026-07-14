<?php

namespace App\Jobs;

use App\Enums\NotificationType;
use App\Mail\NotificationMail;
use App\Models\Customer;
use App\Models\NotificationLog;
use App\Services\Billing\ManualChargeService;
use App\Services\Notifications\TemplateEngine;
use App\Services\Waha\WahaClient;
use App\Support\Money;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

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
        // identically to the breakdown the customer sees here.
        if ($wantsLink) {
            $result = $service->createHostedPage($customer, $this->totalAgorot, $this->description, null, $this->lines);
            $charge = $result['charge'];
            $payUrl = $result['pay_url'];
        } else {
            $charge = $service->createDemand($customer, $this->totalAgorot, $this->description, null, $this->lines);
            $payUrl = null;
        }

        // Issue the proforma (חשבונית עסקה) up front — no-op when no proforma
        // document type is configured. Linet emails it to the customer directly.
        IssueProformaJob::dispatch($charge->id);

        $data = [
            'customer_name' => $customer->contact_name ?: $customer->name,
            'business_name' => config('mail.from.name') ?: config('app.name'),
            'amount' => Money::ils($this->totalAgorot),
            'items' => $this->itemsBlock(),
            'payment_options' => $this->paymentOptions($payUrl, $wantsTransfer),
            // Individual fields kept for any operator template still using them.
            'link' => (string) $payUrl,
            'bank_transfer' => (string) config('billing.bank_transfer_details'),
            'for' => $this->description !== '' ? ' עבור '.$this->description : '',
        ];

        $this->deliver($customer, $templates, $waha, $data);
    }

    /** Render and send the demand over the chosen channel. */
    protected function deliver(Customer $customer, TemplateEngine $templates, WahaClient $waha, array $data): void
    {
        if ($this->channel === 'email' && filled($customer->email)) {
            $rendered = $templates->render('payment.link', 'email', $data);
            if ($rendered) {
                Mail::to($customer->email)->send(new NotificationMail($rendered['subject'] ?? 'בקשת תשלום', $rendered['body']));
                NotificationLog::record('email', NotificationType::PaymentLink, $customer->email, $rendered['subject'] ?? null, $rendered['body'], $customer->id);
            }

            return;
        }

        if (filled($customer->whatsapp_jid) || filled($customer->phone)) {
            $rendered = $templates->render('payment.link', 'whatsapp', $data);
            if ($rendered) {
                $recipient = $customer->whatsappRecipient();
                $waha->sendMessage($recipient, $rendered['body']);
                NotificationLog::record('whatsapp', NotificationType::PaymentLink, $recipient, null, $rendered['body'], $customer->id);
            }
        }
    }

    /**
     * Compose the payment-options section: a secure card link and/or bank
     * transfer details, each only when that method was chosen (and configured).
     */
    protected function paymentOptions(?string $payUrl, bool $wantsTransfer): string
    {
        $sections = [];

        if (filled($payUrl)) {
            $sections[] = "לתשלום מאובטח בכרטיס אשראי:\n{$payUrl}\nהתשלום מתבצע בעמוד המאובטח של חברת הסליקה — פרטי האשראי אינם נשמרים אצלנו.";
        }

        $bank = (string) config('billing.bank_transfer_details');
        if ($wantsTransfer && filled($bank)) {
            $sections[] = "לתשלום בהעברה בנקאית:\n{$bank}";
        }

        // A demand with no usable method still reads sensibly.
        return $sections !== [] ? implode("\n\n", $sections) : 'לפרטי תשלום — השיבו להודעה זו ונשמח לעזור.';
    }

    /**
     * A plain-text itemised breakdown of the request — one bullet per product,
     * with quantity × unit price when a line has more than one. Falls back to a
     * single line from the description + total when no items were supplied, so a
     * simple "amount + description" demand still reads as a clear detail line.
     */
    protected function itemsBlock(): string
    {
        if ($this->lines === []) {
            $name = $this->description !== '' ? $this->description : 'תשלום';

            return '• '.$name.' — '.Money::ils($this->totalAgorot);
        }

        return collect($this->lines)->map(function (array $line): string {
            $qty = (int) ($line['qty'] ?? 1);
            $unit = (int) ($line['unit_price_agorot'] ?? 0);
            $name = (string) ($line['name'] ?? 'פריט');

            return $qty > 1
                ? sprintf('• %s — %d × %s = %s', $name, $qty, Money::ils($unit), Money::ils($qty * $unit))
                : sprintf('• %s — %s', $name, Money::ils($unit));
        })->implode("\n");
    }
}
