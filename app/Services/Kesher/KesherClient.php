<?php

namespace App\Services\Kesher;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Transport for the Kesher (קשר) API. Nothing here decides anything.
 *
 * Kesher has two gateways with two different authentication schemes, and the
 * whole API is case sensitive — a mistyped key is not an error, it is a field
 * that silently does not arrive. So the shape of a request lives here, once,
 * and every caller passes values rather than assembling envelopes of its own.
 *
 * Business rules — when to collect, what a collection means, whether to invoice
 * — deliberately live outside this class.
 */
class KesherClient
{
    /**
     * The master switch: is this integration turned on at all.
     *
     * Separate from having credentials, because the two gateways authenticate
     * differently and one can be configured without the other.
     */
    public function enabled(): bool
    {
        return (bool) config('billing.kesher.enabled');
    }

    /** Gateway 1 needs a username and password in the body. */
    public function canCall(): bool
    {
        return $this->enabled()
            && filled(config('billing.kesher.username'))
            && filled(config('billing.kesher.password'));
    }

    /**
     * Gateway 2 needs only its bearer token.
     *
     * Checked on its own rather than through the Gateway 1 credentials: an
     * installation given a token and nothing else is a valid Kesher setup, and
     * requiring the other gateway's password would make every named endpoint
     * return null with no reason given.
     */
    public function canUseEndpoints(): bool
    {
        return $this->enabled() && filled(config('billing.kesher.token'));
    }

    /**
     * Gateway 1: the `func` router, authenticated by username + password in the
     * body.
     *
     * `format` travels as a query parameter and not in the body — Kesher reads
     * it from outside the JSON object, and sending it inside returns XML to a
     * caller that asked for JSON.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>|null null when the call could not be made at all
     */
    public function call(string $func, array $params = []): ?array
    {
        if (! $this->canCall()) {
            return null;
        }

        $response = $this->send(
            (string) config('billing.kesher.gateway_url').'?format=json',
            [
                'func' => $func,
                'userName' => (string) config('billing.kesher.username'),
                'password' => (string) config('billing.kesher.password'),
                ...$params,
            ],
            $func,
        );

        return $response?->json();
    }

    /**
     * Gateway 2: the named-endpoint route, authenticated by a bearer token.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>|null
     */
    public function endpoint(string $name, array $params = []): ?array
    {
        if (! $this->canUseEndpoints()) {
            return null;
        }

        $response = $this->send(
            rtrim((string) config('billing.kesher.api_url'), '/')."/{$name}?format=json",
            [
                'CompanyDeveloperMail' => (string) config('billing.kesher.developer_mail'),
                ...$params,
            ],
            $name,
            ['Authorization' => 'Bearer '.config('billing.kesher.token')],
        );

        return $response?->json();
    }

    /**
     * Whether a Kesher response reports success.
     *
     * Their `Code` is not a single success value: 0, 944 and 458 all mean "went
     * through" depending on the call, and 4 is a decline. `Status` is the field
     * that answers the question directly, so it leads — the codes are read only
     * when it is absent, which is the case on older endpoints.
     *
     * @param  array<string, mixed>|null  $response
     */
    public function succeeded(?array $response): bool
    {
        if ($response === null) {
            return false;
        }

        if (array_key_exists('Status', $response)) {
            return (bool) $response['Status'];
        }

        return in_array((int) ($response['Code'] ?? -1), [0, 944, 458], true);
    }

    /** The human-readable reason, for a log line or an operator's screen. */
    public function reason(?array $response): string
    {
        return trim((string) ($response['Description'] ?? '')) ?: 'לא התקבלה תשובה מקשר.';
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    private function send(string $url, array $body, string $label, array $headers = []): ?Response
    {
        try {
            $response = Http::withHeaders($headers)
                ->timeout((int) config('billing.kesher.timeout_seconds', 30))
                ->asJson()
                ->post($url, $body);
        } catch (\Throwable $e) {
            // Never rethrown as a bare transport error: the caller has to be
            // able to tell "Kesher said no" from "we never reached Kesher",
            // and only the second one is worth retrying blindly.
            Log::warning('KesherClient: request failed', ['call' => $label, 'error' => $e->getMessage()]);

            return null;
        }

        if ($response->failed()) {
            Log::warning('KesherClient: HTTP error', [
                'call' => $label,
                'status' => $response->status(),
            ]);

            return null;
        }

        return $response;
    }
}
