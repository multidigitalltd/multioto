<?php

namespace App\Jobs;

use App\Enums\SubscriptionStatus;
use App\Mail\DunningNotificationMail;
use App\Models\Subscription;
use App\Services\Waha\WahaClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * Invite a newly onboarded customer to enter their card on Cardcom's hosted
 * page. Sent on WhatsApp and/or email with a short-lived signed link — the
 * card itself is captured entirely by Cardcom (PCI scope stays with them).
 *
 * Heavy/external work runs here in the queue, never in the onboarding request.
 */
class SendCardCaptureLinkJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [120, 600];

    public function __construct(public int $subscriptionId) {}

    public function handle(WahaClient $waha): void
    {
        $subscription = Subscription::with(['customer', 'plan'])->find($this->subscriptionId);

        // Skip if the subscription vanished or was canceled between enqueue and
        // run — a canceled subscription must never receive a card-capture link.
        if (! $subscription || $subscription->status === SubscriptionStatus::Canceled) {
            return;
        }

        $customer = $subscription->customer;

        $replacements = [
            'name' => $customer->name,
            'plan' => $subscription->plan->name,
            'amount' => number_format($subscription->totalChargeAgorot() / 100, 2),
            'link' => URL::temporarySignedRoute(
                'billing.update-card',
                now()->addHours((int) config('billing.card_update_link_ttl_hours')),
                ['customer' => $customer->id],
            ),
        ];

        $subject = __('onboarding.card_capture.subject', $replacements);
        $body = __('onboarding.card_capture.body', $replacements);

        if (filled($customer->whatsapp_jid ?? $customer->phone)) {
            $waha->sendMessage($customer->whatsapp_jid ?? $customer->phone, $body);
        }

        if (filled($customer->email)) {
            Mail::to($customer->email)->send(new DunningNotificationMail($subject, $body));
        }
    }
}
