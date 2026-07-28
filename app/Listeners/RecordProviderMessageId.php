<?php

namespace App\Listeners;

use App\Models\NotificationLog;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Log;

/**
 * Stores the provider's own id for a message we just handed off.
 *
 * That id is the only reliable key to match a later delivery/open/bounce event
 * back to the row it belongs to — matching on recipient alone breaks the moment
 * a customer gets two of our emails in the same hour.
 *
 * The mailable carries our log id in an X-Multioto-Log header; this listener
 * reads it back when the message actually goes out and pairs the two ids.
 * Best-effort throughout: a message that is already delivered must never be
 * "undone" by a bookkeeping failure.
 */
class RecordProviderMessageId
{
    /** Header the mailable sets so we can find our own row again. */
    public const LOG_HEADER = 'X-Multioto-Log';

    public function handle(MessageSent $event): void
    {
        try {
            $headers = $event->message->getHeaders();

            if (! $headers->has(self::LOG_HEADER)) {
                return; // Not a message we track.
            }

            $logId = (int) $headers->get(self::LOG_HEADER)->getBodyAsString();
            $providerId = $this->providerId($event);

            if ($logId <= 0 || $providerId === null) {
                return;
            }

            NotificationLog::whereKey($logId)->update(['provider_message_id' => $providerId]);
        } catch (\Throwable $e) {
            Log::warning('Could not record the provider message id', ['error' => $e->getMessage()]);
        }
    }

    /**
     * The provider's message id. Postmark returns it on the sent message;
     * other transports may not, in which case we simply have nothing to store
     * and delivery events fall back to matching on the recipient.
     */
    private function providerId(MessageSent $event): ?string
    {
        $id = trim((string) ($event->sent->getMessageId() ?? ''));

        return $id !== '' ? $id : null;
    }
}
