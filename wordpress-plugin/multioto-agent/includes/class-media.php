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

        if ($attachmentId > 0) {
            set_post_thumbnail($postId, $attachmentId);
        } else {
            delete_post_thumbnail($postId);
        }

        return wp_json_encode([
            'id' => $postId,
            'attachment_id' => $attachmentId,
            // 0 is a real previous value here — it means "there was none" — and
            // restoring it clears the thumbnail, which is the correct undo.
            'previous' => ['attachment_id' => $previousId],
        ], JSON_UNESCAPED_UNICODE);
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
