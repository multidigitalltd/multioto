<?php

namespace App\Services\Hosting;

use App\Models\Site;
use Illuminate\Support\Facades\Http;

/**
 * Read-only WordPress-site diagnostics: a live probe plus a look at recent
 * monitoring history and the TLS certificate. Gathers the facts the operator
 * (and the AI) need to decide what — if anything — to fix. Changes nothing;
 * every actual fix goes through HostingClient behind the ApprovalGate.
 */
class SiteDiagnostics
{
    /** Hebrew labels for the reversible site fixes the operator can apply. */
    public const FIX_LABELS = [
        'clear_cache' => 'ניקוי מטמון (Cache)',
        'restart' => 'הפעלה מחדש של האתר',
        'maintenance_on' => 'הכנסה למצב תחזוקה',
        'maintenance_off' => 'הוצאה ממצב תחזוקה',
    ];

    /**
     * Run diagnostics and return a structured result plus a Hebrew summary and
     * a suggested fix key ('clear_cache' | 'restart' | null).
     *
     * @return array{summary: string, healthy: bool, suggested_fix: ?string, details: array<string, mixed>}
     */
    public function run(Site $site): array
    {
        $url = $site->monitorUrl();
        $probe = $this->probe($url);
        $ssl = $this->sslInfo($site->domain);
        $history = $this->recentHistory($site);

        $suggested = $this->suggestFix($probe);
        $healthy = $probe['ok'] && ($ssl['days_left'] === null || $ssl['days_left'] > 0);

        $lines = [];
        if ($probe['ok']) {
            $lines[] = "האתר עונה תקין (HTTP {$probe['status']}, {$probe['ms']}ms).";
        } elseif ($probe['status'] === null) {
            $lines[] = "האתר לא עונה: {$probe['error']}.";
        } elseif ($probe['status'] < 500) {
            // 4xx — the server answered but blocked/failed the request (403 חסימה,
            // 401 דורש הזדהות, 404 לא נמצא). זה לא אתר תקין.
            $lines[] = "האתר מחזיר שגיאה (HTTP {$probe['status']}) — האתר לא מוגש כראוי (חסימת הרשאות/אבטחה או דף חסר). דורש בדיקה.";
        } else {
            $lines[] = "האתר מחזיר שגיאת שרת (HTTP {$probe['status']}).";
        }

        if ($ssl['days_left'] !== null) {
            $lines[] = $ssl['days_left'] > 0
                ? "תעודת SSL בתוקף לעוד {$ssl['days_left']} ימים."
                : 'תעודת SSL פגה — יש לחדש בדחיפות.';
        }

        if ($history['total'] > 0) {
            $lines[] = "זמינות ב-{$history['total']} בדיקות אחרונות: {$history['uptime_pct']}%.";
        }

        if ($suggested === 'clear_cache') {
            $lines[] = 'המלצה: ניקוי מטמון (תוכן ישן/שגיאת cache אפשרית).';
        } elseif ($suggested === 'restart') {
            $lines[] = 'המלצה: הפעלה מחדש של האתר (נראה תקוע — 502/504/timeout).';
        }

        return [
            'summary' => implode("\n", $lines),
            'healthy' => $healthy,
            'suggested_fix' => $suggested,
            'details' => ['probe' => $probe, 'ssl' => $ssl, 'history' => $history],
        ];
    }

    /** @return array{ok: bool, status: ?int, ms: int, error: ?string} */
    protected function probe(string $url): array
    {
        $start = microtime(true);

        try {
            $response = Http::timeout((int) config('billing.monitoring.timeout_seconds', 15))
                ->withoutRedirecting()
                ->get($url);
            $ms = (int) ((microtime(true) - $start) * 1000);
            $status = $response->status();

            // Only 2xx/3xx counts as a properly-served site. A 4xx (403 forbidden,
            // 401, 404) means the server answered but did NOT serve the site — that
            // is not healthy, even though it isn't a 5xx crash.
            $ok = $status >= 200 && $status < 400;

            return [
                'ok' => $ok,
                'status' => $status,
                'ms' => $ms,
                'error' => $ok ? null : 'HTTP '.$status,
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'status' => null,
                'ms' => (int) ((microtime(true) - $start) * 1000),
                'error' => mb_substr($e->getMessage(), 0, 200),
            ];
        }
    }

    /** Public helper: TLS certificate days-left for a domain (null if unknown). */
    public function sslDaysLeft(string $domain): ?int
    {
        return $this->sslInfo($domain)['days_left'];
    }

    /**
     * TLS certificate expiry for a domain (best-effort; null when unreachable).
     *
     * @return array{days_left: ?int}
     */
    protected function sslInfo(string $domain): array
    {
        $host = preg_replace('#^https?://#', '', trim($domain));
        $host = strtok($host, '/');

        try {
            $context = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false]]);
            $client = @stream_socket_client(
                'ssl://'.$host.':443',
                $errno,
                $errstr,
                (float) 8,
                STREAM_CLIENT_CONNECT,
                $context,
            );

            if ($client === false) {
                return ['days_left' => null];
            }

            $params = stream_context_get_params($client);
            fclose($client);
            $cert = openssl_x509_parse($params['options']['ssl']['peer_certificate'] ?? null);

            if (! $cert || empty($cert['validTo_time_t'])) {
                return ['days_left' => null];
            }

            return ['days_left' => (int) ceil(($cert['validTo_time_t'] - time()) / 86400)];
        } catch (\Throwable) {
            return ['days_left' => null];
        }
    }

    /**
     * Uptime over the recent monitor checks.
     *
     * @return array{total: int, up: int, uptime_pct: int}
     */
    protected function recentHistory(Site $site): array
    {
        $checks = $site->monitorChecks()->latest('checked_at')->limit(50)->get(['is_up']);
        $total = $checks->count();
        $up = $checks->where('is_up', true)->count();

        return [
            'total' => $total,
            'up' => $up,
            'uptime_pct' => $total > 0 ? (int) round($up / $total * 100) : 100,
        ];
    }

    /** Map a probe result to the safest reversible fix, or null. */
    protected function suggestFix(array $probe): ?string
    {
        if ($probe['ok']) {
            return null;
        }

        // 502/503/504 or a timeout → the process is likely hung; restart.
        if (in_array($probe['status'], [502, 503, 504], true) || $probe['status'] === null) {
            return 'restart';
        }

        // Other 5xx → try a cache clear first (least disruptive).
        if ($probe['status'] >= 500) {
            return 'clear_cache';
        }

        // 4xx (403/401/404) — no safe reversible auto-fix; it's permissions,
        // security or a missing page, so flag it for a human rather than guess.
        return null;
    }
}
