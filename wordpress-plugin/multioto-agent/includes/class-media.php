<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The media library — because "write me a page about the new collection" is
 * half a job without a picture on it.
 *
 * Two things are enforced here rather than left to the caller:
 *
 * The file type is decided by reading the file, not by trusting its name. An
 * upload endpoint that believes the extension is an upload endpoint that
 * accepts PHP named .jpg. SVG is refused outright — WordPress serves it
 * unsanitised, which makes it a script the browser runs on the site's own
 * origin.
 *
 * And an image needs alt text. Not as a nicety: the standard this company
 * builds to requires it, a picture placed by an agent is a picture nobody
 * proof-read, and "I will add it later" is how a site ends up with four
 * hundred images and no descriptions.
 */
class Multioto_Agent_Media
{
    /** Types allowed in, and the extension each is stored under. */
    const ALLOWED = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];

    /** Ceiling for one upload, before the site's own limit is applied. */
    const MAX_BYTES = 15728640; // 15 MB

    const MAX_LIMIT = 50;

    /**
     * What is already in the library — so an existing image is reused instead
     * of uploaded a second time.
     *
     * @param  array<string, mixed>  $args
     */
    public static function listMedia(array $args): string
    {
        $limit = min(self::MAX_LIMIT, max(1, (int) ($args['limit'] ?? 20)));
        $page = max(1, (int) ($args['page'] ?? 1));

        $query = new WP_Query([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => $limit,
            'paged' => $page,
            'orderby' => 'ID',
            'order' => 'DESC',
            's' => trim((string) ($args['search'] ?? '')),
            'post_mime_type' => self::mimeFilter($args),
        ]);

        $items = [];

        foreach ($query->posts as $post) {
            $items[] = [
                'id' => (int) $post->ID,
                'title' => (string) $post->post_title,
                'url' => (string) wp_get_attachment_url((int) $post->ID),
                'mime' => (string) $post->post_mime_type,
                'alt' => (string) get_post_meta((int) $post->ID, '_wp_attachment_image_alt', true),
                'date' => (string) $post->post_date,
            ];
        }

        return wp_json_encode([
            'total' => (int) $query->found_posts,
            'returned' => count($items),
            'page' => $page,
            'pages' => (int) $query->max_num_pages,
            'items' => $items,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * Put a file into the library, from a URL or from base64 bytes.
     *
     * @param  array<string, mixed>  $args
     */
    public static function upload(array $args): string
    {
        self::loadAdminIncludes();

        $filename = self::safeFilename((string) ($args['filename'] ?? ''));
        $tmp = self::fetch($args, $filename);

        try {
            $checked = wp_check_filetype_and_ext($tmp, $filename);
            // proper_filename is set when the name disagreed with the contents;
            // taking it means the stored file is named after what it actually
            // is, rather than after what the caller claimed.
            $filename = $checked['proper_filename'] !== false && $checked['proper_filename'] !== ''
                ? (string) $checked['proper_filename']
                : $filename;
            $mime = (string) $checked['type'];

            if (! isset(self::ALLOWED[$mime])) {
                throw new Multioto_Agent_Rpc_Error(-32602, sprintf(
                    'סוג הקובץ (%s) אינו מותר. מותרים: %s.',
                    $mime !== '' ? $mime : 'לא זוהה',
                    implode(', ', array_keys(self::ALLOWED)),
                ));
            }

            $isImage = strpos($mime, 'image/') === 0;
            $alt = trim((string) ($args['alt'] ?? ''));

            if ($isImage && $alt === '') {
                throw new Multioto_Agent_Rpc_Error(-32602, 'לתמונה חובה טקסט חלופי (alt) — תיאור קצר של מה שרואים בה.');
            }

            $attachTo = (int) ($args['attach_to'] ?? 0);

            $id = media_handle_sideload(
                ['name' => $filename, 'tmp_name' => $tmp],
                $attachTo > 0 ? $attachTo : 0,
                sanitize_text_field((string) ($args['title'] ?? '')) ?: null,
            );

            if (is_wp_error($id)) {
                throw new Multioto_Agent_Rpc_Error(-32000, $id->get_error_message());
            }

            // media_handle_sideload consumed the temp file on success.
            $tmp = '';

            if ($isImage) {
                update_post_meta((int) $id, '_wp_attachment_image_alt', $alt);
            }

            return wp_json_encode([
                'attachment_id' => (int) $id,
                'url' => (string) wp_get_attachment_url((int) $id),
                'filename' => $filename,
                'mime' => $mime,
                'alt' => $alt,
            ], JSON_UNESCAPED_UNICODE);
        } finally {
            // Any refusal above leaves nothing behind in the temp directory.
            if ($tmp !== '' && file_exists($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /**
     * Set (or clear, with attachment_id 0) the featured image of a post.
     *
     * @param  array<string, mixed>  $args
     */
    public static function setThumbnail(array $args): string
    {
        $postId = (int) ($args['id'] ?? 0);
        $post = $postId > 0 ? get_post($postId) : null;

        if (! $post instanceof WP_Post) {
            throw new Multioto_Agent_Rpc_Error(-32602, "פריט תוכן #{$postId} לא נמצא.");
        }

        $attachmentId = (int) ($args['attachment_id'] ?? 0);

        if ($attachmentId > 0) {
            $attachment = get_post($attachmentId);

            if (! $attachment instanceof WP_Post || $attachment->post_type !== 'attachment') {
                throw new Multioto_Agent_Rpc_Error(-32602, "קובץ #{$attachmentId} אינו קיים בספריית המדיה.");
            }

            if (strpos((string) $attachment->post_mime_type, 'image/') !== 0) {
                throw new Multioto_Agent_Rpc_Error(-32602, 'תמונה ראשית חייבת להיות תמונה.');
            }
        }

        $previousId = (int) get_post_thumbnail_id($postId);

        // Compare-and-swap. Reading the featured image and then setting it are
        // two requests with a gap between them, and anybody may edit the post in
        // that gap — which is exactly how a caller ends up replacing a picture
        // somebody chose a moment earlier and reporting success. Passing
        // if_current makes the check and the write one operation: either it is
        // still what the caller last saw, or nothing is written at all.
        $guarded = array_key_exists('if_current', $args);
        $expected = $guarded ? (int) $args['if_current'] : $previousId;

        // Cheapest refusal first, so the ordinary mismatch never reaches the
        // database. It is NOT the guarantee, though — two PHP requests can both
        // get past this line. The guarantee is the conditional write below.
        if ($guarded && $previousId !== $expected) {
            return self::thumbnailUnchanged($postId, $previousId);
        }

        if (! $guarded) {
            if ($attachmentId > 0) {
                set_post_thumbnail($postId, $attachmentId);
            } else {
                delete_post_thumbnail($postId);
            }
        } elseif ($attachmentId !== $previousId) {
            $written = self::swapThumbnail($postId, $attachmentId, $expected);

            if (! $written) {
                // This request already read the meta once, and a write that
                // matched nothing returns before invalidating anything — so the
                // cached value here is still the one we lost to, not the one
                // that won. Nobody can evict another process's runtime cache
                // for us, so it has to be dropped before asking again, or the
                // refusal would name the wrong picture.
                wp_cache_delete($postId, 'post_meta');

                return self::thumbnailUnchanged($postId, (int) get_post_thumbnail_id($postId));
            }
        }

        return wp_json_encode([
            'id' => $postId,
            'attachment_id' => $attachmentId,
            'changed' => true,
            // 0 is a real previous value here — it means "there was none" — and
            // restoring it clears the thumbnail, which is the correct undo.
            'previous' => ['attachment_id' => $previousId],
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Set the featured image only while it is still the one expected.
     *
     * Through core's own functions, deliberately — an earlier version of this
     * went to the postmeta table directly, for a WHERE clause core does not
     * offer. It worked, and it cost six separate pieces of the contract core
     * runs around a meta write: the actions, the short-circuit filters,
     * sanitising, which of those a delete does and does not get, whether the
     * raw or the sanitised value reaches the add fallback, and how many rows a
     * write touches. Every one of them was found only after the previous one
     * had been called complete. Rebuilding a platform's contract by hand is a
     * debt that keeps coming due, and what it bought here was microseconds.
     *
     * The window that actually mattered is already shut by the time this runs:
     * the read and the write used to be separated by an upload allowed 120
     * seconds, which is ample time for an administrator to put a different
     * picture there, and they are now in the same breath. What remains is
     * somebody saving in the same instant, and against that core's own
     * conditional UPDATE is as good as this gets.
     *
     * So the guarantees differ by case, and honestly:
     *
     * - Replacing a known image: `update_post_meta`'s fourth argument becomes
     *   part of the UPDATE's WHERE. A real conditional write.
     * - Clearing a known image: `delete_post_meta`'s third argument narrows
     *   the SELECT, though core then deletes by the ids it found — so a change
     *   landing between those two statements is not caught.
     * - Expecting NO image: nothing to put a condition on, and post meta has
     *   no unique index. `add_post_meta(..., unique)` refuses once a row
     *   exists, and that is the whole of it.
     *
     * @return bool Whether the featured image is now what the caller asked for.
     */
    private static function swapThumbnail(int $postId, int $attachmentId, int $expected): bool
    {
        if ($attachmentId === 0) {
            return (bool) delete_post_meta($postId, '_thumbnail_id', $expected);
        }

        if ($expected === 0) {
            return (bool) add_post_meta($postId, '_thumbnail_id', $attachmentId, true);
        }

        // Core does not hand back the value it stored, only whether it stored
        // one — and if it takes its insert path the value it wrote is the
        // SANITISED one, which we would otherwise have to reproduce by running
        // the sanitiser a second time. A sanitiser is an arbitrary filter: it
        // may count, log, or answer differently on a second call, and running
        // it again to find out what it said the first time is not a question
        // that can be asked twice. So it is overheard instead, from core's own
        // announcement of the row it created.
        // Kept per row, not as "the last one heard": a listener on this very
        // action may add a second _thumbnail_id row to the same post, and its
        // announcement is as true as ours. Keeping only the newest would have
        // us compare OUR row against SOMEBODY ELSE'S value, skip the cleanup,
        // and report that nothing changed while our image stayed on the page.
        $inserted = [];
        $overhear = static function ($metaId, $objectId, $metaKey, $metaValue) use (&$inserted, $postId) {
            if ((int) $objectId === $postId && $metaKey === '_thumbnail_id') {
                $inserted[(int) $metaId] = $metaValue;
            }
        };

        add_action('added_post_meta', $overhear, 10, 4);

        try {
            $written = update_post_meta($postId, '_thumbnail_id', $attachmentId, $expected);
        } finally {
            remove_action('added_post_meta', $overhear, 10);
        }

        // An integer rather than true means core took its insert path: the row
        // we were told to expect had been removed, so nothing was swapped and
        // ours was added to an empty spot instead. That is not what was asked
        // for, and it goes straight back out.
        //
        // By its own id, so a row somebody else added is never the one removed
        // — and only while it still holds what we put in it. Core fires
        // added_post_meta on the way in, and a listener there, or an
        // administrator in the same instant, may already have made that row
        // theirs; deleting it then would throw away a newer picture in the name
        // of tidying up ours.
        //
        // Reading it and then deleting it is not one operation, and cannot be
        // through this API. It narrows the window to the gap between these two
        // lines instead of leaving it open from the insert, which is the honest
        // best available without going behind core's back again.
        if (is_int($written)) {
            // Against the value core STORED — overheard above — and not against
            // the one we asked for. On a site with a sanitiser the two differ,
            // the comparison would miss, the cleanup would be skipped, and the
            // answer would say nothing changed while a new featured image sat
            // on the page. That is worse than the overwrite this exists to
            // prevent, because it is also untrue.
            // Whether it was heard is a question about the KEY, not about the
            // value: a sanitiser may hand core a null, core will store it and
            // announce it, and reading that announcement as silence would
            // leave the row sitting there while we reported nothing changed.
            $heard = array_key_exists($written, $inserted);
            $row = get_metadata_by_mid('post', $written);

            if ($row !== false
                && $heard
                && (int) $row->post_id === $postId
                && $row->meta_key === '_thumbnail_id'
                && self::sameMetaValue($row->meta_value, $inserted[$written])) {
                delete_metadata_by_mid('post', $written);
            }

            return false;
        }

        return (bool) $written;
    }

    /**
     * Is the row still holding the value we put in it?
     *
     * Meta values come back from the database as strings whatever went in, so
     * the comparison is made on that footing — except for null, which is a
     * value a sanitiser may legitimately produce and which is not the same
     * thing as an empty string.
     *
     * @param  mixed  $stored
     * @param  mixed  $written
     */
    private static function sameMetaValue($stored, $written): bool
    {
        if ($stored === null || $written === null) {
            return $stored === $written;
        }

        return is_scalar($stored) && is_scalar($written) && (string) $stored === (string) $written;
    }

    /**
     * Nothing was written, and this is what is there instead.
     */
    private static function thumbnailUnchanged(int $postId, int $currentId): string
    {
        return wp_json_encode([
            'id' => $postId,
            'changed' => false,
            'current' => ['attachment_id' => $currentId],
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Remove a file from the media library, for good.
     *
     * Exists because an upload has no undo of its own. A change that uploaded a
     * picture and then could not use it used to leave that picture in the
     * customer's library — invisible, never asked for, and one more of them
     * every time they tried again.
     *
     * A file still shown as somebody's featured image is refused: deleting it
     * would blank that page, which is the opposite of tidying up.
     *
     * @param  array<string, mixed>  $args
     */
    public static function delete(array $args): string
    {
        $attachmentId = (int) ($args['attachment_id'] ?? 0);
        $attachment = $attachmentId > 0 ? get_post($attachmentId) : null;

        if (! $attachment instanceof WP_Post || $attachment->post_type !== 'attachment') {
            throw new Multioto_Agent_Rpc_Error(-32602, "קובץ #{$attachmentId} אינו קיים בספריית המדיה.");
        }

        // Asked of the meta table directly, and deliberately NOT through
        // WP_Query: 'any' does not mean any there. post_type => 'any' drops
        // every type registered with exclude_from_search — which includes
        // WooCommerce's product_variation — and post_status => 'any' drops
        // trashed posts. A variation or a post in the trash would have gone
        // unseen, and deleting is for ever: restoring that post later would
        // restore it pointing at a file that no longer exists.
        global $wpdb;

        $shownOn = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %d LIMIT 1",
            $attachmentId
        ));

        if ($shownOn > 0) {
            throw new Multioto_Agent_Rpc_Error(-32602, sprintf(
                'קובץ #%d משמש כתמונה ראשית של פריט תוכן #%d. הסירו אותו משם לפני המחיקה.',
                $attachmentId,
                $shownOn,
            ));
        }

        if (wp_delete_attachment($attachmentId, true) === false) {
            throw new Multioto_Agent_Rpc_Error(-32000, "מחיקת קובץ #{$attachmentId} נכשלה.");
        }

        return wp_json_encode(['deleted_id' => $attachmentId], JSON_UNESCAPED_UNICODE);
    }

    /**
     * The bytes, written to a temp file — from a URL or from base64.
     *
     * @param  array<string, mixed>  $args
     */
    private static function fetch(array $args, string $filename): string
    {
        $url = trim((string) ($args['url'] ?? ''));
        $data = (string) ($args['data'] ?? '');

        if ($url !== '') {
            return self::download($url);
        }

        if ($data === '') {
            throw new Multioto_Agent_Rpc_Error(-32602, 'חסר קובץ: יש להעביר url או data (base64).');
        }

        // Tolerate a full data: URL, which is what a browser hands over.
        if (stripos($data, 'data:') === 0 && strpos($data, ',') !== false) {
            $data = substr($data, strpos($data, ',') + 1);
        }

        $bytes = base64_decode(str_replace(["\r", "\n", ' '], '', $data), true);

        if ($bytes === false || $bytes === '') {
            throw new Multioto_Agent_Rpc_Error(-32602, 'שדה data אינו base64 תקין.');
        }

        self::assertSize(strlen($bytes));

        $tmp = wp_tempnam($filename);

        if (! $tmp || file_put_contents($tmp, $bytes) === false) {
            throw new Multioto_Agent_Rpc_Error(-32000, 'לא ניתן היה לכתוב את הקובץ הזמני.');
        }

        return $tmp;
    }

    /** Download a remote file, refusing anything that is not a public URL. */
    private static function download(string $url): string
    {
        // wp_http_validate_url rejects loopback and private ranges — without it
        // this tool would fetch whatever the site's own network can reach and
        // publish the answer to the media library.
        if (wp_http_validate_url($url) === false) {
            throw new Multioto_Agent_Rpc_Error(-32602, 'הכתובת אינה כתובת ציבורית תקינה.');
        }

        $tmp = download_url($url, 30);

        if (is_wp_error($tmp)) {
            throw new Multioto_Agent_Rpc_Error(-32000, 'הורדת הקובץ נכשלה: '.$tmp->get_error_message());
        }

        try {
            self::assertSize((int) filesize($tmp));
        } catch (Multioto_Agent_Rpc_Error $e) {
            @unlink($tmp);
            throw $e;
        }

        return $tmp;
    }

    private static function assertSize(int $bytes): void
    {
        $max = min(self::MAX_BYTES, (int) wp_max_upload_size() ?: self::MAX_BYTES);

        if ($bytes > $max) {
            throw new Multioto_Agent_Rpc_Error(-32602, sprintf(
                'הקובץ גדול מדי (%s), המקסימום הוא %s.',
                size_format($bytes),
                size_format($max),
            ));
        }
    }

    /** A filename that cannot escape the uploads directory. */
    private static function safeFilename(string $filename): string
    {
        $filename = sanitize_file_name(wp_basename($filename));

        if ($filename === '' || strpos($filename, '.') === false) {
            throw new Multioto_Agent_Rpc_Error(-32602, 'חסר שם קובץ עם סיומת (למשל banner.jpg).');
        }

        return $filename;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return string|list<string>
     */
    private static function mimeFilter(array $args)
    {
        $mime = trim((string) ($args['mime_type'] ?? ''));

        return $mime !== '' ? $mime : array_keys(self::ALLOWED);
    }

    private static function loadAdminIncludes(): void
    {
        require_once ABSPATH.'wp-admin/includes/file.php';
        require_once ABSPATH.'wp-admin/includes/media.php';
        require_once ABSPATH.'wp-admin/includes/image.php';
    }
}
