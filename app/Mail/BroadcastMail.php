<?php

namespace App\Mail;

use App\Listeners\RecordProviderMessageId;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

class BroadcastMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  array{is_marketing: bool, business: string, support: ?string, note: string, unsubscribe_url: ?string}  $footer
     *                                                                                                                         Sender identity and the opt-out link that an advertising message must
     *                                                                                                                         carry. The template renders nothing when is_marketing is false, so a
     *                                                                                                                         service announcement stays a plain message.
     */
    public function __construct(
        public string $subjectLine,
        public string $bodyText,
        public array $footer = ['is_marketing' => false, 'business' => '', 'support' => null, 'note' => '', 'unsubscribe_url' => null],
        public ?string $bodyHtml = null,
        public ?int $logId = null,
    ) {}

    /**
     * Two headers, both for measurement:
     *  - our notification-log id, so the provider's delivery/open/bounce event
     *    can be matched back to the exact row (RecordProviderMessageId);
     *  - Postmark's open-tracking switch, enabled for broadcasts only. A
     *    transactional mail (an invoice, a password link) is not something we
     *    want to know the reading habits of.
     */
    public function headers(): Headers
    {
        $text = ['X-PM-TrackOpens' => 'true'];

        if ($this->logId !== null) {
            $text[RecordProviderMessageId::LOG_HEADER] = (string) $this->logId;
        }

        return new Headers(text: $text);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.broadcast');
    }
}
