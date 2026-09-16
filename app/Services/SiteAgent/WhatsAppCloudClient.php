<?php

namespace App\Services\SiteAgent;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * A thin client for the official WhatsApp Business Cloud API (Meta).
 *
 * Deliberately separate from WahaClient. That one drives the support inbox over
 * a bridge to a phone; this one is the product's own number, and the two must
 * be able to fail independently — a customer paying for the agent should not
 * lose it because the support phone dropped its session.
 *
 * Thin by the architecture rule: it sends, it verifies a signature, it reports
 * what happened. No decision about WHAT to send lives here.
 */
class WhatsAppCloudClient
{
    public function configured(): bool
    {
        return filled(config('siteagent.whatsapp.phone_number_id'))
            && filled(config('siteagent.whatsapp.token'));
    }

    /**
     * Send a plain-text message.
     *
     * Returns the provider's message id, or null when the send failed — never a
     * bare bool. The id is what lets a reply be tied to the message it answers,
     * and a caller that cannot tell "sent" from "not sent" is one that will
     * eventually tell a customer their site changed when nothing was sent.
     */
    public function sendText(string $to, string $body): ?string
    {
        if (! $this->configured()) {
            Log::warning('WhatsAppCloudClient: not configured, message not sent');

            return null;
        }

        $number = $this->normalize($to);

        if ($number === '') {
            return null;
        }

        try {
            $response = Http::withToken((string) config('siteagent.whatsapp.token'))
                ->timeout((int) config('siteagent.whatsapp.timeout_seconds', 20))
                ->post($this->endpoint(), [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $number,
                    'type' => 'text',
                    // Link previews off: the agent quotes page titles and URLs
                    // from the customer's own site, and an unfurled preview of a
                    // draft page is a leak of something not published yet.
                    'text' => ['preview_url' => false, 'body' => $body],
                ]);

            if ($response->failed()) {
                Log::warning('WhatsAppCloudClient: send rejected', [
                    'status' => $response->status(),
                    // The provider's own words. A status code alone never says
                    // whether the number is wrong, the token expired, or the
                    // 24-hour window closed — and those need different answers.
                    'error' => Str::limit((string) $response->json('error.message', ''), 200),
                ]);

                return null;
            }

            return $response->json('messages.0.id');
        } catch (\Throwable $e) {
            Log::warning('WhatsAppCloudClient: send failed', ['error' => Str::limit($e->getMessage(), 200)]);

            return null;
        }
    }

    /**
     * Fetch an image the customer sent, as bytes we may actually publish.
     *
     * Two calls, because that is how Meta serves media: an id resolves to a
     * short-lived URL, and the URL itself needs the same bearer token. Neither
     * step is skippable and neither result is trusted.
     *
     * Everything a file could be wrong about is checked HERE, before the bytes
     * reach a customer's website: the type is read from the CONTENT and not
     * from what Meta said it was, it must be one of a handful of image types,
     * and it must be under the size cap. A file that lies about itself is
     * exactly the file that must not be uploaded anywhere.
     *
     * @return array{bytes: string, mime: string, extension: string}|null
     */
    public function downloadMedia(string $mediaId): ?array
    {
        if (! $this->configured() || trim($mediaId) === '') {
            return null;
        }

        $token = (string) config('siteagent.whatsapp.token');
        $timeout = (int) config('siteagent.whatsapp.timeout_seconds', 20);

        try {
            $lookup = Http::withToken($token)->timeout($timeout)->get(sprintf(
                'https://graph.facebook.com/%s/%s',
                trim((string) config('siteagent.whatsapp.api_version', 'v21.0'), '/'),
                rawurlencode($mediaId),
            ));

            $url = (string) $lookup->json('url', '');

            // Only ever follow Meta's own host. The URL arrives in a response,
            // and a fetch that follows wherever a response points is a request
            // forger waiting for one bad day.
            if (! $lookup->successful() || ! Str::startsWith($url, 'https://') || ! $this->isMetaHost($url)) {
                Log::warning('WhatsAppCloudClient: media lookup rejected', ['media_id' => $mediaId]);

                return null;
            }

            $download = Http::withToken($token)->timeout($timeout)->get($url);

            if (! $download->successful()) {
                return null;
            }

            $bytes = $download->body();
        } catch (\Throwable $e) {
            Log::warning('WhatsAppCloudClient: media download failed', ['error' => Str::limit($e->getMessage(), 200)]);

            return null;
        }

        $maxBytes = max(1, (int) config('siteagent.media.max_megabytes', 8)) * 1024 * 1024;

        if ($bytes === '' || strlen($bytes) > $maxBytes) {
            return null;
        }

        // Read from the bytes themselves. What the sender called it, and what
        // the provider reported, are both claims.
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $allowed = self::ALLOWED_MEDIA;

        if (! array_key_exists($mime, $allowed)) {
            Log::warning('WhatsAppCloudClient: media type refused', ['mime' => $mime]);

            return null;
        }

        return ['bytes' => $bytes, 'mime' => $mime, 'extension' => $allowed[$mime]];
    }

    /**
     * Image types a customer's site may receive, and the extension each one
     * gets. Deliberately short, and deliberately not driven by the filename:
     * the extension is OURS to decide, so nothing named ".php" is ever written.
     */
    private const ALLOWED_MEDIA = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    /** Meta's own media hosts, and nothing else. */
    private function isMetaHost(string $url): bool
    {
        $host = Str::lower((string) parse_url($url, PHP_URL_HOST));

        if ($host === '') {
            return false;
        }

        foreach (['graph.facebook.com', 'lookaside.fbsbx.com', 'scontent.xx.fbcdn.net'] as $allowed) {
            if ($host === $allowed || Str::endsWith($host, '.fbcdn.net')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Is this delivery really from Meta?
     *
     * Meta signs the RAW body with the app secret. The comparison is on the raw
     * bytes for that reason: re-encoding a decoded payload changes key order and
     * spacing, and the signature would never match again — which would either
     * break every delivery or, if somebody then "fixed" it by skipping the
     * check, accept deliveries from anyone.
     *
     * Fails closed. A blank secret rejects everything rather than accepting it.
     */
    public function signatureIsValid(string $rawBody, ?string $header): bool
    {
        $secret = (string) config('siteagent.whatsapp.app_secret');

        if ($secret === '' || ! is_string($header) || ! Str::startsWith($header, 'sha256=')) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $header);
    }

    /**
     * The number as Meta wants it: digits only, country code included.
     *
     * An Israeli mobile typed the way people write it — 050-123-4567 — has a
     * leading zero that must become 972. Sending to "972050..." reaches nobody,
     * and does so silently.
     */
    public function normalize(string $number): string
    {
        $digits = preg_replace('/\D+/', '', $number) ?? '';

        if ($digits === '') {
            return '';
        }

        if (Str::startsWith($digits, '0')) {
            return '972'.ltrim($digits, '0');
        }

        return $digits;
    }

    private function endpoint(): string
    {
        return sprintf(
            'https://graph.facebook.com/%s/%s/messages',
            trim((string) config('siteagent.whatsapp.api_version', 'v21.0'), '/'),
            trim((string) config('siteagent.whatsapp.phone_number_id'), '/'),
        );
    }
}
