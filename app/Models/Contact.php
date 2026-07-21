<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A named contact person for a customer (optionally scoped to one of their
 * sites), with a role. Its email/phone/WhatsApp identifiers are used to
 * associate an inbound message to the right customer.
 */
class Contact extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id', 'site_id', 'name', 'role', 'email', 'phone', 'whatsapp_jid', 'is_primary',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
