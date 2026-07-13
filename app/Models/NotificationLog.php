<?php

namespace App\Models;

use App\Enums\NotificationType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * One recorded outbound message to a customer. Written centrally by every send
 * path so the team has a single, searchable history of everything that left the
 * system. Never the source of truth for delivery — just an honest audit trail.
 */
class NotificationLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id', 'channel', 'type', 'recipient',
        'subject', 'body', 'status', 'error', 'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => NotificationType::class,
            'sent_at' => 'datetime',
        ];
    }

    /**
     * Record a sent (or failed) customer message. Best-effort by design:
     * logging must never break the actual send, so any failure here is
     * swallowed to the application log rather than thrown.
     */
    public static function record(
        string $channel,
        NotificationType $type,
        ?string $recipient,
        ?string $subject,
        ?string $body,
        ?int $customerId = null,
        string $status = 'sent',
        ?string $error = null,
    ): void {
        try {
            static::create([
                'customer_id' => $customerId,
                'channel' => $channel,
                'type' => $type,
                'recipient' => $recipient !== null ? Str::limit($recipient, 250, '') : null,
                'subject' => $subject !== null ? Str::limit($subject, 250, '') : null,
                'body' => $body,
                'status' => $status,
                'error' => $error !== null ? Str::limit($error, 250, '') : null,
                'sent_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('NotificationLog record failed', ['error' => $e->getMessage()]);
        }
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
