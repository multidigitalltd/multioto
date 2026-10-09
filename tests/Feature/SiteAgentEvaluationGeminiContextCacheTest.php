<?php

namespace Tests\Feature;

use App\Services\Ai\ClaudeClient;
use App\Services\Ai\GeminiContextCache;
use App\Services\SiteAgent\Evaluation\EvaluationGeminiContextCache;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SiteAgentEvaluationGeminiContextCacheTest extends TestCase
{
    private const SALT = 'run-one-stable-random-salt-1234567890';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'billing.ai.enabled' => true,
            'billing.ai.api_key' => 'private-provider-key',
            'billing.ai.provider' => 'google',
            'billing.ai.model' => 'gemini-3.1-flash-lite',
            'billing.ai.base_url' => 'https://generativelanguage.googleapis.com',
            'siteagent.assistant.cache.enabled' => true,
            'siteagent.assistant.cache.ttl_minutes' => 60,
        ]);
        $this->travelTo(now()->setDate(2020, 1, 1)->startOfDay());
        Http::preventStrayRequests();
    }

    private function remote(string $name = 'prefix-one', int $seconds = 3600): array
    {
        return [
            'name' => 'cachedContents/'.$name,
            'model' => 'models/'.config('billing.ai.model'),
            'expireTime' => gmdate('Y-m-d\TH:i:s\Z', time() + $seconds),
        ];
    }

    private function acquire(EvaluationGeminiContextCache $cache, string $system = 'private stable prefix'): ?array
    {
        return $cache->acquire(
            'site-agent:customer:1:site:1', config('billing.ai.base_url'), config('billing.ai.model'),
            config('billing.ai.api_key'), $system, [['functionDeclarations' => [['name' => 'private-tool-catalog']]]],
        );
    }

    public function test_provider_prefix_survives_child_process_handoff_and_case_cache_flush_without_sharing_private_content(): void
    {
        Http::fake(['*' => fn () => Http::response($this->remote())]);
        $first = new EvaluationGeminiContextCache([], self::SALT);
        $entry = $this->acquire($first);
        $this->assertNotNull($entry);
        $state = $first->exportState();
        $this->assertCount(2, $state['entries']);
        Cache::put('a-case-page-result', 'private live page', 60);
        Cache::flush();
        $this->assertSame($entry, $this->acquire($first));

        config(['app.key' => 'new-random-child-process-key']);
        $second = new EvaluationGeminiContextCache(json_decode(json_encode($state), true), self::SALT);
        $this->assertSame($entry, $this->acquire($second));
        $this->assertSame('active', $second->status()['state']);
        $this->assertNull(Cache::get($entry['key']));
        Http::assertSentCount(1);

        $snapshot = json_encode($second->exportState());
        foreach (['private-provider-key', 'private stable prefix', 'private-tool-catalog', 'private live page', self::SALT] as $private) {
            $this->assertStringNotContainsString($private, $snapshot);
        }
    }

    public function test_provider_expiry_uses_real_time_even_with_frozen_benchmark_clock_in_future(): void
    {
        $this->travelTo(now()->setDate(2040, 1, 1));
        Http::fake(['*' => fn () => Http::response($this->remote())]);
        $cache = new EvaluationGeminiContextCache([], self::SALT);
        $entry = $this->acquire($cache);
        $this->assertTrue($cache->usable($entry));
        $this->assertEqualsWithDelta(time() + 3600, $entry['expires_at'], 2);
        $next = new EvaluationGeminiContextCache($cache->exportState(), self::SALT);
        $this->assertSame($entry, $this->acquire($next));
        Http::assertSentCount(1);
    }

    public function test_distinct_runs_credentials_and_changed_prefixes_do_not_reuse_provider_resources(): void
    {
        $created = 0;
        Http::fake(['*' => fn () => Http::response($this->remote('prefix-'.++$created))]);
        $first = new EvaluationGeminiContextCache([], self::SALT);
        $entry = $this->acquire($first);
        $otherRun = new EvaluationGeminiContextCache($first->exportState(), 'different-run-salt-1234567890');
        $this->assertNotSame($entry['key'], $this->acquire($otherRun)['key']);
        config(['billing.ai.api_key' => 'rotated-provider-key']);
        $rotated = new EvaluationGeminiContextCache($first->exportState(), self::SALT);
        $this->assertNotSame($entry['key'], $this->acquire($rotated)['key']);
        $this->assertNotSame($entry['key'], $this->acquire($first, 'changed stable prefix')['key']);
        Http::assertSentCount(4);
    }

    public function test_near_expiry_renews_instead_of_reposting_prefix_and_expired_handoff_recreates(): void
    {
        $requests = 0;
        Http::fake(['*' => function (Request $request) use (&$requests) {
            $requests++;
            if ($requests === 2) {
                $this->assertSame('PATCH', $request->method());
                $this->assertSame(['ttl' => '3600s'], $request->data());
            }

            return Http::response($this->remote(seconds: $requests === 1 ? 180 : 3600));
        }]);
        $cache = new EvaluationGeminiContextCache([], self::SALT);
        $this->acquire($cache);
        $next = new EvaluationGeminiContextCache($cache->exportState(), self::SALT);
        $this->acquire($next);
        $expired = $next->exportState();
        foreach ($expired['entries'] as &$item) {
            $item['expires_at'] = time() - 1;
        }
        unset($item);
        $last = new EvaluationGeminiContextCache($expired, self::SALT);
        $this->acquire($last);
        Http::assertSentCount(3);
    }

    public function test_cache_failure_stops_generation_and_cooldown_survives_next_process(): void
    {
        Http::fake(['*' => function (Request $request) {
            $this->assertStringEndsWith('/cachedContents', $request->url());

            return Http::response(['error' => ['message' => 'Model does not support caching.']], 400);
        }]);
        $cache = new EvaluationGeminiContextCache([], self::SALT);
        $this->app->instance(GeminiContextCache::class, $cache);
        $client = app(ClaudeClient::class);
        $this->assertNull($client->converse('stable prefix', 'private owner message', [], fn () => [], cacheScope: 'evaluation'));
        $this->assertSame('fallback', $cache->status()['state']);
        $this->assertSame('model_unsupported', $cache->status()['reason']);
        $next = new EvaluationGeminiContextCache($cache->exportState(), self::SALT);
        $this->app->instance(GeminiContextCache::class, $next);
        $this->assertNull($client->converse('stable prefix', 'another owner message', [], fn () => [], cacheScope: 'evaluation'));
        Http::assertSentCount(1);
    }

    public function test_disabled_cache_remains_disabled_without_management_requests(): void
    {
        config(['siteagent.assistant.cache.enabled' => false]);
        $cache = new EvaluationGeminiContextCache([], self::SALT);
        $this->assertNull($this->acquire($cache));
        $this->assertSame('disabled', $cache->status()['state']);
        Http::assertNothingSent();
    }

    #[DataProvider('invalidStates')]
    public function test_untrusted_snapshots_cannot_import_arbitrary_cache_values(array $state): void
    {
        $cache = new EvaluationGeminiContextCache($state, self::SALT);
        $this->assertSame(['schema' => 1, 'entries' => []], $cache->exportState());
        Http::assertNothingSent();
    }

    public static function invalidStates(): array
    {
        $prefix = 'site-agent:gemini-context:v1:';
        $entryKey = $prefix.'entry:'.str_repeat('a', 64);
        $statusKey = $prefix.'status:'.str_repeat('b', 64);
        $expiry = time() + 3600;
        $item = ['value' => [
            'key' => $entryKey, 'name' => 'cachedContents/valid', 'expires_at' => $expiry,
            'configuration' => $statusKey,
        ], 'expires_at' => $expiry];
        $invalidPath = $item;
        $invalidPath['value']['name'] = 'cachedContents/../models/secret';
        $extra = $item;
        $extra['value']['prompt'] = 'private conversation';

        return [
            'arbitrary cache key' => [['schema' => 1, 'entries' => ['conversation-history' => $item]]],
            'provider path injection' => [['schema' => 1, 'entries' => [$entryKey => $invalidPath]]],
            'extra private value' => [['schema' => 1, 'entries' => [$entryKey => $extra]]],
            'missing metadata expiry' => [['schema' => 1, 'entries' => [$entryKey => ['value' => $item['value']]]]],
            'unknown enum' => [['schema' => 1, 'entries' => [$entryKey.':cooldown' => ['value' => 'private-provider-key', 'expires_at' => $expiry]]]],
            'oversized state' => [['schema' => 1, 'entries' => [$prefix.'generation' => ['value' => str_repeat('x', 140000), 'expires_at' => 0]]]],
            'too many entries' => [['schema' => 1, 'entries' => array_fill(0, 129, $item)]],
            'unknown schema' => [['schema' => 2, 'entries' => []]],
            'unbounded lifetime' => [['schema' => 1, 'entries' => [$entryKey => ['value' => $item['value'], 'expires_at' => 0]]]],
        ];
    }

    public function test_local_rebuild_generation_survives_handoff_without_using_global_cache(): void
    {
        Http::fake(['*' => fn () => Http::response($this->remote())]);
        $cache = new EvaluationGeminiContextCache([], self::SALT);
        $first = $this->acquire($cache);
        $cache->invalidate();
        $next = new EvaluationGeminiContextCache($cache->exportState(), self::SALT);
        $this->assertFalse($next->usable($first));
        $this->assertNotSame($first['key'], $this->acquire($next)['key']);
        Http::assertSentCount(2);
    }
}
