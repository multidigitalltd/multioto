<?php

namespace App\Services\SiteAgent\Evaluation;

use App\Services\Ai\GeminiContextCache;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Provider prefix metadata survives isolated scenario processes without sharing
 * site reads, conversations, Laravel caches or credentials with another case.
 */
class EvaluationGeminiContextCache extends GeminiContextCache
{
    private const PREFIX = 'site-agent:gemini-context:v1:';

    private const MAX_ENTRIES = 128;

    private const MAX_BYTES = 131072;

    private const REASONS = [
        'busy', 'unavailable', 'provider_unavailable', 'prefix_too_short',
        'model_unsupported', 'invalid_response', 'reference_rejected',
    ];

    private readonly Repository $metadata;

    public function __construct(array $state, private readonly string $salt)
    {
        if (strlen($salt) < 16 || strlen($salt) > 512) {
            throw new \InvalidArgumentException('A bounded evaluation cache scope is required.');
        }

        // Benchmark dates are fixed; provider expiry and cooldowns use real time.
        $store = new class extends ArrayStore
        {
            public function get($key)
            {
                $item = $this->storage[$key] ?? null;
                if ($item === null) {
                    return null;
                }
                if ($item['expiresAt'] !== 0 && microtime(true) >= $item['expiresAt']) {
                    $this->forget($key);

                    return null;
                }

                return $item['value'];
            }

            protected function calculateExpiration($seconds)
            {
                return $seconds > 0 ? microtime(true) + $seconds : 0;
            }
        };
        $this->metadata = new Repository($store);

        // A malformed handoff merely loses the optimization, never broadens the
        // isolated child's capabilities or imports arbitrary cache content.
        $state = self::sanitizeState($state);
        foreach ($state['entries'] as $key => $item) {
            if ($item['expires_at'] === 0) {
                $this->metadata->forever($key, $item['value']);
            } elseif ($item['expires_at'] > $this->timestamp()) {
                $this->metadata->put($key, $item['value'], $item['expires_at'] - $this->timestamp());
            }
        }
    }

    /** Only hashes, provider resource handles, expiry and fixed status enums. */
    public function exportState(): array
    {
        $entries = [];
        foreach ($this->metadata->getStore()->all() as $key => $item) {
            if ($item['expiresAt'] !== 0 && $item['expiresAt'] <= microtime(true)) {
                continue;
            }
            $entries[$key] = [
                'value' => $item['value'],
                'expires_at' => (int) $item['expiresAt'],
            ];
        }
        $state = ['schema' => 1, 'entries' => $entries];

        return self::sanitizeState($state);
    }

    public function acquire(string $scope, string $base, string $model, string $apiKey, string $system, array $tools): ?array
    {
        $entry = parent::acquire($scope, $base, $model, $apiKey, $system, $tools);
        if ($this->enabled() && $entry === null) {
            // Stop a bulk benchmark before silently paying full-prefix input
            // costs for hundreds of scenarios. Production keeps its fallback.
            throw new \RuntimeException('The evaluation provider cache is unavailable.');
        }

        return $entry;
    }

    protected function store(): CacheRepository
    {
        return $this->metadata;
    }

    protected function timestamp(): int
    {
        return time();
    }

    protected function credentialSalt(): string
    {
        return $this->salt;
    }

    public static function sanitizeState(array $state): array
    {
        return self::validState($state) ? $state : ['schema' => 1, 'entries' => []];
    }

    private static function validState(array $state): bool
    {
        if (! self::hasKeys($state, ['schema', 'entries']) || $state['schema'] !== 1
            || ! is_array($state['entries']) || count($state['entries']) > self::MAX_ENTRIES) {
            return false;
        }
        try {
            if (strlen(json_encode($state, JSON_THROW_ON_ERROR)) > self::MAX_BYTES) {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }

        foreach ($state['entries'] as $key => $item) {
            if (! is_string($key) || ! is_array($item) || ! self::hasKeys($item, ['value', 'expires_at'])
                || ! is_int($item['expires_at']) || $item['expires_at'] < 0
                || $item['expires_at'] > time() + 86520
                || ($item['expires_at'] === 0 && $key !== self::PREFIX.'generation')
                || ! self::validValue($key, $item['value'])) {
                return false;
            }
        }

        return true;
    }

    private static function validValue(string $key, mixed $value): bool
    {
        if ($key === self::PREFIX.'generation') {
            return is_string($value) && preg_match('/^[a-zA-Z0-9]{32}$/D', $value) === 1;
        }
        if (preg_match('/^'.preg_quote(self::PREFIX, '/').'entry:[a-f0-9]{64}:cooldown$/D', $key)) {
            return in_array($value, self::REASONS, true);
        }
        if (preg_match('/^'.preg_quote(self::PREFIX, '/').'status:[a-f0-9]{64}$/D', $key)) {
            return is_array($value) && self::hasKeys($value, ['state', 'expires_at', 'reason'])
                && in_array($value['state'], ['active', 'fallback'], true)
                && ($value['expires_at'] === null || self::validExpiry($value['expires_at']))
                && ($value['reason'] === null || in_array($value['reason'], self::REASONS, true));
        }
        if (preg_match('/^'.preg_quote(self::PREFIX, '/').'entry:[a-f0-9]{64}$/D', $key)) {
            return is_array($value) && self::hasKeys($value, ['key', 'name', 'expires_at', 'configuration'])
                && $value['key'] === $key && is_string($value['name'])
                && preg_match('#^cachedContents/[a-zA-Z0-9_-]{1,256}$#D', $value['name']) === 1
                && self::validExpiry($value['expires_at']) && is_string($value['configuration'])
                && preg_match('/^'.preg_quote(self::PREFIX, '/').'status:[a-f0-9]{64}$/D', $value['configuration']) === 1;
        }

        return false;
    }

    private static function validExpiry(mixed $expiry): bool
    {
        return is_int($expiry) && $expiry > 0 && $expiry <= time() + 86520;
    }

    private static function hasKeys(array $value, array $keys): bool
    {
        return count($value) === count($keys) && count(array_intersect(array_keys($value), $keys)) === count($keys);
    }
}
