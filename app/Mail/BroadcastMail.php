<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BroadcastMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  array{is_marketing: bool, business: string, address: ?string, unsubscribe_url: string}  $footer
     *                                                                                                          Sender identity and the opt-out link that an advertising message must
     *                                                                                                          carry. The template renders nothing when is_marketing is false, so a
     *                                                                                                          service announcement stays a plain message.
     */
    public function __construct(
        public string $subjectLine,
        public string $bodyText,
        public array $footer = ['is_marketing' => false, 'business' => '', 'address' => null, 'unsubscribe_url' => ''],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.broadcast');
    }
}
