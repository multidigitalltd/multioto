<?php

namespace App\Jobs;

use App\Enums\SiteChangeStatus;
use App\Enums\UserRole;
use App\Models\Site;
use App\Models\SystemLog;
use App\Models\User;
use App\Services\Agent\McpClient;
use App\Services\Agent\SiteChangeJournal;
use App\Services\Agent\SiteOperations;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

/**
 * Carry out one operation a manager asked for on a managed site.
 *
 * Queued rather than run inline: it talks to the customer's site over the
 * network, and no HTTP request the panel serves should ever wait on that.
 *
 * Single attempt on purpose. These operations change the live site, and none of
 * them is safe to repeat blindly on a timeout — a retry that lands after a first
 * attempt actually succeeded would do the work twice. A failure is reported, not
 * retried.
 *
 * The result always reaches a person. The operator pressed a button and walked
 * away from the screen; an operation that succeeded, failed or never ran must
 * say so in the notification bell and in the site's change journal, because a
 * silent one is indistinguishable from one that was never dispatched.
 */
class RunSiteOperationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(
        public int $siteId,
        public string $operation,
        public ?int $requestedBy = null,
    ) {}

    public function handle(McpClient $mcp, SiteChangeJournal $journal): void
    {
        $site = Site::find($this->siteId);
        $operation = SiteOperations::find($this->operation);

        if (! $site || $operation === null) {
            return;
        }

        try {
            $this->guard($site);
            $output = $mcp->textContent($mcp->callTool($site, $operation['tool'], $operation['arguments'], $operation['timeout']));
        } catch (\Throwable $e) {
            $journal->record(
                $site,
                summary: $operation['summary'].' — נכשל',
                tool: $operation['tool'],
                arguments: $operation['arguments'],
                initiatedBy: $this->initiator(),
                status: SiteChangeStatus::Failed,
            )->update(['error' => Str::limit($e->getMessage(), 500)]);

            SystemLog::record('warning', 'site-operation',
                "{$operation['label']} נכשלה באתר {$site->domain}: ".Str::limit($e->getMessage(), 300),
                ['site_id' => $site->id, 'operation' => $this->operation]);

            $this->tell($site, $operation['label'].' — נכשלה', Str::limit($e->getMessage(), 300), 'danger');

            return;
        }

        $journal->record(
            $site,
            summary: $operation['summary'],
            tool: $operation['tool'],
            arguments: $operation['arguments'],
            afterState: Str::limit($output, 2000) ?: null,
            initiatedBy: $this->initiator(),
        );

        SystemLog::record('info', 'site-operation',
            "{$operation['label']} בוצעה באתר {$site->domain}.",
            ['site_id' => $site->id, 'operation' => $this->operation]);

        $this->tell($site, $operation['label'].' — בוצעה', $operation['cost'], 'success');
    }

    /**
     * The conditions under which we refuse to touch a customer's site.
     *
     * The kill-switch is honoured here as everywhere else. It says nothing runs
     * on a customer site while it is off, and "a manager pressed the button"
     * is not an exception to it — that is precisely the case it exists for.
     */
    private function guard(Site $site): void
    {
        if (! config('agent.actions_enabled')) {
            throw new \RuntimeException('מנגנון הפעולות על אתרים כבוי (kill-switch). יש להפעיל אותו בהגדרות הסוכן.');
        }

        if (! $site->mcp_enabled || blank($site->mcp_endpoint)) {
            throw new \RuntimeException("חיבור ה-AI לאתר {$site->domain} כבוי או לא מוגדר, ולכן אי אפשר לבצע את הפעולה.");
        }
    }

    private function initiator(): string
    {
        return $this->requestedBy !== null ? 'user:'.$this->requestedBy : 'panel';
    }

    /** Tell whoever asked for it. With nobody named, tell the managers. */
    private function tell(Site $site, string $title, string $body, string $color): void
    {
        $recipients = $this->requestedBy !== null
            ? User::where('id', $this->requestedBy)->get()
            : User::query()->where('role', UserRole::Admin)->get();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::make()
            ->title($site->domain.' — '.$title)
            ->body($body)
            ->icon('heroicon-o-key')
            ->color($color)
            ->sendToDatabase($recipients);
    }
}
