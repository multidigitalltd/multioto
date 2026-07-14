<?php

namespace App\Services\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Validates and stores an inbound attachment (an image/file a customer sent on
 * WhatsApp or email) on a private disk, returning the metadata we keep on the
 * ticket message. Security-first per the Multi Digital standard:
 *
 *  - MIME allow-list only (no executables, no PHP, no scriptable SVG).
 *  - The stored extension is DERIVED FROM THE MIME, never from the sender's
 *    filename, so a "invoice.php" can never land as an executable file.
 *  - Size cap enforced before writing.
 *  - Private disk; files are only ever served behind panel auth.
 *
 * Returns null when the attachment is rejected — the caller keeps the message,
 * just without that file.
 */
class AttachmentStore
{
    /**
     * @return array{name: string, mime: string, size: int, path: string, disk: string}|null
     */
    public function store(int $ticketId, string $filename, string $contents, ?string $declaredMime = null): ?array
    {
        $config = config('billing.support.attachments');
        $size = strlen($contents);

        if ($size === 0 || $size > (int) $config['max_bytes']) {
            return null;
        }

        // Trust the bytes, not the sender: sniff the real MIME and fall back to
        // the declared one only if sniffing is unavailable.
        $mime = $this->sniff($contents) ?? $declaredMime;

        $allowed = $config['allowed_mimes'];
        if (! is_string($mime) || ! array_key_exists($mime, $allowed)) {
            return null;
        }

        $extension = $this->resolveExtension($mime, $allowed[$mime], $filename);
        $path = sprintf('attachments/%d/%s.%s', $ticketId, (string) Str::uuid(), $extension);

        Storage::disk($config['disk'])->put($path, $contents);

        return [
            'name' => $this->safeDisplayName($filename, $extension),
            'mime' => $mime,
            'size' => $size,
            'path' => $path,
            'disk' => $config['disk'],
        ];
    }

    /**
     * The stored extension. Defaults to the MIME-derived one (the security-safe
     * mapping), but keeps the sender's original extension when it's a known-safe
     * text format — many of them (csv, tsv, ics, vcf, log…) all sniff as
     * text/plain, so a customer's ".csv" would otherwise land as ".txt". Only
     * the text/plain family is widened, and only to a fixed allow-list, so a
     * "invoice.php" can still never be written.
     */
    protected function resolveExtension(string $mime, string $mimeExtension, string $filename): string
    {
        if ($mime === 'text/plain') {
            $original = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

            if (in_array($original, ['txt', 'csv', 'tsv', 'log', 'md', 'vcf', 'ics'], true)) {
                return $original;
            }
        }

        return $mimeExtension;
    }

    /** Real MIME from the file contents (null when finfo is unavailable). */
    protected function sniff(string $contents): ?string
    {
        if (! class_exists(\finfo::class)) {
            return null;
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents);

        return is_string($mime) && $mime !== '' ? $mime : null;
    }

    /**
     * A safe display name: the sender's base filename stripped of any path, kept
     * short, with the MIME-derived extension enforced. Display only — never used
     * as the stored path.
     */
    protected function safeDisplayName(string $filename, string $extension): string
    {
        $base = Str::of(basename(str_replace('\\', '/', $filename)))
            ->replaceMatches('/[^\p{L}\p{N}._ -]/u', '')
            ->beforeLast('.')
            ->limit(80, '')
            ->trim()
            ->value();

        $base = $base !== '' ? $base : 'קובץ';

        return "{$base}.{$extension}";
    }
}
