<?php

namespace App\Jobs;

use App\Enums\DunningChannel;
use App\Enums\DunningStatus;
use App\Mail\DunningNotificationMail;
use App\Models\DunningEvent;
use App\Services\Waha\WahaClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * Deliver a single queued dunning event on its channel (WhatsApp via WAHA, or
 * transactional email). Message texts live in lang/he/dunning.php.
 */
class SendDunningNotificationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [120, 600];

    public function __construct(public int $dunningEventId) {}

    public function handle(WahaClient $waha): void
    {
        $event = DunningEvent::with(['subscription.customer', 'subscription.plan', 'charge'])
            ->find($this->dunningEventId);

        if (! $event || $event->status !== DunningStatus::Queued) {
            return;
        }

        $customer = $event->subscription->customer;

        $replacements = [
            'name' => $customer->name,
            'plan' => $event->subscription->plan->name,
            'amount' => number_format(($event->charge?->total_agorot ?? $event->subscription->totalChargeAgorot()) / 100, 2),
            'update_link' => URL::temporarySignedRoute(
                'billing.update-card',
                now()->addHours((int) config('billing.card_update_link_ttl_hours')),
                ['customer' => $customer->id],
            ),
        ];

        $subject = __("dunning.{$event->template_key}.subject", $replacements);
        $body = __("dunning.{$event->template_key}.body", $replacements);

        // On a transient send failure the exception bubbles up and the queue
        // retries with backoff; the event stays Queued so the retry actually
        // processes it. Only exhausting all tries marks it Failed (see failed()).
        if ($event->channel === DunningChannel::Whatsapp) {
            $waha->sendMessage($customer->whatsapp_jid ?? $customer->phone, $body);
        } else {
            Mail::to($customer->email)->send(new DunningNotificationMail($subject, $body));
        }

        $event->update(['status' => DunningStatus::Sent, 'sent_at' => now()]);
    }

    /**
     * All retries exhausted — record the terminal failure.
     */
    public function failed(?\Throwable $exception): void
    {
        DunningEvent::find($this->dunningEventId)?->update(['status' => DunningStatus::Failed]);
    }
}
