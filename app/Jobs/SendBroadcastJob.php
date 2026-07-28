<?php

namespace App\Jobs;

use App\Enums\BroadcastChannel;
use App\Enums\BroadcastStatus;
use App\Enums\NotificationType;
use App\Jobs\Concerns\PausesForShabbat;
use App\Mail\BroadcastMail;
use App\Models\Broadcast;
use App\Models\NotificationLog;
use App\Models\SystemLog;
use App\Services\Support\BroadcastAudience;
use App\Services\Support\BroadcastRenderer;
use App\Services\Waha\WahaClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

/**
 * Deliver a broadcast to its customer segment.
 *
 * Email is the default for wide sends (chunked). WhatsApp broadcasts are
 * intentionally slow — an aggressive per-message throttle mitigates the
 * number-ban risk of the unofficial transport (§7); keep segments small.
 */
class SendBroadcastJob implements ShouldQueue
{
    use PausesForShabbat;
    use Queueable;

    /** Seconds a single send may take before the worker kills it. */
    public const TIMEOUT_SECONDS = 3600;

    /**
     * Seconds reserved inside the timeout for everything that is not a
     * throttle sleep: the segment queries, the per-recipient sends and the
     * final status write.
     */
    private const OVERHEAD_SECONDS = 300;

    public int $tries = 1;

    public $timeout = self::TIMEOUT_SECONDS;

    private BroadcastAudience $audience;

    private BroadcastRenderer $renderer;

    public function __construct(public int $broadcastId) {}

    /** @return array<int, int> */
    protected function shabbatDispatchArgs(): array
    {
        return [$this->broadcastId];
    }

    /**
     * How many WhatsApp recipients one run can finish before the worker kills
     * it. The throttle sleeps alone dominate: at the default 30 seconds a
     * 200-recipient audience needs 100 minutes, so the job would die halfway
     * through, leaving the broadcast stuck on "בשליחה" with no safe retry —
     * a retry would message everyone who already received it a second time.
     */
    public static function maxWhatsappRecipients(): int
    {
        $throttle = max(1, (int) config('billing.waha.broadcast_throttle_seconds'));

        return max(1, intdiv(self::TIMEOUT_SECONDS - self::OVERHEAD_SECONDS, $throttle));
    }

    public function handle(WahaClient $waha, BroadcastAudience $audience, BroadcastRenderer $renderer): void
    {
        if ($this->rescheduledForShabbat()) {
            return;
        }

        $this->audience = $audience;
        $this->renderer = $renderer;

        // Claim the broadcast atomically. "שלח עכשיו" dispatches directly while
        // the five-minute scheduler may dispatch the same scheduled row, so two
        // jobs can race — and a broadcast has no per-recipient dedupe, meaning a
        // second run would mail every customer twice. Only a row still waiting
        // to be sent can be claimed; whoever loses the race exits here.
        $claimed = Broadcast::whereKey($this->broadcastId)
            ->whereIn('status', [BroadcastStatus::Draft, BroadcastStatus::Scheduled])
            ->update(['status' => BroadcastStatus::Sending]);

        if ($claimed === 0) {
            return;
        }

        $broadcast = Broadcast::find($this->broadcastId);

        if (! $broadcast) {
            return;
        }

        // A WhatsApp audience too large to finish inside the timeout must never
        // START: a half-sent broadcast is worse than an unsent one, because the
        // row stays on "בשליחה" and the only way forward messages the first
        // half twice. Hand it back as a draft so the scheduler stops picking it
        // up and the operator sees why.
        if ($broadcast->channel === BroadcastChannel::Whatsapp) {
            $max = self::maxWhatsappRecipients();
            $count = $this->audience->reachable($broadcast->channel, $broadcast->segment,
                marketing: (bool) $broadcast->is_marketing)->count();

            if ($count > $max) {
                $broadcast->update(['status' => BroadcastStatus::Draft, 'scheduled_at' => null]);

                SystemLog::record('error', 'support',
                    "הדיוור \"{$broadcast->subject}\" לא נשלח: {$count} נמענים בוואטסאפ, "
                        ."והמקסימום לשליחה אחת הוא {$max} (בגלל ההשהיה בין הודעות). "
                        .'צמצמו את קהל היעד או פצלו לכמה דיוורים.',
                    ['broadcast_id' => $broadcast->id]);

                return;
            }
        }

        $sent = 0;

        $this->audience
            ->reachable($broadcast->channel, $broadcast->segment, marketing: (bool) $broadcast->is_marketing)
            // The placeholders need the customer's site and plan; loading them
            // per row would be an N+1 across the whole customer base.
            ->with(['sites:id,customer_id,domain', 'subscriptions.plan:id,name'])
            ->chunkById((int) config('billing.broadcasts.email_chunk_size'), function ($customers) use ($broadcast, $waha, &$sent) {
                foreach ($customers as $customer) {
                    // reachable() already excluded customers with no address on
                    // this channel; the guards below only catch whitespace-only
                    // values, which a NOT NULL / != '' filter still lets through.
                    try {
                        // Rendered per customer: placeholders resolve against
                        // this customer, and the opt-out link is theirs alone.
                        $subject = $this->renderer->subject($broadcast, $customer);
                        $body = $this->renderer->body($broadcast, $customer);

                        if ($broadcast->channel === BroadcastChannel::Email) {
                            if (blank(trim((string) $customer->email))) {
                                continue;
                            }
                            // The log row is created BEFORE the send so its id can
                            // ride along in a header — that is what lets the
                            // provider's delivery/open/bounce event find this exact
                            // row later. Recorded as "queued", not "sent": at this
                            // point the message has only reached our own queue.
                            $log = NotificationLog::record(
                                'email', NotificationType::Broadcast, $customer->email,
                                $subject, $body, $customer->id, 'queued', null, $broadcast->id,
                            );

                            Mail::to($customer->email)->queue(new BroadcastMail(
                                $subject, $body,
                                $this->renderer->emailFooter($broadcast, $customer),
                                $this->renderer->bodyHtml($broadcast, $customer),
                                $log?->id,
                            ));
                        } else {
                            $chatId = trim((string) $customer->whatsappRecipient());
                            if ($chatId === '') {
                                continue;
                            }
                            $waha->sendMessage($chatId, $body);
                            NotificationLog::record(
                                'whatsapp', NotificationType::Broadcast, $chatId,
                                null, $body, $customer->id, 'sent', null, $broadcast->id,
                            );
                            sleep((int) config('billing.waha.broadcast_throttle_seconds'));
                        }

                        $sent++;
                    } catch (\Throwable $e) {
                        report($e); // One bad recipient must not kill the whole send.
                    }
                }

                $broadcast->update(['sent_count' => $sent]);
            });

        $broadcast->update(['status' => BroadcastStatus::Sent, 'sent_count' => $sent]);
    }
}
