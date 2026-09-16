<?php

namespace App\Services\SiteAgent;

use App\Models\Site;
use App\Models\SiteAgentRequest;
use App\Services\Agent\McpClient;
use Illuminate\Support\Facades\Storage;
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

        if (! $site instanceof Site) {
            return $this->failure('הבקשה חסרה אתר.');
        }

        return match ($request->operation) {
            SiteAgentRequest::OP_PRICE, SiteAgentRequest::OP_STOCK => $this->applyProduct($site, $plan),
            SiteAgentRequest::OP_IMAGE => $this->applyImage($site, $plan),
            default => $this->applyPage($site, $request, $plan),
        };
    }

    /**
     * A text edit on a page.
     *
     * @param  array<string, mixed>  $plan
     * @return array{ok: bool, reason: string|null, restore: array<string, mixed>|null}
     */
    private function applyPage(Site $site, SiteAgentRequest $request, array $plan): array
    {
        $pageId = (int) ($plan['page_id'] ?? 0);

        if ($pageId <= 0) {
            return $this->failure('הבקשה חסרה עמוד.');
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
     * A price or stock change on one product.
     *
     * WooCommerce hands back the product's previous state, and that is what the
     * undo keeps — not what we read a moment earlier. Between reading and
     * writing, a sale can start, an order can drop the stock, and putting back
     * a number we saw before either of those would be inventing a state the
     * shop was never in.
     *
     * @param  array<string, mixed>  $plan
     * @return array{ok: bool, reason: string|null, restore: array<string, mixed>|null}
     */
    private function applyProduct(Site $site, array $plan): array
    {
        $productId = (int) ($plan['product_id'] ?? 0);
        $fields = (array) ($plan['fields'] ?? []);

        if ($productId <= 0 || $fields === []) {
            return $this->failure('הבקשה חסרה מוצר או ערכים לעדכון.');
        }

        try {
            $result = json_decode($this->mcp->textContent($this->mcp->callTool($site, 'wc_product_update', [
                'product_id' => $productId,
                ...$fields,
            ], 60)), true);
        } catch (\Throwable $e) {
            return $this->failure(Str::limit($e->getMessage(), 200));
        }

        $previous = (array) data_get($result, 'previous', data_get($result, 'before', []));

        if ($previous === []) {
            // The change may well have happened, but without the shop's own
            // "before" there is nothing honest to offer as an undo — so the
            // customer is told that rather than promised one that would guess.
            return ['ok' => true, 'reason' => null, 'restore' => null];
        }

        return ['ok' => true, 'reason' => null, 'restore' => [
            'kind' => 'product',
            'product_id' => $productId,
            'fields' => array_intersect_key($previous, $fields),
        ]];
    }

    /**
     * The image the customer sent, uploaded and set as the featured image.
     *
     * Two steps that must not half-happen: an upload with no attachment leaves
     * a file in the library nobody asked for, and a thumbnail set from an
     * upload that failed would point at nothing. So the upload is confirmed
     * before the second call is made at all.
     *
     * @param  array<string, mixed>  $plan
     * @return array{ok: bool, reason: string|null, restore: array<string, mixed>|null}
     */
    private function applyImage(Site $site, array $plan): array
    {
        $targetId = (int) ($plan['target_id'] ?? 0);
        $alt = trim((string) ($plan['alt'] ?? ''));
        $path = (string) ($plan['image_path'] ?? '');
        $extension = (string) ($plan['extension'] ?? 'jpg');
        $bytes = $path !== '' && Storage::disk('local')->exists($path)
            ? (string) Storage::disk('local')->get($path)
            : '';

        // The description is required by the plugin, by WCAG and by ת"י 5568.
        // Reaching here without one means a planner let it through.
        if ($targetId <= 0 || $alt === '' || $bytes === '') {
            return $this->failure('חסר יעד, תיאור או קובץ לתמונה.');
        }

        // The filename is ours, never the sender's: a name that arrived with
        // the file is a name an attacker chose.
        $filename = 'whatsapp-'.now()->format('Ymd-His').'-'.Str::random(6).'.'.$extension;

        try {
            $uploaded = json_decode($this->mcp->textContent($this->mcp->callTool($site, 'wp_media_upload', [
                'filename' => $filename,
                'data' => base64_encode($bytes),
                'alt' => $alt,
            ], 120)), true);
        } catch (\Throwable $e) {
            return $this->failure(Str::limit($e->getMessage(), 200));
        }

        $attachmentId = (int) data_get($uploaded, 'id', data_get($uploaded, 'attachment_id', 0));

        if ($attachmentId <= 0) {
            return $this->failure('התמונה לא נשמרה בספריית המדיה.');
        }

        try {
            $set = json_decode($this->mcp->textContent($this->mcp->callTool($site, 'wp_post_thumbnail_set', [
                'id' => $targetId,
                'attachment_id' => $attachmentId,
            ], 60)), true);
        } catch (\Throwable $e) {
            return $this->failure(Str::limit($e->getMessage(), 200));
        }

        SiteChangePlanner::forget($site);

        // The file is on the customer's site now; our copy has done its job.
        if ($path !== '') {
            Storage::disk('local')->delete($path);
        }

        return ['ok' => true, 'reason' => null, 'restore' => [
            'kind' => 'thumbnail',
            'target_id' => $targetId,
            // 0 is meaningful: it means the page had no featured image before,
            // and the undo has to be able to put "none" back.
            'attachment_id' => (int) data_get($set, 'previous', data_get($set, 'previous_attachment_id', 0)),
        ]];
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

        if (! $site instanceof Site || $restore === []) {
            return ['ok' => false, 'reason' => 'אין לי גיבוי לשחזור הבקשה הזו.'];
        }

        $kind = (string) ($restore['kind'] ?? 'page');

        if ($kind === 'product') {
            return $this->revertProduct($site, $restore);
        }

        if ($kind === 'thumbnail') {
            return $this->revertThumbnail($site, $restore);
        }

        $pageId = (int) ($restore['page_id'] ?? 0);

        if ($pageId <= 0) {
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
     * @param  array<string, mixed>  $restore
     * @return array{ok: bool, reason: string|null}
     */
    private function revertProduct(Site $site, array $restore): array
    {
        $fields = (array) ($restore['fields'] ?? []);

        if ($fields === []) {
            return ['ok' => false, 'reason' => 'אין לי את הערכים הקודמים של המוצר.'];
        }

        try {
            $this->mcp->textContent($this->mcp->callTool($site, 'wc_product_update', [
                'product_id' => (int) ($restore['product_id'] ?? 0),
                ...$fields,
            ], 60));
        } catch (\Throwable $e) {
            return ['ok' => false, 'reason' => Str::limit($e->getMessage(), 200)];
        }

        return ['ok' => true, 'reason' => null];
    }

    /**
     * @param  array<string, mixed>  $restore
     * @return array{ok: bool, reason: string|null}
     */
    private function revertThumbnail(Site $site, array $restore): array
    {
        try {
            // 0 removes the featured image, which is the correct undo for a
            // page that had none before.
            $this->mcp->textContent($this->mcp->callTool($site, 'wp_post_thumbnail_set', [
                'id' => (int) ($restore['target_id'] ?? 0),
                'attachment_id' => (int) ($restore['attachment_id'] ?? 0),
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
