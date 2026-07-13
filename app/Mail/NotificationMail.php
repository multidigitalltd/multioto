<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Generic templated notification (acknowledgement, status update…). The body
 * is plain text rendered by TemplateEngine; the blade escapes it and converts
 * newlines, so template content can never inject markup.
 */
class NotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $subjectLine,
        public string $bodyText,
        // Not named $replyTo — that clashes with Mailable's own property.
        public ?string $replyToAddress = null,
    ) {}

    public function envelope(): Envelope
    {
        // A Reply-To routes a team member's reply to the inbound support address,
        // so replying to a ticket alert reaches the customer (see IngestEmailMessageJob).
        return new Envelope(
            subject: $this->subjectLine,
            replyTo: $this->replyToAddress !== null ? [new Address($this->replyToAddress)] : [],
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.notification');
    }
}
