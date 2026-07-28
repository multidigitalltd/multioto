<?php

namespace App\Services\Agent;

use App\Models\PendingAction;
use App\Models\Site;
use App\Models\SiteEvent;
use App\Models\Ticket;
use App\Services\Support\AgentReply;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Executes an APPROVED customer content request: appends the approved paragraph
 * to the page it was approved for, then tells the customer it is done.
 *
 * The page is re-read at execution time and the text appended to whatever is
 * there NOW — never overwritten with a snapshot taken when the proposal was
 * made. An owner who approves an hour later cannot silently wipe an edit made
 * in between.
 */
class ContentChangeRunner
{
    public function __construct(private McpClient $mcp, private AgentReply $reply) {}

    public function run(PendingAction $action): void
    {
        $site = Site::find((int) data_get($action->payload, 'site_id'));
        $pageId = (int) data_get($action->payload, 'page_id');
        $addition = trim((string) data_get($action->payload, 'addition'));

        if (! $site || $pageId <= 0 || $addition === '') {
            throw new \RuntimeException('בקשת השינוי חסרה אתר, עמוד או תוכן.');
        }

        if (! $site->mcp_enabled || blank($site->mcp_endpoint)) {
            throw new \RuntimeException('חיבור ה-AI לאתר כבוי — לא ניתן לעדכן את התוכן.');
        }

        // Read the page as it is RIGHT NOW.
        $current = json_decode($this->mcp->textContent($this->mcp->callTool($site, 'wp_content_get', [
            'id' => $pageId,
        ])), true);

        if (! is_array($current) || ! isset($current['content'])) {
            throw new \RuntimeException('לא ניתן לקרוא את תוכן העמוד.');
        }

        // The page may have been unpublished, made private or trashed between
        // the proposal and the approval. Editing it anyway would mark the action
        // executed and tell the customer the text is live on a page nobody can
        // see — better to fail loudly and let a human look.
        $status = (string) ($current['status'] ?? '');
        $type = (string) ($current['type'] ?? 'page');

        if ($type !== 'page' || $status !== 'publish') {
            throw new \RuntimeException(
                "העמוד אינו עמוד מפורסם יותר (סוג: {$type}, סטטוס: ".($status !== '' ? $status : 'לא ידוע').') — השינוי לא בוצע.'
            );
        }

        $before = (string) $current['content'];
        $paragraph = '<p>'.e($addition).'</p>';

        $this->mcp->callTool($site, 'wp_content_update', [
            'id' => $pageId,
            'content' => rtrim($before)."\n\n".$paragraph,
        ]);

        $title = (string) ($current['title'] ?? "עמוד #{$pageId}");

        SiteEvent::record($site->id, 'content_change', 'info',
            "עודכן תוכן בעמוד \"{$title}\" לבקשת הלקוח",
            Str::limit($addition, 300));

        $this->notifyCustomer($action, $title, $addition);
    }

    /**
     * Tell the customer, on their own ticket, exactly what was added and where.
     * Best-effort: the change is already live, so a messaging failure must not
     * mark the action as failed and invite a second run.
     */
    private function notifyCustomer(PendingAction $action, string $pageTitle, string $addition): void
    {
        $ticket = $action->ticket_id !== null ? Ticket::find($action->ticket_id) : null;

        if ($ticket === null) {
            return;
        }

        try {
            $this->reply->send($ticket,
                "ביצענו את השינוי שביקשת בעמוד \"{$pageTitle}\":\n\n{$addition}\n\nאפשר לראות באתר. אם צריך לשנות משהו — פשוט השיבו כאן.");
        } catch (\Throwable $e) {
            Log::warning('ContentChangeRunner: customer notification failed after an applied change', [
                'action_id' => $action->id, 'error' => $e->getMessage(),
            ]);
        }
    }
}
