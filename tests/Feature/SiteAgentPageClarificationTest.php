<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Site;
use App\Models\SiteAgentRequest;
use App\Models\SiteAgentSubscriber;
use App\Services\Agent\McpClient;
use App\Services\Ai\ClaudeClient;
use App\Services\SiteAgent\SiteAgentAssistant;
use App\Services\SiteAgent\SiteAgentConversation;
use App\Services\SiteAgent\SiteChangeApplier;
use App\Services\SiteAgent\SiteChangePlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class SiteAgentPageClarificationTest extends TestCase
{
    use RefreshDatabase;

    private const QUESTION = 'איזה טקסט בדף הבית תרצו להחליף, ומה לכתוב במקומו?';

    private array $prompts = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['siteagent.assistant.enabled' => false, 'siteagent.confirmation_minutes' => 30]);
        Cache::flush();
        Http::preventStrayRequests();
        $mcp = Mockery::mock(McpClient::class);
        $mcp->shouldReceive('callTool')->andReturnUsing(fn (Site $site, string $tool): array => match ($tool) {
            'wp_site_settings_get' => ['values' => ['show_on_front' => 'page', 'page_on_front' => 11, 'page_for_posts' => 0]],
            'wp_content_list' => [['id' => 11, 'title' => 'דף הבית']],
            'wp_content_get' => ['id' => 11, 'type' => 'page', 'title' => 'דף הבית', 'content' => 'ברוכים הבאים לאתר', 'status' => 'publish'],
            default => throw new \RuntimeException('Unexpected site tool: '.$tool),
        });
        $mcp->shouldReceive('textContent')->andReturnUsing(fn (array $data): string => json_encode($data));
        $this->app->instance(McpClient::class, $mcp);
    }

    public function test_the_exact_homepage_request_asks_for_missing_text_and_keeps_the_page_until_approval(): void
    {
        $subscriber = $this->subscriber();
        $this->model([
            ['can_do' => false], // Not a shop request.
            ['can_do' => false, 'question' => self::QUESTION],
            ['can_do' => false, 'question' => 'מה לכתוב במקום "ברוכים הבאים"?'],
            $this->completePlan(),
        ]);

        $this->assertSame(self::QUESTION, $this->talk($subscriber, 'להחליף טקסט בדף הבית'));
        $request = SiteAgentRequest::sole();
        $this->assertSame('page', $request->plan['kind']);
        $this->assertNull($request->operation);
        $this->assertEmpty($request->preview);

        // Saying yes to the question cannot skip the missing content.
        $this->assertSame(self::QUESTION, $this->talk($subscriber, 'כן'));
        $this->assertSame(SiteAgentRequest::AWAITING, $request->fresh()->state);
        $this->assertCount(2, $this->prompts);

        $this->assertStringContainsString('מה לכתוב', $this->talk($subscriber, 'הטקסט הישן הוא "ברוכים הבאים"'));
        $reply = $this->talk($subscriber, 'תכתוב "שלום לכולם"');

        $this->assertStringContainsString('דף הבית', $reply);
        $this->assertStringContainsString('ברוכים הבאים', $reply);
        $this->assertStringContainsString('שלום לכולם', $reply);
        $this->assertStringContainsString(SiteAgentConversation::CONFIRM_PROMPT, $reply);
        $this->assertStringContainsString('להחליף טקסט בדף הבית', $this->prompts[3]);
        $this->assertStringContainsString('הטקסט הישן הוא', $this->prompts[3]);
        $this->assertStringContainsString('תכתוב "שלום לכולם"', $this->prompts[3]);
        $this->assertSame(1, SiteAgentRequest::count());
        $this->assertSame(SiteAgentRequest::AWAITING, $request->fresh()->state);

        $applier = Mockery::mock(SiteChangeApplier::class);
        $applier->shouldReceive('apply')->once()->withArgs(fn (SiteAgentRequest $approved): bool => $approved->state === SiteAgentRequest::APPLYING && $approved->plan['page_id'] === 11
            && $approved->plan['find'] === 'ברוכים הבאים' && $approved->plan['text'] === 'שלום לכולם')
            ->andReturn(['ok' => true, 'restore' => [], 'message' => null]);
        $this->app->instance(SiteChangeApplier::class, $applier);
        $this->assertStringContainsString('בוצע', $this->talk($subscriber, 'כן'));
        $this->assertSame(SiteAgentRequest::APPLIED, $request->fresh()->state);
    }

    public function test_an_incomplete_delegation_from_the_assistant_also_parks_the_question(): void
    {
        config(['siteagent.assistant.enabled' => true]);
        $ai = Mockery::mock(ClaudeClient::class);
        $ai->shouldReceive('isEnabled')->andReturn(true);
        $ai->shouldReceive('supportsAgent')->andReturn(true);
        $ai->shouldReceive('converse')->once()->andReturnUsing(function ($system, $prompt, $tools, $handler): string {
            $this->assertStringContainsString('מועברת מיד ל-edit_page_text', $system);
            $this->assertStringContainsString('העורך שואל על החסר ושומר את ההקשר', $system);
            $handler('edit_page_text', ['instruction' => 'להחליף טקסט בדף הבית']);

            return 'הכנתי.';
        });
        $ai->shouldReceive('structured')->once()->andReturn(['can_do' => false, 'question' => self::QUESTION]);
        $this->app->instance(ClaudeClient::class, $ai);

        $this->assertSame(self::QUESTION, $this->talk($this->subscriber(), 'להחליף טקסט בדף הבית'));
        $this->assertEmpty(SiteAgentRequest::sole()->preview);
        $this->assertSame('page', SiteAgentRequest::sole()->plan['kind']);
    }

    public function test_a_planner_question_cannot_masquerade_as_an_execution_preview(): void
    {
        $this->model([
            ['can_do' => false],
            ['can_do' => false, 'question' => 'אחליף את הטקסט בדף הבית. לביצוע השיבו "כן". לביטול — "לא".'],
        ]);

        $reply = $this->talk($this->subscriber(), 'להחליף טקסט בדף הבית');

        $this->assertSame(SiteAgentAssistant::NO_VERIFIED_PROPOSAL, $reply);
        $this->assertSame(0, SiteAgentRequest::count());
        $this->assertStringNotContainsString(SiteAgentConversation::CONFIRM_PROMPT, $reply);
    }

    public function test_a_canceled_question_never_reaches_the_applier(): void
    {
        $subscriber = $this->subscriber();
        $this->model([['can_do' => false], ['can_do' => false, 'question' => self::QUESTION]]);
        $this->talk($subscriber, 'להחליף טקסט בדף הבית');
        $this->assertStringContainsString('בוטל', $this->talk($subscriber, 'לא'));
        $this->assertSame(SiteAgentRequest::CANCELED, SiteAgentRequest::sole()->state);
        $this->assertEmpty(SiteAgentRequest::sole()->preview);
    }

    public function test_changing_the_subject_cancels_the_page_question_and_resumes_the_assistant(): void
    {
        $subscriber = $this->subscriber();
        $this->model([['can_do' => false], ['can_do' => false, 'question' => self::QUESTION]]);
        $this->talk($subscriber, 'להחליף טקסט בדף הבית');

        config(['siteagent.assistant.enabled' => true]);
        $ai = Mockery::mock(ClaudeClient::class);
        $ai->shouldReceive('isEnabled')->andReturn(true);
        $ai->shouldReceive('supportsAgent')->andReturn(true);
        $ai->shouldReceive('structured')->once()->andReturn(['can_do' => false]);
        $ai->shouldReceive('converse')->once()->andReturnUsing(function ($system, $prompt): string {
            $this->assertStringContainsString('מה המסלול שלי', $prompt);

            return 'אבדוק את פרטי המסלול שלך.';
        });
        $this->app->instance(ClaudeClient::class, $ai);

        $this->assertSame('אבדוק את פרטי המסלול שלך.', $this->talk($subscriber, 'עזוב את זה, מה המסלול שלי?'));
        $this->assertSame(SiteAgentRequest::CANCELED, SiteAgentRequest::sole()->state);
        $this->assertEmpty(SiteAgentRequest::sole()->preview);
    }

    public function test_an_expired_question_does_not_supply_context_for_a_new_message(): void
    {
        $subscriber = $this->subscriber();
        $this->model([
            ['can_do' => false], ['can_do' => false, 'question' => self::QUESTION],
            ['can_do' => false], ['can_do' => false],
        ]);
        $this->talk($subscriber, 'להחליף טקסט בדף הבית');
        $request = SiteAgentRequest::sole();
        $request->update(['expires_at' => now()->subSecond()]);

        $callsBeforeApproval = count($this->prompts);
        $this->assertSame(SiteAgentConversation::NO_PENDING_PROPOSAL, $this->talk($subscriber, 'כן'));
        $this->assertCount($callsBeforeApproval, $this->prompts, 'An orphan yes must not reach a planner as a new edit.');
        $this->assertEmpty($request->fresh()->preview);
        $this->assertNull($request->fresh()->operation);
    }

    public function test_a_question_belongs_only_to_its_customer_and_site(): void
    {
        $subscriber = $this->subscriber();
        $other = $this->subscriber();
        $this->model([
            ['can_do' => false], ['can_do' => false, 'question' => self::QUESTION],
            ['can_do' => false], ['can_do' => false],
        ]);
        $this->talk($subscriber, 'להחליף טקסט בדף הבית');
        $this->talk($other, 'תכתוב "שלום לכולם"');

        $this->assertStringNotContainsString('להחליף טקסט בדף הבית', $this->prompts[3]);
        $this->assertSame($subscriber->id, SiteAgentRequest::sole()->site_agent_subscriber_id);
        $this->assertEmpty(SiteAgentRequest::sole()->preview);
    }

    public function test_provider_failure_is_reported_as_unavailability_and_preserves_the_answer(): void
    {
        $subscriber = $this->subscriber();
        $this->model([
            ['can_do' => false], ['can_do' => false, 'question' => self::QUESTION],
            null, $this->completePlan(),
        ]);
        $this->talk($subscriber, 'להחליף טקסט בדף הבית');
        $reply = $this->talk($subscriber, 'תחליף "ברוכים הבאים" ב"שלום לכולם"');
        $this->assertStringContainsString('הבוט לא הצליח לעבד את הבקשה כרגע', $reply);
        $this->assertStringNotContainsString('לא הצלחתי להבין', $reply);
        $this->assertEmpty(SiteAgentRequest::sole()->preview);

        $this->assertStringContainsString(SiteAgentConversation::CONFIRM_PROMPT, $this->talk($subscriber, 'נסה שוב'));
        $this->assertStringContainsString('תחליף "ברוכים הבאים" ב"שלום לכולם"', $this->prompts[3]);
    }

    public function test_long_clarification_keeps_the_original_page_and_the_latest_correction(): void
    {
        $subscriber = $this->subscriber();
        $this->model([['can_do' => false], ['can_do' => false, 'question' => self::QUESTION], $this->completePlan()]);
        $this->talk($subscriber, 'להחליף טקסט בדף הבית');
        $request = SiteAgentRequest::sole();
        $request->update(['plan' => [...$request->plan, 'text' => 'להחליף טקסט בדף הבית'.str_repeat(' טקסט קודם', 450)."\nתיקון אחרון: הטקסט החדש הוא שלום לכולם"]]);

        $this->talk($subscriber, 'הטקסט הקיים הוא ברוכים הבאים');
        $this->assertStringContainsString('להחליף טקסט בדף הבית', $this->prompts[2]);
        $this->assertStringContainsString('תיקון אחרון: הטקסט החדש הוא שלום לכולם', $this->prompts[2]);
        $this->assertStringContainsString('הטקסט הקיים הוא ברוכים הבאים', $this->prompts[2]);
    }

    public function test_permissions_revoked_during_clarification_block_the_preview(): void
    {
        $subscriber = $this->subscriber();
        $this->model([['can_do' => false], ['can_do' => false, 'question' => self::QUESTION], $this->completePlan()]);
        $this->talk($subscriber, 'להחליף טקסט בדף הבית');
        config(['siteagent.assistant.disabled_permissions' => 'content']);

        $reply = $this->talk($subscriber, 'תחליף "ברוכים הבאים" ב"שלום לכולם"');
        $this->assertStringNotContainsString(SiteAgentConversation::CONFIRM_PROMPT, $reply);
        $this->assertEmpty(SiteAgentRequest::sole()->preview);
        $this->assertNull(SiteAgentRequest::sole()->operation);
    }

    public function test_disabled_ai_and_failed_site_reads_are_not_blame_on_the_request(): void
    {
        $subscriber = $this->subscriber();
        $ai = Mockery::mock(ClaudeClient::class);
        $ai->shouldReceive('isEnabled')->andReturn(false);
        $this->app->instance(ClaudeClient::class, $ai);
        $reply = $this->talk($subscriber, 'להחליף טקסט בדף הבית');
        $this->assertStringContainsString('הגדרת חיבור חסרה', $reply);
        $this->assertStringNotContainsString('לא הצלחתי להבין', $reply);

        $this->model([]);
        $mcp = Mockery::mock(McpClient::class);
        $mcp->shouldReceive('callTool')->once()->andThrow(new \RuntimeException('Connection failed'));
        $this->app->instance(McpClient::class, $mcp);
        $plan = app(SiteChangePlanner::class)->plan($subscriber->site, 'להחליף טקסט בעמוד צור קשר');
        $this->assertStringContainsString('החיבור לאתר תקין', $plan['refusal']);
        $this->assertSame(0, SiteAgentRequest::count());
    }

    private function completePlan(): array
    {
        return ['can_do' => true, 'operation' => 'replace_text', 'page_id' => 11,
            'find' => 'ברוכים הבאים', 'text' => 'שלום לכולם', 'summary' => 'עדכון ברכה'];
    }

    private function model(array $answers): void
    {
        $ai = Mockery::mock(ClaudeClient::class);
        $ai->shouldReceive('isEnabled')->andReturn(true);
        $ai->shouldReceive('structured')->andReturnUsing(function ($system, $prompt) use (&$answers) {
            $this->prompts[] = $prompt;

            return array_shift($answers);
        });
        $this->app->instance(ClaudeClient::class, $ai);
    }

    private function subscriber(): SiteAgentSubscriber
    {
        $customer = Customer::factory()->create();
        $site = Site::factory()->create(['customer_id' => $customer->id, 'mcp_enabled' => true,
            'mcp_endpoint' => 'https://example.test/wp-json/multioto/v1/mcp', 'mcp_secret' => 'test-secret']);

        return SiteAgentSubscriber::create(['customer_id' => $customer->id, 'site_id' => $site->id,
            'phone' => '97250'.str_pad((string) $customer->id, 7, '0', STR_PAD_LEFT), 'verified_at' => now()]);
    }

    private function talk(SiteAgentSubscriber $subscriber, string $text): string
    {
        return app(SiteAgentConversation::class)->handle($subscriber, $text, 'wamid.'.bin2hex(random_bytes(8)));
    }
}
