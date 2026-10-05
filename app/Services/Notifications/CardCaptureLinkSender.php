<?php

namespace App\Services\Notifications;

use App\Enums\NotificationType;
use App\Enums\PaymentMethod;
use App\Mail\DunningNotificationMail;
use App\Models\Customer;
use App\Models\NotificationLog;
use App\Models\Subscription;
use App\Models\SystemLog;
use App\Services\SiteAgent\BotNumberRoute;
use App\Services\SiteAgent\WhatsAppCloudClient;
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
    public function __construct(
        private WahaClient $waha,
        private TemplateEngine $templates,
        private BotNumberRoute $bot,
        private WhatsAppCloudClient $whatsapp,
    ) {}

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

        // A customer who manages their site through the bot hears from the bot,
        // never from the support number. See BotNumberRoute.
        if ($this->bot->carries($customer)) {
            // The operator's on/off switch for this notice governs both routes.
            // The approved template supplies the WORDING on this one, not the
            // decision to send at all — a notice switched off in the settings
            // that still reaches a third of the customers is a switch nobody
            // can trust again.
            if (! $this->templates->isEnabled($key, 'whatsapp')) {
                $skipped[] = 'וואטסאפ (ההודעה כבויה בהגדרות)';
            } else {
                [$sent, $failed, $skipped] = $this->overBotNumber($customer, $sent, $failed, $skipped);
            }
        } else {
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

    /**
     * The WhatsApp leg for a customer the bot carries.
     *
     * Three things differ from the support-number path, and each one is the
     * point rather than a detail:
     *
     *  - **The recipient** is the number bound to the bot, because that is the
     *    conversation this customer already has about this subscription.
     *  - **The link** is chosen by who holds that phone — see BotNumberRoute.
     *  - **The wording** is the approved template's, not ours. Meta allows free
     *    text only inside the 24-hour window a customer's own message opens,
     *    and a payment reminder is by definition sent to somebody who has not
     *    just written.
     *
     * With no approved template there is no way to say it over this number, and
     * the support number is not a fallback for this product. The email still
     * goes out, the skip says exactly why, and it is written to the log —
     * because a missing template is a configuration gap somebody has to close,
     * not a steady state.
     *
     * @param  array<int, string>  $sent
     * @param  array<int, string>  $failed
     * @param  array<int, string>  $skipped
     * @return array{0: array<int, string>, 1: array<int, string>, 2: array<int, string>}
     */
    private function overBotNumber(Customer $customer, array $sent, array $failed, array $skipped): array
    {
        $subscriber = $this->bot->subscriber($customer);
        $template = trim((string) config('siteagent.whatsapp.templates.card_link'));

        if ($subscriber === null) {
            return [$sent, $failed, $skipped];
        }

        if ($template === '') {
            $skipped[] = 'וואטסאפ (אין תבנית מאושרת לקישור תשלום — ההודעה נשלחה במייל בלבד)';

            SystemLog::record('warning', 'site-agent', 'לא נשלחה הודעת תשלום בוואטסאפ — חסרה תבנית', [
                'customer_id' => $customer->id,
                'setting' => 'siteagent.template_card_link',
            ]);

            return [$sent, $failed, $skipped];
        }

        $subscriber->setRelation('customer', $customer);
        $link = $this->bot->paymentLinkFor($subscriber);

        $id = $this->whatsapp->sendTemplate($subscriber->phone, $template, [
            'customer_name' => $customer->name,
            'link' => $link,
        ]);

        if ($id === null) {
            $reason = $this->whatsapp->lastError();
            $failed[] = 'וואטסאפ: '.($reason ?: 'השליחה נדחתה');

            NotificationLog::record(
                'whatsapp', NotificationType::CardLink, $subscriber->phone, null,
                $template, $customer->id, 'failed', (string) $reason,
            );

            return [$sent, $failed, $skipped];
        }

        $sent[] = 'וואטסאפ (מהמספר של הבוט)';
        NotificationLog::record('whatsapp', NotificationType::CardLink, $subscriber->phone, null, $link, $customer->id);

        return [$sent, $failed, $skipped];
    }

    private function reason(\Throwable $e): string
    {
        return Str::limit(trim($e->getMessage()) ?: class_basename($e), 120);
    }
}
