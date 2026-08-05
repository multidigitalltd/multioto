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
            // Never keep document-structure tags — a nested <html>/<body> would
            // corrupt the panel's DOM (see the wrapper strip below). <html>/<body>
            // are unwrapped (keep their content); <head> is dropped WHOLE — its
            // children are metadata (<link>/<meta>/<title>) that must never reach
            // the chat body, or a <link rel="stylesheet"> could load a remote URL.
            ->blockElement('html')
            ->dropElement('head')
            ->blockElement('body')
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

        $clean = trim((new HtmlSanitizer($config))->sanitize(self::keepHighlights($html)));

        // Some sanitizer versions keep the <html>/<head>/<body> document wrapper
        // when the email is a full document (not a fragment). Rendered inside our
        // Livewire component, a nested <body>/<html> makes the browser re-parent
        // the whole page — duplicating the Alpine/Livewire runtime ("multiple
        // instances running") and breaking the panel. Belt-and-suspenders on top
        // of the config above, guaranteed on every sanitizer version:
        //   1) drop any surviving <head>…</head> WHOLE — its metadata children
        //      (<link>/<meta>/<title>) must never reach the chat body;
        //   2) strip the structure-only <html>/<body> tags, keeping their content.
        $clean = (string) preg_replace('#<head\b[^>]*>.*?</head>#is', '', $clean);
        $clean = trim((string) preg_replace('#</?(?:html|body|head)\b[^>]*>#i', '', $clean));

        // strip_tags(): a document that sanitizes down to whitespace/text-only
        // adds nothing over the plain-text body, so skip it.
        return trim(strip_tags($clean)) === '' ? null : $clean;
    }

    /**
     * The inline tags a mail client uses to carry highlighted text.
     *
     * Deliberately not block or table elements: newsletters paint whole
     * wrapper tables and body tags with background colours, and treating those
     * as highlights would turn an entire email yellow. A person marking a
     * sentence produces a span.
     */
    private const HIGHLIGHTABLE = [
        'span', 'font', 'b', 'strong', 'i', 'em', 'u', 's', 'strike',
        'small', 'big', 'code', 'tt', 'a', 'sub', 'sup', 'label',
    ];

    /** Beyond this much text inside one inline tag it is a container, not a highlight. */
    private const MAX_HIGHLIGHT_CHARS = 2000;

    /** Values that appear where a colour would but do not paint anything. */
    private const NOT_A_COLOUR = [
        'transparent', 'inherit', 'initial', 'unset', 'revert', 'currentcolor', 'none',
        'repeat', 'no-repeat', 'repeat-x', 'repeat-y', 'scroll', 'fixed', 'local',
        'padding-box', 'border-box', 'content-box', 'cover', 'contain', 'auto',
        'center', 'top', 'bottom', 'left', 'right', 'space', 'round',
        'white', '#fff', '#ffffff', 'rgb(255,255,255)',
    ];

    /**
     * Rewrite highlighted text as <mark> before the sanitizer sees it.
     *
     * The sanitizer drops every inline style — correctly, because CSS from a
     * stranger can move elements over the panel's own interface and fetch
     * remote URLs. But a customer who highlights a sentence in yellow is saying
     * "this is the important part", and that meaning arrived as a style
     * attribute and was thrown away with it. <mark> carries the same meaning in
     * markup the allow-list already trusts, so the emphasis survives without a
     * single line of foreign CSS reaching the browser.
     */
    private static function keepHighlights(string $html): string
    {
        // Cheap reject first: most mail has no background colour anywhere, and
        // parsing every message into a DOM to learn that is a waste.
        if (preg_match('/background|bgcolor/i', $html) !== 1) {
            return $html;
        }

        $dom = new \DOMDocument;

        // LIBXML_NONET: never fetch anything the document asks for. The wrapper
        // gives us a single node to serialise back out of.
        $loaded = @$dom->loadHTML(
            '<?xml encoding="utf-8" ?><div id="mo-body">'.$html.'</div>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
        );

        if (! $loaded) {
            return $html; // Unparseable — the sanitizer will deal with it.
        }

        $xpath = new \DOMXPath($dom);
        $marked = false;

        foreach ($xpath->query('//*[@style or @bgcolor]') ?: [] as $node) {
            if (! $node instanceof \DOMElement || ! self::highlighted($node)) {
                continue;
            }

            $mark = $dom->createElement('mark');

            while ($node->firstChild !== null) {
                $mark->appendChild($node->firstChild);
            }

            $node->appendChild($mark);
            $marked = true;
        }

        if (! $marked) {
            return $html;
        }

        $body = $xpath->query('//div[@id="mo-body"]')->item(0);

        if ($body === null) {
            return $html;
        }

        $out = '';

        foreach ($body->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }

        return $out;
    }

    /** Whether this element is an inline tag painted in a colour a reader can see. */
    private static function highlighted(\DOMElement $node): bool
    {
        if (! in_array(mb_strtolower($node->nodeName), self::HIGHLIGHTABLE, true)) {
            return false;
        }

        $text = trim($node->textContent);

        // Nothing to emphasise, or so much text that this is a styled container.
        if ($text === '' || mb_strlen($text) > self::MAX_HIGHLIGHT_CHARS) {
            return false;
        }

        // Already carrying a <mark> around everything — leave it alone.
        if ($node->childNodes->length === 1 && mb_strtolower($node->firstChild?->nodeName ?? '') === 'mark') {
            return false;
        }

        $declared = [];

        if (preg_match('/(?:^|;)\s*background(?:-color)?\s*:\s*([^;]+)/i', $node->getAttribute('style'), $found) === 1) {
            $declared[] = $found[1];
        }

        if (trim($node->getAttribute('bgcolor')) !== '') {
            $declared[] = $node->getAttribute('bgcolor');
        }

        foreach ($declared as $value) {
            // Collapse the spaces inside rgb(...) so the shorthand can be read
            // as a list of words.
            $value = (string) preg_replace('/\s*([,()])\s*/', '$1', mb_strtolower(trim($value)));

            foreach (preg_split('/\s+/', $value) ?: [] as $token) {
                if ($token !== '' && ! in_array($token, self::NOT_A_COLOUR, true)) {
                    return true;
                }
            }
        }

        return false;
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
