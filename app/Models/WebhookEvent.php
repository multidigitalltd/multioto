<?php

namespace App\Models;

use App\Enums\WebhookSource;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Audit + idempotency log for every inbound webhook.
 *
 * Use WebhookEvent::record() as the single entry point: it inserts the event
 * once per (unique) external id and reports whether this delivery is a duplicate.
 */
class WebhookEvent extends Model
{
    use HasFactory;

    protected $fillable = ['source', 'event_type', 'external_id', 'payload', 'processed_at'];

    protected function casts(): array
    {
        return [
            'source' => WebhookSource::class,
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * Record an inbound webhook delivery.
     *
     * @return array{0: self, 1: bool} The event row and whether it was freshly created
     *                                 (false = duplicate delivery, skip processing).
     */
    public static function record(WebhookSource $source, string $eventType, ?string $externalId, array $payload): array
    {
        if ($externalId === null) {
            return [self::create([
                'source' => $source,
                'event_type' => $eventType,
                'payload' => $payload,
            ]), true];
        }

        // Idempotency is scoped by source: the same external_id from two different
        // providers is two distinct events, not a duplicate.
        $event = self::firstOrCreate(
            ['source' => $source, 'external_id' => $externalId],
            ['event_type' => $eventType, 'payload' => $payload],
        );

        return [$event, $event->wasRecentlyCreated];
    }

    public function markProcessed(): void
    {
        $this->update(['processed_at' => now()]);
    }
}
