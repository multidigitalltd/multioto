<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * The business logo, uploaded once in settings and reused everywhere we present
 * to the customer: the public signup form, the thank-you page, outbound emails,
 * and the signed customer-card PDF. One source of truth so a logo change lands
 * in every surface at once.
 */
class Branding
{
    /** Stored logo path on the public disk, or null when none was uploaded. */
    public static function logoPath(): ?string
    {
        $path = config('billing.branding.logo_path');

        return filled($path) && Storage::disk('public')->exists($path) ? $path : null;
    }

    /** Absolute URL to the logo (for web pages and emails), or null. */
    public static function logoUrl(): ?string
    {
        $path = self::logoPath();

        // The public disk's url is configured as APP_URL.'/storage', so this is
        // already absolute — do not prefix APP_URL again.
        return $path ? Storage::disk('public')->url($path) : null;
    }

    /**
     * The logo inlined as a data: URI — required by the PDF renderer and safe
     * for emails, since neither can reliably fetch a remote asset.
     */
    public static function logoDataUri(): ?string
    {
        $path = self::logoPath();

        if ($path === null) {
            return null;
        }

        $disk = Storage::disk('public');
        $mime = $disk->mimeType($path) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode($disk->get($path));
    }
}
