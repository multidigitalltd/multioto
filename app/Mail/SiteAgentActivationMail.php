<?php

namespace App\Mail;

use App\Models\SiteAgentOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * What a new customer needs in order to actually start using what they bought.
 *
 * Sent because the buyer closes the tab. Everything they need is on the page
 * after payment, and that page is one browser crash, one "back", one phone
 * handed to somebody else away from being gone — and with it the only address
 * that can show the connection codes again.
 *
 * The codes themselves are NOT in this mail, deliberately. They are the keys to
 * the customer's website, and a mailbox is forwarded, synced to phones and
 * breached far more often than anybody plans for. What is here is the link back
 * to the page that shows them, which we can stop serving and which says out loud
 * what it is holding.
 */
class SiteAgentActivationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public SiteAgentOrder $order) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "סוכן האתר פעיל — {$this->order->domain}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.site-agent-activation', with: [
            'order' => $this->order,
            'link' => route('store.agent.done', ['reference' => $this->order->reference]),
            'installedByUs' => $this->order->wantsUsToInstall(),
        ]);
    }
}
