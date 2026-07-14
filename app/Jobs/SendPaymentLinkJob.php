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
 * Create a hosted Cardcom payment page for an ad-hoc amount and send the link
 * to the customer over the chosen channel (WhatsApp / email). The card is
 * entered only on Cardcom's page; when paid, the existing webhook finalises the
 * charge and issues the Linet invoice — so a link payment behaves exactly like
 * any other one-off charge. Runs on the queue: creating the page is a network
 * call that must never block the request.
 */
class SendPaymentLinkJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public array $backoff = [30];

    /**
     * @param  array<int, array{name: string, qty: int, unit_price_agorot: int}>  $lines
     */
    public function __construct(
        public int $customerId,
        public int $totalAgorot,
        public string $description,
        public string $channel, // whatsapp | email
        public array $lines = [],
    ) {}

    public function handle(ManualChargeService $service, TemplateEngine $templates, WahaClient $waha): void
    {
        $customer = Customer::find($this->customerId);

        if (! $customer) {
            return;
        }

        // A pending charge + hosted page (throws on Cardcom failure → job retries).
        // Line items ride along so the eventual Linet invoice itemises identically
        // to the breakdown the customer sees in this payment request.
        $result = $service->createHostedPage($customer, $this->totalAgorot, $this->description, null, $this->lines);

        $data = [
            'customer_name' => $customer->contact_name ?: $customer->name,
            'business_name' => config('mail.from.name') ?: config('app.name'),
            'amount' => Money::ils($this->totalAgorot),
            'items' => $this->itemsBlock(),
            // Kept for any operator template still using the old inline {{for}}.
            'for' => $this->description !== '' ? ' עבור '.$this->description : '',
            'link' => $result['url'],
        ];

        if ($this->channel === 'email' && filled($customer->email)) {
            $rendered = $templates->render('payment.link', 'email', $data);
            if ($rendered) {
                Mail::to($customer->email)->send(new NotificationMail($rendered['subject'] ?? 'קישור לתשלום', $rendered['body']));
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
