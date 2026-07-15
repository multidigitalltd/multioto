<?php

namespace App\Jobs;

use App\Enums\MessageAuthor;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Models\Site;
use App\Models\Ticket;
use App\Services\Agent\SiteAgent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

/**
 * Runs the AI site operator FOR A TICKET: it finds the customer's connected
 * site, investigates it read-only against what the customer reported, and posts
 * the finding as an internal system note on the ticket — "here's what to do on
 * the site". Any concrete fix the AI proposes goes through the normal approval
 * gate (approvals inbox + WhatsApp), so a manager only approves. Nothing is
 * executed here.
 *
 * This shares the same propose→approve→execute pipeline as every other agent
 * action, so enabling fuller autonomy later (auto-approving low-risk proposals)
 * is a single change in one place — not a rewrite of this flow.
 */
class InvestigateTicketJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public int $ticketId) {}

    public function handle(SiteAgent $agent): void
    {
        $ticket = Ticket::with('customer')->find($this->ticketId);

        if (! $ticket) {
            return;
        }

        $site = $this->connectedSite($ticket);

        if (! $site) {
            $this->note($ticket, '🤖 בדיקת סוכן AI: ללקוח אין אתר מחובר לסוכן, ולכן אין מה לבדוק אוטומטית באתר.');

            return;
        }

        $summary = $agent->investigate($site, $this->goalFrom($ticket, $site));

        if (blank($summary)) {
            $this->note($ticket, "🤖 בדיקת סוכן AI לאתר {$site->domain}: לא הופקה תוצאה (הסוכן כבוי או האתר לא נגיש).");

            return;
        }

        $this->note(
            $ticket,
            "🤖 בדיקת סוכן AI לאתר {$site->domain}:\n\n".Str::limit(trim($summary), 3500)
                ."\n\nכל פעולה שהוצעה ממתינה לאישור ב\"אישורי אוטומציה\" (ובוואטסאפ)."
        );
    }

    /** The customer's connected (MCP-enabled) site, if any. */
    private function connectedSite(Ticket $ticket): ?Site
    {
        return $ticket->customer
            ?->sites()
            ->where('mcp_enabled', true)
            ->whereNotNull('mcp_endpoint')
            ->latest('mcp_last_seen_at')
            ->first();
    }

    /** Turn the ticket into an investigation goal for the agent. */
    private function goalFrom(Ticket $ticket, Site $site): string
    {
        $lastCustomer = $ticket->messages()
            ->where('direction', MessageDirection::Inbound)
            ->latest('created_at')
            ->value('body');

        return trim(sprintf(
            "פנייה של לקוח לגבי האתר %s.\nנושא: %s\n%s\nחקור את האתר והצע תיקון בטוח אם נדרש.",
            $site->domain,
            (string) $ticket->subject,
            filled($lastCustomer) ? 'תיאור הלקוח: '.Str::limit((string) $lastCustomer, 800) : '',
        ));
    }

    /** Record an internal (team-only) note on the ticket. */
    private function note(Ticket $ticket, string $body): void
    {
        $ticket->messages()->create([
            'direction' => MessageDirection::Outbound,
            'channel' => MessageChannel::InternalNote,
            'body' => $body,
            'author' => MessageAuthor::Ai,
        ]);
    }
}
