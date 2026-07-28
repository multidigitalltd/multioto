<?php

namespace App\Jobs;

use App\Enums\BroadcastChannel;
use App\Enums\BroadcastStatus;
use App\Enums\NotificationType;
use App\Jobs\Concerns\PausesForShabbat;
use App\Mail\BroadcastMail;
use App\Models\Broadcast;
use App\Models\NotificationLog;
use App\Services\Support\BroadcastAudience;
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

    public int $tries = 1;

    public $timeout = 3600;

    private BroadcastAudience $audience;

    public function __construct(public int $broadcastId) {}

    /** @return array<int, int> */
    protected function shabbatDispatchArgs(): array
    {
        return [$this->broadcastId];
    }

    public function handle(WahaClient $waha, BroadcastAudience $audience): void
    {
        if ($this->rescheduledForShabbat()) {
            return;
        }

        $this->audience = $audience;

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

        $sent = 0;

        $this->audience->reachable($broadcast->channel, $broadcast->segment)
            ->chunkById((int) config('billing.broadcasts.email_chunk_size'), function ($customers) use ($broadcast, $waha, &$sent) {
                foreach ($customers as $customer) {
                    // reachable() already excluded customers with no address on
                    // this channel; the guards below only catch whitespace-only
                    // values, which a NOT NULL / != '' filter still lets through.
                    try {
                        if ($broadcast->channel === BroadcastChannel::Email) {
                            if (blank(trim((string) $customer->email))) {
                                continue;
                            }
                            Mail::to($customer->email)->queue(new BroadcastMail($broadcast->subject, $broadcast->body));
                            // Broadcast emails are queued, not sent inline — record as
                            // "queued" so the log doesn't claim delivery that hasn't happened.
                            NotificationLog::record('email', NotificationType::Broadcast, $customer->email, $broadcast->subject, $broadcast->body, $customer->id, 'queued');
                        } else {
                            $chatId = trim((string) $customer->whatsappRecipient());
                            if ($chatId === '') {
                                continue;
                            }
                            $waha->sendMessage($chatId, $broadcast->body);
                            NotificationLog::record('whatsapp', NotificationType::Broadcast, $chatId, null, $broadcast->body, $customer->id);
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
