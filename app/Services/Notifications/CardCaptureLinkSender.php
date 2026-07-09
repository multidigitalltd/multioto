<?php

namespace App\Services\Notifications;

use App\Mail\DunningNotificationMail;
use App\Models\Subscription;
use App\Services\Waha\WahaClient;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Builds a signed card-capture link and sends it to the customer over WhatsApp
 * and email, reporting exactly which channel succeeded and which failed. Shared
 * by the dunning job (async) and the manual "send link" buttons (sync), so the
 * operator gets honest feedback instead of a blanket "sent".
 */
class CardCaptureLinkSender
{
    public function __construct(private WahaClient $waha) {}

    /**
     * @return array{link: string, sent: array<int, string>, failed: array<int, string>}
     */
    public function send(Subscription $subscription): array
    {
        $customer = $subscription->customer;

        $link = URL::temporarySignedRoute(
            'billing.update-card',
            now()->addHours((int) config('billing.card_update_link_ttl_hours')),
            ['customer' => $customer->id],
        );

        $replacements = [
            'name' => $customer->name,
            'plan' => $subscription->plan?->name ?? '',
            'amount' => number_format($subscription->totalChargeAgorot() / 100, 2),
            'link' => $link,
        ];
        $subject = __('onboarding.card_capture.subject', $replacements);
        $body = __('onboarding.card_capture.body', $replacements);

        $sent = [];
        $failed = [];

        $whatsappTo = $customer->whatsapp_jid ?? $customer->phone;

        if (filled($whatsappTo)) {
            try {
                $this->waha->sendMessage($whatsappTo, $body);
                $sent[] = 'וואטסאפ';
            } catch (\Throwable $e) {
                $failed[] = 'וואטסאפ: '.$this->reason($e);
            }
        }

        if (filled($customer->email)) {
            try {
                Mail::to($customer->email)->send(new DunningNotificationMail($subject, $body));
                $sent[] = 'אימייל';
            } catch (\Throwable $e) {
                $failed[] = 'אימייל: '.$this->reason($e);
            }
        }

        if ($sent === [] && $failed === []) {
            $failed[] = 'ללקוח אין טלפון/וואטסאפ או אימייל';
        }

        return ['link' => $link, 'sent' => $sent, 'failed' => $failed];
    }

    private function reason(\Throwable $e): string
    {
        return Str::limit(trim($e->getMessage()) ?: class_basename($e), 120);
    }
}
