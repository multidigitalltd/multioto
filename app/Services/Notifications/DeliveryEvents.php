<?php

namespace App\Services\Notifications;

use App\Models\NotificationLog;
use App\Models\SystemLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Applies a delivery event from the email provider (Postmark) to the message it
 * belongs to.
 *
 * The provider is the only party that knows what actually happened to a
 * message, so nothing here is inferred: a log row says "delivered" only because
 * Postmark said so. Events arrive out of order and more than once, which the
 * writes below tolerate — each one is idempotent on its own field.
 */
class DeliveryEvents
{
    /**
     * Postmark record types we act on. Click and SubscriptionChange are
     * deliberately ignored: we do not track link clicks, and unsubscribes are
     * handled by our own signed link rather than by the provider's.
     */
    public const HANDLED = ['Delivery', 'Bounce', 'SpamComplaint', 'Open'];

    /**
     * @param  array<string, mixed>  $payload  the provider's event body
     * @return bool whether the event matched a message we sent
     */
    public function apply(array $payload): bool
    {
        $type = (string) ($payload['RecordType'] ?? '');

        if (! in_array($type, self::HANDLED, true)) {
            return false;
        }

        $log = $this->findLog($payload);

        if ($log === null) {
            // Not ours, or older than the log retention window. Silent by
            // design — a provider replays events, and an unmatched one is
            // noise, not a fault.
            return false;
        }

        $at = $this->timestamp($payload);

        match ($type) {
            'Delivery' => $this->delivered($log, $at),
            'Open' => $this->opened($log, $at),
            'Bounce' => $this->bounced($log, $at, $payload),
            'SpamComplaint' => $this->complained($log, $at),
        };

        return true;
    }

    private function delivered(NotificationLog $log, Carbon $at): void
    {
        // "sent" here means the provider accepted AND delivered it — a stronger
        // claim than the "queued" the send path is allowed to make.
        $log->forceFill(['delivered_at' => $log->delivered_at ?? $at, 'status' => 'sent'])->save();
    }

    private function opened(NotificationLog $log, Carbon $at): void
    {
        // The count moves in the database, not in PHP: two opens of the same
        // message can be handled at the same moment, and a read-modify-write
        // would have both write the same number and lose one.
        NotificationLog::whereKey($log->getKey())->increment('open_count');

        // First open is the interesting one; later opens only raise the count,
        // so forwarding a mail around cannot rewrite when it was first read.
        // An open also proves delivery even if that event never arrived.
        NotificationLog::whereKey($log->getKey())
            ->whereNull('opened_at')
            ->update(['opened_at' => $at]);

        NotificationLog::whereKey($log->getKey())
            ->whereNull('delivered_at')
            ->update(['delivered_at' => $at]);

        // A message someone read is not "בתור" — and open tracking can be the
        // only signal we get. A row the provider already failed keeps its
        // verdict; only a still-queued one moves.
        NotificationLog::whereKey($log->getKey())
            ->where('status', 'queued')
            ->update(['status' => 'sent']);
    }

    private function bounced(NotificationLog $log, Carbon $at, array $payload): void
    {
        $reason = trim((string) ($payload['Description'] ?? $payload['Details'] ?? $payload['Type'] ?? ''));

        $log->forceFill([
            'bounced_at' => $log->bounced_at ?? $at,
            'status' => 'failed',
            'error' => $reason !== '' ? Str::limit($reason, 250, '') : 'הודעה חזרה (bounce)',
        ])->save();

        // Only a permanent failure retires the address. A full mailbox or a
        // transient server error is not a reason to stop writing to a customer.
        if ((bool) ($payload['Inactive'] ?? false) || $this->isHardBounce($payload)) {
            $this->retireAddress($log, $reason);
        }
    }

