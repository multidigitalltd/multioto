<?php

namespace App\Services\Waha;

use App\Services\Health\ConnectionResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Thin client for a WAHA (WhatsApp HTTP API) instance.
 *
 * Used for 1:1 support conversations and dunning reminders. Bulk sends must go
 * through SendBroadcastJob with aggressive throttling (number-ban risk).
 */
class WahaClient
{
    /**
     * Verify the WAHA instance is reachable and the session is up. Called from
     * the admin "test connection" button, so a short synchronous call is fine.
     */
    public function testConnection(): ConnectionResult
    {
        if (blank(config('billing.waha.base_url'))) {
            return ConnectionResult::notConfigured('כתובת WAHA לא הוגדרה');
        }

        try {
            $status = $this->sessionStatus();
            $state = (string) ($status['status'] ?? $status['state'] ?? 'UNKNOWN');

            return match ($state) {
                'WORKING' => ConnectionResult::ok('מחובר ופעיל'),
                'SCAN_QR_CODE', 'STARTING' => ConnectionResult::fail("מחובר ל-WAHA אך ה-session אינו מוכן (מצב: {$state}) — יש לסרוק QR"),
                'FAILED', 'STOPPED' => ConnectionResult::fail("ה-session במצב {$state} — יש להפעיל מחדש"),
                default => ConnectionResult::ok("מחובר ל-WAHA (מצב session: {$state})"),
            };
        } catch (\Throwable $e) {
            return ConnectionResult::fail('לא ניתן להתחבר ל-WAHA: '.Str::limit(trim($e->getMessage()) ?: class_basename($e), 120));
        }
    }

    /**
     * Send a plain text message to a chat id (JID) or E.164 phone number.
     */
    public function sendMessage(string $chatId, string $text): array
    {
        return $this->request('api/sendText', [
            'chatId' => $this->normalizeChatId($chatId),
            'text' => $text,
            'session' => config('billing.waha.session'),
        ]);
    }

    /**
     * Send a file by its raw bytes (base64) — used for locally-stored reply
     * attachments, so WAHA never needs to reach back to our server for a URL.
     */
    public function sendFile(string $chatId, string $filename, string $mime, string $contents, ?string $caption = null): array
    {
        return $this->request('api/sendFile', [
            'chatId' => $this->normalizeChatId($chatId),
            'file' => ['mimetype' => $mime, 'filename' => $filename, 'data' => base64_encode($contents)],
            'caption' => $caption,
            'session' => config('billing.waha.session'),
        ]);
    }

    /**
     * Download inbound media by its WAHA URL (authenticated with the API key).
     * Returns the raw bytes, or null on any failure — a missing attachment must
     * never break message ingestion. Same-origin as the WAHA server only.
     */
    public function downloadMedia(string $url): ?string
    {
        if (! str_starts_with($url, 'http')) {
            return null;
        }

        try {
            $response = Http::withHeaders($this->authHeaders())->timeout(30)->get($url);

            return $response->successful() ? $response->body() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Current session status — used by the scheduler to alert when a QR re-scan is needed.
     */
    public function sessionStatus(): array
    {
        $config = config('billing.waha');

        $response = Http::baseUrl($config['base_url'])
            ->withHeaders($this->authHeaders())
            ->timeout(15)
            ->get('api/sessions/'.$config['session']);

        $response->throw();

        return $response->json() ?? [];
    }

    /**
     * Point the WAHA session's webhook at our inbound endpoint, so incoming
     * WhatsApp messages actually reach the system (WAHA does not push events
     * anywhere until a webhook is configured on the session). Uses WAHA's
     * session-update API; the session keeps its authenticated WhatsApp login.
     */
    public function configureInboundWebhook(string $webhookUrl): array
    {
        $config = config('billing.waha');

        $response = Http::baseUrl($config['base_url'])
            ->withHeaders($this->authHeaders())
            ->timeout(20)
            ->put('api/sessions/'.$config['session'], [
                'config' => [
                    'webhooks' => [[
                        'url' => $webhookUrl,
                        'events' => ['message'],
                    ]],
                ],
            ]);

        $response->throw();

        return $response->json() ?? [];
    }

    /**
     * Convert a phone number to a WAHA chat id; pass JIDs through untouched.
     *
     * Strips punctuation (+, spaces, dashes) and converts a local number with a
     * leading 0 (e.g. Israeli "0501234567") to international form
     * ("972501234567") — WAHA rejects local numbers, which returned a 500.
     */
    public function normalizeChatId(string $chatIdOrPhone): string
    {
        if (str_contains($chatIdOrPhone, '@')) {
            return $chatIdOrPhone; // already a JID / chat id
        }

        $digits = preg_replace('/\D+/', '', $chatIdOrPhone) ?? '';

        if (str_starts_with($digits, '0')) {
            $digits = (string) config('billing.waha.default_country_code', '972').substr($digits, 1);
        }

        return $digits.'@c.us';
    }

    protected function request(string $path, array $payload): array
    {
        $config = config('billing.waha');

        $response = Http::baseUrl($config['base_url'])
            ->withHeaders($this->authHeaders())
            ->timeout(30)
            ->post($path, $payload);

        $response->throw();

        return $response->json() ?? [];
    }

    protected function authHeaders(): array
    {
        $apiKey = config('billing.waha.api_key');

        return $apiKey ? ['X-Api-Key' => $apiKey] : [];
    }
}
