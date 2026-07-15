<?php

namespace App\Models;

use App\Enums\MessageAuthor;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Support\EmailBody;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketMessage extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'ticket_id', 'direction', 'channel', 'body', 'body_html', 'external_message_id', 'author', 'attachments',
        'quality_rating',
    ];

    protected function casts(): array
    {
        return [
            'direction' => MessageDirection::class,
            'channel' => MessageChannel::class,
            'author' => MessageAuthor::class,
            'attachments' => 'array',
            'quality_rating' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Whether this message is a reply the team can rate 1–10 — a sent agent
     * answer or an AI draft. Inbound/customer and system messages are not rated.
     */
    public function isRateable(): bool
    {
        $sentAgentReply = $this->channel !== MessageChannel::InternalNote && $this->author === MessageAuthor::Agent;
        $aiDraft = $this->author === MessageAuthor::Ai && str_contains((string) $this->body, 'טיוטת תשובה');

        return $sentAgentReply || $aiDraft;
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * Display-safe rich body, or null when there is nothing to show. The stored
     * `body_html` is run through the allow-list sanitizer at render time too:
     * it is idempotent for already-clean rows, but it also balances and secures
     * any legacy/raw HTML — malformed markup (unclosed tags) would otherwise
     * corrupt the Livewire component's DOM and break the reply editor.
     */
    public function safeBodyHtml(): ?string
    {
        return EmailBody::toSafeHtml($this->body_html);
    }
}
