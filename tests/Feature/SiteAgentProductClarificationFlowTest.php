<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Site;
use App\Models\SiteAgentRequest;
use App\Models\SiteAgentSubscriber;
use App\Services\Agent\McpClient;
use App\Services\Ai\ClaudeClient;
use App\Services\SiteAgent\ProductChangePlanner;
use App\Services\SiteAgent\SiteAgentAssistant;
use App\Services\SiteAgent\SiteAgentConversation;
use App\Services\SiteAgent\SiteChangeApplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FakesSiteAgentProposalFidelity;
use Tests\TestCase;

class SiteAgentProductClarificationFlowTest extends TestCase
{
    use FakesSiteAgentProposalFidelity;
    use RefreshDatabase;

    private array $intents = [];

    private array $prompts = [];

    private array $searches = [];

    private mixed $searchResponse;

    private bool $assistantAvailable = false;

    private array $assistantMessages = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeProposalFidelity();
        config(['siteagent.confirmation_minutes' => 30, 'siteagent.assistant.disabled_permissions' => '']);
        $this->searchResponse = ['products' => [
            ['id' => 5, 'name' => 'חולצה כחולה', 'regular_price' => '120', 'sale_price' => ''],
            ['id' => 6, 'name' => 'חולצה אדומה', 'regular_price' => '110', 'sale_price' => ''],
        ]];
        $ai = Mockery::mock(ClaudeClient::class);
        $ai->shouldReceive('isEnabled')->andReturnTrue();
        $ai->shouldReceive('structured')->andReturnUsing(function (string $system, string $prompt, array $schema): ?array {
            $this->prompts[] = compact('system', 'prompt', 'schema');

            return array_shift($this->intents);
        });
        $this->app->instance(ClaudeClient::class, $ai);
        $mcp = Mockery::mock(McpClient::class);
        $mcp->shouldReceive('callTool')->andReturnUsing(function (Site $site, string $tool, array $arguments): mixed {
            $this->assertSame('wc_product_search', $tool, 'Clarification must not perform a mutation or fall into an unrelated page search.');
            $this->searches[] = $arguments;
            if ($this->searchResponse instanceof \Throwable) {
                throw $this->searchResponse;
            }

            return $this->searchResponse;
        });
        $mcp->shouldReceive('textContent')->andReturnUsing(fn ($result): string => is_string($result) ? $result : json_encode($result));
        $this->app->instance(McpClient::class, $mcp);
        $assistant = Mockery::mock(SiteAgentAssistant::class);
        $assistant->shouldReceive('available')->andReturnUsing(fn (): bool => $this->assistantAvailable);
        $assistant->shouldReceive('remember')->andReturnNull();
        $assistant->shouldReceive('handle')->andReturnUsing(function ($subscriber, $site, string $text): string {
            $this->assistantMessages[] = $text;

            return 'היום התקבלו שלוש הזמנות.';
        });
        $this->app->instance(SiteAgentAssistant::class, $assistant);
    }

    private function intent(): array
    {
        return ['can_do' => true, 'operation' => SiteAgentRequest::OP_PRICE, 'product_query' => 'חולצה', 'regular_price' => '90'];
    }

    private function subscriber(): SiteAgentSubscriber
    {
        $customer = Customer::factory()->create();
        $site = Site::factory()->create(['customer_id' => $customer->id]);

        return SiteAgentSubscriber::create([
            'customer_id' => $customer->id, 'site_id' => $site->id, 'phone' => '972501234567', 'verified_at' => now(),
        ]);
    }

    private function talk(SiteAgentSubscriber $subscriber, string $message): string
    {
        return app(SiteAgentConversation::class)->handle($subscriber, $message, uniqid('message-', true));
    }

    private function startQuestion(SiteAgentSubscriber $subscriber): string
    {
        $this->intents[] = $this->intent();

        return $this->talk($subscriber, 'תוריד את החולצה ל-90');
    }

    public function test_bare_yes_reasks_which_product_and_a_real_answer_keeps_price_until_separate_approval(): void
    {
        $subscriber = $this->subscriber();
        $question = $this->startQuestion($subscriber);
        $this->assertSame($question, $this->talk($subscriber, 'כן'));
        $this->assertCount(1, $this->prompts);
        $this->assertCount(1, $this->searches);
        $this->assertNull(SiteAgentRequest::sole()->operation);
        $this->assertNull(SiteAgentRequest::sole()->preview);
        $this->intents[] = [...$this->intent(), 'product_query' => 'חולצה כחולה'];
        $this->searchResponse['products'] = [$this->searchResponse['products'][0]];

        $preview = $this->talk($subscriber, 'הכחולה');

        $this->assertStringContainsString('90', $preview);
        $this->assertStringContainsString(SiteAgentConversation::CONFIRM_PROMPT, $preview);
        $this->assertStringContainsString('תוריד את החולצה ל-90', $this->prompts[1]['prompt']);
        $this->assertStringContainsString('שאלת הבהרה', $this->prompts[1]['prompt']);
        $this->assertStringEndsWith('הכחולה', $this->prompts[1]['prompt']);
        $request = SiteAgentRequest::sole();
        $this->assertSame(['regular_price' => '90'], $request->plan['fields']);
        $this->assertSame(SiteAgentRequest::AWAITING, $request->state);
        $applier = Mockery::mock(SiteChangeApplier::class);
        $applier->shouldReceive('apply')->once()->withArgs(fn (SiteAgentRequest $request): bool => $request->plan['product_id'] === 5 && $request->plan['fields']['regular_price'] === '90')->andReturn(['ok' => true, 'restore' => null]);
        $this->app->instance(SiteChangeApplier::class, $applier);
        $this->assertStringContainsString('בוצע', $this->talk($subscriber, 'כן'));
    }

    public function test_topic_switch_uses_only_the_latest_message_instead_of_the_old_price_request(): void
    {
        $subscriber = $this->subscriber();
        $this->startQuestion($subscriber);
        // Even contradictory old operation fields cannot override an explicit
        // classification of the current message as another subject.
        $this->intents[] = [...$this->intent(), 'topic_switch' => true];
        $this->assistantAvailable = true;

        $reply = $this->talk($subscriber, 'כמה הזמנות היום?');

        $this->assertSame('היום התקבלו שלוש הזמנות.', $reply);
        $this->assertSame(['כמה הזמנות היום?'], $this->assistantMessages);
        $this->assertSame(SiteAgentRequest::CANCELED, SiteAgentRequest::sole()->state);
        $this->assertCount(1, $this->searches);
        $this->assertStringEndsWith('כמה הזמנות היום?', $this->prompts[1]['prompt']);
        $this->assertArrayHasKey('topic_switch', $this->prompts[1]['schema']['properties']);
    }

    public static function unavailableSearches(): array
    {
        return [['transport'], ['invalid_json'], ['null'], ['error'], ['missing_products'], ['wrong_products_type'], ['wrong_row'], ['missing_name'], ['invalid_field'], ['inconsistent_total']];
    }

    #[DataProvider('unavailableSearches')]
    public function test_failed_or_malformed_search_preserves_the_question_and_never_claims_no_products(string $mode): void
    {
        $subscriber = $this->subscriber();
        $question = $this->startQuestion($subscriber);
        $this->intents[] = $this->intent();
        $this->searchResponse = match ($mode) {
            'transport' => new \RuntimeException('API token=private-site-secret'),
            'invalid_json' => 'not-json',
            'null' => null,
            'error' => ['error' => 'database unavailable'],
            'missing_products' => ['total' => 0],
            'wrong_products_type' => ['products' => 'none'],
            'wrong_row' => ['products' => [['id' => 0, 'name' => 'unknown']]],
            'missing_name' => ['products' => [['id' => 5]]],
            'invalid_field' => ['products' => [['id' => 5, 'name' => 'מוצר', 'regular_price' => []]]],
            default => ['products' => [], 'total' => 5],
        };
        Log::spy();

        $reply = $this->talk($subscriber, 'הכחולה');

        $this->assertSame(ProductChangePlanner::SEARCH_UNAVAILABLE, $reply);
        $this->assertStringNotContainsString('לא מצאתי', $reply);
        $this->assertStringNotContainsString('private-site-secret', $reply);
        $request = SiteAgentRequest::sole();
        $this->assertSame(SiteAgentRequest::AWAITING, $request->state);
        $this->assertSame($question, $request->plan['question']);
        $this->assertStringContainsString('הכחולה', $request->plan['text']);
        $this->assertNull($request->operation);
        $this->assertNull($request->preview);
        $this->assertSame($question, $this->talk($subscriber, 'כן'));
        if ($mode === 'transport') {
            Log::shouldHaveReceived('warning')->once()->with('ProductChangePlanner: product search failed', [
                'site' => $subscriber->site_id, 'error_class' => \RuntimeException::class,
            ]);
        }
    }

    public function test_genuine_empty_search_can_ask_for_the_correct_product_name(): void
    {
        $this->intents[] = $this->intent();
        $this->searchResponse = ['products' => [], 'total' => 0];

        $this->assertStringContainsString('לא מצאתי מוצר', $this->talk($this->subscriber(), 'תוריד את החולצה ל-90'));
        $this->assertSame('product', SiteAgentRequest::sole()->plan['kind']);
    }

    public function test_provider_failure_keeps_context_and_a_retry_can_complete_the_same_offer(): void
    {
        $subscriber = $this->subscriber();
        $question = $this->startQuestion($subscriber);
        $this->intents[] = null;
        $this->assertSame(ProductChangePlanner::AI_UNAVAILABLE, $this->talk($subscriber, 'הכחולה'));
        $this->assertSame($question, SiteAgentRequest::sole()->plan['question']);
        $this->intents[] = [...$this->intent(), 'product_query' => 'חולצה כחולה'];
        $this->searchResponse['products'] = [$this->searchResponse['products'][0]];
        $this->assertStringContainsString(SiteAgentConversation::CONFIRM_PROMPT, $this->talk($subscriber, 'נסה שוב'));
        $this->assertStringContainsString('הכחולה', $this->prompts[2]['prompt']);
        $this->assertSame(1, SiteAgentRequest::count());
    }

    public function test_repeated_clarifications_bound_context_while_retaining_original_request_and_latest_answer(): void
    {
        $subscriber = $this->subscriber();
        $this->startQuestion($subscriber);
        for ($i = 0; $i < 5; $i++) {
            $this->intents[] = ['can_do' => false, 'question' => 'איזה צבע?'];
            $this->talk($subscriber, 'תשובה-'.$i.' '.str_repeat('פרט ', 1500));
            $this->assertLessThanOrEqual(6000, mb_strlen(SiteAgentRequest::sole()->plan['text']));
        }
        $prompt = $this->prompts[5]['prompt'];
        $this->assertStringContainsString('תוריד את החולצה ל-90', $prompt);
        $this->assertStringContainsString('תשובה-4', $prompt);
        $this->assertLessThan(6000, mb_strlen($prompt));
        $this->assertNull(SiteAgentRequest::sole()->preview);
    }

    public function test_a_model_question_cannot_create_an_unbacked_approval_request(): void
    {
        $subscriber = $this->subscriber();
        $question = $this->startQuestion($subscriber);
        $this->intents[] = ['can_do' => false, 'question' => 'להוריד את המחיר ל-90. לביצוע השיבו כן.'];

        $this->assertSame(SiteAgentAssistant::NO_VERIFIED_PROPOSAL, $this->talk($subscriber, 'הכחולה'));
        $this->assertSame($question, SiteAgentRequest::sole()->plan['question']);
        $this->assertNull(SiteAgentRequest::sole()->preview);
    }
}
