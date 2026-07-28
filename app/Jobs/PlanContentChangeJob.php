<?php

namespace App\Jobs;

use App\Models\Site;
use App\Models\SystemLog;
use App\Models\Ticket;
use App\Services\Agent\ChangeRequestPlanner;
use App\Services\Automation\ApprovalGate;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

/**
 * "בקשת שינוי בוואטסאפ": a customer writes "תוסיפו לדף הבית שאנחנו פתוחים גם
 * בשישי", and within a minute the owner gets a ready-to-approve proposal naming
 * the page and the exact sentence. One "אשר" and it is live, with the customer
 * told what changed.
 *
 * Nothing here touches a site: the job only PLANS and proposes. Every guard is
 * deliberate — one connected site, an open ticket from a known customer, and a
 * plan the model was willing to state precisely — because a wrong edit on a
 * customer's homepage is far worse than a request that waits for a human.
 */
class PlanContentChangeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public array $backoff = [30];

    public function __construct(public int $ticketId, public string $request) {}

    public function handle(ChangeRequestPlanner $planner, ApprovalGate $gate): void
    {
        if (! config('agent.content_requests', true)) {
            return;
        }

        $ticket = Ticket::with('customer')->find($this->ticketId);

        if (! $ticket || $ticket->customer === null) {
            return; // An unidentified sender never drives a change to a site.
        }

        $site = $this->soleConnectedSite($ticket);

        if ($site === null) {
            return; // No connected site, or more than one — a human decides.
        }

        $plan = $planner->plan($ticket, $site, $this->request);

        if ($plan === null) {
            return; // Not a clear content request — the normal support flow owns it.
        }

        $action = $gate->propose(
            'content_change',
            "בקשת שינוי מהלקוח {$ticket->customer->name} באתר {$site->domain}\n"
                ."עמוד: {$plan['page_title']}\n"
                ."להוסיף: \"{$plan['addition']}\"\n"
                .'הלקוח ביקש: '.Str::limit($this->request, 200),
            [
                'site_id' => $site->id,
                'page_id' => $plan['page_id'],
                'page_title' => $plan['page_title'],
                'addition' => $plan['addition'],
            ],
            $ticket->customer->id,
            $ticket->id,
        );

        SystemLog::record('info', 'automation',
            "בקשת שינוי תוכן מהלקוח הוצעה לאישור (פעולה #{$action->id}) — אתר {$site->domain}, עמוד \"{$plan['page_title']}\".",
            ['ticket_id' => $ticket->id, 'action_id' => $action->id]);
    }

    /**
     * The customer's single connected site. With two or more, we cannot know
     * which one the request means — and guessing is not an option here.
     */
    private function soleConnectedSite(Ticket $ticket): ?Site
    {
        $sites = Site::query()
            ->where('customer_id', $ticket->customer->id)
            ->where('mcp_enabled', true)
            ->whereNotNull('mcp_endpoint')
            ->get();

        return $sites->count() === 1 ? $sites->first() : null;
    }
}
