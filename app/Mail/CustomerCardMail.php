<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The "thank you for opening your card" email sent to the customer after they
 * complete the /join form, with the signed customer-card PDF attached.
 */
class CustomerCardMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $customerName,
        public string $pdf,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'תודה על פתיחת הכרטיס — מולטי דיגיטל');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.customer-card', with: [
            'name' => $this->customerName,
        ]);
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->pdf, 'כרטיס-לקוח-חתום.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
