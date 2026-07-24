<?php

namespace App\Services\Security;

/**
 * Homepage content fingerprint for defacement detection: reduce a page's HTML
 * to its title + normalized visible text, compare two fingerprints by textual
 * similarity, and spot classic defacement markers ("hacked by" …). Pure text
 * processing — fetching and state live in CheckSiteContentJob.
 */
class ContentFingerprint
{
    /**
     * Phrases that indicate a defacement even when most of the page survived
     * (e.g. an injected banner on an otherwise intact page). Lowercase.
     */
    private const DEFACEMENT_MARKERS = [
        'hacked by', 'defaced by', 'was hacked', 'site hacked', 'owned by',
        'הותקף על ידי', 'נפרץ על ידי',
    ];

    /**
     * Build a fingerprint from raw HTML.
     *
     * @return array{title: string, text: string, hash: string, length: int}
     */
    public function make(string $html): array
    {
        $title = '';
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
            $title = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5));
        }

        $text = $this->normalizedText($html);

        return [
            'title' => mb_substr($title, 0, 190),
            'text' => $text,
            'hash' => hash('sha256', $text),
            'length' => mb_strlen($text),
        ];
    }

    /**
     * Similarity (0–100) between two normalized-text samples. Capped input —
     * similar_text() is cubic in the worst case, so both sides are truncated to
     * the configured sample size before comparing.
     */
    public function similarity(string $previous, string $current): float
    {
        if ($previous === '' && $current === '') {
            return 100.0;
        }

        if ($previous === '' || $current === '') {
            return 0.0;
        }

        similar_text($previous, $current, $percent);

        return round($percent, 1);
    }

    /** The first defacement marker found in the text, or null when clean. */
    public function defacementMarker(string $text): ?string
    {
        $haystack = mb_strtolower($text);

        foreach (self::DEFACEMENT_MARKERS as $marker) {
            if (str_contains($haystack, $marker)) {
                return $marker;
            }
        }

        return null;
    }

    /**
     * Visible text only: scripts/styles stripped, tags removed, entities
     * decoded, lowercased, whitespace collapsed — truncated to the sample size
     * so storage and comparison stay bounded.
     */
    protected function normalizedText(string $html): string
    {
        $clean = preg_replace('/<(script|style|noscript)[^>]*>.*?<\/\1>/is', ' ', $html) ?? $html;
        $clean = strip_tags($clean);
        $clean = html_entity_decode($clean, ENT_QUOTES | ENT_HTML5);
        $clean = mb_strtolower($clean);
        $clean = preg_replace('/\s+/u', ' ', $clean) ?? $clean;

        $sample = (int) config('security.defacement.sample_chars', 3000);

        return mb_substr(trim($clean), 0, max(500, $sample));
    }
}
