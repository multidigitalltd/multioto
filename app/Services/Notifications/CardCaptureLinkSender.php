<?php

namespace App\Services\Notifications;

use App\Enums\NotificationType;
use App\Enums\PaymentMethod;
use App\Mail\DunningNotificationMail;
use App\Models\Customer;
use App\Models\NotificationLog;
use App\Models\Subscription;
use App\Services\Waha\WahaClient;
use App\Support\CardLink;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Builds a signed card-capture link and sends it to the customer over WhatsApp
 * and email, reporting exactly which channel succeeded and which failed. Shared
 * by the dunning job (async) and the manual "send link" buttons (sync), so the
 * operator gets honest feedback instead of a blanket "sent".
 */
class CardCaptureLinkSender
{
    public function __construct(private WahaClient $waha, private TemplateEngine $templates) {}

    /**
     * @param  string|null  $templateKey  Force a specific template (e.g. 'card.expiring').
     *                                    When null, the tone is chosen automatically
     *                                    from whether the customer is in arrears.
     * @return array{link: string, sent: array<int, string>, failed: array<int, string>, skipped: array<int, string>}
     */
    public function send(Subscription $subscription, ?string $templateKey = null): array
    {
        $customer = $subscription->customer;

        $link = CardLink::for($customer->id);

        // Operator-editable wording (הגדרות → הודעות אוטומטיות): {{customer_name}},
        // {{plan}}, {{amount}}, {{link}}, {{card_last4}}, {{business_name}}.
        $data = [
            'customer_name' => $customer->name,
            'plan' => $subscription->planName(),
            'amount' => number_format($subscription->totalChargeAgorot() / 100, 2),
            'link' => $link,
            'card_last4' => $subscription->token?->card_last4 ?? '',
            'business_name' => config('mail.from.name') ?: config('app.name'),
        ];

        // A caller can pin the template (the "card expiring" reminder needs its own
        // wording — not the welcome/activation copy). Otherwise: a customer whose
        // payment failed (past-due / suspended) is a debtor, not a new signup, so
        // send a debt-toned message. The card link is customer-wide, so any
        // subscription in arrears makes this a debt message even if the one we were
        // handed is active.
        $key = $templateKey ?? ($customer->subscriptions()->inArrears()->exists()
            ? 'card.capture_debt'
            : 'card.capture');

        return $this->deliver($customer, $key, $data, $link);
    }

    /**
     * Ask a CUSTOMER for a card, with no subscription in the picture.
     *
     * The security card is required of everyone at signup, and at that moment
     * no subscription exists yet — the team sets those up afterwards. Routing
     * that request through a subscription would mean the customers who most
     * need asking, the ones who left before the card page, are the ones that
     * cannot be asked.
     *
     * @param  array<string, scalar|null>  $extra  Extra placeholders for the template.
     * @return array{link: string, sent: array<int, string>, failed: array<int, string>, skipped: array<int, string>}
     */
    public function sendToCustomer(Customer $customer, string $templateKey, array $extra = []): array
    {
        $link = CardLink::for($customer->id);

        return $this->deliver($customer, $templateKey, [
            'customer_name' => $customer->name,
            'link' => $link,
            'business_name' => config('mail.from.name') ?: config('app.name'),
            ...$extra,
        ], $link);
    }

    /**
     * Ask a customer to finish the card step of signup, in the wording that is
     * actually true for the way they pay.
     *
     * One place decides, because the two are not interchangeable: a customer
     * paying by transfer is told their payment continues as agreed and this
     * card is only security, and saying that to somebody whose chosen method IS
     * the card promises a regular payment with nothing to run it on.
     *
     * @return array{link: string, sent: array<int, string>, failed: array<int, string>, skipped: array<int, string>}
     */
    public function sendSignupCardRequest(Customer $customer): array
    {
        $method = (string) $customer->payment_method;

        if (! PaymentMethod::isManualValue($method)) {
            return $this->sendToCustomer($customer, 'card.signup_missing');
        }

        return $this->sendToCustomer($customer, 'card.security_missing', [
            'method_label' => PaymentMethod::tryFrom($method)?->getLabel() ?? 'אמצעי התשלום שנבחר',
        ]);
    }

    /**
     * Render and deliver over both channels, reporting each one honestly.
     *
     * @param  array<string, scalar|null>  $data
     * @return array{link: string, sent: array<int, string>, failed: array<int, string>, skipped: array<int, string>}
     */
    private function deliver(Customer $customer, string $key, array $data, string $link): array
    {
        $sent = [];
        $failed = [];
        // Intentional non-deliveries (a channel whose template the operator turned
        // off, or a customer with no contact details) — kept SEPARATE from genuine
        // delivery errors, so the queue job never retries an intentional skip.
        $skipped = [];

        $whatsappTo = $customer->whatsappRecipient();

        if (filled($whatsappTo)) {
            $tpl = $this->templates->render($key, 'whatsapp', $data);

            if ($tpl === null) {
                $skipped[] = 'וואטסאפ (ההודעה כבויה בהגדרות)';
            } else {
                try {
                    $this->waha->sendMessage($whatsappTo, $tpl['body']);
                    $sent[] = 'וואטסאפ';
                    NotificationLog::record('whatsapp', NotificationType::CardLink, $whatsappTo, null, $tpl['body'], $customer->id);
                } catch (\Throwable $e) {
                    $failed[] = 'וואטסאפ: '.$this->reason($e);
                    NotificationLog::record('whatsapp', NotificationType::CardLink, $whatsappTo, null, $tpl['body'], $customer->id, 'failed', $e->getMessage());
                }
            }
        }

        if (filled($customer->email)) {
            $tpl = $this->templates->render($key, 'email', $data);

            if ($tpl === null) {
                $skipped[] = 'אימייל (ההודעה כבויה בהגדרות)';
            } else {
                try {
                    Mail::to($customer->email)->send(new DunningNotificationMail($tpl['subject'], $tpl['body']));
                    $sent[] = 'אימייל';
                    NotificationLog::record('email', NotificationType::CardLink, $customer->email, $tpl['subject'], $tpl['body'], $customer->id);
                } catch (\Throwable $e) {
                    $failed[] = 'אימייל: '.$this->reason($e);
                    NotificationLog::record('email', NotificationType::CardLink, $customer->email, $tpl['subject'], $tpl['body'], $customer->id, 'failed', $e->getMessage());
                }
            }
        }

        if ($sent === [] && $failed === [] && $skipped === []) {
            $skipped[] = 'ללקוח אין טלפון/וואטסאפ או אימייל';
        }

        return ['link' => $link, 'sent' => $sent, 'failed' => $failed, 'skipped' => $skipped];
    }

    private function reason(\Throwable $e): string
    {
        return Str::limit(trim($e->getMessage()) ?: class_basename($e), 120);
    }
}
