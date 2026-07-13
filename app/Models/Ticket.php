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
