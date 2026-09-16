<?php

namespace App\Services\SiteAgent;

use App\Models\Site;
use App\Models\SiteAgentRequest;
use App\Services\Agent\McpClient;
use Illuminate\Support\Str;

/**
 * Carries out a change the customer approved — and only if the page still
 * looks the way it did when they approved it.
 *
 * The page is re-read at execution time, never written from a snapshot taken
 * when the preview was built. Between the preview and the yes, somebody may
 * have edited that page in wp-admin; writing our snapshot back would silently
 * erase their work, and the customer would have approved "add a sentence", not
 * "restore the page to how it looked ten minutes ago".
 *
 * A replacement whose text is no longer there, or is now there twice, is
 * refused rather than guessed at.
 */
class SiteChangeApplier
{
    /** Thrown so the caller can tell the customer in their own words. */
    public const STALE = 'stale';

    public function __construct(private McpClient $mcp) {}

    /**
     * @return array{ok: bool, reason: string|null, restore: array<string, mixed>|null}
     */
    public function apply(SiteAgentRequest $request): array
    {
        $site = $request->site;
        $plan = (array) $request->plan;
        $pageId = (int) ($plan['page_id'] ?? 0);

        if (! $site instanceof Site || $pageId <= 0) {
            return $this->failure('הבקשה חסרה אתר או עמוד.');
        }

        try {
            $page = $this->read($site, $pageId);
        } catch (\Throwable $e) {
            return $this->failure(Str::limit($e->getMessage(), 200));
        }

        if ($page === null) {
            return $this->failure('לא הצלחתי לקרוא את העמוד באתר.');
        }

        // Unpublished, made private or trashed since the preview. Editing it
        // anyway would report a change live on a page nobody can see.
        if ((string) ($page['status'] ?? '') !== 'publish') {
            return $this->failure('העמוד אינו מפורסם יותר, ולכן לא שיניתי בו דבר.');
        }

        $content = (string) ($page['content'] ?? '');
        $title = (string) ($page['title'] ?? '');
        $text = (string) ($plan['text'] ?? '');

        $update = match ($request->operation) {
            SiteAgentRequest::OP_TITLE => ['title' => $text],
            SiteAgentRequest::OP_APPEND => ['content' => rtrim($content)."\n\n".$text],
            SiteAgentRequest::OP_REPLACE => $this->replacement($content, (string) ($plan['find'] ?? ''), $text),
            default => null,
        };

        if ($update === null) {
            return $this->failure(self::STALE, restore: null);
        }

        // What was there before, captured from the page we just read — so the
        // undo puts back what was actually live, not what we imagined was.
        $restore = [
            'page_id' => $pageId,
            'title' => $title,
            'content' => $content,
        ];

        try {
            $this->mcp->textContent($this->mcp->callTool($site, 'wp_content_update', [
                'id' => $pageId,
                ...$update,
            ], 60));
        } catch (\Throwable $e) {
            return $this->failure(Str::limit($e->getMessage(), 200));
        }

        SiteChangePlanner::forget($site);

        return ['ok' => true, 'reason' => null, 'restore' => $restore];
    }

    /**
     * Put a page back exactly as it was.
     *
     * Writes both title and content together: an undo that restored only the
     * field we happened to change would leave a page that is neither what it
     * was nor what the customer asked for.
     *
     * @return array{ok: bool, reason: string|null}
     */
    public function revert(SiteAgentRequest $request): array
    {
        $site = $request->site;
        $restore = (array) $request->restore;
        $pageId = (int) ($restore['page_id'] ?? 0);

        if (! $site instanceof Site || $pageId <= 0) {
            return ['ok' => false, 'reason' => 'אין לי גיבוי לשחזור הבקשה הזו.'];
        }

        try {
            $this->mcp->textContent($this->mcp->callTool($site, 'wp_content_update', [
                'id' => $pageId,
                'title' => (string) ($restore['title'] ?? ''),
                'content' => (string) ($restore['content'] ?? ''),
            ], 60));
        } catch (\Throwable $e) {
            return ['ok' => false, 'reason' => Str::limit($e->getMessage(), 200)];
        }

        SiteChangePlanner::forget($site);

        return ['ok' => true, 'reason' => null];
    }

    /**
     * The content with the approved replacement made — or null when the text
     * the customer approved is no longer uniquely there.
     *
     * @return array{content: string}|null
     */
    private function replacement(string $content, string $find, string $text): ?array
    {
        if ($find === '' || mb_substr_count($content, $find) !== 1) {
            return null;
        }

        $position = mb_strpos($content, $find);

        return ['content' => mb_substr($content, 0, $position).$text.mb_substr($content, $position + mb_strlen($find))];
    }

    /** @return array{id: int, title: string, content: string, status: string}|null */
    private function read(Site $site, int $pageId): ?array
    {
        $page = json_decode($this->mcp->textContent($this->mcp->callTool($site, 'wp_content_get', [
            'id' => $pageId,
        ])), true);

        if (! is_array($page) || ! isset($page['content'])) {
            return null;
        }

        return [
            'id' => $pageId,
            'title' => (string) ($page['title'] ?? ''),
            'content' => (string) $page['content'],
            'status' => (string) ($page['status'] ?? ''),
        ];
    }

    /** @return array{ok: bool, reason: string|null, restore: null} */
    private function failure(string $reason, ?array $restore = null): array
    {
        return ['ok' => false, 'reason' => $reason, 'restore' => $restore];
    }
}
