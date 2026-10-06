<?php

namespace App\Services\SiteAgent;

use Illuminate\Support\Facades\Cache;
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
    /** Meta's own words about the last send that failed. */
    private ?string $lastError = null;

    /**
     * Why the last send failed, in the provider's own words.
     *
     * A caller that only knows "it did not send" can only say "check the
     * WhatsApp connection" — and that sentence fits a template that was never
     * approved, a template approved in a different language, an expired token
     * and a closed service window equally badly, while naming none of them.
     * Meta does say which; the reason it gives is the whole difference between
     * a minute's fix and an afternoon of guessing, so it is carried out of here
     * rather than left in a log file nobody opens.
     *
     * Null when the last send succeeded, or when none has run yet.
     */
    public function lastError(): ?string
    {
        return $this->lastError;
    }

    public function configured(): bool
    {
        return filled(config('siteagent.whatsapp.phone_number_id'))
            && filled(config('siteagent.whatsapp.token'));
    }

    /**
     * Send a plain-text message.
     *
     * Only ever as a REPLY. Meta allows free-form text solely inside the
     * 24-hour service window that a customer's own message opens; outside it
     * the send is rejected (error 131047), so anything this product initiates
     * goes out as a template instead — see sendTemplate().
     *
     * Returns the provider's message id, or null when the send failed — never a
     * bare bool. The id is what lets a reply be tied to the message it answers,
     * and a caller that cannot tell "sent" from "not sent" is one that will
     * eventually tell a customer their site changed when nothing was sent.
     */
    public function sendText(string $to, string $body): ?string
    {
        return $this->send($to, [
            'type' => 'text',
            // Link previews off: the agent quotes page titles and URLs from the
            // customer's own site, and an unfurled preview of a draft page is a
            // leak of something not published yet.
            'text' => ['preview_url' => false, 'body' => $body],
        ]);
    }

    /** Reply-button ids the inbound side maps back to the words they stand for. */
    public const BUTTON_YES = 'site_agent_yes';

    public const BUTTON_NO = 'site_agent_no';

    /** Meta's ceiling on an interactive message's body. */
    private const INTERACTIVE_BODY_MAX = 1024;

    /**
     * An offer with "כן" / "לא" buttons under it.
     *
     * A tap arrives as an interactive reply carrying the button's id, which
     * the inbound side turns back into "כן" or "לא" — so the confirmation
     * path is the same one a typed answer takes. A preview longer than Meta
     * allows in an interactive body goes as text first, with the buttons in
     * a short message after it.
     */
    public function sendConfirmation(string $to, string $body, ?int $requestId = null): ?string
    {
        $question = 'לבצע את השינוי?';
        $preview = null;

        if (mb_strlen($body) > self::INTERACTIVE_BODY_MAX) {
            $preview = $this->sendText($to, $body);

            if ($preview === null) {
                return null;
            }
        } else {
            $question = $body;
        }

        // The preview already went out as text: a failed button message must
        // not send it a second time — typing "כן" still works.
        return $this->send($to, [
            'type' => 'interactive',
            'interactive' => [
                'type' => 'button',
                'body' => ['text' => $question],
                'footer' => ['text' => 'אפשר גם לכתוב "כן" או "לא"'],
                'action' => ['buttons' => [
                    ['type' => 'reply', 'reply' => ['id' => self::BUTTON_YES.($requestId !== null ? ":{$requestId}" : ''), 'title' => '✅ כן, לבצע']],
                    ['type' => 'reply', 'reply' => ['id' => self::BUTTON_NO.($requestId !== null ? ":{$requestId}" : ''), 'title' => '❌ לא']],
                ]],
            ],
        ]) ?? $preview;
    }

    /**
     * The words a tapped button stands for, or '' when the message is not a
     * button reply. Unknown ids read as their visible title, never as a yes.
     *
     * @param  array<string, mixed>  $payload  one inbound message
     */
    public static function buttonText(array $payload): string
    {
        $reply = self::buttonReply($payload);

        return match (strtok((string) ($reply['id'] ?? ''), ':')) {
            self::BUTTON_YES => 'כן',
            self::BUTTON_NO => 'לא',
            default => trim((string) ($reply['title'] ?? '')),
        };
    }

    /**
     * The offer a tapped button belongs to, when it carries one — so an old
     * button scrolled back to is not read as an answer to a newer offer.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function buttonRequestId(array $payload): ?int
    {
        $id = (string) (self::buttonReply($payload)['id'] ?? '');

        return preg_match('/^site_agent_(?:yes|no):(\d+)$/', $id, $match) === 1 ? (int) $match[1] : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function buttonReply(array $payload): array
    {
        return match ((string) ($payload['type'] ?? '')) {
            'interactive' => (array) data_get($payload, 'interactive.button_reply', []),
            'button' => ['id' => '', 'title' => (string) data_get($payload, 'button.text', '')],
            default => [],
        };
    }

    /**
     * Send one of the account's approved templates.
     *
     * This is the only way to reach a customer who has not just written to us,
     * and that covers everything the product initiates: the verification code
     * for a number that has never messaged this account at all, and a notice to
     * a customer whose last message was three weeks ago. Sending those as free
     * text means Meta refuses them — the customer never gets the code that
     * activates the product they just paid for, and nobody here would know why.
     *
     * Templates are accepted at any time, window or no window, so a proactive
     * message never has to ask whether one is open.
     *
     * The form of each body parameter follows the KEY, and that is not a style
     * choice — the two template categories genuinely differ:
     *
     *  - **Utility/Marketing**, where we write the body ourselves: Meta's editor
     *    refuses numeric placeholders now ("lowercase with single underscores"),
     *    so the variables have names and each parameter must carry
     *    `parameter_name`. Pass `['domain' => …]`.
     *  - **Authentication**, where Meta writes the body: the OTP placeholder is
     *    preset and has no name to give, so the code goes positionally. Pass
     *    `[$code]`.
     *
     * Getting it the wrong way round is rejected by Meta, and a rejected
     * template is a customer who hears nothing with no error reaching any screen
     * here. The call site knows the category for certain, so it decides — rather
     * than a setting somebody has to keep in step with Meta's UI.
     *
     * @param  array<string|int, string|int>  $parameters  body parameters: keyed
     *                                                     by variable name, or a
     *                                                     plain list for an OTP
     * @param  string|null  $copyCode  the code for an authentication template's
     *                                 copy-code button; omitted for utility ones
     */
    public function sendTemplate(string $to, string $name, array $parameters = [], ?string $copyCode = null): ?string
    {
        if ($name === '') {
            return null;
        }

        $components = [];

        if ($parameters !== []) {
            $body = [];

            foreach ($parameters as $variable => $value) {
                // Meta rejects a parameter containing a newline or a tab, and
                // a rejected template is a customer who hears nothing.
                $parameter = ['type' => 'text', 'text' => trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '')];

                // A string key is a named variable; an integer key is a list,
                // which is how an authentication template's preset OTP is sent.
                if (is_string($variable)) {
                    $parameter['parameter_name'] = $variable;
                }

                $body[] = $parameter;
            }

            $components[] = ['type' => 'body', 'parameters' => $body];
        }

        if ($copyCode !== null) {
            // Meta's authentication category ships a copy-code button, and the
            // code has to be repeated on it as well as in the body.
            $components[] = [
                'type' => 'button',
                'sub_type' => 'url',
                'index' => '0',
                'parameters' => [['type' => 'text', 'text' => $copyCode]],
            ];
        }

        return $this->send($to, [
            'type' => 'template',
            'template' => [
                'name' => $name,
                'language' => ['code' => (string) config('siteagent.whatsapp.templates.language', 'he')],
                'components' => $components,
            ],
        ]);
    }

    /**
     * The one place a message actually goes out.
     *
     * @param  array<string, mixed>  $message  the type-specific part of the body
     */
    private function send(string $to, array $message): ?string
    {
        $this->lastError = null;

        if (! $this->configured()) {
            $this->lastError = 'השירות אינו מוגדר במלואו (מזהה מספר, טוקן או סוד חסרים).';

            Log::warning('WhatsAppCloudClient: not configured, message not sent');

            return null;
        }

        $number = $this->normalize($to);

        if ($number === '') {
            $this->lastError = 'מספר הטלפון אינו תקין.';

            return null;
        }

        try {
            $response = Http::withToken((string) config('siteagent.whatsapp.token'))
                ->timeout((int) config('siteagent.whatsapp.timeout_seconds', 20))
                ->post($this->endpoint(), [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $number,
                    ...$message,
                ]);

            if ($response->failed()) {
                // The provider's own words. A status code alone never says
                // whether the number is wrong, the token expired, or the
                // 24-hour window closed — and those need different answers.
                $this->lastError = Str::limit((string) $response->json('error.message', ''), 200);

                Log::warning('WhatsAppCloudClient: send rejected', [
                    'status' => $response->status(),
                    'error' => $this->lastError,
                ]);

                return null;
            }

            return $response->json('messages.0.id');
        } catch (\Throwable $e) {
            $this->lastError = Str::limit($e->getMessage(), 200);

            Log::warning('WhatsAppCloudClient: send failed', ['error' => $this->lastError]);

            return null;
        }
    }

    /**
     * What Meta says it charged this WABA, between two instants.
     *
     * Thin by the architecture rule: it asks, and it hands back what came
     * back. Which period to ask about, how to read the categories and what the
     * figures mean against our own billing all live in MessagingCostReport.
     *
     * `pricing_analytics` rather than `conversation_analytics`, because the
     * question is "what did this cost, by category" and only this one breaks the
     * spend down by pricing category.
     *
     * Returns null when the call could not be made or Meta refused it. That is
     * deliberately distinct from an empty result, which means "no spend in this
     * period" — a screen that cannot tell those apart reports ₪0 for an
     * expired token, and ₪0 is the one figure nobody questions.
     *
     * @return array<string, mixed>|null
     */
    /**
     * The display number behind the configured phone-number id.
     *
     * Needed because Meta's analytics filter takes the NUMBER, while everything
     * else here is keyed on its id. Cached for a day: it changes about never, and
     * it would otherwise be a second round trip on every pull.
     *
     * Null when it cannot be resolved — which the caller must treat as "do not
     * ask", never as "ask about everything".
     */
    public function displayPhoneNumber(): ?string
    {
        $id = trim((string) config('siteagent.whatsapp.phone_number_id'), '/');

        if ($id === '' || ! preg_match('/^\d+$/', $id) || blank(config('siteagent.whatsapp.token'))) {
            return null;
        }

        return Cache::remember('siteagent.display_phone_number.'.$id, now()->addDay(), function () use ($id): ?string {
            try {
                $response = Http::withToken((string) config('siteagent.whatsapp.token'))
                    ->timeout((int) config('siteagent.whatsapp.timeout_seconds', 20))
                    ->get(sprintf('https://graph.facebook.com/%s/%s', $this->apiVersion(), $id), [
                        'fields' => 'display_phone_number',
                    ]);

                if ($response->failed()) {
                    return null;
                }

                // Meta returns it formatted ("+972 50-123-4567"); the filter wants
                // digits.
                $digits = preg_replace('/\D+/', '', (string) $response->json('display_phone_number', ''));

                return $digits === '' ? null : $digits;
            } catch (\Throwable) {
                return null;
            }
        });
    }

    public function pricingAnalytics(\DateTimeInterface $start, \DateTimeInterface $end): ?array
    {
        $this->lastError = null;

        $waba = trim((string) config('siteagent.whatsapp.waba_id'), '/');

        if ($waba === '' || blank(config('siteagent.whatsapp.token'))) {
            $this->lastError = 'חסר מזהה חשבון WhatsApp Business (WABA) או טוקן.';

            return null;
        }

        // Built with the ids stripped of anything that is not a digit. The WABA
        // id is operator-entered, and it is interpolated into a URL path here.
        if (! preg_match('/^\d+$/', $waba)) {
            $this->lastError = 'מזהה חשבון ה-WhatsApp Business אינו מספרי.';

            return null;
        }

        /*
         | Both metrics and the category dimension, because the report reads all
         | three numbers. COST alone returns no `volume` and no
         | `pricing_category` at all, which does not fail — it quietly produces a
         | report of zero messages in one unclassified row.
         |
         | `currency` is asked for alongside, as a field of the account itself:
         | the amounts come back as bare decimals "in the WABA's currency" and
         | the analytics envelope never names it. Without it there is nothing to
         | tell shekels from dollars, and the margin would be a subtraction
         | between two different currencies.
         */
        /*
         | Narrowed to OUR number, which is not optional.
         |
         | A WABA can hold several business numbers, and omitting the filter
         | returns the spend of all of them. Our revenue ledger holds only this
         | bot's traffic, so an unfiltered cost would be compared against a
         | fraction of the messages that produced it — and the margin would be
         | wrong by however much the other numbers happen to send.
         |
         | Unresolvable number means no call at all. "Ask about everything" is the
         | one answer that looks like data and is not.
         */
        $number = $this->displayPhoneNumber();

        if ($number === null) {
            $this->lastError = 'לא הצלחנו לזהות את מספר הטלפון של הבוט מול מטא, ובלעדיו העלות הייתה של כל המספרים בחשבון.';

            return null;
        }

        $field = sprintf(
            'pricing_analytics.start(%d).end(%d).granularity(DAILY)'
                .'.phone_numbers([%s]).metric_types([COST,VOLUME]).dimensions([PRICING_CATEGORY])',
            $start->getTimestamp(),
            $end->getTimestamp(),
            $number,
        );

        try {
            $response = Http::withToken((string) config('siteagent.whatsapp.token'))
                ->timeout((int) config('siteagent.whatsapp.timeout_seconds', 20))
                ->get(sprintf('https://graph.facebook.com/%s/%s', $this->apiVersion(), $waba), [
                    'fields' => 'currency,'.$field,
                ]);

            if ($response->failed()) {
                $this->lastError = Str::limit((string) $response->json('error.message', ''), 200);

                Log::warning('WhatsAppCloudClient: pricing analytics rejected', [
                    'status' => $response->status(),
                    'error' => $this->lastError,
                ]);

                return null;
            }

            /*
             | Handed back whole, with the account's currency beside it. Meta
             | nests the figures as pricing_analytics.data[].data_points[] — one
             | wrapper per series — and flattening it is a decision about how to
             | aggregate, which belongs in MessagingCostReport and not here.
             */
            return [
                'currency' => (string) $response->json('currency', ''),
                'analytics' => (array) $response->json('pricing_analytics', []),
            ];
        } catch (\Throwable $e) {
            $this->lastError = Str::limit($e->getMessage(), 200);

            Log::warning('WhatsAppCloudClient: pricing analytics failed', ['error' => $this->lastError]);

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
                $this->apiVersion(),
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
    /**
     * Does this request even claim to be signed?
     *
     * The difference between "signed with the wrong secret" and "not signed at
     * all" is the difference between Meta knocking and a scanner knocking, and
     * only the first is worth waking anybody for. Meta signs every delivery, so
     * a POST with no signature header was never a delivery attempt.
     */
    public function carriesSignature(?string $header): bool
    {
        return is_string($header) && Str::startsWith($header, 'sha256=');
    }

    public function signatureIsValid(string $rawBody, ?string $header): bool
    {
        $secret = (string) config('siteagent.whatsapp.app_secret');

        if ($secret === '' || ! $this->carriesSignature($header)) {
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
            $this->apiVersion(),
            trim((string) config('siteagent.whatsapp.phone_number_id'), '/'),
        );
    }

    /**
     * The Graph version, from the one setting that holds it.
     *
     * Read in one place on purpose: a version repeated at each call site is a
     * version that gets bumped at one of them, and a retired Graph version
     * does not degrade gracefully — every call to it fails.
     */
    private function apiVersion(): string
    {
        return trim((string) config('siteagent.whatsapp.api_version'), '/');
    }
}
