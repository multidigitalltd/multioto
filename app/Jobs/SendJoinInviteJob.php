<?php

namespace App\Jobs;

use App\Mail\NotificationMail;
use App\Services\Waha\WahaClient;
use App\Support\EmailList;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Send a prospect the public signup (/join) link so they complete their own
 * details, prefilled with whatever the team already has (name/phone/email).
 * Sent on email and/or WhatsApp, each best-effort and independent — no customer
 * record is created here; the prospect creates it by submitting /join.
 */
class SendJoinInviteJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public array $backoff = [30];

    public function __construct(
        public string $name,
        public ?string $email = null,
        public ?string $phone = null,
    ) {}

    public function handle(WahaClient $waha): void
    {
        $url = $this->joinUrl();
        $business = config('mail.from.name') ?: config('app.name');
        $greeting = $this->name !== '' ? "שלום {$this->name}," : 'שלום,';
        $body = "{$greeting}\n\nלפתיחת כרטיס לקוח ב־{$business} — מלאו את הפרטים בקישור הבא:\n{$url}\n\nתודה!";

        // Attempt every configured channel independently, but if ALL of them fail
        // the invite was lost — throw so the queue retries ($tries/$backoff)
        // instead of marking a silently-undelivered job as complete.
        $attempted = 0;
        $delivered = 0;
        $lastError = null;

        if (filled($this->email) && EmailList::parse($this->email) !== []) {
            $attempted++;
            try {
                Mail::to(trim((string) $this->email))->send(new NotificationMail('הזמנה להצטרפות — '.$business, $body));
                $delivered++;
            } catch (\Throwable $e) {
                $lastError = $e;
                Log::warning('SendJoinInviteJob: email failed', ['error' => $e->getMessage()]);
            }
        }

        if (filled($this->phone)) {
            $attempted++;
            try {
                $waha->sendMessage($waha->normalizeChatId((string) $this->phone), $body);
                $delivered++;
            } catch (\Throwable $e) {
                $lastError = $e;
                Log::warning('SendJoinInviteJob: WhatsApp failed', ['error' => $e->getMessage()]);
            }
        }

        if ($attempted > 0 && $delivered === 0) {
            throw new \RuntimeException('SendJoinInviteJob: all delivery channels failed', 0, $lastError);
        }
    }

    /** The /join link, prefilled with what the team already entered. */
    private function joinUrl(): string
    {
        $query = array_filter([
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
        ], fn (?string $v): bool => filled($v));

        return route('signup', $query);
    }
}
