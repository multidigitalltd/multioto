<?php

namespace App\Models;

use App\Enums\BusinessType;
use App\Enums\CustomerStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'contact_name', 'business_number', 'business_type', 'vat_exempt', 'email', 'phone',
        'address', 'payment_method', 'terms_accepted_at', 'security_card_terms_at', 'signature_path', 'signed_ip', 'signed_pdf_path',
        'whatsapp_jid', 'cardcom_account_id', 'pending_card_lp_id', 'card_link_token', 'default_token_id', 'status', 'notes',
        'monitoring_report_sent_at', 'onboarding_checklist',
        'marketing_opt_out_at', 'marketing_opt_out_channel',
        'email_bounced_at', 'email_bounce_reason',
    ];

    protected function casts(): array
    {
        return [
            'business_type' => BusinessType::class,
            'status' => CustomerStatus::class,
            'vat_exempt' => 'boolean',
            'terms_accepted_at' => 'datetime',
            'security_card_terms_at' => 'datetime',
            'monitoring_report_sent_at' => 'datetime',
            'onboarding_checklist' => 'array',
            'marketing_opt_out_at' => 'datetime',
            'email_bounced_at' => 'datetime',
        ];
    }

    /**
     * Has this customer asked to stop receiving marketing?
     *
     * Only advertising is affected. Invoices, payment demands, dunning and
     * site-fault alerts are service messages and keep going out — the customer
     * asked not to be marketed to, not to be left in the dark.
     */
    public function hasOptedOutOfMarketing(): bool
    {
        return $this->marketing_opt_out_at !== null;
    }

    /**
     * Did this customer's address come back as permanently undeliverable?
     * Cleared automatically the moment the address itself is corrected — a new
     * address has not bounced, and must not inherit the old one's verdict.
     */
    public function emailHasBounced(): bool
    {
        return $this->email_bounced_at !== null;
    }

    protected static function booted(): void
    {
        static::updating(function (Customer $customer): void {
            if ($customer->isDirty('email')) {
                $customer->email_bounced_at = null;
                $customer->email_bounce_reason = null;
            }
        });
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

    /**
     * The nonce every card-capture link for this customer carries. Generated on
     * first use and stable thereafter, so all outstanding links share it —
     * until revokeCardLinks() rotates it, which invalidates them all at once.
     */
    public function cardLinkToken(): string
    {
        if (blank($this->card_link_token)) {
            $this->forceFill(['card_link_token' => Str::random(40)])->save();
        }

        return $this->card_link_token;
    }

    /** Revoke every outstanding card-capture link by rotating the nonce. */
    public function revokeCardLinks(): void
    {
        $this->forceFill(['card_link_token' => Str::random(40)])->save();
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function paymentTokens(): HasMany
    {
        return $this->hasMany(PaymentToken::class);
    }

    /**
     * Is there a card on file that could actually be charged right now?
     *
     * Asked of the token rows rather than of `default_token_id`: a card that
     * expired, was replaced or was removed by hand is still pointed at by that
     * column, and answering "yes" on the strength of it is how a customer with
     * no usable card reads as covered.
     *
     * And asked through chargeable(), not `status = active`: nothing restamps a
     * card when its printed expiry passes, so status alone would show a green
     * "card on file" for a card every charge would decline.
     */
    public function hasActiveCard(): bool
    {
        return $this->paymentTokens()->chargeable()->exists();
    }

    /**
     * Customers who agreed to leave a security card and never did.
     *
     * The card page is the LAST step of signup and the customer record is
     * already saved by the time they reach it — so closing the tab leaves a
     * customer who looks exactly like one who finished. Nothing else finds
     * them: the missing-card chase runs off subscriptions whose charge date has
     * passed, and a transfer customer has neither a card-collected subscription
     * nor, for weeks after signup, any subscription at all.
     *
     * Keyed on the recorded consent, never on a date or a config value: a
     * customer who was never shown the clause did not agree to it and is not
     * chased for it.
     */
    public function scopeMissingSecurityCard(Builder $query): Builder
    {
        return $query
            ->whereNotNull('security_card_terms_at')
            ->where('status', CustomerStatus::Active)
            ->whereDoesntHave('paymentTokens', fn (Builder $q) => $q->chargeable());
    }

    public function defaultToken(): BelongsTo
    {
        return $this->belongsTo(PaymentToken::class, 'default_token_id');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /** Outbound messages the customer received (email/WhatsApp audit trail). */
    public function notificationLogs(): HasMany
    {
        return $this->hasMany(NotificationLog::class);
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

    /** Plugin licences bought by this customer — what the portal's licences page lists. */
    public function licenses(): HasMany
    {
        return $this->hasMany(License::class);
    }
}
