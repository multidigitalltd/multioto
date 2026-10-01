<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TicketReplyMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int, array{name?: string, mime?: string, path: string, disk?: string}>  $files
     */
    public function __construct(
        public string $subjectLine,
        public string $bodyText,
        public array $files = [],
        public ?string $bodyHtml = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Re: '.$this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.ticket-reply');
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return collect($this->files)
            // A row with no path is a file we refused on the way in, kept on the
            // message so the team can see it was sent. There is nothing to
            // attach, and asking the mailer for it would fail the whole reply.
            ->filter(fn (array $file): bool => filled($file['path'] ?? null))
            ->map(fn (array $file): Attachment => Attachment::fromStorageDisk($file['disk'] ?? 'local', $file['path'])
                ->as($file['name'] ?? 'attachment')
                ->withMime($file['mime'] ?? null))
            ->values()
            ->all();
    }
}
