<?php

namespace App\Services\Ai;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Renewable provider-side storage for a site's stable system instructions and
 * tool catalog. Local persistent cache stores metadata only. Conversation text,
 * tool results, owner details and clock context never belong in this prefix.
 *
 * Google charges for storage until expiry. Renew only when the bot is used;
 * there is deliberately no background task keeping idle caches alive forever.
 */
class GeminiContextCache
{
    private const PREFIX = 'site-agent:gemini-context:v1:';

    private const SAFETY_SECONDS = 30;

    private const MANAGEMENT_SECONDS = 8;

    protected function store(): Repository
    {
        return Cache::store();
    }

    protected function timestamp(): int
    {
        return now()->timestamp;
    }

    protected function credentialSalt(): string
    {
        return (string) config('app.key');
    }

    public function enabled(): bool
    {
        return (bool) config('siteagent.assistant.cache.enabled', true)
            && config('billing.ai.provider') === 'google';
    }

    /**
     * Acquire without waiting for another worker. A cache failure must never
     * disable the conversation. At most one bounded management request occurs.
     *
     * @return array{key: string, name: string, expires_at: int, configuration: string}|null
     */
    public function acquire(string $scope, string $base, string $model, string $apiKey, string $system, array $tools): ?array
    {
        if (! $this->enabled() || trim($scope) === '') {
            return null;
        }

        $lock = null;

        try {
            $configuration = $this->statusKey();
            $key = $this->entryKey($scope, $base, $model, $apiKey, $system, $tools);
            $entry = $this->store()->get($key);
            $usable = $this->usable($entry);
            $renewalWindow = min(300, max(60, (int) ($this->ttlSeconds() / 5)));

            if ($usable && $entry['expires_at'] > $this->timestamp() + $renewalWindow) {
                $this->rememberStatus('active', $entry);

                return $entry;
            }

            if ($reason = $this->store()->get($key.':cooldown')) {
                $this->rememberStatus($usable ? 'active' : 'fallback', $usable ? $entry : null, $reason, $configuration);

                return $usable ? $entry : null;
            }

            $lock = $this->store()->lock($key.':lock', self::MANAGEMENT_SECONDS + 5);

            if (! $lock->get()) {
                $this->rememberStatus($usable ? 'active' : 'fallback', $usable ? $entry : null, 'busy', $configuration);

                return $usable ? $entry : null;
            }

            // A worker may have filled the cache between our read and lock.
            $latest = $this->store()->get($key);
            if ($this->usable($latest) && $latest['expires_at'] > $this->timestamp() + $renewalWindow) {
                $this->rememberStatus('active', $latest);

                return $latest;
            }
            $entry = $latest;
            $usable = $this->usable($entry);
            if ($this->store()->get($key.':cooldown')) {
                return $usable ? $entry : null;
            }

            $request = Http::baseUrl($base)->withHeaders(['x-goog-api-key' => $apiKey])
                ->connectTimeout(3)->timeout(self::MANAGEMENT_SECONDS)->withoutRedirecting();

            $response = $usable
                ? $request->patch('/v1beta/'.$entry['name'], ['ttl' => $this->ttlSeconds().'s'])
                : $request->post('/v1beta/cachedContents', [
                    'model' => 'models/'.$this->model($model),
                    'systemInstruction' => ['parts' => [['text' => $system]]],
                    'tools' => $tools,
                    'ttl' => $this->ttlSeconds().'s',
                ]);

            // Settings/reset changed while HTTP was in flight. This response is
            // from the previous generation and cannot publish its status now.
            if ($configuration !== $this->statusKey()) {
                return null;
            }
            $fresh = $response->successful() ? $this->validatedEntry($key, $model, $configuration, $response) : null;

            if ($fresh !== null && (! $usable || $fresh['name'] === $entry['name'])) {
                if (! $this->store()->put($key, $fresh, max(1, $fresh['expires_at'] - $this->timestamp()))) {
                    throw new \RuntimeException('The local cache could not persist the provider reference.');
                }
                $this->store()->forget($key.':cooldown');
                $this->rememberStatus('active', $fresh);

                return $fresh;
            }

            $reason = $response->successful() ? 'invalid_response' : $this->failureReason($response);
            $this->store()->put($key.':cooldown', $reason, 300);
            // A failed renewal need not discard a resource still valid locally.
            // An explicit provider rejection does: it is not safe to call it live.
            if ($usable && $this->isReferenceFailure($response)) {
                $this->store()->forget($key);
                $usable = false;
            }
            $this->rememberStatus($usable ? 'active' : 'fallback', $usable ? $entry : null, $reason, $configuration);

            return $usable ? $entry : null;
        } catch (\Throwable) {
            // Do not persist/log exception messages: HTTP errors can contain the
            // API key, cached prompt or provider response. Status is an enum only.
            try {
                if (isset($key)) {
                    $this->store()->put($key.':cooldown', 'unavailable', 300);
                }
                if (isset($configuration) && $configuration === $this->statusKey()) {
                    $this->rememberStatus('fallback', null, 'unavailable', $configuration);
                }
            } catch (\Throwable) {
                // The local cache itself can be down. Continue uncached.
            }

            return null;
        } finally {
            try {
                $lock?->release();
            } catch (\Throwable) {
                // Expiring owner-safe lock; never disrupt a customer's reply.
            }
        }
    }

