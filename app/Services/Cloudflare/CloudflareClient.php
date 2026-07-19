<?php

namespace App\Services\Cloudflare;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin client for the one job we need from Cloudflare: whitelist our panel's IP
 * for a customer's zone, so an IP Access Rule lets our server-to-server agent
 * request bypass all of Cloudflare's protections (managed challenge, WAF, rate
 * limiting) — the fix for the "Just a moment…" 403 that blocks the MCP endpoint.
 *
 * The API token is supplied per-call by the operator and never stored or logged;
 * all business decisions live here, not in the caller.
 */
class CloudflareClient
{
    private const BASE = 'https://api.cloudflare.com/client/v4';

    /**
     * Add an "Allow" IP Access Rule for $ip on $domain's zone. Idempotent — a
     * matching rule already in place is treated as success.
     *
     * @return array{ok: bool, message: string}
     */
    public function whitelistIp(string $token, string $domain, string $ip, string $notes): array
    {
        $token = trim($token);

        if ($token === '') {
            return $this->fail('חסר טוקן API של Cloudflare.');
        }

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return $this->fail('כתובת ה-IP של המערכת אינה תקינה.');
        }

        // Every Cloudflare request is inside the same guard, so a timeout or a
        // dropped connection at any step yields the friendly failure notice
        // rather than an unhandled exception out of the Filament action.
        try {
            $zoneId = $this->zoneId($token, $domain);

            if ($zoneId === null) {
                return $this->fail('לא נמצא Zone פעיל ב-Cloudflare עבור הדומיין הזה. ודאו שהאתר מנוהל בחשבון שאליו שייך הטוקן.');
            }

            if ($this->alreadyWhitelisted($token, $zoneId, $ip)) {
                return $this->ok("כתובת ה-IP {$ip} כבר מוחרגת ב-Cloudflare — לא נדרש שינוי.");
            }

            $response = $this->request($token)->post(self::BASE."/zones/{$zoneId}/firewall/access_rules/rules", [
                'mode' => 'whitelist',
                'configuration' => ['target' => 'ip', 'value' => $ip],
                'notes' => $notes,
            ]);
        } catch (\Throwable) {
            return $this->fail('הפנייה ל-Cloudflare נכשלה — בדקו את הטוקן והחיבור לרשת.');
        }

        if ($response->successful() && data_get($response->json(), 'success') === true) {
            return $this->ok("כתובת ה-IP {$ip} הוחרגה מהגנות Cloudflare — חיבור הסוכן לא ייחסם יותר.");
        }

        return $this->fail($this->errorMessage($response, 'החרגת ה-IP ב-Cloudflare נכשלה'));
    }

    /**
     * Purge everything from $domain's Cloudflare cache. Guarded like whitelistIp
     * so any network failure yields a friendly message.
     *
     * @return array{ok: bool, message: string}
     */
    public function purgeCache(string $token, string $domain): array
    {
        $token = trim($token);

        if ($token === '') {
            return $this->fail('חסר טוקן API של Cloudflare.');
        }

        try {
            $zoneId = $this->zoneId($token, $domain);

            if ($zoneId === null) {
                return $this->fail('לא נמצא Zone פעיל ב-Cloudflare עבור הדומיין הזה. ודאו שהאתר מנוהל בחשבון שאליו שייך הטוקן.');
            }

            $response = $this->request($token)->post(self::BASE."/zones/{$zoneId}/purge_cache", [
                'purge_everything' => true,
            ]);
        } catch (\Throwable) {
            return $this->fail('הפנייה ל-Cloudflare נכשלה — בדקו את הטוקן והחיבור לרשת.');
        }

        if ($response->successful() && data_get($response->json(), 'success') === true) {
            return $this->ok("הקאש של {$domain} נוקה ב-Cloudflare.");
        }

        return $this->fail($this->errorMessage($response, 'ניקוי הקאש ב-Cloudflare נכשל'));
    }

    /** Resolve the zone id for a domain, trying the host and each parent domain. */
    private function zoneId(string $token, string $domain): ?string
    {
        foreach ($this->zoneCandidates($domain) as $name) {
            $id = data_get($this->request($token)->get(self::BASE.'/zones', [
                'name' => $name,
                'status' => 'active',
            ])->json(), 'result.0.id');

            if (filled($id)) {
                return (string) $id;
            }
        }

        return null;
    }

    private function alreadyWhitelisted(string $token, string $zoneId, string $ip): bool
    {
        return filled(data_get($this->request($token)->get(self::BASE."/zones/{$zoneId}/firewall/access_rules/rules", [
            'mode' => 'whitelist',
            'configuration.target' => 'ip',
            'configuration.value' => $ip,
        ])->json(), 'result.0.id'));
    }

    /**
     * Zone-name candidates from most to least specific — so a subdomain
     * (shop.example.co.il) still resolves to its registrable zone
     * (example.co.il) without bundling a public-suffix list.
     *
     * @return list<string>
     */
    private function zoneCandidates(string $domain): array
    {
        $host = strtolower(trim($domain));
        $host = (string) preg_replace(['#^https?://#', '#/.*$#', '#^www\.#'], '', $host);
        $parts = array_values(array_filter(explode('.', $host)));

        $candidates = [];
        for ($i = 0; $i <= count($parts) - 2; $i++) {
            $candidates[] = implode('.', array_slice($parts, $i));
        }

        return $candidates;
    }

    private function request(string $token): PendingRequest
    {
        return Http::withToken($token)->acceptJson()->timeout(15);
    }

    private function errorMessage(Response $response, string $prefix): string
    {
        $detail = data_get($response->json(), 'errors.0.message');

        return $prefix.(filled($detail) ? ': '.$detail : " (HTTP {$response->status()})").'.';
    }

    /** @return array{ok: true, message: string} */
    private function ok(string $message): array
    {
        return ['ok' => true, 'message' => $message];
    }

    /** @return array{ok: false, message: string} */
    private function fail(string $message): array
    {
        return ['ok' => false, 'message' => $message];
    }
}
