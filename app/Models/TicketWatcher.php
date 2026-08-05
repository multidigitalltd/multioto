<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An address copied on one ticket: the customer's bookkeeper on a billing
 * question, a supplier on a migration, a colleague who has to stay in the loop.
 *
 * They receive every reply the customer receives and can answer back into the
 * same conversation — but only on this ticket. Being copied on one question is
 * not consent to read everything that customer ever wrote to us.
 */
class TicketWatcher extends Model
{
    protected $fillable = ['ticket_id', 'email', 'name', 'added_by'];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** "שם <כתובת>" when we know the name, otherwise the bare address. */
    public function label(): string
    {
        return filled($this->name) ? "{$this->name} <{$this->email}>" : (string) $this->email;
    }
}
