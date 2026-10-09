<?php

namespace Tests\Feature;

use App\Models\AiCustomerUsage;
use App\Models\AiUsage;
use App\Models\Customer;
use App\Services\Ai\AiUsageAttribution;
use App\Services\Ai\ClaudeClient;
use App\Services\Ai\GeminiContextCache;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GeminiContextCacheTest extends TestCase
{
    use RefreshDatabase;

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
        $this->travelTo(now()->setDate(2026, 10, 9)->setTime(12, 0));
        Http::preventStrayRequests();
    }

    private function tools(): array
    {
        return [[
            'name' => 'read_page',
            'description' => 'Read current content',
            'input_schema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']],
        ]];
    }

    private function converse(string $prompt = 'owner-one private request', string $scope = 'site-agent:customer:1:site:2', ?callable $handler = null): ?string
    {
        return app(ClaudeClient::class)->converse(
            'Stable safety instructions.', $prompt, $this->tools(),
            $handler ?? fn (): array => ['content' => 'private live result'],
            cacheScope: $scope,
        );
    }

    private function remote(string $name = 'prefix-one'): array
    {
        return [
            'name' => 'cachedContents/'.$name,
            'model' => 'models/'.preg_replace('#^models/#', '', (string) config('billing.ai.model')),
            'expireTime' => now()->addMinutes((int) config('siteagent.assistant.cache.ttl_minutes'))->toIso8601String(),
        ];
    }

    private function textResponse(array $usage = []): array
    {
        return [
            'candidates' => [['content' => ['parts' => [['text' => 'מוכן']]]]],
            'usageMetadata' => $usage,
        ];
    }

    private function acquire(string $scope = 'site-agent:customer:1:site:2', string $system = 'stable', array $tools = []): ?array
    {
        return app(GeminiContextCache::class)->acquire(
            $scope, (string) config('billing.ai.base_url'), (string) config('billing.ai.model'),
            (string) config('billing.ai.api_key'), $system, $tools,
        );
    }

    public function test_reuses_remote_prefix_across_workers_messages_clock_changes_and_tool_rounds(): void
    {
        $creations = $generations = $handlers = 0;
        Http::fake(['generativelanguage.googleapis.com/*' => function (Request $request) use (&$creations, &$generations) {
            if (str_ends_with($request->url(), '/cachedContents')) {
                $creations++;
                $this->assertSame('Stable safety instructions.', data_get($request->data(), 'systemInstruction.parts.0.text'));
                $this->assertArrayNotHasKey('contents', $request->data());
                $this->assertSame('3600s', $request['ttl']);
                $this->assertSame($this->tools()[0]['input_schema'], data_get($request->data(), 'tools.0.functionDeclarations.0.parametersJsonSchema'));
                $this->assertStringNotContainsString('private', $request->body());
                $this->assertStringNotContainsString('12:00', $request->body());

                return Http::response($this->remote());
            }
            $generations++;
            $this->assertSame('cachedContents/prefix-one', $request['cachedContent']);
            $this->assertArrayNotHasKey('systemInstruction', $request->data());
            $this->assertArrayNotHasKey('tools', $request->data());

            if ($generations === 1) {
                return Http::response(['candidates' => [['content' => ['parts' => [[
                    'thoughtSignature' => 'opaque',
                    'functionCall' => ['name' => 'read_page', 'id' => 'read-1', 'args' => ['id' => 8]],
                ]]]]]]);
            }
            if ($generations === 2) {
                $this->assertSame('opaque', data_get($request->data(), 'contents.1.parts.0.thoughtSignature'));
                $this->assertSame('read-1', data_get($request->data(), 'contents.2.parts.0.functionResponse.id'));
                $this->assertSame('private live result', data_get($request->data(), 'contents.2.parts.0.functionResponse.response.result'));
            }
            if ($generations === 3) {
                $this->assertStringContainsString('12:02', data_get($request->data(), 'contents.0.parts.0.text'));
                $this->assertStringContainsString('owner-two', data_get($request->data(), 'contents.0.parts.0.text'));
                $this->assertStringNotContainsString('owner-one', $request->body());
                $this->assertStringNotContainsString('private live result', $request->body());
            }

            return Http::response($this->textResponse());
        }]);

        $this->assertSame('מוכן', $this->converse(handler: function () use (&$handlers): array {
            $handlers++;

            return ['content' => 'private live result'];
        }));
        $this->travel(2)->minutes();
        $this->app->forgetInstance(GeminiContextCache::class);
        $this->assertSame('מוכן', $this->converse('owner-two private request'));
        $this->assertSame(1, $creations);
        $this->assertSame(3, $generations);
        $this->assertSame(1, $handlers);
        $this->assertSame('active', app(GeminiContextCache::class)->status()['state']);
    }

    public function test_renews_only_near_expiry_on_use_and_rebuilds_after_idle_expiry(): void
    {
        $methods = [];
        Http::fake(['generativelanguage.googleapis.com/*' => function (Request $request) use (&$methods) {
            $methods[] = $request->method();
            if ($request->method() === 'PATCH') {
                $this->assertSame(['ttl' => '3600s'], $request->data());
                $this->assertStringEndsWith('/v1beta/cachedContents/prefix-one', $request->url());
            }

            return Http::response($this->remote());
        }]);

        $first = $this->acquire();
        $this->travel(54)->minutes();
        $this->assertSame($first, $this->acquire());
        $this->travel(2)->minutes();
        $renewed = $this->acquire();
        $this->assertGreaterThan($first['expires_at'], $renewed['expires_at']);
        $this->travel(61)->minutes();
        $this->assertSame('idle', app(GeminiContextCache::class)->status()['state']);
        $this->assertNotNull($this->acquire());
        $this->assertSame(['POST', 'PATCH', 'POST'], $methods);
    }

    public function test_cache_identity_separates_tenants_sites_catalog_schema_model_credentials_endpoint_and_instructions(): void
    {
        $creations = 0;
        Http::fake(['*' => function () use (&$creations) {
            return Http::response($this->remote('prefix-'.++$creations));
        }]);

        $this->assertNotNull($this->acquire());
        $this->assertNotNull($this->acquire('site-agent:customer:2:site:2'));
        $this->assertNotNull($this->acquire('site-agent:customer:1:site:3'));
        $this->assertNotNull($this->acquire(system: 'Changed safety instructions'));
        $this->assertNotNull($this->acquire(tools: [['functionDeclarations' => [['name' => 'read']]]]));
        $this->assertNotNull($this->acquire(tools: [['functionDeclarations' => [['name' => 'read', 'parametersJsonSchema' => ['type' => 'object']]]]]));
        config(['billing.ai.model' => 'gemini-other']);
        $this->assertSame('idle', app(GeminiContextCache::class)->status()['state']);
        $this->assertNotNull($this->acquire());
        config(['billing.ai.api_key' => 'rotated-private-key']);
        $this->assertSame('idle', app(GeminiContextCache::class)->status()['state']);
        $this->assertNotNull($this->acquire());
        config(['billing.ai.base_url' => 'https://other-google-proxy.example']);
        $this->assertSame('idle', app(GeminiContextCache::class)->status()['state']);
        $this->assertNotNull($this->acquire());
        $this->assertSame(9, $creations);
    }

    public function test_schema_identity_preserves_json_objects_and_array_order_but_ignores_object_property_order(): void
    {
        $creates = 0;
        Http::fake(['*' => function () use (&$creates) {
            return Http::response($this->remote('prefix-'.++$creates));
        }]);
        $first = $this->acquire(tools: [['schema' => ['type' => 'object', 'properties' => (object) []]]]);
        $same = $this->acquire(tools: [['schema' => ['properties' => (object) [], 'type' => 'object']]]);
        $this->assertSame($first, $same);
        $this->assertNotNull($this->acquire(tools: [['schema' => ['properties' => [], 'type' => 'object']]]));
        $this->assertNotNull($this->acquire(tools: [['names' => ['first', 'second']]]));
        $this->assertNotNull($this->acquire(tools: [['names' => ['second', 'first']]]));
        $this->assertSame(4, $creates);
    }

    public function test_operator_rebuild_and_tuning_changes_hide_previous_status_without_network_requests(): void
    {
        Http::fake(['*' => fn () => Http::response($this->remote())]);
        $cache = app(GeminiContextCache::class);
        $entry = $this->acquire();
        $this->assertSame('active', $cache->status()['state']);
        $this->assertStringNotContainsString('private-provider-key', json_encode($cache->status()));
        $this->assertStringNotContainsString('cachedContents', json_encode($cache->status()));
        $cache->invalidate();
        $this->assertFalse($cache->usable($entry));
        $this->assertSame('idle', $cache->status()['state']);
        Http::assertSentCount(1);
        $this->assertNotNull($this->acquire());
        config(['siteagent.assistant.persona' => 'New persona']);
        $this->assertSame('idle', $cache->status()['state']);
        $this->assertNotNull($this->acquire(system: 'New persona'));
        Http::assertSentCount(3);
    }

    public function test_invalidation_during_create_cannot_publish_old_generation_as_active(): void
    {
        Http::fake(['*' => function () {
            app(GeminiContextCache::class)->invalidate();

            return Http::response($this->remote());
        }]);
        $this->assertNull($this->acquire());
        $this->assertSame('idle', app(GeminiContextCache::class)->status()['state']);
    }

    public function test_contending_worker_falls_back_without_duplicate_creation_or_waiting(): void
    {
        $nested = 'not-called';
        Http::fake(['*' => function () use (&$nested) {
            $nested = $this->acquire();

            return Http::response($this->remote());
        }]);
        $this->assertNotNull($this->acquire());
        $this->assertNull($nested);
        Http::assertSentCount(1);
        $this->assertSame('active', app(GeminiContextCache::class)->status()['state']);
    }

    public function test_unsupported_or_short_prefix_uses_uncached_requests_during_bounded_cooldown(): void
    {
        $creations = 0;
        Http::fake(['*' => function (Request $request) use (&$creations) {
            if (str_ends_with($request->url(), '/cachedContents')) {
                $creations++;

                return Http::response(['error' => ['message' => 'Cached content is too small; private-provider-key SECRET-PROMPT']], 400);
            }
            $this->assertArrayNotHasKey('cachedContent', $request->data());
            $this->assertArrayHasKey('tools', $request->data());
            $this->assertArrayHasKey('systemInstruction', $request->data());

            return Http::response($this->textResponse());
        }]);
        $this->assertSame('מוכן', $this->converse());
        $this->assertSame('מוכן', $this->converse());
        $status = app(GeminiContextCache::class)->status();
        $this->assertSame('fallback', $status['state']);
        $this->assertSame('prefix_too_short', $status['reason']);
        $this->assertStringNotContainsString('SECRET', json_encode($status));
        $this->assertStringNotContainsString('private-provider-key', json_encode($status));
        $this->assertSame(1, $creations);
        $this->travel(6)->minutes();
        $this->assertSame('מוכן', $this->converse());
        $this->assertSame(2, $creations);
    }

    public function test_failed_renewal_keeps_still_valid_reference_without_hammering_provider(): void
    {
        Http::fake(['*' => fn (Request $request) => $request->method() === 'PATCH'
            ? Http::response(['error' => ['message' => 'temporarily unavailable']], 503)
            : Http::response($this->remote()),
        ]);
        $first = $this->acquire();
        $this->travel(56)->minutes();
        $this->assertSame($first, $this->acquire());
        $this->assertSame($first, $this->acquire());
        Http::assertSentCount(2);
        $this->travel(4)->minutes();
        $this->assertNull($this->acquire());
        $this->assertSame('fallback', app(GeminiContextCache::class)->status()['state']);
        Http::assertSentCount(2);
    }

    public function test_management_network_exception_uses_uncached_generation_and_cools_down(): void
    {
        $creates = 0;
        Http::fake(['*' => function (Request $request) use (&$creates) {
            if (str_ends_with($request->url(), '/cachedContents')) {
                $creates++;
                throw new \RuntimeException('private-provider-key PRIVATE-PROMPT timeout');
            }
            $this->assertArrayNotHasKey('cachedContent', $request->data());

            return Http::response($this->textResponse());
        }]);
        $this->assertSame('מוכן', $this->converse());
        $this->assertSame('מוכן', $this->converse());
        $this->assertSame(1, $creates);
        $status = app(GeminiContextCache::class)->status();
        $this->assertSame('unavailable', $status['reason']);
        $this->assertStringNotContainsString('private-provider-key', json_encode($status));
        $this->assertStringNotContainsString('PRIVATE-PROMPT', json_encode($status));
    }

    public function test_metadata_store_failure_does_not_break_the_conversation(): void
    {
        Http::fake(['*' => function (Request $request) {
            $this->assertStringEndsWith(':generateContent', $request->url());
            $this->assertArrayNotHasKey('cachedContent', $request->data());

            return Http::response($this->textResponse());
        }]);
        Cache::shouldReceive('store')->andThrow(new \RuntimeException('cache down'));
        $this->assertSame('מוכן', $this->converse());
        Http::assertSentCount(1);
    }

    public function test_rebuild_reports_a_metadata_write_failure_instead_of_success(): void
    {
        $store = \Mockery::mock(Repository::class);
        $store->shouldReceive('forever')->once()->andReturn(false);
        Cache::shouldReceive('store')->andReturn($store);
        $this->expectException(\RuntimeException::class);
        app(GeminiContextCache::class)->invalidate();
    }

    public function test_failed_metadata_write_is_not_reported_as_an_active_reusable_cache(): void
    {
        Http::fake(['*' => fn () => Http::response($this->remote())]);
        $store = \Mockery::mock(Cache::store())->makePartial();
        $store->shouldReceive('put')->andReturn(false);
        Cache::shouldReceive('store')->andReturn($store);
        $this->assertNull($this->acquire());
        $this->assertNotSame('active', app(GeminiContextCache::class)->status()['state']);
    }

    #[DataProvider('badReferences')]
    public function test_invalid_reference_retry_keeps_prior_tool_results_and_never_reexecutes_handlers(int $status, string $message): void
    {
        $requests = $handlers = 0;
        Http::fake(['*' => function (Request $request) use (&$requests, $status, $message) {
            if (str_ends_with($request->url(), '/cachedContents')) {
                return Http::response($this->remote());
            }
            $requests++;
            if ($requests === 1) {
                return Http::response(['candidates' => [['content' => ['parts' => [[
                    'functionCall' => ['name' => 'read_page', 'args' => ['id' => 8]],
                ]]]]]]);
            }
            $this->assertSame('already read', data_get($request->data(), 'contents.2.parts.0.functionResponse.response.result'));
            if ($requests === 2) {
                $this->assertArrayHasKey('cachedContent', $request->data());

                return Http::response(['error' => ['message' => $message]], $status);
            }
            $this->assertArrayNotHasKey('cachedContent', $request->data());
            $this->assertArrayHasKey('systemInstruction', $request->data());
            $this->assertArrayHasKey('tools', $request->data());

            return Http::response($this->textResponse());
        }]);
        $this->assertSame('מוכן', $this->converse(handler: function () use (&$handlers): array {
            $handlers++;

            return ['content' => 'already read'];
        }));
        $this->assertSame(1, $handlers);
        $this->assertSame(3, $requests);
        $this->assertSame('fallback', app(GeminiContextCache::class)->status()['state']);
    }

    public static function badReferences(): array
    {
        return [
            [400, 'CachedContent has expired.'],
            [403, 'You do not have permission to access cached content.'],
            [404, 'Cached content not found.'],
        ];
    }

    public function test_unrelated_provider_denial_is_not_retried_as_a_cache_miss(): void
    {
        Http::fake(['*' => fn (Request $request) => str_ends_with($request->url(), '/cachedContents')
            ? Http::response($this->remote())
            : Http::response(['error' => ['message' => 'API key is invalid']], 403),
        ]);
        $this->assertNull($this->converse());
        Http::assertSentCount(2);
    }

    #[DataProvider('invalidMetadata')]
    public function test_untrusted_provider_metadata_never_becomes_a_resource_reference(array $override): void
    {
        Http::fake(['*' => fn () => Http::response([...$this->remote(), ...$override])]);
        $this->assertNull($this->acquire());
        $this->assertSame('fallback', app(GeminiContextCache::class)->status()['state']);
        $this->assertSame('invalid_response', app(GeminiContextCache::class)->status()['reason']);
    }

    public static function invalidMetadata(): array
    {
        return [
            [['name' => 'https://evil.example/cachedContents/leak']],
            [['name' => 'cachedContents/../../escape']],
            [['model' => 'models/a-different-model']],
            [['expireTime' => 'tomorrow']],
            [['expireTime' => '2030-01-01T00:00:00Z']],
            [['expireTime' => '2020-01-01T00:00:00Z']],
        ];
    }

    public function test_disabled_and_other_provider_paths_do_not_contact_remote_cache(): void
    {
        config(['siteagent.assistant.cache.enabled' => false]);
        $this->assertNull($this->acquire());
        $this->assertSame('disabled', app(GeminiContextCache::class)->status()['state']);
        config(['siteagent.assistant.cache.enabled' => true, 'billing.ai.provider' => 'openai']);
        $this->assertNull($this->acquire());
        $this->assertSame('unsupported_provider', app(GeminiContextCache::class)->status()['state']);
        Http::assertNothingSent();
    }

    public function test_only_safe_metadata_is_persisted_and_reported_cached_tokens_are_not_added_to_total_input(): void
    {
        Cache::setDefaultDriver('database');
        $customer = Customer::factory()->create();
        Http::fake(['*' => fn (Request $request) => str_ends_with($request->url(), '/cachedContents')
            ? Http::response($this->remote())
            : Http::response($this->textResponse(['promptTokenCount' => 4000, 'cachedContentTokenCount' => 3500, 'totalTokenCount' => 4100])),
        ]);
        app(AiUsageAttribution::class)->for($customer->id, fn () => $this->converse());
        $this->assertSame(4000, (int) AiUsage::query()->value('input_tokens'));
        $this->assertSame(3500, (int) AiUsage::query()->value('cached_input_tokens'));
        $this->assertSame(100, (int) AiUsage::query()->value('output_tokens'));
        $this->assertSame(4000, (int) AiCustomerUsage::query()->value('input_tokens'));
        $this->assertSame(3500, (int) AiCustomerUsage::query()->value('cached_input_tokens'));

        $metadata = json_encode(DB::table('cache')->pluck('value')->all());
        foreach (['private-provider-key', 'owner-one', 'Stable safety instructions.', 'private live result', 'Read current content'] as $secret) {
            $this->assertStringNotContainsString($secret, $metadata);
        }
        $this->assertStringContainsString('cachedContents', $metadata);
        Cache::purge('database');
        $this->assertSame('active', app(GeminiContextCache::class)->status()['state']);
        $this->assertSame('מוכן', $this->converse());
        Http::assertSentCount(3);
    }
}
