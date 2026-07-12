<?php

namespace App\Http\Controllers;

use App\Models\TicketMessage;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams an inbound support attachment (an image/file a customer sent) to a
 * signed-in team member. Behind panel auth — never public.
 *
 * Security: images are served inline (safe raster types only — SVG is not in
 * the allow-list); every other type is forced to download, and `nosniff`
 * prevents the browser from re-interpreting the bytes as HTML/JS.
 */
class SupportAttachmentController extends Controller
{
    public function __invoke(TicketMessage $message, int $index): StreamedResponse
    {
        $attachments = $message->attachments ?? [];

        abort_unless(isset($attachments[$index]) && is_array($attachments[$index]), 404);

        $attachment = $attachments[$index];
        $disk = Storage::disk($attachment['disk'] ?? config('billing.support.attachments.disk'));

        abort_unless(isset($attachment['path']) && $disk->exists($attachment['path']), 404);

        $mime = (string) ($attachment['mime'] ?? 'application/octet-stream');
        $inline = str_starts_with($mime, 'image/');

        return $disk->download(
            $attachment['path'],
            (string) ($attachment['name'] ?? 'attachment'),
            [
                'Content-Type' => $mime,
                'X-Content-Type-Options' => 'nosniff',
                'Content-Disposition' => $inline ? 'inline' : 'attachment',
            ],
        );
    }
}
