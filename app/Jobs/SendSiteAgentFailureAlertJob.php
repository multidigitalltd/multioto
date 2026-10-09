<?php

namespace App\Jobs;

use App\Mail\NotificationMail;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

/** Retry mail independently, without re-reading a changing conversation or sending WhatsApp again. */
class SendSiteAgentFailureAlertJob implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public array $backoff = [60, 300];

    public function __construct(
        public readonly int $webhookEventId,
        public readonly array $recipients,
        public readonly string $bodyText,
    ) {}

    public function handle(): void
    {
        $key = 'site-agent:failure-alert:v1:'.$this->webhookEventId;
        try {
            $lock = Cache::lock($key.':lock', 180);
            if (! $lock->get()) {
                throw new \RuntimeException('Site-agent failure alert is already being sent.');
            }
            try {
                if (Cache::has($key)) {
                    return;
                }

                Mail::to($this->recipients)->send(new NotificationMail('בוט ניהול אתר — בקשה שלא הובנה', $this->bodyText));
                Cache::put($key, true, now()->addDays(30));
            } finally {
                $lock->release();
            }
        } catch (\Throwable) {
            // Transport errors can quote credentials, recipient or message
            // content. The queue may persist this exception, so omit its cause.
            throw new \RuntimeException('Site-agent failure alert mail delivery failed.');
        }
    }
}
