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
use Illuminate\Support\Collection;
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

        $sites = $this->connectedSites($ticket);

        if ($sites->isEmpty()) {
            $this->note($ticket, '🤖 בדיקת סוכן AI: ללקוח אין אתר מחובר לסוכן, ולכן אין מה לבדוק אוטומטית באתר.');

            return;
        }

        $site = $this->siteForTicket($ticket, $sites);

        // Multiple connected sites and the ticket doesn't name one — don't guess
        // which site to touch. Ask the team to run the check from the right site.
        if (! $site) {
            $domains = $sites->pluck('domain')->implode(', ');
            $this->note($ticket, "🤖 בדיקת סוכן AI: ללקוח כמה אתרים מחוברים ({$domains}) והפנייה לא מציינת באיזה מדובר. "
                .'הפעל "בדיקת סוכן AI" מעמוד האתר הספציפי.');

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

    /** The customer's connected (MCP-enabled) sites. */
    private function connectedSites(Ticket $ticket): Collection
    {
        return $ticket->customer
            ? $ticket->customer->sites()
                ->where('mcp_enabled', true)
                ->whereNotNull('mcp_endpoint')
                ->get()
            : collect();
    }

    /**
     * Pick the site the ticket is actually about. One connected site → it. More
     * than one → only when the ticket text names exactly one of their domains;
     * otherwise null (don't guess which site to operate on).
     *
     * @param  Collection<int, Site>  $sites
     */
    private function siteForTicket(Ticket $ticket, Collection $sites): ?Site
    {
        if ($sites->count() === 1) {
            return $sites->first();
        }

        $haystack = Str::lower($ticket->subject.' '.(string) $ticket->messages()
            ->where('direction', MessageDirection::Inbound)
            ->latest('created_at')
            ->value('body'));

        $named = $sites->filter(fn (Site $s): bool => filled($s->domain)
            && str_contains($haystack, Str::lower($s->domain)))->values();

        return $named->count() === 1 ? $named->first() : null;
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
