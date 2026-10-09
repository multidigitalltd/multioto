<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Site;
use App\Models\SiteAgentRequest;
use App\Models\SiteAgentSubscriber;
use App\Services\Agent\McpClient;
use App\Services\Ai\ClaudeClient;
use App\Services\SiteAgent\SiteAgentConversation;
use App\Services\SiteAgent\SiteChangePlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SiteAgentHomepageTest extends TestCase
{
    use RefreshDatabase;

    private array $settings = ['show_on_front' => 'page', 'page_on_front' => 999, 'page_for_posts' => 0];

    private array $calls = [];

    private array $prompts = [];

    private array $pages = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['siteagent.assistant.enabled' => false]);
        Cache::flush();
        Http::preventStrayRequests();
        foreach (range(1, 15) as $id) {
            $this->pages[$id] = ['id' => $id, 'title' => $id === 1 ? 'דף הבית' : 'עמוד '.$id,
                'type' => 'page', 'status' => 'publish', 'content' => 'ברוכים הבאים'];
        }
        $this->pages[999] = ['id' => 999, 'title' => 'Welcome 2018', 'type' => 'page',
            'status' => 'publish', 'content' => 'ברוכים הבאים'];
        $mcp = Mockery::mock(McpClient::class);
        $mcp->shouldReceive('callTool')->andReturnUsing(fn (Site $site, string $tool, array $args = []): array => $this->siteCall($tool, $args));
        $mcp->shouldReceive('textContent')->andReturnUsing(fn (array $data): string => json_encode($data));
        $this->app->instance(McpClient::class, $mcp);
    }

    public function test_an_old_homepage_is_found_by_settings_and_changed_only_after_approval(): void
    {
        $subscriber = $this->subscriber();
        $this->planning($this->plan());
        $reply = $this->talk($subscriber, 'בדף הבית תחליף ברוכים הבאים בשלום לכולם');

        $this->assertStringContainsString('Welcome 2018', $reply);
        $this->assertStringContainsString('[דף הבית המוגדר באתר]', $this->prompts[1]);
        $this->assertStringContainsString('999', $this->prompts[1]);
        $this->assertSame(999, SiteAgentRequest::sole()->plan['page_id']);
        $this->assertNotContains('wp_content_update', array_column($this->calls, 0));
        $this->assertSame(['type' => 'page', 'status' => 'publish', 'limit' => 15], collect($this->calls)->first(fn (array $call): bool => $call[0] === 'wp_content_list')[1]);

        $this->assertStringContainsString('בוצע', $this->talk($subscriber, 'כן'));
        $this->assertSame('שלום לכולם', $this->pages[999]['content']);
        $this->assertSame('ברוכים הבאים', $this->pages[1]['content']);
        $this->assertCount(2, array_filter($this->calls, fn (array $call): bool => $call[0] === 'wp_site_settings_get'));
    }

    public function test_a_page_named_home_is_not_accepted_in_place_of_the_actual_homepage(): void
    {
        $this->planning($this->plan(1));
        $reply = $this->talk($this->subscriber(), 'בדף הבית תחליף ברוכים הבאים בשלום לכולם');
        $this->assertStringContainsString('אינו דף הבית', $reply);
        $this->assertSame(0, SiteAgentRequest::count());
        $this->assertNotContains('wp_content_update', array_column($this->calls, 0));
    }

    #[DataProvider('invalidFrontPages')]
    public function test_wrong_unpublished_or_nonpage_front_content_never_produces_an_offer(array $changes): void
    {
        $this->pages[999] = [...$this->pages[999], ...$changes];
        $this->planning($this->plan());
        $reply = $this->talk($this->subscriber(), 'בדף הבית תחליף ברוכים הבאים בשלום לכולם');
        $this->assertStringContainsString('לא הצלחתי לקרוא עמוד מפורסם', $reply);
        $this->assertSame(0, SiteAgentRequest::count());
    }

    public static function invalidFrontPages(): array
    {
        return ['wrong id' => [['id' => 998]], 'draft' => [['status' => 'draft']], 'post' => [['type' => 'post']]];
    }

    public function test_a_blog_index_explains_why_there_is_no_static_homepage_to_edit(): void
    {
        $this->settings['show_on_front'] = 'posts';
        $this->planning($this->plan());
        $result = app(SiteChangePlanner::class)->plan($this->subscriber()->site, 'להחליף טקסט בדף הבית');

        $this->assertStringContainsString('רשימת הפוסטים האחרונים', $result['refusal']);
        $this->assertSame([], $this->prompts);
        $this->assertSame([['wp_site_settings_get', []]], $this->calls);
    }

    #[DataProvider('changedSettings')]
    public function test_homepage_settings_are_checked_again_before_the_approved_write(array $changes): void
    {
        $subscriber = $this->subscriber();
        $this->planning($this->plan());
        $this->talk($subscriber, 'בדף הבית תחליף ברוכים הבאים בשלום לכולם');
        $this->settings = [...$this->settings, ...$changes];

        $reply = $this->talk($subscriber, 'כן');
        $this->assertStringContainsString('השתנה', $reply);
        $this->assertSame(SiteAgentRequest::FAILED, SiteAgentRequest::sole()->state);
        $this->assertNotContains('wp_content_update', array_column($this->calls, 0));
    }

    public static function changedSettings(): array
    {
        return ['new homepage' => [['page_on_front' => 1]], 'blog index' => [['show_on_front' => 'posts']],
            'changed blog page' => [['page_for_posts' => 8]]];
    }

    public function test_older_plugins_keep_named_page_edits_but_do_not_guess_the_homepage(): void
    {
        $subscriber = $this->subscriber();
        $subscriber->site->update(['mcp_capabilities' => ['tools' => [['name' => 'wp_content_list'], ['name' => 'wp_content_get']]]]);
        $this->planning($this->plan(2));
        $planner = app(SiteChangePlanner::class);

        $this->assertStringContainsString('לעדכן את תוסף הסוכן', $planner->plan($subscriber->site, 'להחליף טקסט בדף הבית')['refusal']);
        $this->assertSame([], $this->calls);
        $this->assertSame(2, $planner->plan($subscriber->site, 'בעמוד 2 תחליף ברוכים הבאים בשלום לכולם')['page_id']);
        $this->assertNotContains('wp_option_get', array_column($this->calls, 0));
    }

    public function test_malformed_model_values_are_rejected_without_coercion(): void
    {
        $this->planning([...$this->plan(), 'text' => ['wrong' => 'shape']]);
        $this->assertNull(app(SiteChangePlanner::class)->plan($this->subscriber()->site, 'להחליף טקסט בדף הבית'));
        $this->assertSame(0, SiteAgentRequest::count());
    }

    public function test_the_real_openai_client_drives_clarification_then_preview_then_approval(): void
    {
        config(['siteagent.assistant.enabled' => true, 'billing.ai.enabled' => true,
            'billing.ai.provider' => 'openai', 'billing.ai.api_key' => 'test-key',
            'billing.ai.base_url' => 'https://ai.example.test/v1', 'billing.ai.model' => 'gpt-test']);
        $this->app->forgetInstance(McpClient::class);
        $question = 'איזה טקסט בדף הבית תרצו להחליף, ומה לכתוב במקומו?';
        $agentTurns = 0;
        $plannerTurns = 0;
        Http::fake([
            'https://ai.example.test/*' => function (Request $request) use (&$agentTurns, &$plannerTurns, $question) {
                $body = $request->data();
                if (isset($body['tools'])) {
                    $this->assertContains('edit_page_text', array_column(array_column($body['tools'], 'function'), 'name'));
                    if (++$agentTurns === 1) {
                        return Http::response(['choices' => [['message' => ['role' => 'assistant', 'content' => null,
                            'tool_calls' => [['id' => 'edit-1', 'type' => 'function', 'function' => ['name' => 'edit_page_text',
                                'arguments' => json_encode(['instruction' => 'אני רוצה להחליף את טקסט הפתיחה בדף הבית'])]]]]]]]);
                    }

                    return Http::response(['choices' => [['message' => ['content' => 'הבקשה הועברה לעורך.']]]]);
                }
                $this->assertFalse(data_get($body, 'response_format.json_schema.strict'));
                $prompt = data_get($body, 'messages.1.content');
                $this->assertStringContainsString('[דף הבית המוגדר באתר]', $prompt);
                if (++$plannerTurns === 1) {
                    $result = ['can_do' => false, 'question' => $question];
                } else {
                    $this->assertStringContainsString('טקסט הפתיחה בדף הבית', $prompt);
                    $this->assertStringContainsString('שלום לכולם', $prompt);
                    $result = $this->plan();
                }

                return Http::response(['choices' => [['message' => ['content' => json_encode($result)]]]]);
            },
            'https://example.test/*' => function (Request $request) {
                $result = $this->siteCall(data_get($request->data(), 'params.name'), (array) data_get($request->data(), 'params.arguments', []));

                return Http::response(['jsonrpc' => '2.0', 'id' => $request->data()['id'],
                    'result' => ['content' => [['type' => 'text', 'text' => json_encode($result)]]]]);
            },
        ]);
        $subscriber = $this->subscriber();

        $this->assertSame($question, $this->talk($subscriber, 'אני רוצה להחליף את טקסט הפתיחה בדף הבית'));
        $this->assertEmpty(SiteAgentRequest::sole()->preview);
        $reply = $this->talk($subscriber, 'במקום ברוכים הבאים תכתוב שלום לכולם');
        $this->assertStringContainsString(SiteAgentConversation::CONFIRM_PROMPT, $reply);
        $this->assertNotContains('wp_content_update', array_column($this->calls, 0));
        $this->assertStringContainsString('בוצע', $this->talk($subscriber, 'כן'));
        $this->assertSame('שלום לכולם', $this->pages[999]['content']);
        $this->assertSame(2, $agentTurns);
        $this->assertSame(2, $plannerTurns);
    }

    private function siteCall(string $tool, array $args): array
    {
        $this->calls[] = [$tool, $args];
        if ($tool === 'wp_content_update') {
            $this->pages[$args['id']]['content'] = $args['content'];

            return ['updated_id' => $args['id']];
        }

        return match ($tool) {
            'wp_site_settings_get' => ['values' => $this->settings],
            'wp_content_list' => array_values(array_slice($this->pages, 0, 15, true)),
            'wp_content_get' => $this->pages[$args['id']] ?? [],
            default => throw new \RuntimeException('Unexpected tool: '.$tool),
        };
    }

    private function plan(int $pageId = 999): array
    {
        return ['can_do' => true, 'operation' => 'replace_text', 'page_id' => $pageId,
            'find' => 'ברוכים הבאים', 'text' => 'שלום לכולם', 'summary' => 'עדכון ברכה'];
    }

    private function planning(array $result): void
    {
        $ai = Mockery::mock(ClaudeClient::class);
        $ai->shouldReceive('isEnabled')->andReturn(true);
        $ai->shouldReceive('structured')->andReturnUsing(function ($system, $prompt) use ($result): array {
            $this->prompts[] = $prompt;

            return $result;
        });
        $this->app->instance(ClaudeClient::class, $ai);
    }

    private function subscriber(): SiteAgentSubscriber
    {
        $customer = Customer::factory()->create();
        $site = Site::factory()->create(['customer_id' => $customer->id, 'mcp_enabled' => true,
            'mcp_endpoint' => 'https://example.test/wp-json/md-agent/v1/mcp', 'mcp_secret' => 'test-secret']);

        return SiteAgentSubscriber::create(['customer_id' => $customer->id, 'site_id' => $site->id,
            'phone' => '972501234567', 'verified_at' => now()]);
    }

    private function talk(SiteAgentSubscriber $subscriber, string $text): string
    {
        return app(SiteAgentConversation::class)->handle($subscriber, $text, 'wamid.'.bin2hex(random_bytes(8)));
    }
}
