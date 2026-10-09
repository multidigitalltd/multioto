<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Site;
use App\Models\SiteAgentMessage;
use App\Models\SiteAgentRequest;
use App\Models\SiteAgentSubscriber;
use App\Services\Agent\McpClient;
use App\Services\SiteAgent\Evaluation\EvaluationMcpClient;
use App\Services\SiteAgent\Evaluation\EvaluationWorld;
use App\Services\SiteAgent\SiteAgentAssistant;
use App\Services\SiteAgent\SiteAgentConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Real assistant, proposer, fidelity checker and transport; only remote HTTP is scripted. */
class SiteAgentProposalFidelityFlowTest extends TestCase
{
    use RefreshDatabase;

    private EvaluationWorld $world;

    private SiteAgentSubscriber $subscriber;

    private array $reviews = [];

    private int $agentCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        config(['billing.ai.enabled' => true, 'billing.ai.api_key' => 'test-key',
            'billing.ai.provider' => 'google', 'billing.ai.model' => 'gemini-3.1-flash-lite',
            'billing.ai.base_url' => 'https://generativelanguage.googleapis.com',
            'siteagent.enabled' => true, 'siteagent.assistant.enabled' => true,
            'siteagent.assistant.cache.enabled' => false, 'siteagent.assistant.disabled_permissions' => '']);
        $this->world = new EvaluationWorld;
        $customer = Customer::factory()->create();
        $site = Site::factory()->create(['customer_id' => $customer->id, 'domain' => 'evaluation.example',
            'mcp_enabled' => true, 'mcp_endpoint' => 'https://evaluation.example/wp-json/multioto/v1/mcp',
            'mcp_capabilities' => ['tools' => array_map(fn ($name) => ['name' => $name], $this->world->supportedTools())]]);
        $this->subscriber = SiteAgentSubscriber::create(['customer_id' => $customer->id, 'site_id' => $site->id,
            'phone' => '972501234567', 'verified_at' => now()]);
        $this->app->instance(McpClient::class, new EvaluationMcpClient($this->world, $site->id));
    }

    public function test_wrong_page_append_is_repaired_to_internal_link_before_any_offer_exists(): void
    {
        $owner = 'את המילים "אנחנו כאן" שציטטת קשר לעמוד צור קשר.';
        foreach ([['user', 'מה כתוב כרגע בעמוד אודות?'], ['assistant', 'בעמוד אודות כתוב: אנחנו כאן מאז 2010.']] as [$role, $body]) {
            SiteAgentMessage::create(['site_agent_subscriber_id' => $this->subscriber->id,
                'site_id' => $this->subscriber->site_id, 'role' => $role, 'body' => $body]);
        }
        $this->model(function ($turn) {
            return match ($turn) {
                1 => [$this->tool('get_content', ['id' => 44]), $this->tool('get_internal_links', ['id' => 45])],
                2 => [$this->tool('propose_text_edit', ['id' => 44, 'action' => 'append', 'text' => 'אנחנו כאן'])],
                3 => [$this->tool('propose_internal_link', ['id' => 45, 'values' => ['text' => 'אנחנו כאן', 'target_id' => 44]])],
                default => $this->fail('An offer must stop the agent without another response.'),
            };
        }, function ($review) use ($owner) {
            $this->assertSame(0, SiteAgentRequest::count(), 'No rejected or unreviewed offer may become approvable.');
            $this->assertSame($owner, $review['current_owner_message']);
            $this->assertCount(2, $review['recent_conversation']);
            $this->assertStringContainsString(count($this->reviews) === 1 ? '#44' : '#45', $review['candidate_offer']['preview']);

            return count($this->reviews) === 1
                ? ['verdict' => 'revise', 'reason' => 'wrong_action', 'feedback' => 'נדרש קישור בעמוד אודות, לא הוספת טקסט בעמוד צור קשר.']
                : ['verdict' => 'allow', 'reason' => 'matched', 'feedback' => ''];
        });
        $conversation = app(SiteAgentConversation::class);
        $reply = $conversation->handle($this->subscriber, $owner, 'link-request');
        $this->assertStringContainsString(SiteAgentConversation::CONFIRM_PROMPT, $reply);
        $request = SiteAgentRequest::sole();
        $this->assertSame(SiteAgentRequest::OP_INTERNAL_LINK, $request->operation);
        $this->assertSame('טלפון: 03-1234567', $this->world->state['content'][44]['content']);
        $this->assertFalse(collect($this->world->calls)->contains(fn ($call) => $call['changed'] ?? false));
        $this->assertCount(2, $this->reviews);
        $this->assertStringContainsString('בוצע', $conversation->handle($this->subscriber, 'כן', 'link-yes'));
        $this->assertStringContainsString('<a ', $this->world->state['content'][45]['content']);
        $this->assertStringContainsString('הוחזר', $conversation->handle($this->subscriber, 'בטל', 'link-undo'));
        $this->assertSame('אנחנו כאן מאז 2010.', $this->world->state['content'][45]['content']);
        $this->assertCount(2, $this->reviews, 'Confirmation and undo use the saved verified plan without another AI review.');
    }

    #[DataProvider('terminalReviews')]
    public function test_rejected_or_invalid_review_never_leaves_a_pending_offer(array $review, string $expected): void
    {
        $this->model(fn ($turn) => $turn === 1
            ? [$this->tool('get_subscription', ['subscription_id' => 601])]
            : ($turn > 2 && ($review['verdict'] ?? null) === 'clarify'
                ? [['text' => 'באיזה פריט לבצע את השינוי?']]
                : [$this->tool('propose_subscription_status', ['subscription_id' => 601, 'status' => 'pending-cancel'])]),
            fn () => $review);
        $conversation = app(SiteAgentConversation::class);
        $reply = $conversation->handle($this->subscriber, 'בטל מיד ולצמיתות את מנוי WooCommerce 601, לא בסוף התקופה ובלי אפשרות שחזור.', 'cancel-request');
        $this->assertStringContainsString($expected, $reply);
        $this->assertStringNotContainsString(SiteAgentConversation::CONFIRM_PROMPT, $reply);
        $this->assertSame(0, SiteAgentRequest::count());
        $this->assertCount(1, $this->reviews);
        $yesReply = $conversation->handle($this->subscriber, 'כן', 'cancel-yes');
        $this->assertSame(($review['verdict'] ?? null) === 'clarify' ? 'באיזה פריט לבצע את השינוי?' : SiteAgentConversation::NO_PENDING_PROPOSAL, $yesReply);
        $this->assertSame(0, SiteAgentRequest::count());
        $this->assertSame('active', $this->world->state['subscriptions'][601]['status']);
        $this->assertFalse(collect($this->world->calls)->contains(fn ($call) => $call['changed'] ?? false));
    }

    public static function terminalReviews(): array
    {
        return [
            'excluded alternative' => [['verdict' => 'refuse', 'reason' => 'excluded_alternative', 'feedback' => 'ביטול בסוף התקופה נשלל.'], 'לא ניתן לבצע מכאן'],
            'unknown target' => [['verdict' => 'clarify', 'reason' => 'ambiguous_target', 'feedback' => 'היעד חסר.'], 'באיזה עמוד או פריט'],
            'invalid review' => [['verdict' => 'allow'], 'לא הצלחתי לאמת'],
        ];
    }

    public function test_a_second_wrong_offer_stops_the_turn_without_a_third_review(): void
    {
        $this->model(fn ($turn) => $turn === 1
            ? [$this->tool('get_content', ['id' => 44])]
            : [$this->tool('propose_text_edit', ['id' => 44, 'action' => 'append', 'text' => 'אנחנו כאן'])],
            fn () => ['verdict' => 'revise', 'reason' => 'wrong_target', 'feedback' => 'עמוד המקור הוא אודות.']);
        $reply = app(SiteAgentAssistant::class)->handle($this->subscriber, $this->subscriber->site,
            'בעמוד אודות קשר את אנחנו כאן לעמוד צור קשר', 'retry', fn () => $this->fail('No delegate expected.'));
        $this->assertStringContainsString('לא תאמה', $reply);
        $this->assertSame(0, SiteAgentRequest::count());
        $this->assertCount(2, $this->reviews);
        $this->assertSame(3, $this->agentCalls);
    }

    private function model(callable $agent, callable $review): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => function ($request) use ($agent, $review) {
            $data = $request->data();
            if (isset($data['generationConfig']['responseSchema'])) {
                $this->assertArrayNotHasKey('tools', $data);
                $this->assertArrayNotHasKey('cachedContent', $data);
                $prompt = json_decode($data['contents'][0]['parts'][0]['text'], true);
                $this->reviews[] = $prompt;
                $this->assertSame(['operation', 'preview'], array_keys($prompt['candidate_offer']));
                $parts = [['text' => json_encode($review($prompt), JSON_UNESCAPED_UNICODE)]];
            } else {
                $parts = $agent(++$this->agentCalls);
            }

            return Http::response(['candidates' => [['content' => ['role' => 'model', 'parts' => $parts], 'finishReason' => 'STOP']]]);
        }]);
    }

    private function tool(string $name, array $args): array
    {
        return ['functionCall' => ['name' => $name, 'args' => $args]];
    }
}
