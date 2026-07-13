<?php

namespace App\Support;

use Illuminate\Support\Str;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Turns an inbound email into readable content. `toText()` is the canonical
 * plain text (kept for AI drafting, search and outbound replies): it prefers
 * the sender's plain-text part and, for HTML-only mail, converts block tags to
 * newlines so nothing collapses into one run. `toSafeHtml()` produces a
 * display-only rich rendering — the same email run through an allow-list
 * sanitizer — so the conversation view can keep bold/links/lists and real
 * paragraph breaks without ever exposing the panel to the raw (untrusted) HTML.
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
     * A sanitized, display-only HTML rendering of the email — or null when the
     * email carried no HTML part (the caller then falls back to the plain text).
     *
     * The HTML is UNTRUSTED (it comes from whoever emailed us), so it is passed
     * through Symfony's allow-list HtmlSanitizer, which keeps formatting tags
     * (paragraphs, <br>, bold/italic, lists, links, blockquotes) and strips
     * everything dangerous — scripts, inline styles, event handlers and
     * javascript: URLs. Inline images are dropped too, so remote tracking pixels
     * never load; genuine attachments are shown separately. The result is safe
     * to echo with {!! !!}.
     */
    public static function toSafeHtml(?string $html): ?string
    {
        if (($html = trim((string) $html)) === '') {
            return null;
        }

        $config = (new HtmlSanitizerConfig)
            ->allowSafeElements()
            ->blockElement('img')
            ->allowLinkSchemes(['https', 'http', 'mailto', 'tel'])
            ->forceAttribute('a', 'rel', 'noopener nofollow noreferrer')
            ->forceAttribute('a', 'target', '_blank');

        $clean = trim((new HtmlSanitizer($config))->sanitize($html));

        // strip_tags(): a document that sanitizes down to whitespace/text-only
        // adds nothing over the plain-text body, so skip it.
        return trim(strip_tags($clean)) === '' ? null : $clean;
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
