<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * A browser (Web Push) alert to a team member — a new ticket, a customer reply.
 * Delivered only via the Web Push channel (the in-panel bell is sent separately
 * by TeamNotifier), and queued so the HTTP round-trip to the push service never
 * blocks ticket handling.
 */
class TeamPushNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private string $title,
        private string $body,
        private string $url,
    ) {}

    /**
     * @return array<int, class-string>
     */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->title)
            ->body($this->body)
            ->icon('/favicon.ico')
            ->data(['url' => $this->url])
            // A stable tag collapses repeat alerts for the same ticket into one.
            ->options(['TTL' => 1800]);
    }
}
