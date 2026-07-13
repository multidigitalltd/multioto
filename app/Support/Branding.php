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
     * The footer shown at the bottom of every customer email. Uses the value
     * configured in settings, falling back to a copyright line built from the
     * sender name and current year.
     */
    public static function emailFooter(): string
    {
        $custom = config('billing.branding.email_footer');
        if (filled($custom)) {
            return (string) $custom;
        }

        $name = config('mail.from.name') ?: config('app.name');

        return '© '.date('Y').' '.$name.'. כל הזכויות שמורות.';
    }

    /**
     * The logo inlined as a data: URI — required by the PDF renderer, which
     * cannot fetch a remote asset. NOT for emails: mail clients (Gmail) block
     * data: URIs, so emails must use a hosted URL (see logoEmailUrl()).
     */
    public static function logoDataUri(): ?string
    {
        $file = self::logoFile();

        return $file === null ? null : 'data:'.$file['mime'].';base64,'.base64_encode($file['contents']);
    }

    /**
     * Absolute, always-public URL to the logo for use in emails — served by the
     * dedicated branding.logo route rather than the storage symlink, so it
     * resolves even where /storage isn't publicly served. Null when no logo.
     */
    public static function logoEmailUrl(): ?string
    {
        return self::logoPath() !== null ? route('branding.logo') : null;
    }

    /**
     * The raw logo bytes + mime, or null when none is uploaded. Shared by the
     * data-URI (PDF) and the public logo route (email).
     *
     * @return array{contents: string, mime: string}|null
     */
    public static function logoFile(): ?array
    {
        $path = self::logoPath();

        if ($path === null) {
            return null;
        }

        $disk = Storage::disk('public');

        return [
            'contents' => $disk->get($path),
            'mime' => $disk->mimeType($path) ?: 'image/png',
        ];
    }
}
