<?php

namespace App\Services\Compliance;

/**
 * Checks whether a site carries the documents Israeli businesses are expected
 * to publish: a privacy policy (חוק הגנת הפרטיות, and sharply more relevant
 * since Amendment 13), terms of use, an accessibility statement, and — for a
 * store — a returns/cancellation policy (חוק הגנת הצרכן) plus business contact
 * details.
 *
 * Works from the homepage HTML: these documents are linked from the footer of
 * essentially every site, so a missing LINK is the signal. It reports what is
 * missing, never legal advice — the fix is a service the team sells.
 */
class LegalDocsScanner
{
    /**
     * doc key => [Hebrew label, severity, link words, url fragments].
     *
     * @var array<string, array{0: string, 1: string, 2: list<string>, 3: list<string>}>
     */
    private const DOCS = [
        'privacy' => ['מדיניות פרטיות', 'critical',
            ['מדיניות פרטיות', 'הצהרת פרטיות', 'privacy policy', 'privacy'],
            ['privacy', 'pratiut', 'privacy-policy']],
        'terms' => ['תנאי שימוש', 'warning',
            ['תנאי שימוש', 'תקנון', 'תנאים והגבלות', 'terms of use', 'terms'],
            ['terms', 'takanon', 'terms-of-use', 'tos']],
        'accessibility' => ['הצהרת נגישות', 'critical',
            ['הצהרת נגישות', 'נגישות', 'accessibility statement'],
            ['accessibility', 'negishut', 'nagishut']],
        'refund' => ['מדיניות ביטולים והחזרות', 'warning',
            ['מדיניות ביטול', 'ביטול עסקה', 'החזרות', 'מדיניות החזרות', 'refund policy', 'returns'],
            ['refund', 'returns', 'cancellation', 'bitul']],
    ];

    /**
     * @param  bool  $isStore  a store must also publish a returns policy
     * @return array{missing: list<array{key: string, label: string, severity: string}>, found: list<string>, has_contact: bool}
     */
    public function scan(string $html, bool $isStore = false): array
    {
        $missing = [];
        $found = [];

        foreach (self::DOCS as $key => [$label, $severity, $words, $fragments]) {
            if ($key === 'refund' && ! $isStore) {
                continue; // Only a shop sells anything to cancel.
            }

            if ($this->linked($html, $words, $fragments)) {
                $found[] = $key;

                continue;
            }

            $missing[] = ['key' => $key, 'label' => $label, 'severity' => $severity];
        }

        return [
            'missing' => $missing,
            'found' => $found,
            'has_contact' => $this->hasContactDetails($html),
        ];
    }

    /**
     * A document counts as present when a link's TEXT names it, or a link's URL
     * carries the matching path — covering both Hebrew and transliterated slugs.
     *
     * @param  list<string>  $words
     * @param  list<string>  $fragments
     */
    private function linked(string $html, array $words, array $fragments): bool
    {
        preg_match_all('/<a\b([^>]*)>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $attributes = $match[1] ?? '';
            $text = mb_strtolower(trim(html_entity_decode(strip_tags($match[2] ?? ''), ENT_QUOTES | ENT_HTML5)));

            foreach ($words as $word) {
                if ($text !== '' && str_contains($text, mb_strtolower($word))) {
                    return true;
                }
            }

            if (preg_match('/href\s*=\s*["\']([^"\']+)/i', $attributes, $href) === 1) {
                $url = strtolower($href[1]);

                foreach ($fragments as $fragment) {
                    if (str_contains($url, $fragment)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Business contact details: consumer regulations expect a reachable phone
     * or email on the site itself, not only inside a contact form.
     */
    private function hasContactDetails(string $html): bool
    {
        return preg_match('/href\s*=\s*["\'](tel:|mailto:)/i', $html) === 1
            || preg_match('/\b0(5\d|[2-4,8-9])[-\s]?\d{7}\b/', $html) === 1;
    }
}
