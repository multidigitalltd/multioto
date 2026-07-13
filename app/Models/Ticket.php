<?php

namespace App\Models;

use App\Enums\TicketChannel;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ticket extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id', 'contact_name', 'contact_handle', 'channel', 'subject',
        'status', 'priority', 'assignee', 'external_thread_ref',
        'first_response_at', 'resolved_at',
    ];

    /**
     * Who the ticket is from, for display: the matched customer's name, or —
     * for an unidentified enquiry — the captured sender identity (name + email
     * for email, pushname + phone for WhatsApp), falling back to a generic label.
     */
    public function senderName(): string
    {
        if ($this->customer) {
            return $this->customer->name;
        }

        $name = trim((string) $this->contact_name);
        $handle = trim((string) $this->contact_handle);

        if ($name !== '' && $handle !== '') {
            return "{$name} · {$handle}";
        }

        return $name !== '' ? $name : ($handle !== '' ? $handle : 'פונה לא מזוהה');
    }

    /**
     * Machine-readable tag appended to outbound email subjects so a reply — from
     * the customer OR an agent, on any subject, and regardless of how the ticket
     * originated — threads back onto this ticket instead of opening a new one.
     */
    public function emailTag(): string
    {
        return "[MD#{$this->id}]";
    }

    /**
     * Extract a ticket id from an inbound subject's [MD#123] tag, if present.
     * The "MD" namespace prevents a foreign system's plain [#123] subject from
     * being mistaken for one of our ticket tags.
     */
    public static function idFromSubject(?string $subject): ?int
    {
        if ($subject !== null && preg_match('/\[MD#(\d+)\]/', $subject, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    protected function casts(): array
    {
        return [
            'channel' => TicketChannel::class,
            'status' => TicketStatus::class,
            'priority' => TicketPriority::class,
            'first_response_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class);
    }
}
