<?php

namespace App\Services\Growth;

use App\Models\MonitorCheck;
use App\Models\Site;

/**
 * Turns what the platform already knows about a site into a priced list of work
 * worth offering: accessibility remediation, the missing legal documents, known
 * vulnerabilities, a slow site, broken links, missing SEO basics, an outdated
 * PHP, or monitoring that was never switched on.
 *
 * Every opportunity carries the EVIDENCE that produced it ("14 תמונות ללא
 * טקסט חלופי"), so the team quotes from facts rather than from a hunch — and a
 * customer who asks "why do I need this?" gets a straight answer.
 */
class OpportunityRadar
{
    /**
     * @param  array{broken_links?: list<string>, seo?: array<string, mixed>, php_version?: ?string}  $probe
     *                                                                                                        extra findings gathered by the job (they need HTTP/MCP access)
     * @return list<array{key: string, title: string, evidence: string, price_agorot: int, severity: string}>
     */
    public function build(Site $site, array $probe = []): array
    {
        return collect([
            $this->accessibility($site),
            $this->legalDocs($site),
            $this->vulnerabilities($site),
            $this->speed($site),
            $this->brokenLinks($probe),
            $this->seoBasics($probe),
            $this->phpUpgrade($probe),
            $this->monitoring($site),
        ])->filter()->values()->all();
    }

    /** Total indicative value of a list of opportunities, in agorot. */
    public function totalAgorot(array $opportunities): int
    {
        return collect($opportunities)->sum(fn (array $o): int => (int) ($o['price_agorot'] ?? 0));
    }

    /**
     * @return array{key: string, title: string, evidence: string, price_agorot: int, severity: string}|null
     */
    private function accessibility(Site $site): ?array
    {
        $issues = (array) data_get($site->compliance_scan, 'issues', []);

        if ($issues === []) {
            return null;
        }

        $score = (int) data_get($site->compliance_scan, 'score', 100);
        $critical = collect($issues)->where('severity', 'critical')->count();

        return $this->opportunity('accessibility', 'התאמת נגישות (ת"י 5568)',
            "ציון נגישות {$score}/100 · ".count($issues)." ממצאים, מתוכם {$critical} קריטיים: "
                .collect($issues)->pluck('title')->take(3)->implode(', '),
            $critical > 0 ? 'high' : 'medium');
    }

    private function legalDocs(Site $site): ?array
    {
        $missing = (array) data_get($site->compliance_scan, 'missing_docs', []);

        if ($missing === []) {
            return null;
        }

        return $this->opportunity('legal_docs', 'כתיבת מסמכי חובה לאתר',
            'חסרים באתר: '.collect($missing)->pluck('label')->implode(', '),
            'high');
    }

    private function vulnerabilities(Site $site): ?array
    {
        // 'items' is the key ScanSiteVulnerabilitiesJob actually writes.
        $findings = (array) data_get($site->vulnerability_scan, 'items', []);

        if ($findings === []) {
            return null;
        }

        return $this->opportunity('vulnerabilities', 'טיפול בפגיעויות אבטחה ידועות',
            count($findings).' רכיבים עם פגיעות ידועה: '
                .collect($findings)->pluck('name')->filter()->take(3)->implode(', '),
            'high');
    }

    private function speed(Site $site): ?array
    {
        $threshold = (int) config('growth.opportunities.slow_response_ms', 2500);

        $average = MonitorCheck::query()
            ->where('site_id', $site->id)
            ->where('checked_at', '>=', now()->subWeek())
            ->where('is_up', true)
            ->avg('response_ms');

        if ($average === null || (int) $average < $threshold) {
            return null;
        }

        return $this->opportunity('speed', 'שיפור מהירות האתר',
            'זמן תגובה ממוצע בשבוע האחרון: '.round((int) $average / 1000, 1).' שניות (מעל הסף של '
                .round($threshold / 1000, 1).' שניות) — פוגע בחוויית המשתמש ובדירוג בגוגל.',
            'medium');
    }

    /**
     * @param  array<string, mixed>  $probe
     */
    private function brokenLinks(array $probe): ?array
    {
        $broken = (array) ($probe['broken_links'] ?? []);

        if ($broken === []) {
            return null;
        }

        return $this->opportunity('broken_links', 'תיקון קישורים שבורים',
            count($broken).' קישורים מדף הבית מובילים לעמוד שגיאה: '
                .collect($broken)->take(3)->implode(', '),
            'medium');
    }

    /**
     * @param  array<string, mixed>  $probe
     */
    private function seoBasics(array $probe): ?array
    {
        $seo = (array) ($probe['seo'] ?? []);
        $gaps = [];

        if (($seo['has_description'] ?? true) === false) {
            $gaps[] = 'אין תיאור מטא (meta description) לדף הבית';
        }

        if (($seo['has_title'] ?? true) === false) {
            $gaps[] = 'אין כותרת עמוד (title)';
        }

        if (($seo['has_og'] ?? true) === false) {
            $gaps[] = 'אין תגיות שיתוף לרשתות (Open Graph) — שיתוף בפייסבוק/וואטסאפ ייראה ריק';
        }

        if ((int) ($seo['images_without_lazy'] ?? 0) >= 10) {
            $gaps[] = $seo['images_without_lazy'].' תמונות נטענות ללא טעינה עצלה (lazy loading)';
        }

        if ($gaps === []) {
            return null;
        }

        return $this->opportunity('seo_basics', 'השלמת יסודות SEO וביצועים',
            implode(' · ', $gaps), 'medium');
    }

    /**
     * @param  array<string, mixed>  $probe
     */
    private function phpUpgrade(array $probe): ?array
    {
        $version = (string) ($probe['php_version'] ?? '');
        $minimum = (string) config('growth.opportunities.min_php_version', '8.1');

        if ($version === '' || version_compare($version, $minimum, '>=')) {
            return null;
        }

        return $this->opportunity('php_upgrade', 'שדרוג גרסת PHP בשרת',
            "האתר רץ על PHP {$version} — גרסה שאינה מקבלת עוד עדכוני אבטחה. שדרוג משפר גם מהירות.",
            'high');
    }

    private function monitoring(Site $site): ?array
    {
        if ($site->monitor_enabled) {
            return null;
        }

        return $this->opportunity('monitoring', 'הוספת ניטור ותחזוקה שוטפת',
            'הניטור לאתר כבוי — אין התראה על נפילה, על תפוגת תעודת SSL או על פריצה.',
            'medium');
    }

    /**
     * @return array{key: string, title: string, evidence: string, price_agorot: int, severity: string}
     */
    private function opportunity(string $key, string $title, string $evidence, string $severity): array
    {
        return [
            'key' => $key,
            'title' => $title,
            'evidence' => $evidence,
            'price_agorot' => (int) config("growth.opportunities.prices.{$key}", 0),
            'severity' => $severity,
        ];
    }
}
