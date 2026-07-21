<?php

namespace App\Services\Billing;

use App\Enums\NotificationType;
use App\Mail\NotificationMail;
use App\Models\Charge;
use App\Models\NotificationLog;
use App\Services\Notifications\TemplateEngine;
use App\Services\Waha\WahaClient;
use App\Support\Money;
use App\Support\PaymentLink;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Builds and sends a payment-demand message (initial request or reminder) for a
 * charge, over email or WhatsApp. Everything the customer sees — the itemised
 * breakdown and the payment options (cancelable card link and/or bank transfer)
 * — is derived from the charge itself, so the first send and any later reminder
 * stay perfectly in sync.
 */
class DemandDispatcher
{
    public function __construct(
        private WahaClient $waha,
        private TemplateEngine $templates,
    ) {}

    /**
     * Render the given template for the charge and deliver it on the channel.
     * `offerTransfer` includes bank-transfer details when they're configured.
     */
    public function send(Charge $charge, string $templateKey, string $channel, bool $offerTransfer): void
    {
        $customer = $charge->customer;

        if (! $customer) {
            return;
        }

        $data = [
            'customer_name' => $customer->contact_name ?: $customer->name,
            'business_name' => config('mail.from.name') ?: config('app.name'),
            'amount' => Money::ils($charge->total_agorot),
            'items' => $this->items($charge),
            'payment_options' => $this->paymentOptions($charge, $offerTransfer),
            // Individual fields kept for any operator template still using them.
            'link' => filled($charge->cardcom_pay_url) ? PaymentLink::for($charge->id) : '',
            'bit' => filled($charge->cardcom_bit_url) ? PaymentLink::bitFor($charge->id) : '',
            'bank_transfer' => $this->bankDetails(),
            'for' => filled($charge->description) ? ' עבור '.$charge->description : '',
        ];

        if ($channel === 'email' && filled($customer->email)) {
            $rendered = $this->templates->render($templateKey, 'email', $data);
            if ($rendered) {
                Mail::to($customer->email)->send(new NotificationMail($rendered['subject'] ?? 'בקשת תשלום', $rendered['body']));
                NotificationLog::record('email', NotificationType::PaymentLink, $customer->email, $rendered['subject'] ?? null, $rendered['body'], $customer->id);
                $this->logSent($charge, 'email', $templateKey);
            }

            return;
        }

        if (filled($customer->whatsapp_jid) || filled($customer->phone)) {
            $rendered = $this->templates->render($templateKey, 'whatsapp', $data);
            if ($rendered) {
                $recipient = $customer->whatsappRecipient();
                $this->waha->sendMessage($recipient, $rendered['body']);
                NotificationLog::record('whatsapp', NotificationType::PaymentLink, $recipient, null, $rendered['body'], $customer->id);
                $this->logSent($charge, 'whatsapp', $templateKey);
            }
        }
    }

    /**
     * Append this send to the charge's demand log, so the team has the full
     * "contacted at" history (demand_sent_at only keeps the last one). Best
     * effort — a logging hiccup must never break the actual send.
     */
    private function logSent(Charge $charge, string $channel, string $templateKey): void
    {
        try {
            $log = $charge->demand_reminders_log ?? [];
            $log[] = [
                'at' => now()->toIso8601String(),
                'channel' => $channel,
                'type' => $templateKey === 'payment.reminder' ? 'reminder' : 'demand',
            ];

            $charge->update(['demand_reminders_log' => $log]);
        } catch (\Throwable $e) {
            // The customer has already been contacted — a failed audit-write must
            // never bubble up and turn a completed send into a failed/retried job
            // (which would re-send) or block the caller's own bookkeeping.
            Log::warning('DemandDispatcher: send-log write failed', [
                'charge_id' => $charge->id,
                'error' => Str::limit($e->getMessage(), 200),
            ]);
        }
    }

    /**
     * The business bank-transfer details shown on a demand. Single source of
     * truth: the bank-transfer instructions from the signup form settings
     * (הגדרות ← טופס הרשמה), so the account is maintained in one place only.
     */
    private function bankDetails(): string
    {
        // Single source of truth: the signup-form bank-transfer instructions.
        // Fall back to the legacy BANK_TRANSFER_DETAILS env for installs that
        // still carry it, so a demand never silently loses the account.
        foreach ([
            config('billing.signup.instructions.bank_transfer'),
            config('billing.bank_transfer_details'),
        ] as $value) {
            $value = trim((string) $value);

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * A plain-text itemised breakdown — one bullet per product, with quantity ×
     * unit price when a line has more than one. Uses the charge's own lines
     * (a single line is synthesised from its description + total).
     */
    public function items(Charge $charge): string
    {
        return collect($charge->invoiceLines())->map(function (array $line): string {
            $qty = (int) ($line['qty'] ?? 1);
            $unit = (int) ($line['unit_price_agorot'] ?? 0);
            $name = (string) ($line['name'] ?? 'פריט');

            return $qty > 1
                ? sprintf('• %s — %d × %s = %s', $name, $qty, Money::ils($unit), Money::ils($qty * $unit))
                : sprintf('• %s — %s', $name, Money::ils($unit));
        })->implode("\n");
    }

    /**
     * The payment-options section. Bank transfer is our preferred method, so
     * it's presented FIRST and highlighted; the (cancelable) card link, when the
     * charge has a Cardcom page, follows as a secondary "also possible" option.
     */
    public function paymentOptions(Charge $charge, bool $offerTransfer): string
    {
        $sections = [];

        $bank = $this->bankDetails();
        if ($offerTransfer && filled($bank)) {
            $sections[] = "🏦 לתשלום בהעברה בנקאית (הדרך המועדפת):\n{$bank}";
        }

        // Bit — a one-tap instant option (when the terminal supports it).
        if (filled($charge->cardcom_bit_url)) {
            $sections[] = "⚡ לתשלום מהיר ב-Bit:\n".PaymentLink::bitFor($charge->id);
        }

        if (filled($charge->cardcom_pay_url)) {
            $sections[] = "אפשר גם לשלם בכרטיס אשראי בקישור המאובטח:\n".PaymentLink::for($charge->id)
                ."\nהתשלום מתבצע בעמוד המאובטח של חברת הסליקה — פרטי האשראי אינם נשמרים אצלנו.";
        }

        return $sections !== [] ? implode("\n\n", $sections) : 'לפרטי תשלום — השיבו להודעה זו ונשמח לעזור.';
    }
}
