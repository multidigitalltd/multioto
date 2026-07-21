<?php

namespace App\Services\Notifications;

use App\Enums\NotificationType;
use App\Mail\DunningNotificationMail;
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
     * @return array{link: string, sent: array<int, string>, failed: array<int, string>}
     */
    public function send(Subscription $subscription): array
    {
        $customer = $subscription->customer;

        $link = CardLink::for($customer->id);

        // Operator-editable wording (הגדרות → הודעות אוטומטיות): {{customer_name}},
        // {{plan}}, {{amount}}, {{link}}, {{business_name}}.
        $data = [
            'customer_name' => $customer->name,
            'plan' => $subscription->planName(),
            'amount' => number_format($subscription->totalChargeAgorot() / 100, 2),
            'link' => $link,
            'business_name' => config('mail.from.name') ?: config('app.name'),
        ];

        // A customer whose payment failed (past-due / suspended) is a debtor, not
        // a new signup — send a debt-toned message, not the welcome one. The card
        // link is customer-wide, so any subscription in arrears makes this a debt
        // message, even if the subscription we were handed happens to be active.
        $inArrears = $customer->subscriptions()->inArrears()->exists();
        $key = $inArrears ? 'card.capture_debt' : 'card.capture';

        $sent = [];
        $failed = [];
        $disabled = 0;

        $whatsappTo = $customer->whatsappRecipient();

        if (filled($whatsappTo)) {
            $tpl = $this->templates->render($key, 'whatsapp', $data);

            if ($tpl === null) {
                $disabled++;
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
                $disabled++;
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

        if ($sent === [] && $failed === []) {
            $failed[] = $disabled > 0
                ? 'ההודעה כבויה בהגדרות → הודעות אוטומטיות (הקישור עצמו נוצר).'
                : 'ללקוח אין טלפון/וואטסאפ או אימייל';
        }

        return ['link' => $link, 'sent' => $sent, 'failed' => $failed];
    }

    private function reason(\Throwable $e): string
    {
        return Str::limit(trim($e->getMessage()) ?: class_basename($e), 120);
    }
}
