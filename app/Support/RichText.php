<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Converts the basic rich-text HTML produced by the reply editor (paragraphs,
 * bold/italic, bulleted/numbered lists, links) into WhatsApp's own markup, so an
 * agent can format one reply and have it read correctly on either channel —
 * WhatsApp does NOT understand HTML, and raw tags would reach the customer.
 *
 * WhatsApp formatting: *bold*, _italic_, ~strikethrough~, ```monospace```.
 */
class RichText
{
    public static function toWhatsapp(?string $html): string
    {
        $html = trim((string) $html);

        if ($html === '') {
            return '';
        }

        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        // Force UTF-8 and wrap so a fragment parses with a single root.
        $dom->loadHTML(
            '<?xml encoding="UTF-8"?><div>'.$html.'</div>',
            LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();

        $root = $dom->getElementsByTagName('div')->item(0);
        $text = $root ? self::renderChildren($root) : '';

        return self::normalize($text);
    }

    private static function renderChildren(\DOMNode $node): string
    {
        $out = '';

        foreach ($node->childNodes as $child) {
            $out .= self::renderNode($child);
        }

        return $out;
    }

    private static function renderNode(\DOMNode $node): string
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            return $node->textContent;
        }

        if ($node->nodeType !== XML_ELEMENT_NODE) {
            return '';
        }

        $tag = strtolower($node->nodeName);
        $inner = self::renderChildren($node);

        return match ($tag) {
            'strong', 'b' => self::wrap($inner, '*'),
            'em', 'i' => self::wrap($inner, '_'),
            's', 'del', 'strike' => self::wrap($inner, '~'),
            'code' => self::wrap($inner, '```'),
            'br' => "\n",
            'p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote' => trim($inner)."\n\n",
            'ul' => self::renderList($node, ordered: false)."\n",
            'ol' => self::renderList($node, ordered: true)."\n",
            'a' => self::renderLink($node, $inner),
            default => $inner,
        };
    }

    /** Wrap non-empty inner text in a WhatsApp marker, preserving outer spacing. */
    private static function wrap(string $inner, string $marker): string
    {
        return trim($inner) === '' ? $inner : $marker.trim($inner).$marker;
    }

    private static function renderList(\DOMNode $node, bool $ordered): string
    {
        $lines = [];
        $i = 1;

        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE && strtolower($child->nodeName) === 'li') {
                $marker = $ordered ? $i++.'. ' : '• ';
                $lines[] = $marker.trim(self::renderChildren($child));
            }
        }

        return implode("\n", $lines);
    }

    /** "text (url)" — or just the URL when the link text is the URL itself. */
    private static function renderLink(\DOMNode $node, string $inner): string
    {
        $href = trim((string) ($node->attributes?->getNamedItem('href')?->nodeValue ?? ''));
        $inner = trim($inner);

        if ($href === '' || $href === $inner) {
            return $inner !== '' ? $inner : $href;
        }

        return $inner !== '' ? "{$inner} ({$href})" : $href;
    }

    /** Trim trailing per-line spaces and collapse 3+ blank lines to one. */
    private static function normalize(string $text): string
    {
        $text = preg_replace('/[ \t]+\n/', "\n", $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim(Str::of($text)->toString());
    }
}