    private function complained(NotificationLog $log, Carbon $at): void
    {
        $log->forceFill(['complained_at' => $log->complained_at ?? $at])->save();

        // Someone pressing "spam" is the clearest opt-out there is; honouring it
        // silently is both correct and the only way to protect our sending
        // reputation for everyone else.
        $customer = $log->customer;

        if ($customer !== null && ! $customer->hasOptedOutOfMarketing()) {
            $customer->update([
                'marketing_opt_out_at' => $at,
                'marketing_opt_out_channel' => 'סימון כספאם',
            ]);

            SystemLog::record('warning', 'support',
                "הלקוח {$customer->name} סימן הודעה שלנו כספאם והוסר אוטומטית מדיוור פרסומי.",
                ['customer_id' => $customer->id]);
        }
    }

    /** Stop mailing an address the provider says is permanently unreachable. */
    private function retireAddress(NotificationLog $log, string $reason): void
    {
        $customer = $log->customer;

        if ($customer === null || $customer->email_bounced_at !== null) {
            return;
        }

        // The bounce belongs to the address it was sent to. Correcting a typo
        // is exactly what someone does while the old address is still bouncing,
        // and a late webhook for it must not suppress the replacement.
        if ($this->normalize($customer->email) !== $this->normalize($log->recipient)) {
            return;
        }

        $customer->update([
            'email_bounced_at' => now(),
            'email_bounce_reason' => $reason !== '' ? Str::limit($reason, 250, '') : null,
        ]);

        SystemLog::record('warning', 'support',
            "כתובת המייל של {$customer->name} ({$customer->email}) חזרה כלא קיימת ולא תקבל דיוור עד לתיקון.",
            ['customer_id' => $customer->id]);
    }

    /** Email addresses compare case-insensitively and ignore stray whitespace. */
    private function normalize(?string $address): string
    {
        return mb_strtolower(trim((string) $address));
    }

    /** @param array<string, mixed> $payload */
    private function findLog(array $payload): ?NotificationLog
    {
        $messageId = trim((string) ($payload['MessageID'] ?? ''));

        if ($messageId !== '') {
            // A present id is the answer, match or no match. Most of our mail
            // is transactional and never records a provider id, so falling
            // through to the recipient would pin those events onto whatever we
            // happened to send that customer last — which is usually the newest
            // broadcast, whose numbers would then be wrong.
            return NotificationLog::where('provider_message_id', $messageId)->first();
        }

        // Fallback only for a payload that truly omits the id: the most recent
        // email we sent to that address. Bounded to a week so an old row is
        // never rewritten by a stray replay.
        $recipient = trim((string) ($payload['Recipient'] ?? $payload['Email'] ?? ''));

        if ($recipient === '') {
            return null;
        }

        return NotificationLog::where('channel', 'email')
            ->where('recipient', $recipient)
            ->where('sent_at', '>=', now()->subWeek())
            ->latest('sent_at')
            ->first();
    }

    /** @param array<string, mixed> $payload */
    private function isHardBounce(array $payload): bool
    {
        // Postmark's own classification. Soft failures (SoftBounce,
        // Transient, DnsError) are excluded on purpose.
        return in_array((string) ($payload['Type'] ?? ''), [
            'HardBounce', 'BadEmailAddress', 'Blocked', 'SpamNotification', 'ManuallyDeactivated',
        ], true);
    }

    /** @param array<string, mixed> $payload */
    private function timestamp(array $payload): Carbon
    {
        foreach (['DeliveredAt', 'ReceivedAt', 'BouncedAt', 'RecordedAt'] as $key) {
            if (filled($payload[$key] ?? null)) {
                try {
                    // Provider timestamps are UTC; every other time in this app
                    // is stored as local wall-clock. Without the conversion the
                    // column would read back shifted by the UTC offset, so an
                    // email opened at 13:00 would show as opened at 10:00.
                    return Carbon::parse((string) $payload[$key])
                        ->setTimezone(config('app.timezone'));
                } catch (\Throwable) {
                    // Fall through to now(): a malformed timestamp must not
                    // discard the event itself.
                }
            }
        }

        return now();
    }
}
