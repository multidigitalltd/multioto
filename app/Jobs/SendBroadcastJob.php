<?php

namespace App\Jobs;

use App\Enums\BroadcastChannel;
use App\Enums\BroadcastStatus;
use App\Enums\CustomerStatus;
use App\Enums\NotificationType;
use App\Mail\BroadcastMail;
use App\Models\Broadcast;
use App\Models\Customer;
use App\Models\NotificationLog;
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
    use Queueable;

    public int $tries = 1;

    public $timeout = 3600;

    public function __construct(public int $broadcastId) {}

    public function handle(WahaClient $waha): void
    {
        $broadcast = Broadcast::find($this->broadcastId);

        if (! $broadcast || $broadcast->status === BroadcastStatus::Sent) {
            return;
        }

        $broadcast->update(['status' => BroadcastStatus::Sending]);

        $sent = 0;

        $this->segmentQuery($broadcast)
            ->chunkById((int) config('billing.broadcasts.email_chunk_size'), function ($customers) use ($broadcast, $waha, &$sent) {
                foreach ($customers as $customer) {
                    try {
                        if ($broadcast->channel === BroadcastChannel::Email) {
                            if (! $customer->email) {
                                continue;
                            }
                            Mail::to($customer->email)->queue(new BroadcastMail($broadcast->subject, $broadcast->body));
                            // Broadcast emails are queued, not sent inline — record as
                            // "queued" so the log doesn't claim delivery that hasn't happened.
                            NotificationLog::record('email', NotificationType::Broadcast, $customer->email, $broadcast->subject, $broadcast->body, $customer->id, 'queued');
                        } else {
                            $chatId = $customer->whatsapp_jid ?? $customer->phone;
                            if (! $chatId) {
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

    /**
     * Build the recipient query from the stored segment definition.
     * Supported filters: status, plan_ids, customer_ids.
     */
    protected function segmentQuery(Broadcast $broadcast)
    {
        $segment = $broadcast->segment ?? [];

        return Customer::query()
            ->where('status', $segment['status'] ?? CustomerStatus::Active->value)
            ->when($segment['customer_ids'] ?? null, fn ($q, $ids) => $q->whereKey($ids))
            ->when($segment['plan_ids'] ?? null, fn ($q, $planIds) => $q->whereHas(
                'subscriptions', fn ($sq) => $sq->whereIn('plan_id', $planIds),
            ))
            ->orderBy('id');
    }
}
