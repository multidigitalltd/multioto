<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Site;
use App\Models\SiteAgentRequest;
use App\Models\SiteAgentSubscriber;
use App\Services\Ai\ClaudeClient;
use App\Services\SiteAgent\SiteActionProposer;
use App\Services\SiteAgent\SiteAgentConversation;
use App\Services\SiteAgent\SiteAgentToolbox;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Real conversation, AI transport, MCP client and approval; only HTTP is faked. */
class SiteAgentGeminiRoundTripTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('approvalStates')]
    public function test_free_form_text_reaches_gemini_and_site_writes_wait_for_approval(bool $changedSincePreview, bool $cached): void
    {
        config([
            'billing.ai.enabled' => true, 'billing.ai.api_key' => 'test-key',
            'billing.ai.provider' => 'google', 'billing.ai.model' => 'gemini-flash-latest',
            'billing.ai.base_url' => 'https://generativelanguage.googleapis.com',
            'siteagent.enabled' => true, 'siteagent.assistant.enabled' => true,
            'siteagent.assistant.disabled_permissions' => '',
            'siteagent.assistant.cache.enabled' => $cached,
        ]);
        Cache::flush();
        Http::preventStrayRequests();
        $customer = Customer::factory()->create();
        $site = Site::factory()->create([
            'customer_id' => $customer->id, 'domain' => 'store.test', 'mcp_enabled' => true,
            'mcp_endpoint' => 'https://store.test/wp-json/multioto/v1/mcp', 'mcp_secret' => 'test-site-secret',
            'mcp_capabilities' => ['server' => ['version' => '1.11.0'], 'tools' => array_map(
                fn (string $name): array => ['name' => $name],
                array_unique([...SiteAgentToolbox::pluginTools(), ...app(SiteActionProposer::class)->pluginTools()]),
            )],
        ]);
        $subscriber = SiteAgentSubscriber::create([
            'customer_id' => $customer->id, 'site_id' => $site->id,
            'phone' => '972501234567', 'verified_at' => now(),
        ]);
        $message = 'חשבתי על זה, בוא נעשה את החולצה הכחולה ב־79.90 שקלים במבצע. תכין לי את השינוי בבקשה.';
        $product = ['id' => 7, 'name' => 'חולצה כחולה', 'regular_price' => '100.00',
            'sale_price' => '', 'sale_from' => null, 'sale_to' => null, 'status' => 'publish'];
        $modelTurns = 0;
        $reads = [];
        $writes = [];
        $approvalSent = false;
        $cacheCreates = 0;
        $catalogWire = null;

        Http::fake([
            'generativelanguage.googleapis.com/v1beta/cachedContents' => function (Request $request) use (&$cacheCreates, &$catalogWire, $cached) {
                $this->assertTrue($cached);
                $this->assertSame('POST', $request->method());
                $cacheCreates++;
                $catalogWire = json_decode($request->body());

                return Http::response([
                    'name' => 'cachedContents/test-catalog',
                    'model' => 'models/gemini-flash-latest',
                    'expireTime' => now()->addHour()->toIso8601String(),
                    'usageMetadata' => ['totalTokenCount' => 12000],
                ]);
            },
            'generativelanguage.googleapis.com/v1beta/models/*:generateContent' => function (Request $request) use (&$modelTurns, $message, $cached, &$catalogWire) {
                $data = json_decode($request->body(), true);
                if ($cached) {
                    $this->assertSame('cachedContents/test-catalog', $data['cachedContent'] ?? null);
                    $this->assertArrayNotHasKey('tools', $data);
                    $this->assertArrayNotHasKey('systemInstruction', $data);
                }
                $wire = $cached ? $catalogWire : json_decode($request->body());
                $tools = json_decode(json_encode($wire->tools[0]->functionDeclarations), true);
                $this->assertGreaterThan(65, count($tools));
                $this->assertContains('propose_acf_update', array_column($tools, 'name'));
                foreach ($tools as $tool) {
                    $this->assertArrayHasKey('parametersJsonSchema', $tool);
                    $this->assertArrayNotHasKey('parameters', $tool);
                }
                $modelTurns++;
                if ($modelTurns === 1) {
                    // Associative decoding hides the difference between {} and
                    // []; inspect the actual serialized catalog as JSON objects.
                    foreach ($wire->tools[0]->functionDeclarations as $declaration) {
                        $this->assertInstanceOf(\stdClass::class, $declaration->parametersJsonSchema);
                        $this->assertSame([], $this->invalidSchemaMaps($declaration->parametersJsonSchema), $declaration->name);
                    }
                    $this->assertStringContainsString($message, data_get($data, 'contents.0.parts.0.text'));

                    return $this->functionCall('find_products', ['search' => 'חולצה כחולה'], 'lookup');
                }
                if ($modelTurns === 2) {
                    $result = data_get($data, 'contents.2.parts.0.functionResponse');
                    $this->assertSame('lookup', $result['id']);
                    $this->assertStringContainsString('חולצה כחולה', $result['response']['result']);
                    $this->assertStringContainsString('7', $result['response']['result']);

                    // Even text accompanying the tool call cannot replace
                    // the verified proposal with a premature success claim.
                    return $this->functionCall('propose_product_update', ['product_id' => 7, 'sale_price' => '79.90'], 'proposal', 'בוצע כבר בחנות!');
                }
                $this->fail('A verified proposal must end the model loop without another request.');
            },
            'store.test/*' => function (Request $request) use (&$product, &$reads, &$writes, &$approvalSent) {
                $data = $request->data();
                $this->assertSame('tools/call', $data['method']);
                $tool = $data['params']['name'];
                $arguments = (array) $data['params']['arguments'];
                if ($tool === 'wc_product_update') {
                    $this->assertTrue($approvalSent, 'The site must not be changed before a separate yes.');
                    $this->assertSame(7, $arguments['product_id']);
                    $this->assertSame('79.90', $arguments['sale_price']);
                    $writes[] = $arguments;
                    $previous = $product;
                    $product['sale_price'] = $arguments['sale_price'];
                    $result = ['changed' => true, 'previous' => $previous];
                } else {
                    $reads[] = $tool;
                    $result = match ($tool) {
                        'wc_product_search' => ['total' => 1, 'products' => [$product]],
                        'wc_product_get' => $product,
                        default => throw new \RuntimeException('Unexpected site read: '.$tool),
                    };
                }

                return Http::response(['jsonrpc' => '2.0', 'id' => $data['id'],
                    'result' => ['content' => [['type' => 'text', 'text' => json_encode($result, JSON_UNESCAPED_UNICODE)]], 'isError' => false]]);
            },
        ]);

        $ai = app(ClaudeClient::class);
        $this->app->instance(ClaudeClient::class, $ai);
        $preview = app(SiteAgentConversation::class)->handle($subscriber, $message, 'free-form-message');
        $pending = SiteAgentRequest::first();
        $this->assertNotNull($pending, $ai->lastError() ?? $preview);
        $this->assertSame(SiteAgentRequest::AWAITING, $pending->state);
        $this->assertSame(SiteAgentRequest::OP_PRODUCT, $pending->operation);
        $this->assertStringContainsString('חולצה כחולה', $preview);
        $this->assertStringContainsString('79.90', $preview);
        $this->assertStringContainsString(SiteAgentConversation::CONFIRM_PROMPT, $preview);
        $this->assertStringNotContainsString('בוצע כבר בחנות', $preview);
        $this->assertSame(['wc_product_search', 'wc_product_get'], $reads);
        $this->assertSame([], $writes);
        $this->assertSame('', $product['sale_price']);

        if ($changedSincePreview) {
            $product['sale_price'] = '90.00';
        }
        $approvalSent = true;
        $reply = app(SiteAgentConversation::class)->handle($subscriber, 'כן', 'separate-approval');
        $this->assertSame(2, $modelTurns, 'The saved proposal ends the model loop; approval is handled by code.');
        $this->assertSame($cached ? 1 : 0, $cacheCreates, 'A cached catalog is created once for all model rounds.');
        $this->assertContains('wc_product_get', array_slice($reads, 2), 'The price is read again at approval.');

        if ($changedSincePreview) {
            $this->assertStringContainsString('זה השתנה באתר', $reply);
            $this->assertSame([], $writes);
            $this->assertSame('90.00', $product['sale_price']);
            $this->assertSame(SiteAgentRequest::FAILED, $pending->fresh()->state);
        } else {
            $this->assertStringContainsString('בוצע', $reply);
            $this->assertCount(1, $writes);
            $this->assertSame('79.90', $product['sale_price']);
            $this->assertSame(SiteAgentRequest::APPLIED, $pending->fresh()->state);
        }
    }

    private function functionCall(string $name, array $arguments, string $id, string $text = ''): PromiseInterface
    {
        $parts = $text === '' ? [] : [['text' => $text]];
        $parts[] = [
            'functionCall' => ['id' => $id, 'name' => $name, 'args' => $arguments],
            'thoughtSignature' => 'test-signature',
        ];

        return Http::response(['candidates' => [['content' => ['parts' => $parts]]]]);
    }

    /** Schema maps must serialize as JSON objects, including empty maps. */
    private function invalidSchemaMaps(mixed $node, string $path = ''): array
    {
        if (! is_object($node) && ! is_array($node)) {
            return [];
        }

        $invalid = [];
        foreach ($node as $key => $value) {
            $childPath = $path.'.'.$key;
            if (in_array($key, ['properties', 'patternProperties', '$defs', 'definitions', 'dependentSchemas'], true)
                && ! $value instanceof \stdClass) {
                $invalid[] = $childPath;
            }
            $invalid = [...$invalid, ...$this->invalidSchemaMaps($value, $childPath)];
        }

        return $invalid;
    }

    public static function approvalStates(): array
    {
        return [
            'approved while unchanged' => [false, false],
            'changed before approval' => [true, false],
            'cached catalog, approved while unchanged' => [false, true],
            'cached catalog, changed before approval' => [true, true],
        ];
    }
}
