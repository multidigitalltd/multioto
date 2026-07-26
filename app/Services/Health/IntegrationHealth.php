<?php

namespace App\Services\Health;

use App\Services\Cardcom\CardcomClient;
use App\Services\Linet\LinetClient;
use App\Services\Security\VulnerabilityFeedClient;
use App\Services\Waha\WahaClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Runs the "test connection" checks for each external integration and returns a
 * uniform ConnectionResult. Each check is an explicit, admin-triggered action,
 * so a short synchronous HTTP call is acceptable here.
 */
class IntegrationHealth
{
    public function __construct(
        private CardcomClient $cardcom,
        private LinetClient $linet,
        private WahaClient $waha,
    ) {}

    public function check(string $key): ConnectionResult
    {
        return match ($key) {
            'cardcom' => $this->cardcom->testConnection(),
            'linet' => $this->linet->testConnection(),
            'waha' => $this->waha->testConnection(),
            'email' => $this->checkEmail(),
            'security' => $this->checkSecuritySources(),
            default => ConnectionResult::fail('אינטגרציה לא מוכרת'),
        };
    }

    /**
     * One-click diagnostic for ALL the reputation/vulnerability sources: is
     * URLhaus reachable, can the resolver query Spamhaus DBL, is the Safe
     * Browsing key valid, is the vulnerability feed reachable. Reports a
     * per-source line so the operator sees exactly what is blocked and where.
     */
    private function checkSecuritySources(): ConnectionResult
    {
        $lines = [];
        $allOk = true;

        // URLhaus — must carry the configured abuse.ch Auth-Key, exactly like
        // the real reputation scans (the API answers 401 without one).
        $urlhausKey = trim((string) config('security.reputation.urlhaus_auth_key'));

        try {
            $urlhaus = Http::asForm()->timeout(15)
                ->withHeaders($urlhausKey !== '' ? ['Auth-Key' => $urlhausKey] : [])
                ->post(
                    (string) config('security.reputation.urlhaus_host_url'),
                    ['host' => 'example.com'],
                );
            $ok = $urlhaus->successful();
            $lines[] = 'URLhaus: '.($ok ? 'נגיש ✓' : match (true) {
                $urlhaus->status() === 401 && $urlhausKey === '' => 'נדרש Auth-Key חינמי מ-auth.abuse.ch (השדה למטה)',
                $urlhaus->status() === 403 && $urlhausKey !== '' => 'ה-Auth-Key נדחה (403) — העתיקו מחדש את המפתח המלא מ-auth.abuse.ch ושמרו שוב',
                default => 'נכשל (HTTP '.$urlhaus->status().')',
            });
            $allOk = $allOk && $ok;
        } catch (\Throwable $e) {
            $lines[] = 'URLhaus: לא נגיש ('.Str::limit($e->getMessage(), 80).')';
            $allOk = false;
        }

        // Spamhaus DBL — a DNS probe against the documented test point. Public
        // resolvers (8.8.8.8 / 1.1.1.1) are refused by Spamhaus.
        if ($this->spamhausProbeWorks()) {
            $lines[] = 'Spamhaus DBL: נגיש ✓';
        } else {
            $lines[] = 'Spamhaus DBL: ה-resolver לא מצליח לשאול (כנראה DNS ציבורי 8.8.8.8/1.1.1.1 שחסום — יש לעבור ל-resolver של ספק האחסון)';
            $allOk = false;
        }

        // Google Safe Browsing — validate the key with Google's test URL.
        $sbKey = trim((string) config('security.reputation.safe_browsing_key'));

        if ($sbKey === '') {
            $lines[] = 'Google Safe Browsing: לא הוגדר מפתח (אופציונלי)';
        } else {
            try {
                $sb = Http::timeout(15)->acceptJson()->post(
                    'https://safebrowsing.googleapis.com/v4/threatMatches:find?key='.urlencode($sbKey),
                    [
                        'client' => ['clientId' => 'multioto', 'clientVersion' => '1.0'],
                        'threatInfo' => [
                            'threatTypes' => ['MALWARE'],
                            'platformTypes' => ['ANY_PLATFORM'],
                            'threatEntryTypes' => ['URL'],
                            'threatEntries' => [['url' => 'http://malware.testing.google.test/testing/malware/']],
                        ],
                    ],
                );
                $ok = $sb->successful();
                $lines[] = 'Google Safe Browsing: '.($ok
                    ? 'המפתח תקין ✓'
                    : 'המפתח נדחה (HTTP '.$sb->status().') — ודאו שה-Safe Browsing API מופעל בפרויקט ושהמפתח לא מוגבל לשירות אחר');
                $allOk = $allOk && $ok;
            } catch (\Throwable $e) {
                $lines[] = 'Google Safe Browsing: הבקשה נכשלה ('.Str::limit($e->getMessage(), 80).')';
                $allOk = false;
            }
        }

        // The vulnerability feed (Wordfence by default / WPScan when selected).
        $feedSource = strtolower((string) config('security.vulnerabilities.source', 'wordfence')) === 'wpscan' ? 'WPScan' : 'Wordfence';

        try {
            $feed = app(VulnerabilityFeedClient::class);
            $ok = $feed->available();
            $lines[] = "פיד פגיעויות ({$feedSource}): ".($ok ? 'נגיש ✓' : 'לא זמין'.(($why = $feed->lastError()) !== null ? " — {$why}" : ''));
            $allOk = $allOk && $ok;
        } catch (\Throwable $e) {
            $lines[] = "פיד פגיעויות ({$feedSource}): שגיאה (".Str::limit($e->getMessage(), 80).')';
            $allOk = false;
        }

        $message = implode(' · ', $lines);

        return $allOk ? ConnectionResult::ok($message) : ConnectionResult::fail($message);
    }

    /**
     * Can this server's resolver query the Spamhaus DBL? Probes the documented
     * always-listed test point. Isolated for stubbing in tests (no real DNS).
     */
    protected function spamhausProbeWorks(): bool
    {
        foreach ((array) (@dns_get_record('test.dbl.spamhaus.org', DNS_A) ?: []) as $record) {
            if (str_starts_with((string) ($record['ip'] ?? ''), '127.0.1.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Postmark exposes a clean, side-effect-free auth check: GET /server with
     * the server token returns 200 when the token is valid.
     */
    private function checkEmail(): ConnectionResult
    {
        $token = config('services.postmark.token');

        if (blank($token)) {
            return ConnectionResult::notConfigured('Server Token של Postmark לא הוגדר');
        }

        try {
            $response = Http::withHeaders([
                'X-Postmark-Server-Token' => $token,
                'Accept' => 'application/json',
            ])->timeout(10)->get('https://api.postmarkapp.com/server');

            if ($response->successful()) {
                $name = $response->json('Name');

                return ConnectionResult::ok($name ? "מחובר לשרת Postmark: {$name}" : 'מחובר ל-Postmark');
            }

            if ($response->status() === 401) {
                return ConnectionResult::fail('Postmark דחה את ה-Server Token');
            }

            return ConnectionResult::fail('Postmark החזיר קוד '.$response->status());
        } catch (\Throwable $e) {
            return ConnectionResult::fail('לא ניתן להתחבר ל-Postmark: '.Str::limit(trim($e->getMessage()) ?: class_basename($e), 120));
        }
    }
}