    /** Safe retry classification; unrelated auth, quota and schema errors stay errors. */
    public function isReferenceFailure(Response $response): bool
    {
        if (! in_array($response->status(), [400, 403, 404], true)) {
            return false;
        }

        $message = $response->json('error.message');

        return is_string($message)
            && preg_match('/cached[ _-]?contents?|cached content|cache[^\n]{0,80}(?:expired|not found|permission|invalid|missing)/i', $message) === 1;
    }

    /** A generation rejected this resource. One uncached retry may now proceed. */
    public function reject(array $entry): void
    {
        try {
            if (isset($entry['key']) && is_string($entry['key']) && str_starts_with($entry['key'], self::PREFIX)) {
                $this->store()->forget($entry['key']);
                $this->store()->put($entry['key'].':cooldown', 'reference_rejected', 60);
            }
            if (($entry['configuration'] ?? null) === $this->statusKey()) {
                $this->rememberStatus('fallback', null, 'reference_rejected', $entry['configuration']);
            }
        } catch (\Throwable) {
            // Generation can proceed without the optimization.
        }
    }

    /** Local-only rebuild: old provider resources expire normally, without renewal. */
    public function invalidate(): void
    {
        if (! $this->store()->forever(self::PREFIX.'generation', Str::random(32))) {
            throw new \RuntimeException('The local cache could not save the rebuild request.');
        }
    }

    /**
     * Last observed cache status for the selected provider/model/credential and
     * generation, not a claim that every site's prefix already exists remotely.
     * No network calls or provider identifiers appear on the admin screen.
     */
    public function status(): array
    {
        $status = [
            'enabled' => (bool) config('siteagent.assistant.cache.enabled', true),
            'supported_provider' => config('billing.ai.provider') === 'google',
            'state' => 'idle',
            'expires_at' => null,
            'reason' => null,
            'ttl_minutes' => (int) ($this->ttlSeconds() / 60),
        ];

        if (! $status['enabled']) {
            return [...$status, 'state' => 'disabled'];
        }
        if (! $status['supported_provider']) {
            return [...$status, 'state' => 'unsupported_provider'];
        }

        try {
            $observed = $this->store()->get($this->statusKey());
            if (is_array($observed)) {
                $expires = $observed['expires_at'] ?? null;
                if (($observed['state'] ?? '') === 'active' && (! is_int($expires) || $expires <= $this->timestamp() + self::SAFETY_SECONDS)) {
                    return [...$status, 'reason' => 'expired'];
                }

                return [...$status, ...$observed, 'expires_at' => is_int($expires) ? CarbonImmutable::createFromTimestampUTC($expires)->toIso8601String() : null];
            }
        } catch (\Throwable) {
            return [...$status, 'state' => 'fallback', 'reason' => 'unavailable'];
        }

        return $status;
    }

