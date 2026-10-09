<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Site;
use App\Models\SiteAgentMessage;
use App\Models\SiteAgentRequest;
use App\Models\SiteAgentSubscriber;
use App\Services\Ai\ClaudeClient;
use App\Services\SiteAgent\SiteActionProposer;
use App\Services\SiteAgent\SiteAgentAssistant;
use App\Services\SiteAgent\SiteAgentConversation;
use App\Services\SiteAgent\SiteAgentToolbox;
use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Real conversation, AI client, proposals, approval and MCP; only HTTP is faked. */
class SiteAgentVirtualProductConversationTest extends TestCase
{
    use RefreshDatabase;

    private ?array $product = null;

    private array $siteCalls = [];

    private array $modelRequests = [];

    private bool $writeAuthorized = false;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'billing.ai.enabled' => true, 'billing.ai.api_key' => 'test-key',
            'billing.ai.provider' => 'google', 'billing.ai.model' => 'gemini-3.1-flash-lite',
            'billing.ai.base_url' => 'https://generativelanguage.googleapis.com',
            'siteagent.enabled' => true, 'siteagent.assistant.enabled' => true,
            'siteagent.assistant.disabled_permissions' => '',
            'siteagent.assistant.cache.enabled' => false,
        ]);
        Cache::flush();
        Http::preventStrayRequests();
        $this->app->instance(ClaudeClient::class, app(ClaudeClient::class));
    }

    public function test_virtual_product_is_previewed_created_verified_and_published_then_queried_from_live_data(): void
    {
        $message = 'להוסיף מוצר דןגמא וירטואלי 150 שח';
        $this->fakeRemote(function (int $turn, array $body) use ($message): PromiseInterface {
            if ($turn === 1) {
                $this->assertStringContainsString($message, data_get($body, 'contents.0.parts.0.text'));
                $create = collect($body['tools'][0]['functionDeclarations'])->firstWhere('name', 'propose_product_create');
                $this->assertSame('boolean', data_get($create, 'parametersJsonSchema.properties.virtual.type'));

                return $this->functionCall('propose_product_create', [
                    'name' => 'דןגמא', 'regular_price' => '150.00', 'virtual' => true, 'publish' => true,
                ], 'המוצר כבר נוצר ופורסם.');
            }
            if (in_array($turn, [2, 4], true)) {
                $prompt = data_get($body, 'contents.0.parts.0.text');
                $this->assertStringContainsString('המוצר וירטואלי?', $prompt);
                $this->assertStringContainsString('90', $prompt, 'The previous creation result must remain in context.');

                return $this->functionCall('get_product', ['product_id' => 90]);
            }
            $this->assertContains($turn, [3, 5]);
            $result = json_decode(data_get($body, 'contents.2.parts.0.functionResponse.response.result'), true);
            $this->assertSame(90, $result['id']);
            $this->assertSame('simple', $result['type']);
            $this->assertSame($turn === 3, $result['virtual']);

            return $this->modelText($result['virtual'] ? 'כן, המוצר מוגדר וירטואלי — ללא משלוח.' : 'לא, כרגע המוצר מוגדר פיזי.');
        });
        $subscriber = $this->subscriber();
        $conversation = app(SiteAgentConversation::class);

        $preview = $conversation->handle($subscriber, $message, 'create-request');
        $pending = SiteAgentRequest::sole();
        $this->assertSame(SiteAgentRequest::AWAITING, $pending->state);
        $this->assertSame(SiteAgentRequest::OP_PRODUCT_CREATE, $pending->operation);
        $this->assertSame('דןגמא', $pending->plan['fields']['name']);
        $this->assertTrue($pending->plan['fields']['virtual']);
        $this->assertSame('publish', $pending->plan['extra']['status']);
        $this->assertStringContainsString('וירטואלי', $preview);
        $this->assertStringContainsString('ללא משלוח', $preview);
        $this->assertStringContainsString('150.00', $preview);
        $this->assertStringContainsString(SiteAgentConversation::CONFIRM_PROMPT, $preview);
        $this->assertStringNotContainsString('כבר נוצר', $preview);
        $this->assertSame([], $this->writes());
        $this->assertNull($this->product);

        $this->writeAuthorized = true;
        $this->assertStringContainsString('נוצר ופורסם', $conversation->handle($subscriber, 'כן', 'create-approval'));
        $this->writeAuthorized = false;
        $this->assertSame(SiteAgentRequest::APPLIED, $pending->fresh()->state);
        $this->assertSame(['wc_product_create', 'wc_product_update'], array_column($this->writes(), 0));
        $this->assertTrue($this->writes()[0][1]['virtual']);
        $this->assertSame('publish', $this->writes()[1][1]['status']);
        $this->assertSame('wc_product_get', $this->siteCalls[1][0], 'Verify the created type before publishing it.');
        $this->assertSame('publish', $this->product['status']);
        $this->assertTrue($this->product['virtual']);
        $this->assertSame('דןגמא', $this->product['name']);
        $this->assertSame('150.00', $this->product['regular_price']);
        $this->assertCount(1, $this->modelRequests, 'The proposal ends the model loop; approval adds no model turn.');

        $readCount = count($this->siteCalls);
        $this->assertSame('כן, המוצר מוגדר וירטואלי — ללא משלוח.', $conversation->handle($subscriber, 'המוצר וירטואלי?', 'type-question'));
        $this->assertSame([['wc_product_get', ['product_id' => 90]]], array_slice($this->siteCalls, $readCount));
        $this->assertCount(2, $this->writes());
        $this->assertSame(1, SiteAgentRequest::count());

        // A manual change invalidates the remembered creation result. Answer
        // from the current product, without silently converting it back.
        $this->product['virtual'] = false;
        $readCount = count($this->siteCalls);
        $this->assertSame('לא, כרגע המוצר מוגדר פיזי.', $conversation->handle($subscriber, 'המוצר וירטואלי?', 'type-question-again'));
        $this->assertSame([['wc_product_get', ['product_id' => 90]]], array_slice($this->siteCalls, $readCount));
        $this->assertFalse($this->product['virtual']);
        $this->assertCount(2, $this->writes());
        $this->assertSame(1, SiteAgentRequest::count());
    }

    public function test_existing_physical_product_is_converted_only_on_yes_and_undo_restores_false(): void
    {
        $this->product = $this->productState();
        $this->fakeRemote(function (int $turn, array $body): PromiseInterface {
            if ($turn === 1) {
                return $this->functionCall('find_products', ['search' => 'דןגמא']);
            }
            if ($turn === 2) {
                $update = collect($body['tools'][0]['functionDeclarations'])->firstWhere('name', 'propose_product_update');
                $this->assertSame('boolean', data_get($update, 'parametersJsonSchema.properties.virtual.type'));

                return $this->functionCall('propose_product_update', ['product_id' => 90, 'virtual' => true], 'הסוג כבר עודכן.');
            }
            $this->fail('The verified conversion proposal must end the model loop.');
        });
        $subscriber = $this->subscriber();
        $conversation = app(SiteAgentConversation::class);

        $preview = $conversation->handle($subscriber, 'תהפוך את המוצר דןגמא לווירטואלי ללא משלוח', 'convert-request');
        $pending = SiteAgentRequest::sole();
        $this->assertSame(SiteAgentRequest::OP_PRODUCT, $pending->operation);
        $this->assertSame(['virtual' => true], $pending->plan['fields']);
        $this->assertSame(['virtual' => false], $pending->plan['current']);
        $this->assertStringContainsString('פיזי', $preview);
        $this->assertStringContainsString('וירטואלי', $preview);
        $this->assertStringContainsString('ללא משלוח', $preview);
        $this->assertStringContainsString(SiteAgentConversation::CONFIRM_PROMPT, $preview);
        $this->assertSame([], $this->writes());
        $this->assertFalse($this->product['virtual']);

        $this->writeAuthorized = true;
        $this->assertStringContainsString('בוצע', $conversation->handle($subscriber, 'כן', 'convert-approval'));
        $this->assertTrue($this->product['virtual']);
        $this->assertSame(SiteAgentRequest::APPLIED, $pending->fresh()->state);
        $this->assertSame(['virtual' => false], $pending->fresh()->restore['fields']);

        $this->assertStringContainsString('הוחזר', $conversation->handle($subscriber, 'בטל', 'undo-conversion'));
        $this->assertFalse($this->product['virtual']);
        $this->assertSame(SiteAgentRequest::REVERTED, $pending->fresh()->state);
        $this->assertSame([
            ['wc_product_update', ['product_id' => 90, 'virtual' => true]],
            ['wc_product_update', ['product_id' => 90, 'virtual' => false]],
        ], $this->writes());
        $this->assertSame('דןגמא', $this->product['name']);
        $this->assertSame('150.00', $this->product['regular_price']);
        $this->assertCount(2, $this->modelRequests);
    }

    #[DataProvider('invalidCreatedProductReadbacks')]
    public function test_unverified_creation_stays_a_single_draft_and_is_reported_as_partial(array $readback): void
    {
        Storage::fake('local');
        $this->fakeRemote(function (int $turn, array $body): PromiseInterface {
            if ($turn === 1) {
                return $this->functionCall('propose_product_create', [
                    'name' => 'דןגמא', 'regular_price' => '150.00', 'virtual' => true, 'publish' => true,
                ]);
            }
            $this->assertSame(2, $turn, 'Only the later question needs another model call.');
            $prompt = data_get($body, 'contents.0.parts.0.text');
            $this->assertStringContainsString('"state":"partially_applied"', $prompt);
            $this->assertStringContainsString('"created_id":90', $prompt);
            $this->assertStringContainsString('לא הצלחתי לאמת', $prompt);
            $this->assertStringNotContainsString('[היסטוריית השיחה', $prompt);
            $this->assertStringNotContainsString('site-agent/', $prompt);

            return $this->modelText('המוצר כבר נוצר, אך הבקשה בוצעה חלקית. אין כרגע הצעה נוספת לאישור.');
        }, $readback);
        $subscriber = $this->subscriber();
        $conversation = app(SiteAgentConversation::class);

        $conversation->handle($subscriber, 'להוסיף מוצר דןגמא וירטואלי 150 שח', 'partial-request');
        $pending = SiteAgentRequest::sole();
        $this->assertSame(SiteAgentRequest::AWAITING, $pending->state);
        $this->assertSame([], $this->writes());
        // A staged image belongs to this approved creation. Early verification
        // failure must consume it even though attachment was never reached.
        $photo = 'site-agent/'.$subscriber->id.'/'.str_repeat('a', 32).'.jpg';
        $unrelated = 'site-agent/'.$subscriber->id.'/'.str_repeat('b', 32).'.jpg';
        Storage::disk('local')->put($photo, 'staged image bytes');
        Storage::disk('local')->put($unrelated, 'another staged image');
        $pending->update(['plan' => [...$pending->plan, 'image_path' => $photo, 'image_alt' => 'מוצר לדוגמה']]);

        $this->writeAuthorized = true;
        $reply = $conversation->handle($subscriber, 'כן', 'partial-approval');
        $this->writeAuthorized = false;
        $this->assertStringContainsString('בוצעה חלקית', $reply);
        $this->assertStringContainsString('לא הצלחתי לאמת', $reply);
        $this->assertStringContainsString('לא ביצעתי את שלב הפרסום', $reply);
        $this->assertStringNotContainsString('✅', $reply);
        $this->assertSame(SiteAgentRequest::APPLIED, $pending->fresh()->state, 'The draft exists, so retrying must not recreate it.');
        $this->assertSame(90, $pending->fresh()->plan['created_id']);
        $this->assertSame('partial', $pending->fresh()->plan['execution_outcome']['status']);
        $this->assertSame('draft', $this->product['status']);
        $this->assertSame(['wc_product_create', 'wc_product_get'], array_column($this->siteCalls, 0));
        Storage::disk('local')->assertMissing($photo);
        Storage::disk('local')->assertExists($unrelated);

        // Even when the transcript no longer supplies the warning or ID, the
        // persisted action outcome must prevent a false full-success recap.
        SiteAgentMessage::where('site_agent_subscriber_id', $subscriber->id)->delete();
        $conversation->handle($subscriber, 'כן', 'old-approval-repeated');
        $this->assertCount(1, $this->modelRequests, 'A repeated bare yes cannot restart the model or creation.');
        SiteAgentMessage::where('site_agent_subscriber_id', $subscriber->id)->delete();
        $recap = $conversation->handle($subscriber, 'מה קרה לבקשת יצירת המוצר?', 'partial-outcome-question');
        $this->assertStringContainsString('בוצעה חלקית', $recap);
        $this->assertCount(2, $this->modelRequests, 'The persisted partial outcome is provided to the actual follow-up question.');
        $this->assertCount(1, $this->writes());
        $this->assertSame('wc_product_create', $this->writes()[0][0]);
        $this->assertSame('draft', $this->product['status']);
        $this->assertSame(1, SiteAgentRequest::count());
        $this->assertSame(0, SiteAgentRequest::where('state', SiteAgentRequest::AWAITING)->count());
    }

    public static function invalidCreatedProductReadbacks(): array
    {
        return [
            'missing product data' => [[]],
            'virtual flag did not persist' => [['id' => 90, 'type' => 'simple', 'virtual' => false, 'status' => 'draft']],
        ];
    }

    public function test_virtual_flag_changed_during_publishing_is_reported_as_partial_after_final_readback(): void
    {
        $readbacks = 0;
        $this->fakeRemote(fn (int $turn): PromiseInterface => $turn === 1
            ? $this->functionCall('propose_product_create', [
                'name' => 'דןגמא', 'regular_price' => '150.00', 'virtual' => true, 'publish' => true,
            ])
            : $this->modelText('ההצעה הוכנה.'), function () use (&$readbacks): array {
                if (++$readbacks === 2) {
                    // A third-party publication hook changes the type after
                    // the first draft verification already succeeded.
                    $this->product['virtual'] = false;
                }

                return $this->product;
            });
        $subscriber = $this->subscriber();
        $conversation = app(SiteAgentConversation::class);
        $conversation->handle($subscriber, 'להוסיף מוצר דןגמא וירטואלי 150 שח', 'late-change-request');

        $this->writeAuthorized = true;
        $reply = $conversation->handle($subscriber, 'כן', 'late-change-approval');

        $this->assertStringContainsString('בוצעה חלקית', $reply);
        $this->assertStringContainsString('ייתכן שהוא כבר פורסם', $reply);
        $this->assertStringNotContainsString('✅', $reply);
        $this->assertStringNotContainsString('לא פורסם', $reply);
        $this->assertSame(SiteAgentRequest::APPLIED, SiteAgentRequest::sole()->state);
        $this->assertSame('publish', $this->product['status']);
        $this->assertFalse($this->product['virtual']);
        $this->assertSame(['wc_product_create', 'wc_product_get', 'wc_product_update', 'wc_product_get'], array_column($this->siteCalls, 0));
        $this->assertCount(2, $this->writes());
    }

    public function test_unbacked_virtual_conversion_invitation_is_repaired_once_then_refused(): void
    {
        $phantom = 'תרצי שאעדכן אותו למוצר וירטואלי (ללא משלוח)?';
        $this->fakeRemote(function (int $turn, array $body) use ($phantom): PromiseInterface {
            if ($turn === 2) {
                $this->assertSame($phantom, data_get($body, 'contents.1.parts.0.text'));
                $this->assertStringContainsString('לא נוצרה במערכת הצעה', data_get($body, 'contents.2.parts.0.text'));
            }

            return $this->modelText($phantom);
        });

        $reply = app(SiteAgentConversation::class)->handle($this->subscriber(), 'המוצר וירטואלי?', 'unverified-question');

        $this->assertSame(SiteAgentAssistant::NO_VERIFIED_PROPOSAL, $reply);
        $this->assertCount(2, $this->modelRequests);
        $this->assertSame(0, SiteAgentRequest::count());
        $this->assertSame([], $this->siteCalls);
        $this->assertSame(0, SiteAgentMessage::where('role', SiteAgentMessage::ASSISTANT)->where('body', $phantom)->count());
    }

    private function fakeRemote(Closure $model, array|Closure|null $readback = null): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/v1beta/models/*:generateContent' => function (Request $request) use ($model): PromiseInterface {
                $body = $request->data();
                $this->modelRequests[] = $body;
                $this->assertArrayHasKey('tools', $body);

                return $model(count($this->modelRequests), $body);
            },
            'virtual-store.test/*' => function (Request $request) use ($readback): PromiseInterface {
                $body = $request->data();
                $this->assertSame('tools/call', $body['method']);
                $tool = $body['params']['name'];
                $arguments = (array) $body['params']['arguments'];
                $this->siteCalls[] = [$tool, $arguments];
                if ($tool === 'wc_product_create') {
                    $this->assertTrue($this->writeAuthorized);
                    $this->assertNull($this->product, 'Creation must not be retried.');
                    $this->product = [...$this->productState(), ...$arguments, 'status' => 'draft'];
                    $result = ['id' => 90, 'status' => 'draft'];
                } elseif ($tool === 'wc_product_update') {
                    $this->assertTrue($this->writeAuthorized);
                    $this->assertSame(90, $arguments['product_id']);
                    $previous = $this->product;
                    unset($arguments['product_id']);
                    $this->product = [...$this->product, ...$arguments];
                    $result = ['changed' => true, 'previous' => $previous];
                } else {
                    $result = match ($tool) {
                        'wc_product_get' => $readback instanceof Closure ? $readback() : ($readback ?? $this->product ?? []),
                        'wc_product_search' => ['total' => $this->product === null ? 0 : 1, 'products' => $this->product === null ? [] : [$this->product]],
                        default => throw new \RuntimeException('Unexpected site tool: '.$tool),
                    };
                }

                return Http::response(['jsonrpc' => '2.0', 'id' => $body['id'], 'result' => [
                    'content' => [['type' => 'text', 'text' => json_encode($result, JSON_UNESCAPED_UNICODE)]], 'isError' => false,
                ]]);
            },
        ]);
    }

    private function writes(): array
    {
        return array_values(array_filter($this->siteCalls, fn (array $call): bool => in_array($call[0], ['wc_product_create', 'wc_product_update'], true)));
    }

    private function productState(): array
    {
        return ['id' => 90, 'name' => 'דןגמא', 'type' => 'simple', 'virtual' => false,
            'regular_price' => '150.00', 'sale_price' => '', 'status' => 'publish'];
    }

    private function functionCall(string $name, array $arguments, string $text = ''): PromiseInterface
    {
        $parts = $text === '' ? [] : [['text' => $text]];
        $parts[] = [
            'functionCall' => ['id' => $name, 'name' => $name, 'args' => $arguments],
        ];

        return Http::response(['candidates' => [['content' => ['parts' => $parts]]]]);
    }

    private function modelText(string $text): PromiseInterface
    {
        return Http::response(['candidates' => [['content' => ['parts' => [['text' => $text]]]]]]);
    }

    private function subscriber(): SiteAgentSubscriber
    {
        $customer = Customer::factory()->create();
        $site = Site::factory()->create([
            'customer_id' => $customer->id, 'domain' => 'virtual-store.test', 'mcp_enabled' => true,
            'mcp_endpoint' => 'https://virtual-store.test/wp-json/multioto/v1/mcp', 'mcp_secret' => 'test-site-secret',
            'mcp_capabilities' => ['server' => ['version' => '1.12.0'], 'tools' => array_map(
                fn (string $name): array => ['name' => $name],
                array_unique([...SiteAgentToolbox::pluginTools(), ...app(SiteActionProposer::class)->pluginTools()]),
            )],
        ]);

        return SiteAgentSubscriber::create([
            'customer_id' => $customer->id, 'site_id' => $site->id,
            'phone' => '972501234567', 'verified_at' => now(),
        ]);
    }
}
