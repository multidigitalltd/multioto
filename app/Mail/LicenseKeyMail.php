<?php

namespace App\Mail;

use App\Models\License;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The one message that carries a licence key.
 *
 * The key exists in exactly two places after this: the customer's inbox and the
 * shops they install it on. Nothing in our system can read it back — so this
 * mail is not a copy of a record, it IS the record, and that is said in it.
 */
class LicenseKeyMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public License $license,
        public string $key,
        public bool $isReplacement = false,
    ) {}

    public function envelope(): Envelope
    {
        $plugin = $this->license->product?->name ?? 'התוסף';

        return new Envelope(subject: $this->isReplacement
            ? "מפתח רישיון חדש ל{$plugin}"
            : "מפתח הרישיון שלך ל{$plugin}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.license-key', with: [
            'license' => $this->license,
            'key' => $this->key,
            'isReplacement' => $this->isReplacement,
            'plugin' => $this->license->product?->name ?? 'התוסף',
            'expires' => $this->license->expires_at?->format('d/m/Y'),
            'sites' => $this->license->isUnlimited() ? null : $this->license->sites_limit,
        ]);
    }
}
