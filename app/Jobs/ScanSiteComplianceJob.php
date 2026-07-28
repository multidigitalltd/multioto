<?php

namespace App\Jobs;

use App\Enums\SiteType;
use App\Models\Site;
use App\Models\SiteEvent;
use App\Models\SystemLog;
use App\Services\Compliance\AccessibilityScanner;
use App\Services\Compliance\LegalDocsScanner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

/**
 * Weekly compliance audit of a customer site: accessibility (ת"י 5568 / WCAG
 * 2.2 AA, the machine-checkable part) and the legal documents an Israeli
 * business is expected to publish — privacy policy, terms, accessibility
 * statement, and for a store a returns policy.
 *
 * The result is a per-site report the team can show the customer, and the raw
 * material for a remediation quote. Read-only: it fetches the homepage and
 * judges the HTML, nothing more.
 */
class ScanSiteComplianceJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public array $backoff = [60];

    public function __construct(public int $siteId) {}

    public function failed(?\Throwable $e): void
    {
        SystemLog::record('error', 'monitoring',
            "סריקת נגישות/תאימות לאתר #{$this->siteId} נכשלה בשגיאה לא צפויה: ".($e?->getMessage() ?: 'שגיאה לא ידועה'),
            ['site_id' => $this->siteId]);
    }

    public function handle(AccessibilityScanner $accessibility, LegalDocsScanner $docs): void
    {
        if (! config('security.compliance.enabled', true)) {
            return;
        }

        $site = Site::find($this->siteId);

        if (! $site || blank($site->domain)) {
            return;
        }

        try {
            $response = Http::timeout((int) config('billing.monitoring.timeout_seconds', 10))
                ->withHeaders(['User-Agent' => 'MultiotoComplianceScan/1.0'])
                ->get('https://'.$site->domain.'/');
        } catch (\Throwable $e) {
            SystemLog::record('info', 'monitoring',
                "סריקת הנגישות לאתר {$site->domain} לא הושלמה (העמוד לא נטען): ".$e->getMessage(),
                ['site_id' => $site->id]);

            return;
        }

        if (! $response->successful()) {
            return; // An unreachable site is the uptime monitor's story.
        }

        $html = $response->body();

        $a11y = $accessibility->scan($html);
        $legal = $docs->scan($html, $site->site_type === SiteType::Store);

        $previous = (array) $site->compliance_scan;

        $site->update(['compliance_scan' => [
            'scanned_at' => now()->toIso8601String(),
            'score' => $a11y['score'],
            'issues' => $a11y['issues'],
            'has_widget' => $a11y['has_widget'],
            'has_statement' => $a11y['has_statement'],
            'missing_docs' => $legal['missing'],
            'found_docs' => $legal['found'],
            'has_contact' => $legal['has_contact'],
        ]]);

        $this->recordFindings($site, $previous, $a11y, $legal);
    }

    /**
     * Log a finding when the picture WORSENS (or on the first scan) — a site
     * that has been missing a privacy policy for months must not add a new row
     * every week, but a document that disappears is worth dating.
     *
     * @param  array<string, mixed>  $previous
     * @param  array{score: int, issues: list<array<string, string>>}  $a11y
     * @param  array{missing: list<array{key: string, label: string, severity: string}>}  $legal
     */
    private function recordFindings(Site $site, array $previous, array $a11y, array $legal): void
    {
        $first = $previous === [];
        $previousScore = (int) ($previous['score'] ?? 100);
        $previousMissing = collect((array) ($previous['missing_docs'] ?? []))->pluck('key')->all();
        $currentMissing = collect($legal['missing'])->pluck('key')->all();
        $newlyMissing = array_values(array_diff($currentMissing, $previousMissing));

        // Accessibility: report the first scan, and any later deterioration.
        if ($a11y['issues'] !== [] && ($first || $a11y['score'] < $previousScore)) {
            $titles = collect($a11y['issues'])->pluck('title')->take(4)->implode(' · ');

            SiteEvent::record($site->id, 'accessibility',
                collect($a11y['issues'])->contains(fn (array $i): bool => $i['severity'] === 'critical') ? 'warning' : 'info',
                "ציון נגישות: {$a11y['score']}/100 — ".count($a11y['issues']).' ממצאים',
                $titles);
        }

        if ($newlyMissing !== [] || ($first && $legal['missing'] !== [])) {
            $labels = collect($legal['missing'])->pluck('label')->implode(', ');

            SiteEvent::record($site->id, 'legal_docs', 'warning',
                'מסמכים משפטיים חסרים באתר: '.count($legal['missing']),
                $labels);
        }
    }
}
