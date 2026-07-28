<?php

namespace App\Jobs;

use App\Models\Site;
use App\Models\SiteEvent;
use App\Models\SystemLog;
use App\Services\Notifications\TeamNotifier;
use App\Services\Security\LayoutFingerprint;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

/**
 * Visual/layout regression watch: fingerprint the homepage's STRUCTURE (how
 * many images, links, headings, stylesheets; does it still have a header, a
 * menu, a footer) and compare with the last good reading.
 *
 * This is the failure the uptime monitor is blind to — an update lands, the
 * page still answers 200 in 300ms, and the site looks wrecked to every visitor.
 * A fetch failure changes nothing here: downtime belongs to MonitorSiteJob.
 */
class CheckSiteLayoutJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public array $backoff = [60];

    /** $rebaseline accepts the CURRENT layout as correct (after a redesign). */
    public function __construct(public int $siteId, public bool $rebaseline = false) {}

    public function failed(?\Throwable $e): void
    {
        SystemLog::record('error', 'monitoring',
            "בדיקת מבנה העמוד לאתר #{$this->siteId} נכשלה בשגיאה לא צפויה: ".($e?->getMessage() ?: 'שגיאה לא ידועה'),
            ['site_id' => $this->siteId]);
    }

    public function handle(LayoutFingerprint $fingerprint, TeamNotifier $team): void
    {
        if (! config('security.layout.enabled', true) && ! $this->rebaseline) {
            return;
        }

        $site = Site::with('customer')->find($this->siteId);

        if (! $site) {
            return;
        }

        if (blank($site->domain) || ! $site->monitor_enabled) {
            SystemLog::record('warning', 'monitoring',
                "בדיקת מבנה העמוד לאתר #{$site->id} לא רצה — ".(blank($site->domain) ? 'לא מוגדר דומיין לאתר.' : 'הניטור לאתר כבוי.'),
                ['site_id' => $site->id]);

            return;
        }

        try {
            // The homepage a visitor sees — never monitor_url, which may point
            // at a /health endpoint that survives a broken theme.
            $response = Http::timeout((int) config('billing.monitoring.timeout_seconds', 10))
                ->withHeaders(['User-Agent' => 'MultiotoLayoutWatch/1.0'])
                ->get('https://'.$site->domain.'/');
        } catch (\Throwable $e) {
            SystemLog::record('info', 'monitoring',
                "בדיקת מבנה העמוד לאתר {$site->domain} לא הושלמה (העמוד לא נטען): ".$e->getMessage(),
                ['site_id' => $site->id]);

            return;
        }

        if (! $response->successful()) {
            return; // Downtime is the uptime monitor's business, not ours.
        }

        $current = $fingerprint->make($response->body());
        $previous = (array) data_get($site->layout_snapshot, 'fingerprint', []);

        $snapshot = [
            'fingerprint' => $current,
            'checked_at' => now()->toIso8601String(),
        ];

        // First sight, or an explicit "this is how it should look" — baseline.
        if ($previous === [] || $this->rebaseline) {
            $site->update(['layout_snapshot' => $snapshot + ['status' => 'ok', 'baselined_at' => now()->toIso8601String()]]);

            return;
        }

        $reasons = $fingerprint->breakages($previous, $current);

        if ($reasons === []) {
            // Intact — roll the baseline forward so gradual, legitimate growth
            // never accumulates into a false alarm.
            $site->update(['layout_snapshot' => $snapshot + ['status' => 'ok']]);

            return;
        }

        // Alert only on ENTERING the broken state (or when the breakage itself
        // changes). A page that stays broken for a week must not produce a week
        // of identical alarms and duplicate findings-log rows.
        $wasBroken = data_get($site->layout_snapshot, 'status') === 'broken';
        $sameReasons = (array) data_get($site->layout_snapshot, 'reasons', []) === $reasons;
        $announce = ! $wasBroken || ! $sameReasons;

        // Broken: KEEP the old baseline, so the next run compares against the
        // last known-good page rather than against the broken one.
        $site->update(['layout_snapshot' => [
            'fingerprint' => $previous,
            'checked_at' => $snapshot['checked_at'],
            'status' => 'broken',
            'reasons' => $reasons,
            'broken_at' => data_get($site->layout_snapshot, 'broken_at') ?: now()->toIso8601String(),
            'alerted_at' => $announce ? now()->toIso8601String() : data_get($site->layout_snapshot, 'alerted_at'),
        ]]);

        if ($announce) {
            $this->alert($team, $site, $reasons);
        }
    }

    /**
     * @param  list<string>  $reasons
     */
    private function alert(TeamNotifier $team, Site $site, array $reasons): void
    {
        $who = $site->customer ? " ({$site->customer->name})" : '';
        $list = collect($reasons)->map(fn (string $r): string => "• {$r}")->implode("\n");

        $title = "🧱 מבנה העמוד באתר {$site->domain} השתנה בצורה חריגה";

        $team->alert(
            $title,
            "דף הבית של {$site->domain}{$who} עדיין עולה תקין — אבל המבנה שלו נשבר:\n{$list}\n\n"
                .'זה בדרך כלל עדכון תוסף/תבנית שהרס את התצוגה. בדקו את דף הבית בעין, ואם התצוגה תקינה — אשרו את המבנה הנוכחי בעמוד האתר.',
            rtrim((string) config('app.url'), '/')."/admin/sites/{$site->id}",
        );

        SiteEvent::record($site->id, 'layout_broken', 'critical', $title, implode(' · ', $reasons));
    }
}
