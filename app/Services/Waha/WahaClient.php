<?php

namespace App\Services\Waha;

use Illuminate\Support\Facades\Http;

/**
 * Thin client for a WAHA (WhatsApp HTTP API) instance.
 *
 * Used for 1:1 support conversations and dunning reminders. Bulk sends must go
 * through SendBroadcastJob with aggressive throttling (number-ban risk).
 */
class WahaClient
{
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
     * Send a media message (file by URL) with optional caption.
     */
    public function sendMedia(string $chatId, string $fileUrl, ?string $caption = null): array
    {
        return $this->request('api/sendFile', [
            'chatId' => $this->normalizeChatId($chatId),
            'file' => ['url' => $fileUrl],
            'caption' => $caption,
            'session' => config('billing.waha.session'),
        ]);
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
     * Convert an E.164 phone number to a WAHA chat id; pass JIDs through untouched.
     */
    public function normalizeChatId(string $chatIdOrPhone): string
    {
        if (str_contains($chatIdOrPhone, '@')) {
            return $chatIdOrPhone;
        }

        return ltrim($chatIdOrPhone, '+').'@c.us';
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
