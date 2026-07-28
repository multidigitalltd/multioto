<?php

namespace App\Services\Compliance;

/**
 * Static accessibility audit of a rendered page, aimed at the checks that
 * matter for Israeli law (ת"י 5568 / WCAG 2.2 AA) and that can be judged from
 * the HTML alone: a missing language or direction, images without alternative
 * text, form fields without labels, links that say nothing ("לחץ כאן"), a
 * heading structure that starts in the middle, and whether the site carries an
 * accessibility statement and an accessibility widget at all.
 *
 * Deliberately honest about its limits: contrast, focus order and screen-reader
 * behaviour need a real browser and a human. This finds the issues that are
 * both common and objectively decidable — the ones worth selling a fix for.
 */
class AccessibilityScanner
{
    /** Link texts that tell a screen-reader user nothing about the target. */
    private const EMPTY_LINK_TEXTS = [
        'כאן', 'לחץ כאן', 'לחצו כאן', 'קרא עוד', 'קראו עוד', 'עוד', 'המשך',
        'click here', 'read more', 'more', 'here', 'link',
    ];

    /** Markers of a bundled accessibility widget (Israeli market + global). */
    private const WIDGET_MARKERS = [
        'nagich', 'negishut', 'accessibility-widget', 'userway', 'accessibe',
        'equalweb', 'enable-toolbar', 'pojo-a11y', 'wp-accessibility',
    ];

    /** Words that identify a link to the accessibility statement. */
    private const STATEMENT_WORDS = ['הצהרת נגישות', 'נגישות', 'accessibility statement'];

    /**
     * @return array{score: int, issues: list<array{key: string, severity: string, title: string, detail: string}>, has_widget: bool, has_statement: bool}
     */
    public function scan(string $html): array
    {
        $issues = [];

        if (! $this->hasLang($html)) {
            $issues[] = $this->issue('lang', 'critical', 'לא הוגדרה שפת העמוד',
                'לתגית <html> חסר lang="he" — קורא מסך לא יידע באיזו שפה להקריא את התוכן.');
        }

        if (! $this->hasDir($html)) {
            $issues[] = $this->issue('dir', 'warning', 'לא הוגדר כיוון כתיבה',
                'לתגית <html> חסר dir="rtl" — טקסט עברי עלול להיקרא בכיוון שגוי בטכנולוגיות מסייעות.');
        }

        [$images, $missingAlt] = $this->images($html);

        if ($missingAlt > 0) {
            $issues[] = $this->issue('img_alt', $missingAlt > 5 ? 'critical' : 'warning',
                "{$missingAlt} תמונות ללא טקסט חלופי",
                "מתוך {$images} תמונות בעמוד, ל-{$missingAlt} אין alt — משתמש עיוור לא יקבל שום מידע עליהן.");
        }

        $unlabelled = $this->unlabelledInputs($html);

        if ($unlabelled > 0) {
            $issues[] = $this->issue('form_labels', 'critical',
                "{$unlabelled} שדות טופס ללא תווית",
                'שדה בלי <label>/aria-label לא ניתן למילוי בקורא מסך — קריטי בטופס יצירת קשר.');
        }

        $vague = $this->vagueLinks($html);

        if ($vague > 0) {
            $issues[] = $this->issue('link_text', 'warning',
                "{$vague} קישורים בטקסט לא ברור",
                'קישורים כמו "לחץ כאן" / "קרא עוד" חסרי משמעות כשקורא מסך מקריא את רשימת הקישורים.');
        }

        if (! $this->hasH1($html)) {
            $issues[] = $this->issue('headings', 'warning', 'אין כותרת ראשית (H1) בעמוד',
                'מבנה כותרות תקין הוא הדרך העיקרית לנווט בעמוד בקורא מסך.');
        }

        if (! $this->hasSkipLink($html)) {
            $issues[] = $this->issue('skip_link', 'info', 'אין קישור "דלג לתוכן"',
                'ללא קישור דילוג, משתמש מקלדת חייב לעבור את כל התפריט בכל עמוד מחדש.');
        }

        $hasStatement = $this->hasStatement($html);

        if (! $hasStatement) {
            $issues[] = $this->issue('statement', 'critical', 'לא נמצאה הצהרת נגישות',
                'תקנות הנגישות מחייבות הצהרת נגישות נגישה מכל עמוד — היעדרה הוא חשיפה משפטית ישירה.');
        }

        $hasWidget = $this->hasWidget($html);

        if (! $hasWidget) {
            $issues[] = $this->issue('widget', 'warning', 'לא זוהה רכיב נגישות באתר',
                'רכיב נגישות (תפריט הגדלה/ניגודיות) הוא הציפייה המקובלת באתרים ישראליים ומקל על עמידה בתקן.');
        }

        return [
            'score' => $this->score($issues),
            'issues' => $issues,
            'has_widget' => $hasWidget,
            'has_statement' => $hasStatement,
        ];
    }

