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

    public function __construct(private McpClient $mcp, private SiteChangePlanner $planner) {}

    /**
     * @return array{ok: bool, reason: string|null, message: string|null, restore: array<string, mixed>|null}
     */
    public function apply(SiteAgentRequest $request): array
    {
        $site = $request->site;
        $plan = (array) $request->plan;

        if (! $site instanceof Site) {
            return $this->refuse('הבקשה חסרה אתר.');
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
     * @return array{ok: bool, reason: string|null, message: string|null, restore: array<string, mixed>|null}
     */
    private function applyPage(Site $site, SiteAgentRequest $request, array $plan): array
    {
        $pageId = (int) ($plan['page_id'] ?? 0);

        if ($pageId <= 0) {
            return $this->refuse('הבקשה חסרה עמוד.');
        }

        try {
            $page = $this->read($site, $pageId);
        } catch (\Throwable $e) {
            return $this->failure(Str::limit($e->getMessage(), 200));
        }

        if ($page === null) {
            return $this->refuse('לא הצלחתי לקרוא את העמוד באתר.');
        }

        // Unpublished, made private or trashed since the preview. Editing it
        // anyway would report a change live on a page nobody can see.
        if ((string) ($page['status'] ?? '') !== 'publish') {
            return $this->refuse('העמוד אינו מפורסם יותר, ולכן לא שיניתי בו דבר.');
        }

        $content = (string) ($page['content'] ?? '');
        $title = (string) ($page['title'] ?? '');
        $text = (string) ($plan['text'] ?? '');

        // A title change that would overwrite somebody else's rename.
        //
        // The page is re-read for every operation, but only the CONTENT was
        // being compared: a title approved against "צור קשר" was written over
        // whatever the title had become since, which is the same silent
        // overwrite the re-read exists to prevent.
        if ($request->operation === SiteAgentRequest::OP_TITLE
            && array_key_exists('page_title', $plan)
            && ! $this->sameText($title, (string) $plan['page_title'])) {
            return $this->refuse(self::STALE);
        }

        // The visible text of an Elementor page is not in `content`, so the
        // ordinary update would change a field nobody reads and report success.
        if ((bool) ($plan['elementor'] ?? false) && $request->operation === SiteAgentRequest::OP_REPLACE) {
            return $this->applyElementor($site, $pageId, (string) ($plan['find'] ?? ''), $text);
        }

        $update = match ($request->operation) {
            SiteAgentRequest::OP_TITLE => ['title' => $text],
            SiteAgentRequest::OP_APPEND => ['content' => rtrim($content)."\n\n".$text],
            SiteAgentRequest::OP_REPLACE => $this->replacement($content, (string) ($plan['find'] ?? ''), $text),
            default => null,
        };

        if ($update === null) {
            return $this->refuse(self::STALE);
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

        // And what the page looks like now that our change is on it — read
        // back rather than assumed, because WordPress sanitises and normalises
        // on save, and an undo comparing against the text WE sent would find a
        // mismatch every time and refuse every legitimate undo.
        //
        // This is what makes the undo safe: revert() checks the page still
        // looks like this before writing the old content back, so an edit made
        // in wp-admin afterwards is never silently erased.
        $restore['after'] = $this->stateAfter($site, $pageId, [
            'title' => (string) ($update['title'] ?? $title),
            'content' => (string) ($update['content'] ?? $content),
        ]);

        return ['ok' => true, 'reason' => null, 'message' => null, 'restore' => $restore];
    }

    /**
     * A text replacement inside an Elementor page.
     *
     * Elementor stores what the visitor reads as widget settings, not as the
     * post content — the plugin says so on every read. Writing `content` there
     * changes a field nobody sees, and the agent would tell the customer their
     * page was updated when nothing on it moved.
     *
     * The widget is found at execution time, by the same rule the page path
     * uses: the approved text must be in exactly one widget, exactly once.
     * Anything else is us not knowing which words they meant.
     *
     * @return array{ok: bool, reason: string|null, message: string|null, restore: array<string, mixed>|null}
     */
    private function applyElementor(Site $site, int $pageId, string $find, string $text): array
    {
        if ($find === '') {
            return $this->refuse(self::STALE);
        }

        $matches = array_values(array_filter(
            $this->planner->elementorTexts($site, $pageId),
            fn (array $widget): bool => mb_substr_count($widget['text'], $find) === 1,
        ));

        if (count($matches) !== 1) {
            return $this->refuse(self::STALE);
        }

        $widget = $matches[0];
        $replaced = $this->replacement($widget['text'], $find, $text);

        if ($replaced === null) {
            return $this->refuse(self::STALE);
        }

        try {
            $result = json_decode($this->mcp->textContent($this->mcp->callTool($site, 'wp_elementor_text_update', [
                'id' => $pageId,
                'widget_id' => $widget['widget_id'],
                'setting' => $widget['setting'],
                'text' => $replaced['content'],
            ], 60)), true);
        } catch (\Throwable $e) {
            return $this->failure(Str::limit($e->getMessage(), 200));
        }

        SiteChangePlanner::forget($site);

        return ['ok' => true, 'reason' => null, 'message' => null, 'restore' => [
            'kind' => 'elementor',
            'page_id' => $pageId,
            'widget_id' => $widget['widget_id'],
            'setting' => (string) data_get($result, 'setting', $widget['setting']),
            // Elementor's own "before", not the text we read a moment earlier.
            'text' => (string) data_get($result, 'previous', $widget['text']),
            'after' => $replaced['content'],
        ]];
    }

    /**
     * Put one Elementor text back, if it is still the text we left.
     *
     * @param  array<string, mixed>  $restore
     * @return array{ok: bool, reason: string|null, message: string|null}
     */
    private function revertElementor(Site $site, array $restore): array
    {
        $pageId = (int) ($restore['page_id'] ?? 0);
        $widgetId = (string) ($restore['widget_id'] ?? '');

        if ($pageId <= 0 || $widgetId === '') {
            return $this->refuse('אין לי גיבוי לשחזור הבקשה הזו.');
        }

        $setting = (string) ($restore['setting'] ?? '');

        // Both, not just the widget. One Elementor widget can carry several
        // editable settings — an icon box has a title and a description, a
        // repeater has a path per row — so matching the id alone compares the
        // wrong text and refuses a perfectly safe undo.
        $live = collect($this->planner->elementorTexts($site, $pageId))
            ->first(fn (array $text): bool => $text['widget_id'] === $widgetId
                && (string) $text['setting'] === $setting);

        if ($live === null) {
            return $this->refuse('לא הצלחתי לקרוא את העמוד באתר.');
        }

        if (! $this->sameText((string) $live['text'], (string) ($restore['after'] ?? ''))) {
            return $this->refuse(self::STALE);
        }

        try {
            $this->mcp->textContent($this->mcp->callTool($site, 'wp_elementor_text_update', [
                'id' => $pageId,
                'widget_id' => $widgetId,
                'setting' => $setting,
                'text' => (string) ($restore['text'] ?? ''),
            ], 60));
        } catch (\Throwable $e) {
            return $this->failure(Str::limit($e->getMessage(), 200));
        }

        SiteChangePlanner::forget($site);

        return ['ok' => true, 'reason' => null, 'message' => null];
    }

    /**
     * The page as it stands now, or — if it cannot be read back — what we sent.
     *
     * A failed read must not fail a change that already happened, so the
     * fallback is our own text. It may not match byte for byte after the site's
     * own filtering, in which case the undo refuses and says so; refusing an
     * undo is recoverable, and overwriting somebody's work is not.
     *
     * @param  array{title: string, content: string}  $sent
     * @return array{title: string, content: string}
     */
    private function stateAfter(Site $site, int $pageId, array $sent): array
    {
        try {
            $page = $this->read($site, $pageId);
        } catch (\Throwable) {
            return $sent;
        }

        return $page === null
            ? $sent
            : ['title' => $page['title'], 'content' => $page['content']];
    }

    /**
     * The attachment the thumbnail tool says it displaced.
     *
     * The plugin answers `previous: {attachment_id: 42}` — an OBJECT. Casting
     * that to int gives 1, so the undo either installed attachment #1 or
     * compared against it and called every image change stale. Read the field
     * by name, and keep the scalar form as a fallback for any other plugin.
     *
     * @param  mixed  $result
     */
    /**
     * Put back the featured image we should never have replaced.
     *
     * Best effort by nature: if this fails the page is left showing our picture
     * instead of theirs, which the caller has to say out loud rather than
     * report as an ordinary refusal.
     */
    private function restoreThumbnail(Site $site, int $targetId, int $attachmentId): bool
    {
        try {
            $this->mcp->callTool($site, 'wp_post_thumbnail_set', [
                'id' => $targetId,
                'attachment_id' => $attachmentId,
            ], 60);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Take an upload back out of the library when nothing came to point at it.
     *
     * An upload has no undo of its own, so a change that failed after it left
     * the picture behind — invisible, unasked for, and one more of them on
     * every retry. Silent on failure on purpose: a site running a plugin older
     * than 1.6.2 has no such tool, and a leftover file must never replace the
     * real reason the change did not happen.
     */
    private function discardUpload(Site $site, int $attachmentId): void
    {
        if ($attachmentId <= 0) {
            return;
        }

        try {
            $this->mcp->callTool($site, 'wp_media_delete', ['attachment_id' => $attachmentId], 60);
        } catch (\Throwable) {
            // Nothing to say to the customer about our own housekeeping.
        }
    }

    private function thumbnailPrevious($result, int $default = 0): int
    {
        $previous = data_get($result, 'previous');

        if (is_array($previous)) {
            return (int) data_get($previous, 'attachment_id', $default);
        }

        return $previous === null
            ? (int) data_get($result, 'previous_attachment_id', $default)
            : (int) $previous;
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
     * @return array{ok: bool, reason: string|null, message: string|null, restore: array<string, mixed>|null}
     */
    private function applyProduct(Site $site, array $plan): array
    {
        $productId = (int) ($plan['product_id'] ?? 0);
        $fields = (array) ($plan['fields'] ?? []);

        if ($productId <= 0 || $fields === []) {
            return $this->refuse('הבקשה חסרה מוצר או ערכים לעדכון.');
        }

        // Is the product still what the customer was shown?
        //
        // These are absolute values, not deltas: an approved "stock 40" written
        // after an order took two off the shelf puts those two back, and an
        // approved price written after somebody repriced in wp-admin throws
        // that away. The page path re-reads before writing for exactly this
        // reason; the shop was not doing it.
        // Only the fields about to change, under the shop's own names: a
        // product renamed since the preview is not a reason to refuse a price
        // change nobody else touched.
        $current = array_intersect_key((array) ($plan['current'] ?? []), $fields);

        if ($current !== []) {
            $live = $this->productNow($site, $productId, $current);

            if ($live === []) {
                return $this->refuse('לא הצלחתי לקרוא את המוצר מהחנות.');
            }

            foreach ($current as $field => $value) {
                if (! $this->sameText((string) ($live[$field] ?? ''), (string) $value)) {
                    return $this->refuse(self::STALE);
                }
            }
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
            return ['ok' => true, 'reason' => null, 'message' => null, 'restore' => null];
        }

        // Everything the write touched, not only what we asked it to touch.
        //
        // wc_product_update has side effects of its own: ending a sale clears
        // its scheduled dates, and setting a quantity turns stock management
        // on. An undo that restores only the field we named would leave a
        // discount that was meant to end running for ever, or leave a product
        // managing stock it never managed — and report success either way.
        $touched = array_merge($fields, $this->sideEffectsOf($fields));

        return ['ok' => true, 'reason' => null, 'message' => null, 'restore' => [
            'kind' => 'product',
            'product_id' => $productId,
            'fields' => array_intersect_key($previous, $touched),
            // What the product looks like now that our change is on it, read
            // back from the shop. The undo checks against this, because a shop
            // moves on its own: an order drops the stock, an administrator sets
            // a price, and restoring the values from before our change would
            // erase a sale that really happened.
            'after' => $this->productNow($site, $productId, $touched),
        ]];
    }

    /**
     * The fields the shop changes on its own when these are written.
     *
     * Named here rather than discovered by diffing the whole product: a diff
     * would also catch everything an ORDER changed in the same moment, and the
     * undo would then put a customer's purchase back on the shelf.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, true>
     */
    private function sideEffectsOf(array $fields): array
    {
        $extra = [];

        // Ending or changing a sale clears the window it was scheduled for.
        if (array_key_exists('sale_price', $fields)) {
            $extra['sale_from'] = true;
            $extra['sale_to'] = true;
        }

        // Setting a quantity switches stock management on.
        if (array_key_exists('stock_quantity', $fields)) {
            $extra['manage_stock'] = true;
            $extra['stock_status'] = true;
        }

        return $extra;
    }

    /**
     * The fields we just changed, as the shop reports them now.
     *
     * Read back rather than taken from what we sent: WooCommerce normalises
     * prices ("90" becomes "90.00") and derives stock status from quantity, so
     * comparing the live product against our own strings would call an
     * untouched product "edited" and refuse every undo.
     *
     * An empty result means we could not tell — and the undo then refuses,
     * because the alternative is overwriting a change we cannot see.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function productNow(Site $site, int $productId, array $fields): array
    {
        try {
            $product = json_decode($this->mcp->textContent($this->mcp->callTool($site, 'wc_product_get', [
                'product_id' => $productId,
            ])), true);
        } catch (\Throwable) {
            return [];
        }

        return is_array($product) ? array_intersect_key($product, $fields) : [];
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
     * @return array{ok: bool, reason: string|null, message: string|null, restore: array<string, mixed>|null}
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
            return $this->refuse('חסר יעד, תיאור או קובץ לתמונה.');
        }

        // Is the picture we are about to replace still the one that was there
        // when the offer was made?
        //
        // The plugin reports the featured image from 1.6.1 — a page through the
        // content tool, a PRODUCT through the shop tool, because products are
        // deliberately unreadable as content. Read through the planner so that
        // both are asked the way each answers: reading products through the
        // content tool alone throws, and the throw would fail every shop image
        // instead of applying it. An older site reports neither, and the check
        // then stands down rather than refusing every image change.
        //
        // Where it IS reported, an image somebody set in wp-admin between the
        // preview and the yes is not ours to throw away without a word.
        $expected = $plan['thumbnail_id'] ?? null;

        if ($expected !== null) {
            $current = $this->planner->thumbnailOf($site, $targetId);

            // Nothing answering NOW is not an old plugin — building the preview
            // got a number out of this very site, so this site reports the
            // featured image. A blank here is a read that failed, and standing
            // down on it stands down exactly when we cannot see. The upload
            // that follows may well succeed on a connection that recovered by
            // then, and the picture an administrator put there in between would
            // be gone with nobody told.
            //
            // A target with no image at all answers 0, not nothing.
            if ($current === null) {
                return $this->failure('thumbnail unreadable at confirmation');
            }

            if ($current !== (int) $expected) {
                return $this->refuse(self::STALE);
            }
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
            return $this->refuse('התמונה לא נשמרה בספריית המדיה.');
        }

        try {
            $set = json_decode($this->mcp->textContent($this->mcp->callTool($site, 'wp_post_thumbnail_set', [
                'id' => $targetId,
                'attachment_id' => $attachmentId,
            ], 60)), true);
        } catch (\Throwable $e) {
            // The picture is in their library already and nothing points at it.
            // Left alone it stays there for ever, and every retry adds another.
            $this->discardUpload($site, $attachmentId);

            return $this->failure(Str::limit($e->getMessage(), 200));
        }

        $displaced = $this->thumbnailPrevious($set);

        // The check before the upload is not enough on its own: the upload
        // itself is allowed 120 seconds, and an administrator can put a
        // different picture there while it runs. The setter reports which image
        // it ACTUALLY displaced, which is the only account of that window we
        // get — and without reading it we would have overwritten their picture
        // and told the customer it went well.
        if ($expected !== null && $displaced !== (int) $expected) {
            $restored = $this->restoreThumbnail($site, $targetId, $displaced);
            $this->discardUpload($site, $attachmentId);

            return $restored
                ? $this->refuse(self::STALE)
                : $this->failure('displaced a newer featured image and could not put it back');
        }

        SiteChangePlanner::forget($site);

        // The file is on the customer's site now; our copy has done its job.
        if ($path !== '') {
            Storage::disk('local')->delete($path);
        }

        return ['ok' => true, 'reason' => null, 'message' => null, 'restore' => [
            'kind' => 'thumbnail',
            'target_id' => $targetId,
            // 0 is meaningful: it means the page had no featured image before,
            // and the undo has to be able to put "none" back.
            'attachment_id' => $displaced,
            // What WE put there. The undo refuses unless this is still the
            // featured image, so a picture the customer chose afterwards is
            // never quietly replaced by the one it succeeded.
            'after' => $attachmentId,
        ]];
    }

    /**
     * Put a page back exactly as it was.
     *
     * Writes both title and content together: an undo that restored only the
     * field we happened to change would leave a page that is neither what it
     * was nor what the customer asked for.
     *
     * @return array{ok: bool, reason: string|null, message: string|null}
     */
    public function revert(SiteAgentRequest $request): array
    {
        $site = $request->site;
        $restore = (array) $request->restore;

        if (! $site instanceof Site || $restore === []) {
            return $this->refuse('אין לי גיבוי לשחזור הבקשה הזו.');
        }

        $kind = (string) ($restore['kind'] ?? 'page');

        if ($kind === 'product') {
            return $this->revertProduct($site, $restore);
        }

        if ($kind === 'thumbnail') {
            return $this->revertThumbnail($site, $restore);
        }

        if ($kind === 'elementor') {
            return $this->revertElementor($site, $restore);
        }

        $pageId = (int) ($restore['page_id'] ?? 0);

        if ($pageId <= 0) {
            return $this->refuse('אין לי גיבוי לשחזור הבקשה הזו.');
        }

        // Has anything happened to this page since we changed it?
        //
        // An undo writes back the WHOLE title and content, so it is destructive
        // by construction: if the customer or an administrator edited the page
        // in wp-admin after the agent's change, writing our snapshot back
        // erases every one of those edits. The apply path already refuses to
        // overwrite work it did not expect; the undo path must refuse for
        // exactly the same reason.
        $after = (array) ($restore['after'] ?? []);

        if ($after !== []) {
            try {
                $live = $this->read($site, $pageId);
            } catch (\Throwable $e) {
                return $this->failure(Str::limit($e->getMessage(), 200));
            }

            if ($live === null) {
                return $this->refuse('לא הצלחתי לקרוא את העמוד באתר.');
            }

            if (! $this->sameText($live['title'], (string) ($after['title'] ?? ''))
                || ! $this->sameText($live['content'], (string) ($after['content'] ?? ''))) {
                return $this->refuse(self::STALE);
            }
        }

        try {
            $this->mcp->textContent($this->mcp->callTool($site, 'wp_content_update', [
                'id' => $pageId,
                'title' => (string) ($restore['title'] ?? ''),
                'content' => (string) ($restore['content'] ?? ''),
            ], 60));
        } catch (\Throwable $e) {
            return $this->failure(Str::limit($e->getMessage(), 200));
        }

        SiteChangePlanner::forget($site);

        return ['ok' => true, 'reason' => null, 'message' => null];
    }

    /**
     * Same text, ignoring how the line endings came back.
     *
     * The only tolerance allowed. WordPress rewrites CRLF to LF on save and may
     * trim the trailing newline, and treating that as "somebody edited the
     * page" would refuse every undo on a site that does it. Anything beyond
     * whitespace at the edges IS an edit, and is treated as one.
     */
    private function sameText(string $live, string $expected): bool
    {
        $normalize = fn (string $text): string => trim(str_replace("\r\n", "\n", $text));

        return $normalize($live) === $normalize($expected);
    }

    /**
     * @param  array<string, mixed>  $restore
     * @return array{ok: bool, reason: string|null, message: string|null}
     */
    private function revertProduct(Site $site, array $restore): array
    {
        $fields = (array) ($restore['fields'] ?? []);
        $productId = (int) ($restore['product_id'] ?? 0);

        if ($fields === []) {
            return $this->refuse('אין לי את הערכים הקודמים של המוצר.');
        }

        // Has the shop moved since we changed it?
        //
        // The same rule as the page undo, and it matters more here: between the
        // change and the undo an order can have dropped the stock, and putting
        // back the quantity from before our change would hand the customer back
        // items they have already sold.
        $after = (array) ($restore['after'] ?? []);

        if ($after === []) {
            return $this->refuse('איני יכול לוודא שהמוצר לא השתנה מאז, ולכן לא שיניתי בו דבר.');
        }

        $live = $this->productNow($site, $productId, $after);

        if ($live === []) {
            return $this->refuse('לא הצלחתי לקרוא את המוצר מהחנות.');
        }

        foreach ($after as $field => $value) {
            // Compared as strings: the shop gives a price back as "90.00" and a
            // quantity as a number, and only their written form is comparable
            // across the two reads.
            if (! $this->sameText((string) ($live[$field] ?? ''), (string) $value)) {
                return $this->refuse(self::STALE);
            }
        }

        try {
            $this->mcp->textContent($this->mcp->callTool($site, 'wc_product_update', [
                'product_id' => $productId,
                ...$fields,
            ], 60));
        } catch (\Throwable $e) {
            return $this->failure(Str::limit($e->getMessage(), 200));
        }

        return ['ok' => true, 'reason' => null, 'message' => null];
    }

    /**
     * @param  array<string, mixed>  $restore
     * @return array{ok: bool, reason: string|null, message: string|null}
     */
    private function revertThumbnail(Site $site, array $restore): array
    {
        $targetId = (int) ($restore['target_id'] ?? 0);
        $installed = (int) ($restore['after'] ?? 0);
        $previous = (int) ($restore['attachment_id'] ?? 0);

        // There is no read-only "which image is featured" tool — but the setter
        // reports what it replaced, and that is enough to be safe: put the old
        // image back, and if what we displaced was NOT the image we installed,
        // somebody had chosen another one since. Put theirs straight back and
        // refuse.
        //
        // Two writes in the bad case, and none of the customer's work lost.
        // Overwriting a picture they picked, silently, is the outcome that is
        // not acceptable here.
        try {
            $set = json_decode($this->mcp->textContent($this->mcp->callTool($site, 'wp_post_thumbnail_set', [
                'id' => $targetId,
                'attachment_id' => $previous,
            ], 60)), true);
        } catch (\Throwable $e) {
            return $this->failure(Str::limit($e->getMessage(), 200));
        }

        $displaced = $this->thumbnailPrevious($set, $installed);

        if ($installed > 0 && $displaced !== $installed) {
            try {
                $this->mcp->callTool($site, 'wp_post_thumbnail_set', [
                    'id' => $targetId,
                    'attachment_id' => $displaced,
                ], 60);
            } catch (\Throwable) {
                // Nothing more we can do from here; the team sees the reason.
            }

            return $this->refuse(self::STALE);
        }

        SiteChangePlanner::forget($site);

        return ['ok' => true, 'reason' => null, 'message' => null];
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

    /**
     * A technical failure: the site's API threw, timed out or answered wrongly.
     *
     * The detail is kept as `reason` — it goes on the request row for the team
     * to read — and the customer is told something plain. An exception message
     * from somebody's WordPress is an internal detail, and forwarding it to a
     * WhatsApp message is both useless to them and against the standard.
     *
     * @return array{ok: bool, reason: string, message: string, restore: null}
     */
    private function failure(string $reason): array
    {
        return [
            'ok' => false,
            'reason' => $reason,
            // A fragment, not a sentence: the caller puts "לא הצלחתי לבצע את
            // השינוי" — or "להחזיר" — in front of it. A whole sentence here
            // came out stuttering ("לא הצלחתי להחזיר את השינוי: לא הצלחתי
            // לבצע את השינוי באתר"), which is what a paying customer read.
            'message' => 'משהו השתבש מול האתר. נסו שוב, ואם זה חוזר — נשמח לעזור.',
            'restore' => null,
        ];
    }

    /**
     * A refusal we can explain: the page is gone, the text moved, nothing was
     * backed up. Said to the customer in the same words we record.
     *
     * @return array{ok: bool, reason: string, message: string, restore: null}
     */
    private function refuse(string $reason): array
    {
        return ['ok' => false, 'reason' => $reason, 'message' => $reason, 'restore' => null];
    }
}