    public function usable(mixed $entry): bool
    {
        $valid = $this->enabled() && is_array($entry)
            && is_string($entry['key'] ?? null)
            && str_starts_with($entry['key'], self::PREFIX)
            && is_string($entry['name'] ?? null)
            && preg_match('#^cachedContents/[A-Za-z0-9_-]{1,256}$#D', $entry['name']) === 1
            && is_int($entry['expires_at'] ?? null)
            && $entry['expires_at'] > $this->timestamp() + self::SAFETY_SECONDS;
        if (! $valid) {
            return false;
        }

        try {
            return ($entry['configuration'] ?? null) === $this->statusKey();
        } catch (\Throwable) {
            return false;
        }
    }

    private function validatedEntry(string $key, string $model, string $configuration, Response $response): ?array
    {
        $data = $response->json();
        if (! is_array($data) || ! is_string($data['model'] ?? null) || $this->model($data['model']) !== $this->model($model)
            || ! is_string($data['expireTime'] ?? null) || ! preg_match('/^\d{4}-\d{2}-\d{2}T/', $data['expireTime'])) {
            return null;
        }

        try {
            $expiry = CarbonImmutable::parse($data['expireTime'])->timestamp;
        } catch (\Throwable) {
            return null;
        }
        $entry = ['key' => $key, 'name' => $data['name'] ?? null, 'expires_at' => $expiry, 'configuration' => $configuration];

        return $this->usable($entry) && $expiry <= $this->timestamp() + $this->ttlSeconds() + 120 ? $entry : null;
    }

    private function entryKey(string $scope, string $base, string $model, string $apiKey, string $system, array $tools): string
    {
        return self::PREFIX.'entry:'.hash('sha256', json_encode($this->canonical([
            'generation' => $this->store()->get(self::PREFIX.'generation', 'initial'),
            'scope' => $scope,
            'endpoint' => rtrim($base, '/'),
            'model' => $this->model($model),
            'credential' => hash_hmac('sha256', $apiKey, $this->credentialSalt()),
            'system' => $system,
            'tools' => $tools,
            'ttl' => $this->ttlSeconds(),
        ]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /** Stable object-key ordering, while preserving semantically ordered arrays. */
    private function canonical(mixed $value): mixed
    {
        if (is_object($value)) {
            $properties = get_object_vars($value);
            ksort($properties);

            return (object) array_map($this->canonical(...), $properties);
        }
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map($this->canonical(...), $value);
    }

    private function statusKey(): string
    {
        return self::PREFIX.'status:'.hash('sha256', json_encode([
            $this->store()->get(self::PREFIX.'generation', 'initial'),
            config('billing.ai.provider'),
            config('billing.ai.base_url'),
            config('billing.ai.model'),
            hash_hmac('sha256', (string) config('billing.ai.api_key'), $this->credentialSalt()),
            $this->ttlSeconds(),
            config('siteagent.assistant.persona'),
            config('siteagent.assistant.style'),
            config('siteagent.assistant.work_rules'),
            config('siteagent.assistant.instructions'),
            config('siteagent.assistant.disabled_permissions'),
        ], JSON_THROW_ON_ERROR));
    }

    private function rememberStatus(string $state, ?array $entry = null, ?string $reason = null, ?string $configuration = null): void
    {
        $key = $configuration ?? $entry['configuration'] ?? $this->statusKey();
        if ($key !== $this->statusKey()) {
            return;
        }
        $this->store()->put($key, [
            'state' => $state,
            'expires_at' => $entry['expires_at'] ?? null,
            'reason' => $reason,
        ], 86400);
    }

    private function failureReason(Response $response): string
    {
        $message = $response->json('error.message');
        if (is_string($message) && preg_match('/(?:too (?:small|short)|minimum|min_total_token)/i', $message)) {
            return 'prefix_too_short';
        }
        if (is_string($message) && preg_match('/(?:not supported|unsupported|does not support)/i', $message)) {
            return 'model_unsupported';
        }

        return 'provider_unavailable';
    }

    private function ttlSeconds(): int
    {
        return min(1440, max(15, (int) config('siteagent.assistant.cache.ttl_minutes', 60))) * 60;
    }

    private function model(string $model): string
    {
        return (string) preg_replace('#^models/#', '', trim($model));
    }
}