    /**
     * 0–100. Critical findings cost the most; the score is a conversation
     * opener with the customer, never a legal certification.
     *
     * @param  list<array{severity: string}>  $issues
     */
    private function score(array $issues): int
    {
        $penalty = 0;

        foreach ($issues as $issue) {
            $penalty += match ($issue['severity']) {
                'critical' => 20,
                'warning' => 8,
                default => 3,
            };
        }

        return max(0, 100 - $penalty);
    }

    /**
     * @return array{key: string, severity: string, title: string, detail: string}
     */
    private function issue(string $key, string $severity, string $title, string $detail): array
    {
        return ['key' => $key, 'severity' => $severity, 'title' => $title, 'detail' => $detail];
    }

    private function hasLang(string $html): bool
    {
        return preg_match('/<html[^>]*\blang\s*=\s*["\']?[a-z]{2}/i', $html) === 1;
    }

    private function hasDir(string $html): bool
    {
        return preg_match('/<html[^>]*\bdir\s*=\s*["\']?(rtl|ltr)/i', $html) === 1;
    }

    /** @return array{0: int, 1: int} total images, images with no usable alt */
    private function images(string $html): array
    {
        preg_match_all('/<img\b[^>]*>/i', $html, $matches);
        $tags = $matches[0] ?? [];
        $missing = 0;

        foreach ($tags as $tag) {
            // alt="" is CORRECT for decorative images — only a missing alt
            // attribute (and no aria-label / role=presentation) is a failure.
            $hasAlt = preg_match('/\balt\s*=/i', $tag) === 1;
            $hasAria = preg_match('/\b(aria-label|aria-labelledby)\s*=/i', $tag) === 1;
            $decorative = preg_match('/\brole\s*=\s*["\']?(presentation|none)/i', $tag) === 1;

            if (! $hasAlt && ! $hasAria && ! $decorative) {
                $missing++;
            }
        }

        return [count($tags), $missing];
    }

    private function unlabelledInputs(string $html): int
    {
        preg_match_all('/<(input|select|textarea)\b[^>]*>/i', $html, $matches);
        $unlabelled = 0;

        foreach ($matches[0] ?? [] as $tag) {
            // Buttons and hidden fields need no visible label.
            if (preg_match('/\btype\s*=\s*["\']?(hidden|submit|button|image|reset)/i', $tag) === 1) {
                continue;
            }

            $labelled = preg_match('/\b(aria-label|aria-labelledby|title)\s*=/i', $tag) === 1;

            if ($labelled) {
                continue;
            }

            // A field with an id MAY be labelled by a <label for="…"> elsewhere.
            if (preg_match('/\bid\s*=\s*["\']([^"\']+)/i', $tag, $m) === 1
                && preg_match('/<label\b[^>]*\bfor\s*=\s*["\']'.preg_quote($m[1], '/').'["\']/i', $html) === 1) {
                continue;
            }

            $unlabelled++;
        }

        return $unlabelled;
    }

    private function vagueLinks(string $html): int
    {
        preg_match_all('/<a\b[^>]*>(.*?)<\/a>/is', $html, $matches);
        $vague = 0;

        foreach ($matches[1] ?? [] as $inner) {
            $text = mb_strtolower(trim(html_entity_decode(strip_tags($inner), ENT_QUOTES | ENT_HTML5)));

            if ($text !== '' && in_array($text, self::EMPTY_LINK_TEXTS, true)) {
                $vague++;
            }
        }

        return $vague;
    }

    private function hasH1(string $html): bool
    {
        return preg_match('/<h1\b/i', $html) === 1;
    }

    private function hasSkipLink(string $html): bool
    {
        return preg_match('/<a\b[^>]*href\s*=\s*["\']#[^"\']*(content|main|skip)/i', $html) === 1
            || preg_match('/(skip-link|skip-to-content|דלג לתוכן)/iu', $html) === 1;
    }

    private function hasStatement(string $html): bool
    {
        foreach (self::STATEMENT_WORDS as $word) {
            if (preg_match('/<a\b[^>]*>[^<]*'.preg_quote($word, '/').'/iu', $html) === 1) {
                return true;
            }
        }

        return preg_match('/href\s*=\s*["\'][^"\']*(accessibility|negishut|nagishut)/i', $html) === 1;
    }

    private function hasWidget(string $html): bool
    {
        foreach (self::WIDGET_MARKERS as $marker) {
            if (stripos($html, $marker) !== false) {
                return true;
            }
        }

        return false;
    }
}
