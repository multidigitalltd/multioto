<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Turns an inbound email into readable plain text that keeps its line
 * structure. Prefers the sender's plain-text part; when the email is
 * HTML-only, converts the HTML to text preserving paragraph/line breaks
 * (block tags become newlines) instead of collapsing everything to one run.
 * We never render the raw HTML — that would be an XSS vector — so bold/colour
 * are dropped, but the message stays legible.
 */
class EmailBody
{
    public static function toText(?string $text, ?string $html = null): string
    {
        $text = trim((string) $text);

        if ($text !== '') {
            return self::normalizeNewlines($text);
        }

        if (($html = trim((string) $html)) === '') {
            return '';
        }

        // Drop non-content elements entirely (script/style would otherwise leak
        // their source into the text).
        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html);

        // Block-level boundaries and explicit breaks become newlines.
        $html = preg_replace('#<br\s*/?>#i', "\n", $html);
        $html = preg_replace('#</(p|div|li|tr|h[1-6]|blockquote)>#i', "\n", $html);
        $html = preg_replace('#<(p|div|li|tr|h[1-6]|blockquote)\b[^>]*>#i', "\n", $html);

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return self::normalizeNewlines($text);
    }

    /**
     * Normalise CRLF/CR to LF, trim trailing spaces per line, and collapse runs
     * of 3+ blank lines to a single blank line.
     */
    private static function normalizeNewlines(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+\n/', "\n", $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim(Str::of($text)->toString());
    }
}
