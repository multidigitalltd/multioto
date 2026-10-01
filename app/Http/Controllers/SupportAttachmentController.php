<?php

namespace App\Http\Controllers;

use App\Models\TicketMessage;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams an inbound support attachment (a file a customer sent) to a signed-in
 * team member. Behind panel auth — never public.
 *
 * ## This is the control, not the allow-list
 *
 * The store keeps every file type a customer sends, by design: a support inbox
 * exists to receive what people actually send, and the team judges the file. That
 * decision is only safe because of what happens here.
 *
 * Exactly one set of types is rendered in the browser — raster images, audio and
 * video, which browsers display and never execute. Everything else is served as
 * `application/octet-stream` with `Content-Disposition: attachment`, so the
 * browser saves it and never interprets it.
 *
 * The type that makes this necessary is SVG. It is an image to a person and a
 * script to a browser, so serving one inline would run the sender's JavaScript on
 * the panel's own origin, with the operator's session. Same for HTML. Both are
 * now kept rather than refused, which is precisely why neither may be rendered.
 *
 * `nosniff` closes the other half: without it a browser may ignore the declared
 * type and re-interpret the bytes as HTML anyway.
 */
class SupportAttachmentController extends Controller
{
    /**
     * Types safe to render in a browser: pixels and media only.
     *
     * An allow-list rather than a "not these" rule, because the cost of the two
     * mistakes is not symmetric — a missing preview is an inconvenience, and one
     * scriptable type slipping into this list is an account takeover.
     */
    private const INLINE = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif',
        'image/bmp', 'image/x-ms-bmp',
        'audio/mpeg', 'audio/ogg', 'audio/mp4', 'audio/aac', 'audio/wav', 'audio/x-wav', 'audio/amr',
        'video/mp4', 'video/quicktime', 'video/3gpp', 'video/webm',
    ];

    public function __invoke(TicketMessage $message, int $index): StreamedResponse
    {
        $attachments = $message->attachments ?? [];

        abort_unless(isset($attachments[$index]) && is_array($attachments[$index]), 404);

        $attachment = $attachments[$index];
        $disk = Storage::disk($attachment['disk'] ?? config('billing.support.attachments.disk'));

        abort_unless(isset($attachment['path']) && $disk->exists($attachment['path']), 404);

        $mime = (string) ($attachment['mime'] ?? 'application/octet-stream');
        $inline = in_array($mime, self::INLINE, true);

        return $disk->download(
            $attachment['path'],
            (string) ($attachment['name'] ?? 'attachment'),
            [
                // Never echo a type we were told. A file renders as itself only
                // when it is on the list above; otherwise it is an opaque
                // download, whatever it claims to be.
                'Content-Type' => $inline ? $mime : 'application/octet-stream',
                'X-Content-Type-Options' => 'nosniff',
                'Content-Disposition' => $inline ? 'inline' : 'attachment',
            ],
        );
    }
}
