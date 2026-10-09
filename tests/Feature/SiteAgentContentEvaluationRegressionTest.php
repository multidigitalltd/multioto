<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Site;
use App\Models\SiteAgentRequest;
use App\Models\SiteAgentSubscriber;
use App\Services\Agent\McpClient;
use App\Services\Ai\ClaudeClient;
use App\Services\SiteAgent\Evaluation\EvaluationWorld;
use App\Services\SiteAgent\SiteActionProposer;
use App\Services\SiteAgent\SiteAgentConversation;
use App\Services\SiteAgent\SiteAgentToolbox;
use App\Services\SiteAgent\SiteChangePlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\Concerns\FakesSiteAgentProposalFidelity;
use Tests\TestCase;

class SiteAgentContentEvaluationRegressionTest extends TestCase
{
    use FakesSiteAgentProposalFidelity;
    use RefreshDatabase;

    private EvaluationWorld $world;

    private SiteAgentSubscriber $subscriber;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeProposalFidelity();
        Cache::flush();
        Http::preventStrayRequests();
        config(['siteagent.assistant.enabled' => false]);
        $this->world = new EvaluationWorld;
        $customer = Customer::factory()->create();
        $site = Site::factory()->create(['customer_id' => $customer->id, 'domain' => 'evaluation.example',
            'mcp_capabilities' => ['tools' => array_map(fn ($name) => ['name' => $name], $this->world->supportedTools())]]);
        $this->subscriber = SiteAgentSubscriber::create(['customer_id' => $customer->id, 'site_id' => $site->id,
            'phone' => '972501234567', 'verified_at' => now()]);
        $mcp = Mockery::mock(McpClient::class);
        $mcp->shouldReceive('callTool')->andReturnUsing(fn (Site $site, string $name, array $args = []) => $this->world->handle($name, $args));
        $mcp->shouldReceive('textContent')->andReturnUsing(fn (array $result) => json_encode($result));
        $this->app->instance(McpClient::class, $mcp);
    }

    public function test_content_search_without_type_finds_drafts_and_custom_posts_instead_of_only_pages(): void
    {
        $toolbox = app(SiteAgentToolbox::class);
        $draft = $toolbox->read($this->subscriber->site, 'find_content', ['search' => 'חדשות החברה']);
        $this->assertFalse($draft['is_error']);
        $this->assertContains(46, $draft['ids']);
        $this->assertSame('draft', json_decode($draft['content'], true)['items'][0]['status']);
        $this->assertEqualsCanonicalizing(['page', 'post', 'project'], json_decode($draft['content'], true)['searched_types']);

        $custom = $toolbox->read($this->subscriber->site, 'find_content', ['search' => 'פרויקט צפון']);
        $this->assertContains(47, $custom['ids']);
        $this->assertSame('project', json_decode($custom['content'], true)['items'][0]['type']);
        $this->assertFalse(collect($this->world->calls)->contains(fn ($call) => $call['write']));
    }

    public function test_explicit_content_type_keeps_one_native_search(): void
    {
        $result = app(SiteAgentToolbox::class)->read($this->subscriber->site, 'find_content', ['type' => 'post', 'search' => 'חדשות']);
        $this->assertContains(46, $result['ids']);
        $this->assertSame(['wp_content_list'], array_column($this->world->calls, 'tool'));
    }

    public function test_discovery_is_bounded_and_reports_failed_and_unsearched_types(): void
    {
        $calls = [];
        $mcp = Mockery::mock(McpClient::class);
        $mcp->shouldReceive('callTool')->andReturnUsing(function (Site $site, string $tool, array $args = []) use (&$calls): array {
            $calls[] = [$tool, $args];
            if ($tool === 'wp_post_types_list') {
                return array_map(fn ($index) => ['type' => 'custom_'.$index], range(1, 10));
            }
            $this->assertLessThanOrEqual(30, $args['limit']);
            if ($args['type'] === 'custom_2') {
                throw new \RuntimeException('Transient private transport details');
            }

            return [['id' => 100 + count($calls), 'type' => $args['type'], 'title' => 'פריט']];
        });
        $mcp->shouldReceive('textContent')->andReturnUsing(fn (array $result) => json_encode($result));
        $this->app->instance(McpClient::class, $mcp);
        $result = app(SiteAgentToolbox::class)->read($this->subscriber->site, 'find_content', ['limit' => 30]);
        $data = json_decode($result['content'], true);
        $this->assertFalse($result['is_error']);
        $this->assertCount(9, $calls);
        $this->assertSame(['custom_2'], $data['failed_types']);
        $this->assertSame(['custom_9', 'custom_10'], $data['remaining_types']);
        $this->assertCount(7, $data['items']);
        $this->assertStringNotContainsString('private transport', $result['content']);
    }

    public function test_a_verified_elementor_replacement_needs_no_second_model_and_preserves_approval_and_undo(): void
    {
        $ai = Mockery::mock(ClaudeClient::class);
        $ai->shouldNotReceive('structured');
        $this->app->instance(ClaudeClient::class, $ai);
        $offer = app(SiteActionProposer::class)->propose($this->subscriber->site, 'propose_text_edit',
            ['id' => 48, 'action' => 'replace', 'find' => 'לומדים ביחד', 'text' => 'מתקדמים ביחד'], [48]);
        $this->assertArrayNotHasKey('error', $offer);
        $this->assertTrue($offer['plan']['elementor']);
        $this->assertSame('לומדים ביחד', $this->world->state['elementor'][48]['hero']['text']);
        $request = $this->save($offer);
        $conversation = app(SiteAgentConversation::class);
        $this->assertStringContainsString('בוצע', $conversation->handle($this->subscriber, 'כן', 'elementor-yes'));
        $this->assertSame('מתקדמים ביחד', $this->world->state['elementor'][48]['hero']['text']);
        $this->assertSame('עמוד נחיתה', $this->world->state['content'][48]['title']);
        $this->assertStringContainsString('הוחזר', $conversation->handle($this->subscriber, 'בטל', 'elementor-undo'));
        $this->assertSame('לומדים ביחד', $this->world->state['elementor'][48]['hero']['text']);
        $this->assertSame(SiteAgentRequest::REVERTED, $request->refresh()->state);
        $this->assertNotContains('wp_content_update', array_column($this->world->calls, 'tool'));
    }

    public function test_duplicate_elementor_text_and_unsupported_append_have_no_proposal(): void
    {
        $this->world->state['elementor'][48]['duplicate'] = ['setting' => 'title', 'text' => 'לומדים ביחד'];
        foreach (['replace', 'append'] as $action) {
            $offer = app(SiteActionProposer::class)->propose($this->subscriber->site, 'propose_text_edit',
                ['id' => 48, 'action' => $action, 'find' => 'לומדים ביחד', 'text' => 'טקסט חדש'], [48]);
            $this->assertArrayHasKey('error', $offer);
        }
        $this->assertFalse(collect($this->world->calls)->contains(fn ($call) => $call['write']));
    }

    public function test_html_cannot_bypass_the_internal_link_target_verification(): void
    {
        $offer = app(SiteActionProposer::class)->propose($this->subscriber->site, 'propose_text_edit',
            ['id' => 45, 'action' => 'replace', 'find' => '2010', 'text' => '<a href="https://evaluation.example/?p=44">2010</a>'], [45]);
        $this->assertStringContainsString('propose_internal_link', $offer['error']);
        $this->assertFalse(collect($this->world->calls)->contains(fn ($call) => $call['write']));
    }

    public function test_direct_elementor_edit_cannot_modify_a_link_attribute(): void
    {
        $this->world->state['elementor'][48]['hero']['text'] = '<a href="/contact/">Contact</a>';
        $offer = app(SiteActionProposer::class)->propose($this->subscriber->site, 'propose_text_edit',
            ['id' => 48, 'action' => 'replace', 'find' => '/contact/', 'text' => '/elsewhere/'], [48]);
        $this->assertArrayHasKey('error', $offer);
        $this->assertFalse(collect($this->world->calls)->contains(fn ($call) => $call['write']));
    }

    public function test_elementor_execution_read_must_match_the_requested_page(): void
    {
        $mcp = Mockery::mock(McpClient::class);
        $mcp->shouldReceive('callTool')->withArgs(fn (Site $site, string $tool, array $args) => $tool === 'wp_elementor_texts_get' && $args['id'] === 48)
            ->twice()->andReturn(['id' => 49, 'texts' => [['widget_id' => 'hero', 'setting' => 'title', 'text' => 'Wrong page']]],
                ['texts' => [['widget_id' => 'hero', 'setting' => 'title', 'text' => 'Unknown page']]]);
        $mcp->shouldReceive('textContent')->andReturnUsing(fn (array $result) => json_encode($result));
        $this->app->instance(McpClient::class, $mcp);
        $planner = app(SiteChangePlanner::class);
        $this->assertSame([], $planner->elementorTexts($this->subscriber->site, 48));
        $this->assertSame([], $planner->elementorTexts($this->subscriber->site, 48));
    }

    public function test_elementor_status_change_after_application_blocks_undo(): void
    {
        $this->world->state['content'][48]['status'] = 'draft';
        $offer = app(SiteActionProposer::class)->propose($this->subscriber->site, 'propose_text_edit',
            ['id' => 48, 'action' => 'replace', 'find' => 'לומדים ביחד', 'text' => 'מתקדמים ביחד'], [48]);
        $request = $this->save($offer);
        $conversation = app(SiteAgentConversation::class);
        $this->assertStringContainsString('בוצע', $conversation->handle($this->subscriber, 'כן', 'draft-elementor-yes'));
        $this->assertSame('draft', $request->refresh()->restore['page_status']);
        $this->world->state['content'][48]['status'] = 'publish';
        $this->assertStringContainsString('לא החזרתי', $conversation->handle($this->subscriber, 'בטל', 'draft-elementor-undo'));
        $this->assertSame('מתקדמים ביחד', $this->world->state['elementor'][48]['hero']['text']);
    }

    public function test_markup_moved_since_the_preview_cannot_be_edited_as_visible_text(): void
    {
        $offer = app(SiteActionProposer::class)->propose($this->subscriber->site, 'propose_text_edit',
            ['id' => 48, 'action' => 'replace', 'find' => 'לומדים ביחד', 'text' => 'מתקדמים ביחד'], [48]);
        $request = $this->save($offer);
        $this->world->state['elementor'][48]['hero']['text'] = '<div title="לומדים ביחד">טקסט אחר</div>';
        app(SiteAgentConversation::class)->handle($this->subscriber, 'כן', 'attribute-race');
        $this->assertSame(SiteAgentRequest::FAILED, $request->refresh()->state);
        $this->assertFalse(collect($this->world->calls)->contains(fn ($call) => $call['write']));
    }

    public function test_page_planner_requires_a_complete_protocol_and_can_explain_unsupported_layout(): void
    {
        $ai = Mockery::mock(ClaudeClient::class);
        $ai->shouldReceive('isEnabled')->andReturn(true);
        $ai->shouldReceive('structured')->once()->andReturnUsing(function ($system, $prompt, $schema) {
            foreach (['can_do', 'operation', 'page_id', 'find', 'text', 'refusal'] as $key) {
                $this->assertContains($key, $schema['required']);
            }

            return ['can_do' => false, 'operation' => 'none', 'page_id' => 0, 'find' => '', 'text' => '',
                'question' => '', 'summary' => '', 'refusal' => 'שינוי פריסה ומחיקת וידגט אינם נתמכים; לא שונה דבר באתר.'];
        });
        $this->app->instance(ClaudeClient::class, $ai);
        $plan = app(SiteChangePlanner::class)->plan($this->subscriber->site, 'שנה את פריסת עמוד הנחיתה');
        $this->assertArrayHasKey('refusal', $plan);
        $this->assertArrayNotHasKey('question', $plan);
    }

    private function save(array $offer): SiteAgentRequest
    {
        return SiteAgentRequest::create(['site_agent_subscriber_id' => $this->subscriber->id,
            'site_id' => $this->subscriber->site_id, 'customer_id' => $this->subscriber->customer_id,
            'message' => 'החלף בכותרת אלמנטור לומדים ביחד במתקדמים ביחד',
            'operation' => $offer['plan']['operation'], 'plan' => $offer['plan'], 'preview' => $offer['preview'],
            'state' => SiteAgentRequest::AWAITING, 'expires_at' => now()->addMinutes(30)]);
    }
}
