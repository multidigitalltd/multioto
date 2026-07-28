<?php

namespace App\Services\Notifications;

use App\Models\Customer;
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

        $at = $this->timestamp($payload);
        $log = $this->findLog($payload);

        if ($log === null) {
            // No row to update: most of our mail is transactional and carries no
            // provider id, and a provider also replays old events. There is still
            // one thing worth acting on — a permanent failure or a spam report is
            // a fact about the ADDRESS, not about one message, and ignoring it
            // would leave a dead address in every future send.
            return $this->applyToAddress($type, $payload, $at);
        }

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
        $this->earliest($log, 'delivered_at', $at);

        // "sent" here means the provider accepted AND delivered it — a stronger
        // claim than the "queued" the send path is allowed to make. A row that
        // already failed keeps its verdict rather than being talked out of it
        // by a stale replay.
        $this->markSent($log);
    }

    /**
     * Keep the EARLIEST time in a timestamp column.
     *
     * Events arrive out of order, so "the first one we processed" is not the
     * same as "the first one that happened" — writing only when the column is
     * empty would freeze a later read in place of the actual first one.
     */
    private function earliest(NotificationLog $log, string $column, Carbon $at): void
    {
        NotificationLog::whereKey($log->getKey())
            ->where(fn ($query) => $query->whereNull($column)->orWhere($column, '>', $at))
            ->update([$column => $at]);
    }

    /** A message the provider has accounted for is no longer "בתור". */
    private function markSent(NotificationLog $log): void
    {
        NotificationLog::whereKey($log->getKey())
            ->where('status', 'queued')
            ->update(['status' => 'sent']);
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
        $this->earliest($log, 'opened_at', $at);
        $this->earliest($log, 'delivered_at', $at);

        // Open tracking can be the only signal enabled, and a mail proven to
        // have been read must not sit in the history as "בתור".
        $this->markSent($log);
    }

    private function bounced(NotificationLog $log, Carbon $at, array $payload): void
    {
        $reason = trim((string) ($payload['Description'] ?? $payload['Details'] ?? $payload['Type'] ?? ''));

        $this->earliest($log, 'bounced_at', $at);

        NotificationLog::whereKey($log->getKey())->update([
            'status' => 'failed',
            'error' => $reason !== '' ? Str::limit($reason, 250, '') : 'הודעה חזרה (bounce)',
        ]);

        // Only a permanent failure retires the address. A full mailbox or a
        // transient server error is not a reason to stop writing to a customer.
        if ($this->isPermanent($payload)) {
            $this->retireAddress($log->customer, (string) $log->recipient, $reason);
        }
    }

    private function complained(NotificationLog $log, Carbon $at): void
    {
        $this->earliest($log, 'complained_at', $at);
        $this->optOutOfMarketing($log->customer, $at);
    }

    /**
     * What an event can still tell us when it matches no message of ours.
     *
     * Only broadcasts carry our tracking header, so a delivery or open for a
     * dunning notice or a ticket reply lands here with nothing to write to. A
     * bounce or a spam report is different in kind: it describes the ADDRESS,
     * and acting on it is what keeps a dead one out of the next broadcast and
     * protects the sender score every other customer's mail depends on.
     *
     * @param  array<string, mixed>  $payload
     * @return bool whether anything was acted on
     */
    private function applyToAddress(string $type, array $payload, Carbon $at): bool
    {
        $address = trim((string) ($payload['Recipient'] ?? $payload['Email'] ?? ''));

        if ($address === '' || ! in_array($type, ['Bounce', 'SpamComplaint'], true)) {
            return false;
        }

        $customer = Customer::whereRaw('LOWER(email) = ?', [$this->normalize($address)])->first();

        if ($customer === null) {
            return false;
        }

        if ($type === 'SpamComplaint') {
            $this->optOutOfMarketing($customer, $at);

            return true;
        }

        if (! $this->isPermanent($payload)) {
            return false;
        }

        $this->retireAddress($customer, $address,
            trim((string) ($payload['Description'] ?? $payload['Details'] ?? $payload['Type'] ?? '')));

        return true;
    }

    /**
     * Pressing "spam" is the clearest opt-out there is; honouring it silently is
     * both correct and the only way to protect our sending reputation for
     * everyone else.
     */
    private function optOutOfMarketing(?Customer $customer, Carbon $at): void
    {
        if ($customer === null || $customer->hasOptedOutOfMarketing()) {
            return;
        }

        $customer->update([
            'marketing_opt_out_at' => $at,
            'marketing_opt_out_channel' => 'סימון כספאם',
        ]);

        SystemLog::record('warning', 'support',
            "הלקוח {$customer->name} סימן הודעה שלנו כספאם והוסר אוטומטית מדיוור פרסומי.",
            ['customer_id' => $customer->id]);
    }

    /** Stop mailing an address the provider says is permanently unreachable. */
    private function retireAddress(?Customer $customer, string $bouncedAddress, string $reason): void
    {
        if ($customer === null || $customer->email_bounced_at !== null) {
            return;
        }

        // The bounce belongs to the address it was sent to. Correcting a typo
        // is exactly what someone does while the old address is still bouncing,
        // and a late webhook for it must not suppress the replacement.
        if ($this->normalize($customer->email) !== $this->normalize($bouncedAddress)) {
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

    /** @param array<string, mixed> $payload */
    private function isPermanent(array $payload): bool
    {
        return (bool) ($payload['Inactive'] ?? false) || $this->isHardBounce($payload);
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
