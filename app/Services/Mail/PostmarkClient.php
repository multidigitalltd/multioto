<?php

namespace App\Services\Mail;

use Illuminate\Support\Facades\Http;

/**
 * Thin client for Postmark's Account API, used only to READ which sender
 * identities are verified so the mail-settings screen can show them. Sending
 * mail goes through Laravel's Postmark transport (server token) — not here.
 *
 * The account token is a separate, account-level credential from the server
 * token; without it we simply can't enumerate senders, and the screen falls
 * back to free-text entry.
 */
class PostmarkClient
{
    private const BASE = 'https://api.postmarkapp.com';

    /**
     * Verified sender signatures and domains for the account.
     *
     * @return array{senders: array<int, array{email: string, name: ?string, confirmed: bool}>, domains: array<int, string>}
     *
     * @throws \RuntimeException when the account token is missing/rejected.
     */
    public function verifiedIdentities(): array
    {
        $token = config('services.postmark.account_token');

        if (blank($token)) {
            throw new \RuntimeException('Account Token של Postmark לא הוגדר — נדרש כדי לסנכרן שולחים מאומתים.');
        }

        return [
            'senders' => $this->senders($token),
            'domains' => $this->domains($token),
        ];
    }

    /**
     * Whether the given from-address is a verified sender: either a confirmed
     * signature, or any address under a listed (DKIM) domain.
     */
    public function isVerifiedSender(string $email, array $identities): bool
    {
        $email = strtolower(trim($email));

        foreach ($identities['senders'] as $sender) {
            if ($sender['confirmed'] && strtolower($sender['email']) === $email) {
                return true;
            }
        }

        $domain = strtolower((string) substr(strrchr($email, '@') ?: '', 1));

        return $domain !== '' && in_array($domain, array_map('strtolower', $identities['domains']), true);
    }

    /** @return array<int, array{email: string, name: ?string, confirmed: bool}> */
    private function senders(string $token): array
    {
        $response = $this->get($token, '/senders', ['count' => 100, 'offset' => 0]);

        return collect($response['SenderSignatures'] ?? [])
            ->map(fn (array $s) => [
                'email' => (string) ($s['EmailAddress'] ?? ''),
                'name' => $s['Name'] ?? null,
                'confirmed' => (bool) ($s['Confirmed'] ?? false),
            ])
            ->filter(fn (array $s) => $s['email'] !== '')
            ->values()
            ->all();
    }

    /** @return array<int, string> */
    private function domains(string $token): array
    {
        $response = $this->get($token, '/domains', ['count' => 100, 'offset' => 0]);

        return collect($response['Domains'] ?? [])
            ->pluck('Name')
            ->filter()
            ->map(fn ($n) => (string) $n)
            ->values()
            ->all();
    }

    private function get(string $token, string $path, array $query): array
    {
        $response = Http::withHeaders([
            'X-Postmark-Account-Token' => $token,
            'Accept' => 'application/json',
        ])->timeout(15)->get(self::BASE.$path, $query);

        if ($response->status() === 401) {
            throw new \RuntimeException('Postmark דחה את ה-Account Token.');
        }

        $response->throw();

        return $response->json() ?? [];
    }
}
