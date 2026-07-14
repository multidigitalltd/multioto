<?php

namespace App\Models;

use App\Enums\BusinessType;
use App\Enums\CustomerStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'contact_name', 'business_number', 'business_type', 'vat_exempt', 'email', 'phone',
        'address', 'payment_method', 'terms_accepted_at', 'signature_path', 'signed_ip', 'signed_pdf_path',
        'whatsapp_jid', 'cardcom_account_id', 'pending_card_lp_id', 'default_token_id', 'status', 'notes',
        'monitoring_report_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'business_type' => BusinessType::class,
            'status' => CustomerStatus::class,
            'vat_exempt' => 'boolean',
            'terms_accepted_at' => 'datetime',
            'monitoring_report_sent_at' => 'datetime',
        ];
    }

    /**
     * The best WhatsApp destination for this customer: the stored chat id when
     * present, otherwise the phone number. One accessor so every sender resolves
     * the recipient the same way (an empty jid falls through to the phone).
     */
    public function whatsappRecipient(): ?string
    {
        return filled($this->whatsapp_jid) ? $this->whatsapp_jid : $this->phone;
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function paymentTokens(): HasMany
    {
        return $this->hasMany(PaymentToken::class);
    }

    public function defaultToken(): BelongsTo
    {
        return $this->belongsTo(PaymentToken::class, 'default_token_id');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /** Tickets newest-first — for the customer card's "פניות" list. */
    public function recentTickets(): HasMany
    {
        return $this->tickets()->latest();
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
