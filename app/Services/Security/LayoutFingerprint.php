<?php

namespace App\Services\Security;

/**
 * Structural fingerprint of a page — the "is it still built the same way?"
 * measure that catches a broken layout the uptime monitor never sees: after a
 * plugin/theme update the page still returns 200, but the header vanished, the
 * menu lost its items, or half the images stopped rendering.
 *
 * Deliberately structural, not textual: the defacement watch already compares
 * words (CheckSiteContentJob). Here we count the building blocks, so ordinary
 * copy edits are invisible while a collapsed layout stands out.
 */
class LayoutFingerprint
{
    /** Landmarks whose disappearance is, on its own, a broken page. */
    public const LANDMARKS = ['header', 'nav', 'footer'];

    /**
     * @return array{images: int, links: int, headings: int, forms: int, scripts: int, stylesheets: int, bytes: int, landmarks: array<string, bool>}
     */
    public function make(string $html): array
    {
        return [
            'images' => $this->count('/<img\b/i', $html),
            'links' => $this->count('/<a\b[^>]*\shref=/i', $html),
            'headings' => $this->count('/<h[1-3]\b/i', $html),
            'forms' => $this->count('/<form\b/i', $html),
            'scripts' => $this->count('/<script\b/i', $html),
            'stylesheets' => $this->count('/<link\b[^>]*stylesheet/i', $html),
            'bytes' => strlen($html),
            'landmarks' => $this->landmarks($html),
        ];
    }

    /**
     * Human-readable reasons the layout looks broken compared with the baseline,
     * or [] when the page is structurally intact.
     *
     * A "drop" is judged in RELATIVE terms with an absolute floor, so a page
     * with 4 images losing one is noise while a page with 40 losing 30 is not.
     *
     * @param  array<string, mixed>  $previous
     * @param  array<string, mixed>  $current
     * @return list<string>
     */
    public function breakages(array $previous, array $current): array
    {
        $reasons = [];

        // A landmark that existed and is now gone — header/menu/footer.
        foreach (self::LANDMARKS as $landmark) {
            $had = (bool) data_get($previous, "landmarks.{$landmark}", false);
            $has = (bool) data_get($current, "landmarks.{$landmark}", false);

            if ($had && ! $has) {
                $reasons[] = match ($landmark) {
                    'header' => 'הכותרת העליונה (header) נעלמה מהעמוד',
                    'nav' => 'תפריט הניווט (nav) נעלם מהעמוד',
                    default => 'הכותרת התחתונה (footer) נעלמה מהעמוד',
                };
            }
        }

        $labels = [
            'images' => 'תמונות',
            'links' => 'קישורים',
            'headings' => 'כותרות',
            'stylesheets' => 'קובצי עיצוב (CSS)',
        ];

        $threshold = (float) config('security.layout.drop_ratio', 0.6);
        $floor = (int) config('security.layout.min_elements', 5);

        foreach ($labels as $key => $label) {
            $before = (int) ($previous[$key] ?? 0);
            $after = (int) ($current[$key] ?? 0);

            if ($before < $floor) {
                continue; // Too few to reason about — any change is noise.
            }

            if ($after <= $before * (1 - $threshold)) {
                $reasons[] = "מספר ה{$label} בעמוד צנח מ-{$before} ל-{$after}";
            }
        }

        // A page that lost most of its weight is almost never a copy edit.
        $bytesBefore = (int) ($previous['bytes'] ?? 0);
        $bytesAfter = (int) ($current['bytes'] ?? 0);

        if ($bytesBefore > 5000 && $bytesAfter <= $bytesBefore * (1 - $threshold)) {
            $reasons[] = 'נפח העמוד צנח בכמעט '.round((1 - ($bytesAfter / max(1, $bytesBefore))) * 100).'%';
        }

        return $reasons;
    }

    /** @return array<string, bool> */
    private function landmarks(string $html): array
    {
        $found = [];

        foreach (self::LANDMARKS as $landmark) {
            // The semantic element, or the ARIA/class equivalent themes use.
            $found[$landmark] = preg_match('/<'.$landmark.'\b/i', $html) === 1
                || preg_match('/(role=["\']?(banner|navigation|contentinfo)|(id|class)=["\'][^"\']*'.$landmark.')/i', $html) === 1;
        }

        return $found;
    }

    private function count(string $pattern, string $html): int
    {
        return (int) preg_match_all($pattern, $html);
    }
}
