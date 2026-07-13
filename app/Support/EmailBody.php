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
     * Beyond this input size we don't render a rich body. The sanitizer would
     * otherwise silently truncate its input (default 20 KB), which — since the
     * view prefers body_html — would show the agent only the start of a long
     * email. Well above any real message, so we simply fall back to the full
     * plain-text body instead of a clipped rich one.
     */
    private const MAX_HTML_BYTES = 500_000;

    /**
     * A sanitized, display-only HTML rendering of the email — or null when the
     * email carried no HTML part (the caller then falls back to the plain text).
     *
     * The HTML is UNTRUSTED (it comes from whoever emailed us), so it is passed
     * through Symfony's allow-list HtmlSanitizer, which keeps formatting tags
     * (paragraphs, <br>, bold/italic, lists, links, blockquotes) and strips
     * everything dangerous — scripts, inline styles, event handlers and
     * javascript: URLs. Every remote-loading media element (img/video/audio/
     * source/track/picture) is dropped as well, so tracking pixels and other
     * attacker-controlled URLs never load; genuine attachments are shown
     * separately. The result is safe to echo with {!! !!}.
     */
    public static function toSafeHtml(?string $html): ?string
    {
        if (($html = trim((string) $html)) === '') {
            return null;
        }

        // Don't render a body the sanitizer would truncate — the plain body is
        // complete, so fall back to it rather than show a clipped message.
        if (strlen($html) > self::MAX_HTML_BYTES) {
            return null;
        }

        $config = (new HtmlSanitizerConfig)
            ->allowSafeElements()
            // Drop everything that can fetch a remote resource (tracking pixels,
            // attacker URLs). Attachments are rendered separately from metadata.
            ->blockElement('img')
            ->blockElement('picture')
            ->blockElement('source')
            ->blockElement('video')
            ->blockElement('audio')
            ->blockElement('track')
            ->allowLinkSchemes(['https', 'http', 'mailto', 'tel'])
            ->withMaxInputLength(self::MAX_HTML_BYTES)
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
