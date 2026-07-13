<?php

namespace App\Jobs;

use App\Enums\DunningChannel;
use App\Enums\DunningStatus;
use App\Enums\NotificationType;
use App\Mail\DunningNotificationMail;
use App\Models\DunningEvent;
use App\Models\NotificationLog;
use App\Services\Waha\WahaClient;
use App\Support\CardLink;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

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
            'plan' => $event->subscription->planName(),
            'amount' => number_format(($event->charge?->total_agorot ?? $event->subscription->totalChargeAgorot()) / 100, 2),
            'update_link' => CardLink::for($customer->id),
        ];

        $subject = __("dunning.{$event->template_key}.subject", $replacements);
        $body = __("dunning.{$event->template_key}.body", $replacements);

        // On a transient send failure the exception bubbles up and the queue
        // retries with backoff; the event stays Queued so the retry actually
        // processes it. Only exhausting all tries marks it Failed (see failed()).
        if ($event->channel === DunningChannel::Whatsapp) {
            $recipient = $customer->whatsappRecipient();
            $waha->sendMessage($recipient, $body);
            NotificationLog::record('whatsapp', NotificationType::Dunning, $recipient, null, $body, $customer->id);
        } else {
            Mail::to($customer->email)->send(new DunningNotificationMail($subject, $body));
            NotificationLog::record('email', NotificationType::Dunning, $customer->email, $subject, $body, $customer->id);
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
