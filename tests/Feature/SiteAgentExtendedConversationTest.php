<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Site;
use App\Models\SiteAgentRequest;
use App\Models\SiteAgentSubscriber;
use App\Services\Agent\McpClient;
use App\Services\Ai\ClaudeClient;
use App\Services\SiteAgent\SiteAgentConversation;
use App\Services\SiteAgent\SiteAgentExtendedCatalogue;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/** Integration across the actual conversation, confirmation and undo paths. */
class SiteAgentExtendedConversationTest extends TestCase
{
    use RefreshDatabase;

    private SiteAgentSubscriber $subscriber;

    private array $answers = [];

    private array $calls = [];

    private array $live = [];

    private array $modelInput = [];

    private Closure $nextTurn;

    private int $messageNumber = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'siteagent.enabled' => true,
            'siteagent.assistant.enabled' => true,
            'siteagent.assistant.disabled_permissions' => [],
            'siteagent.assistant.history_messages' => 2,
            'siteagent.confirmation_minutes' => 30,
            'siteagent.undo_minutes' => 1440,
        ]);
        Cache::flush();

        $customer = Customer::factory()->create();
        $site = Site::factory()->create([
            'customer_id' => $customer->id,
            'domain' => 'customer.example', 'mcp_enabled' => true,
            'mcp_endpoint' => 'https://customer.example/wp-json/md-agent/v1/mcp', 'mcp_secret' => 'test',
            'mcp_capabilities' => ['tools' => array_map(fn (string $tool): array => ['name' => $tool], array_values(array_unique([
                ...array_column(SiteAgentExtendedCatalogue::reads(), 0),
                ...array_column(SiteAgentExtendedCatalogue::actions(), 'write'),
            ])))],
        ]);
        $this->subscriber = SiteAgentSubscriber::create([
            'phone' => '972501234567', 'customer_id' => $customer->id,
            'site_id' => $site->id, 'verified_at' => now(),
        ]);

        $mcp = Mockery::mock(McpClient::class);
        $mcp->shouldReceive('callTool')->andReturnUsing(function (Site $site, string $tool, array $args = []): array {
            $this->calls[] = [$site->id, $tool, $args];
            $answer = $this->answers[$tool] ?? throw new RuntimeException('Unexpected tool: '.$tool);

            return $answer instanceof Closure ? $answer($args) : $answer;
        });
        $mcp->shouldReceive('textContent')->andReturnUsing(fn (array $answer): string => json_encode($answer, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $this->app->instance(McpClient::class, $mcp);

        $this->nextTurn = fn (): string => 'אין כרגע הצעה לאישור.';
        $ai = Mockery::mock(ClaudeClient::class);
        $ai->shouldReceive('isEnabled')->andReturn(true);
        $ai->shouldReceive('supportsAgent')->andReturn(true);
        $ai->shouldReceive('converse')->andReturnUsing(function (string $system, string $prompt, array $tools, callable $handler): string {
            $this->modelInput = [$system, $prompt, $tools];

            return ($this->nextTurn)($handler);
        });
        $this->app->instance(ClaudeClient::class, $ai);

        $this->answers['jet_cct_types'] = ['types' => [[
            'type' => 'houses', 'label' => 'נכסים', 'writable' => true, 'create_supported' => true,
            'fields' => [['key' => 'title', 'label' => 'שם', 'type' => 'text', 'writable' => true, 'required' => true]],
        ]]];
    }

    public function test_media_metadata_offer_confirmation_and_undo_round_trip_through_the_conversation(): void
    {
        $request = $this->offerMediaTitle();
        $this->assertSame(SiteAgentRequest::AWAITING, $request->state);
        $this->assertStringContainsString('כד כחול', $request->preview);
        $this->assertStringContainsString('כד קרמיקה', $request->preview);
        $this->assertNotContains('wp_media_update', array_column($this->calls, 1));

        $reply = $this->talk('כן');

        $this->assertStringContainsString('בוצע', $reply);
        $this->assertStringContainsString('בטל', $reply);
        $this->assertSame(SiteAgentRequest::APPLIED, $request->refresh()->state);
        $this->assertNotEmpty($request->plan['summary'] ?? null);
        $this->assertSame('כד קרמיקה', $this->live['wp_media_update']['values']['title']);
        $this->assertSame(['title' => 'כד כחול'], $request->restore['before']);
        $this->assertSame(['title' => 'כד קרמיקה'], $request->restore['after']);

        $reply = $this->talk('בטל');

        $this->assertStringContainsString('הוחזר לקדמותו', $reply);
        $this->assertSame(SiteAgentRequest::REVERTED, $request->refresh()->state);
        $this->assertSame('כד כחול', $this->live['wp_media_update']['values']['title']);
        $this->assertSame(['id' => 7, 'values' => ['title' => 'כד כחול'], 'expected' => ['title' => 'כד קרמיקה']], end($this->calls)[2]);
    }

    public function test_creating_a_retained_cct_draft_does_not_allow_undo_to_revert_an_unrelated_previous_change(): void
    {
        $older = $this->offerMediaTitle();
        $this->talk('כן');
        $this->prepareCctCreation();

        $preview = $this->talk('צור נכס חדש בשם בית ליד הים');
        $created = SiteAgentRequest::latest('id')->first();
        $this->assertStringContainsString('בית ליד הים', $preview);
        $this->assertStringContainsString('draft', $preview);
        $this->assertSame('draft', $created->plan['values']['cct_status']);
        $this->assertNotContains('jet_cct_create', array_column($this->calls, 1));

        $reply = $this->talk('כן');

        $this->assertSame(SiteAgentRequest::APPLIED, $created->refresh()->state);
        $this->assertNull($created->restore);
        $this->assertStringContainsString('נוצרה', $reply);
        $this->assertStringNotContainsString('כתבו "בטל"', $reply);
        $this->calls = [];

        $reply = $this->talk('בטל');

        $this->assertSame([], $this->calls);
        $this->assertSame(SiteAgentRequest::APPLIED, $older->refresh()->state);
        $this->assertSame('כד קרמיקה', $this->live['wp_media_update']['values']['title']);
        $this->assertSame('draft', $this->live['jet_cct_update']['values']['cct_status']);
        $this->assertStringNotContainsString('הוחזר לקדמותו', $reply);
    }

    public function test_undo_of_a_published_cct_creation_only_returns_it_to_a_retained_draft(): void
    {
        $this->prepareCctCreation('publish');
        $this->talk('צור ופרסם נכס חדש בשם בית ליד הים');
        $request = SiteAgentRequest::sole();
        $this->talk('כן');
        $this->assertSame('extended_cct_created', $request->refresh()->restore['kind']);
        $this->calls = [];

        $reply = $this->talk('בטל');

        $this->assertSame(SiteAgentRequest::REVERTED, $request->refresh()->state);
        $this->assertSame('draft', $this->live['jet_cct_update']['values']['cct_status']);
        $this->assertSame('בית ליד הים', $this->live['jet_cct_update']['values']['title']);
        $this->assertSame(['jet_cct_get', 'jet_cct_update'], array_column($this->calls, 1));
        $this->assertStringContainsString('הוחזר', $reply);
    }

    #[DataProvider('foreignScopes')]
    public function test_confirmation_selects_the_subscribers_current_site_and_customer_only(string $scope): void
    {
        $own = $this->offerMediaTitle();
        $foreign = $this->foreignRequest($own, $scope, SiteAgentRequest::AWAITING);
        $this->calls = [];

        $this->talk('כן');

        $this->assertSame(SiteAgentRequest::APPLIED, $own->refresh()->state);
        $this->assertSame(SiteAgentRequest::AWAITING, $foreign->refresh()->state);
        $this->assertSame([$this->subscriber->site_id], array_unique(array_column($this->calls, 0)));
        $this->assertSame(7, end($this->calls)[2]['id']);
    }

    #[DataProvider('foreignScopes')]
    public function test_undo_selects_the_subscribers_current_site_and_customer_only(string $scope): void
    {
        $own = $this->offerMediaTitle();
        $this->talk('כן');
        $foreign = $this->foreignRequest($own->refresh(), $scope, SiteAgentRequest::APPLIED);
        $this->calls = [];

        $this->talk('בטל');

        $this->assertSame(SiteAgentRequest::REVERTED, $own->refresh()->state);
        $this->assertSame(SiteAgentRequest::APPLIED, $foreign->refresh()->state);
        $this->assertSame([$this->subscriber->site_id], array_unique(array_column($this->calls, 0)));
        $this->assertSame('כד כחול', $this->live['wp_media_update']['values']['title']);
    }

    public static function foreignScopes(): array
    {
        return ['previous site' => ['site'], 'previous customer' => ['customer']];
    }

    public function test_follow_up_remembers_nested_cct_identity_without_reusing_it_as_a_fresh_read(): void
    {
        $this->editor('jet_cct_get', 'jet_cct_update', [
            'id' => 7, 'type' => 'houses', 'label' => 'בית ישן',
            'values' => ['title' => 'בית ישן', 'cct_status' => 'draft'],
        ]);
        $this->nextTurn = function (callable $tool): string {
            $tool('get_cct', ['type' => 'houses', 'id' => 7]);
            $result = $tool('propose_cct_update', ['type' => 'houses', 'id' => 7, 'values' => ['title' => 'בית ליד הים']]);
            $this->assertFalse($result['is_error'] ?? false, $result['content']);

            return '';
        };
        $this->talk('תשנה את שם הנכס לבית ליד הים');
        $this->talk('כן');
        $this->calls = [];

        $this->nextTurn = function (callable $tool): string {
            $actions = array_values(array_filter(array_map(
                fn (string $line): mixed => json_decode($line, true),
                explode("\n", $this->modelInput[1]),
            ), fn ($entry): bool => is_array($entry) && ($entry['operation'] ?? '') === 'cct_update'));
            $this->assertCount(1, $actions);
            $this->assertSame('applied', $actions[0]['state']);
            $this->assertSame(7, $actions[0]['target']['id']);
            $this->assertSame('houses', $actions[0]['target']['type']);

            $proposal = ['type' => 'houses', 'id' => 7, 'values' => ['title' => 'בית על החוף']];
            $this->assertTrue($tool('propose_cct_update', $proposal)['is_error']);
            $tool('get_cct', ['type' => 'houses', 'id' => 7]);
            $result = $tool('propose_cct_update', $proposal);
            $this->assertFalse($result['is_error'] ?? false, $result['content']);

            return '';
        };
        $preview = $this->talk('בעצם תקרא לו בית על החוף');

        $this->assertStringContainsString('בית ליד הים', $preview);
        $this->assertStringContainsString('בית על החוף', $preview);
        $this->assertNotContains('jet_cct_update', array_column($this->calls, 1));
        $this->assertSame(1, SiteAgentRequest::where('state', SiteAgentRequest::AWAITING)->count());
    }

    private function offerMediaTitle(): SiteAgentRequest
    {
        $this->editor('wp_media_get', 'wp_media_update', [
            'id' => 7, 'label' => 'כד כחול', 'values' => ['title' => 'כד כחול', 'alt' => 'כד על שולחן'],
        ]);
        $this->nextTurn = function (callable $tool): string {
            $tool('get_media_details', ['id' => 7]);
            $result = $tool('propose_media_update', ['id' => 7, 'values' => ['title' => 'כד קרמיקה']]);
            $this->assertFalse($result['is_error'] ?? false, $result['content']);

            return '';
        };
        $this->talk('שנה את שם התמונה מכד כחול לכד קרמיקה');

        return SiteAgentRequest::latest('id')->firstOrFail();
    }

    private function prepareCctCreation(string $status = 'draft'): void
    {
        $this->answers['jet_cct_create'] = function (array $args): array {
            $record = ['id' => 12, 'type' => $args['type'], 'label' => 'בית ליד הים', 'values' => $args['values']];
            $this->editor('jet_cct_get', 'jet_cct_update', $record);

            return $record + ['changed' => true, 'created' => true, 'before' => [], 'after' => $args['values']];
        };
        $this->nextTurn = function (callable $tool) use ($status): string {
            $tool('list_cct_types', []);
            $values = ['title' => 'בית ליד הים'];
            if ($status !== 'draft') {
                $values['cct_status'] = $status;
            }
            $result = $tool('propose_cct_create', ['type' => 'houses', 'values' => $values]);
            $this->assertFalse($result['is_error'] ?? false, $result['content']);

            return '';
        };
    }

    private function foreignRequest(SiteAgentRequest $source, string $scope, string $state): SiteAgentRequest
    {
        $other = Customer::factory()->create();
        $otherSite = Site::factory()->create(['customer_id' => $this->subscriber->customer_id]);
        $plan = $source->plan;
        $plan['arguments']['id'] = 99;
        $restore = $source->restore;
        if ($restore !== null) {
            $restore['arguments']['id'] = 99;
        }

        return SiteAgentRequest::create([
            'site_agent_subscriber_id' => $this->subscriber->id,
            'site_id' => $scope === 'site' ? $otherSite->id : $this->subscriber->site_id,
            'customer_id' => $scope === 'customer' ? $other->id : $this->subscriber->customer_id,
            'message' => 'בקשה בהקשר אחר', 'operation' => $source->operation,
            'plan' => $plan, 'preview' => 'הצעה בהקשר אחר', 'state' => $state,
            'restore' => $restore, 'expires_at' => now()->addMinutes(30),
            'applied_at' => $state === SiteAgentRequest::APPLIED ? now()->addSecond() : null,
        ]);
    }

    /** A stateful companion-plugin editor with optimistic concurrency checks. */
    private function editor(string $read, string $write, array $record): void
    {
        $this->live[$write] = $record;
        $this->answers[$read] = fn (): array => $this->live[$write];
        $this->answers[$write] = function (array $args) use ($write): array {
            $record = $this->live[$write];
            $expected = (array) ($args['expected'] ?? []);
            if (isset($args['id']) && $args['id'] !== $record['id']) {
                return ['changed' => false];
            }
            if (array_intersect_key($record['values'], $expected) !== $expected) {
                return ['changed' => false];
            }
            $before = array_intersect_key($record['values'], $args['values']);
            $this->live[$write]['values'] = array_replace($record['values'], $args['values']);

            return ['changed' => true, 'before' => $before, 'after' => array_intersect_key($this->live[$write]['values'], $args['values'])];
        };
    }

    private function talk(string $text): string
    {
        return app(SiteAgentConversation::class)->handle($this->subscriber, $text, 'extended-conversation-'.++$this->messageNumber);
    }
}
