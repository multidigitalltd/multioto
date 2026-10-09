<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Site;
use App\Models\SiteAgentSubscriber;
use App\Services\SiteAgent\SiteActionProposer;
use App\Services\SiteAgent\SiteAgentConversation;
use App\Services\SiteAgent\SiteAgentToolbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Real assistant/transport: stable tools are reused, private conversation context is fresh. */
class SiteAgentCachedConversationTest extends TestCase
{
    use RefreshDatabase;

    private array $created = [];

    private array $generated = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'billing.ai.enabled' => true,
            'billing.ai.api_key' => 'test-cache-key',
            'billing.ai.provider' => 'google',
            'billing.ai.model' => 'gemini-3.1-flash-lite',
            'billing.ai.base_url' => 'https://generativelanguage.googleapis.com',
            'siteagent.enabled' => true,
            'siteagent.assistant.enabled' => true,
            'siteagent.assistant.cache.enabled' => true,
            'siteagent.assistant.cache.ttl_minutes' => 60,
            'siteagent.assistant.disabled_permissions' => '',
            'app.timezone' => 'Asia/Jerusalem',
        ]);
        Cache::flush();
        Http::preventStrayRequests();
        Http::fake([
            'generativelanguage.googleapis.com/v1beta/cachedContents' => function (Request $request) {
                $this->assertSame('POST', $request->method());
                $this->created[] = $request->data();

                return Http::response([
                    'name' => 'cachedContents/catalog-'.count($this->created),
                    'model' => 'models/gemini-3.1-flash-lite',
                    'expireTime' => now()->addHour()->toIso8601String(),
                ]);
            },
            'generativelanguage.googleapis.com/v1beta/models/*:generateContent' => function (Request $request) {
                $this->generated[] = $request->data();
                $this->assertArrayNotHasKey('tools', $request->data());
                $this->assertArrayNotHasKey('systemInstruction', $request->data());

                return Http::response(['candidates' => [['content' => ['parts' => [['text' => 'אפשר להמשיך מכאן.']]]]]]);
            },
        ]);
    }

    public function test_multiple_messages_reuse_catalog_while_time_owner_and_history_are_current(): void
    {
        $this->travelTo(now()->setTime(10, 10));
        $subscriber = $this->subscriber('private-first.test', 'בעל האתר הראשון');
        $conversation = app(SiteAgentConversation::class);
        $this->assertSame('אפשר להמשיך מכאן.', $conversation->handle($subscriber, 'בקשה פרטית ראשונה', 'first'));

        $this->travel(5)->minutes();
        $subscriber->update(['name' => 'בעל האתר המעודכן']);
        $this->assertSame('אפשר להמשיך מכאן.', $conversation->handle($subscriber->fresh(), 'נמשיך מאותו מקום', 'second'));

        $this->assertCount(1, $this->created);
        $this->assertCount(2, $this->generated);
        $this->assertSame($this->generated[0]['cachedContent'], $this->generated[1]['cachedContent']);
        $prefix = json_encode($this->created[0], JSON_UNESCAPED_UNICODE);
        foreach (['private-first.test', 'בעל האתר הראשון', 'בעל האתר המעודכן', 'בקשה פרטית ראשונה', 'נמשיך מאותו מקום', '10:10', '10:15'] as $private) {
            $this->assertStringNotContainsString($private, $prefix);
        }
        $freshPrompt = data_get($this->generated[1], 'contents.0.parts.0.text');
        $this->assertStringContainsString('private-first.test', $freshPrompt);
        $this->assertStringContainsString('בעל האתר המעודכן', $freshPrompt);
        $this->assertStringContainsString('בקשה פרטית ראשונה', $freshPrompt);
        $this->assertStringContainsString('נמשיך מאותו מקום', $freshPrompt);
        $this->assertStringContainsString(now()->format('d/m/Y H:i'), $freshPrompt);
    }

    public function test_customers_and_sites_do_not_reuse_each_others_cache_or_history(): void
    {
        $first = $this->subscriber('first-private.test', 'לקוח ראשון');
        $otherSite = $this->subscriber('same-owner-other-site.test', 'אתר נוסף', $first->customer);
        $otherCustomer = $this->subscriber('other-customer.test', 'לקוח אחר');
        $conversation = app(SiteAgentConversation::class);
        foreach ([$first, $otherSite, $otherCustomer] as $index => $subscriber) {
            $this->assertSame('אפשר להמשיך מכאן.', $conversation->handle($subscriber, 'הודעה ייחודית '.$index, 'message-'.$index));
        }
        $this->assertCount(3, $this->created);
        $this->assertCount(3, array_unique(array_column($this->generated, 'cachedContent')));
        $this->assertStringNotContainsString('הודעה ייחודית 0', data_get($this->generated[1], 'contents.0.parts.0.text'));
        $this->assertStringNotContainsString('הודעה ייחודית 0', data_get($this->generated[2], 'contents.0.parts.0.text'));
    }

    public function test_changed_instructions_and_revoked_permissions_create_new_catalogs(): void
    {
        $subscriber = $this->subscriber('settings-change.test', 'בעל האתר');
        $conversation = app(SiteAgentConversation::class);
        $conversation->handle($subscriber, 'שלום', 'before');
        $this->assertContains('propose_product_update', array_column(data_get($this->created[0], 'tools.0.functionDeclarations'), 'name'));

        config(['siteagent.assistant.work_rules' => 'תן קודם הסבר קצר על מטרת השינוי.']);
        $conversation->handle($subscriber, 'נמשיך בבקשה', 'instructions');
        $this->assertCount(2, $this->created);
        $this->assertStringContainsString('תן קודם הסבר קצר', data_get($this->created[1], 'systemInstruction.parts.0.text'));

        config(['siteagent.assistant.disabled_permissions' => 'products_update']);
        $conversation->handle($subscriber, 'ומה עכשיו?', 'permissions');
        $this->assertCount(3, $this->created);
        $this->assertNotContains('propose_product_update', array_column(data_get($this->created[2], 'tools.0.functionDeclarations'), 'name'));
        $this->assertCount(3, array_unique(array_column($this->generated, 'cachedContent')));
    }

    private function subscriber(string $domain, string $name, ?Customer $customer = null): SiteAgentSubscriber
    {
        $customer ??= Customer::factory()->create();
        $site = Site::factory()->create([
            'customer_id' => $customer->id,
            'domain' => $domain,
            'mcp_enabled' => true,
            'mcp_endpoint' => 'https://'.$domain.'/wp-json/multioto/v1/mcp',
            'mcp_secret' => 'test-secret',
            'mcp_capabilities' => ['server' => ['version' => '1.11.0'], 'tools' => array_map(
                fn (string $tool): array => ['name' => $tool],
                array_unique([...SiteAgentToolbox::pluginTools(), ...app(SiteActionProposer::class)->pluginTools()]),
            )],
        ]);

        return SiteAgentSubscriber::create([
            'customer_id' => $customer->id,
            'site_id' => $site->id,
            'name' => $name,
            'phone' => '972501234567',
            'verified_at' => now(),
        ]);
    }
}
