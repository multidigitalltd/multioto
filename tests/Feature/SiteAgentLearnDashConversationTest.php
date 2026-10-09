<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Site;
use App\Models\SiteAgentRequest;
use App\Models\SiteAgentSubscriber;
use App\Services\Agent\McpClient;
use App\Services\Agent\McpError;
use App\Services\Ai\ClaudeClient;
use App\Services\SiteAgent\SiteAgentConversation;
use App\Services\SiteAgent\SiteChangeApplier;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/** Real WhatsApp approvals and undo; AI and the native LearnDash boundary are mocked. */
class SiteAgentLearnDashConversationTest extends TestCase
{
    use RefreshDatabase;

    private SiteAgentSubscriber $subscriber;

    private array $remote = [];

    private array $calls = [];

    private array $toolResults = [];

    private array $modelInput = [];

    private Closure $nextTurn;

    private int $messageNumber = 0;

    private array $selector = ['user_id' => 8, 'kind' => 'course', 'target_id' => 74];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'siteagent.enabled' => true, 'siteagent.assistant.enabled' => true,
            'siteagent.assistant.disabled_permissions' => [], 'siteagent.assistant.history_messages' => 4,
            'siteagent.confirmation_minutes' => 30, 'siteagent.undo_minutes' => 1440,
        ]);
        Cache::flush();
        $customer = Customer::factory()->create();
        $site = Site::factory()->create([
            'customer_id' => $customer->id, 'domain' => 'learndash-conversation.example',
            'mcp_enabled' => true, 'mcp_secret' => 'test-site-secret',
            'mcp_endpoint' => 'https://learndash-conversation.example/wp-json/md-agent/v1/mcp',
            'mcp_capabilities' => ['server' => ['version' => '1.11.0'], 'tools' => array_map(fn (string $tool): array => ['name' => $tool], [
                'ld_capabilities', 'ld_courses_list', 'ld_course_get', 'ld_student_course_get', 'ld_groups_list', 'ld_group_get',
                'ld_membership_get', 'ld_membership_prepare', 'ld_membership_apply', 'ld_membership_revert',
            ])],
        ]);
        $this->subscriber = SiteAgentSubscriber::create([
            'phone' => '972501234567', 'customer_id' => $customer->id,
            'site_id' => $site->id, 'verified_at' => now(),
        ]);
        $this->editor($this->state(false, false, []), $this->state(true, true, ['direct']));
        $mcp = Mockery::mock(McpClient::class);
        $mcp->shouldReceive('callTool')->andReturnUsing(function (Site $site, string $tool, array $args = []): array {
            $this->assertSame($this->subscriber->site_id, $site->id);
            $this->calls[] = [$tool, $args];

            return $this->remoteCall($tool, $args);
        });
        $mcp->shouldReceive('textContent')->andReturnUsing(fn (array $data): string => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $this->app->instance(McpClient::class, $mcp);
        $this->nextTurn = fn (): string => 'אין כרגע הצעה לאישור.';
        $ai = Mockery::mock(ClaudeClient::class);
        $ai->shouldReceive('isEnabled')->andReturn(true);
        $ai->shouldReceive('supportsAgent')->andReturn(true);
        $ai->shouldReceive('converse')->andReturnUsing(function (string $system, string $prompt, array $tools, callable $handler): string {
            $this->modelInput = [$system, $prompt, $tools];

            return ($this->nextTurn)(function (string $name, array $args) use ($handler): array {
                $result = $handler($name, $args);
                $this->toolResults[] = [$name, $result];

                return $result;
            });
        });
        $this->app->instance(ClaudeClient::class, $ai);
    }

    public function test_course_enrollment_requires_consent_and_can_be_undone_once_without_progress_writes(): void
    {
        $request = $this->offer();
        $this->assertSame(SiteAgentRequest::AWAITING, $request->state);
        $this->assertSame('learndash_membership', $request->operation);
        $this->assertStringContainsString('תלמיד לדוגמה', $request->preview);
        $this->assertStringContainsString('קורס צילום', $request->preview);
        $this->assertSame($this->remote['before'], $this->remote['current']);
        $this->assertSame(0, $this->remote['writes']);
        $this->assertSame(['ld_membership_get', 'ld_membership_get', 'ld_membership_prepare'], array_column($this->calls, 0));

        $reply = $this->talk('כן');

        $this->assertStringContainsString('בוצע', $reply);
        $this->assertStringContainsString('בטל', $reply);
        $this->assertSame(SiteAgentRequest::APPLIED, $request->refresh()->state);
        $this->assertNotNull($request->applied_at);
        $this->assertSame($this->remote['after'], $this->remote['current']);
        $this->assertSame('learndash_membership', $request->restore['kind']);
        $this->assertSame($this->seal('before'), $request->restore['before']);
        $this->assertSame($this->seal('after'), $request->restore['after']);
        $this->assertSame(['ld_membership_apply', $this->selector + ['expected' => $this->seal('before'), 'prepared' => $this->seal('prepared')]], end($this->calls));
        $this->talk('כן');
        $this->assertSame(1, $this->remote['writes']);

        $reply = $this->talk('בטל');

        $this->assertStringContainsString('הוחזר לקדמותו', $reply);
        $this->assertSame(SiteAgentRequest::REVERTED, $request->refresh()->state);
        $this->assertNotNull($request->reverted_at);
        $this->assertSame($this->remote['before'], $this->remote['current']);
        $this->assertSame(['ld_membership_revert', $this->selector + ['expected' => $this->seal('after'), 'restore' => $this->seal('before')]], end($this->calls));
        $this->assertSame(2, $this->remote['writes']);
        $this->talk('בטל');
        $this->assertSame(2, $this->remote['writes']);
        $this->assertSame(['ld_membership_get', 'ld_membership_prepare', 'ld_membership_apply', 'ld_membership_revert'], array_values(array_unique(array_column($this->calls, 0))));
    }

    public function test_removing_direct_enrollment_does_not_claim_group_access_will_disappear(): void
    {
        $this->editor($this->state(true, true, ['direct', 'group:19']), $this->state(false, true, ['group:19']));
        $this->remote['notes'] = ['הגישה לקורס תישאר דרך הקבוצה גם אחרי הסרת ההרשמה הישירה.'];
        $request = $this->offer('remove');
        $this->assertStringContainsString('הגישה לקורס תישאר דרך הקבוצה', $request->preview);
        $this->assertSame(0, $this->remote['writes']);
        $this->talk('כן');
        $this->assertSame(SiteAgentRequest::APPLIED, $request->refresh()->state);
        $this->assertFalse($this->remote['current']['direct_member']);
        $this->assertTrue($this->remote['current']['effective_access']);
        $this->talk('בטל');
        $this->assertTrue($this->remote['current']['direct_member']);
        $this->assertTrue($this->remote['current']['effective_access']);
    }

    public function test_group_membership_shows_course_impacts_and_preserves_its_scope_during_undo(): void
    {
        $this->selector = ['user_id' => 8, 'kind' => 'group', 'target_id' => 19];
        $this->editor($this->state(false, null, []), $this->state(true, null, ['direct']));
        $this->remote['target'] = ['id' => 19, 'title' => 'קבוצת מתחילים', 'type' => 'group'];
        $this->remote['impacts'] = [['course_id' => 74, 'title' => 'קורס צילום', 'before_access' => false, 'after_access' => true]];
        $request = $this->offer();
        $this->assertStringContainsString('קבוצת מתחילים', $request->preview);
        $this->assertStringContainsString('קורס צילום', $request->preview);
        $this->assertSame($this->selector, $request->plan['arguments']);
        $this->talk('כן');
        $this->assertSame(SiteAgentRequest::APPLIED, $request->refresh()->state);
        $this->assertSame($this->selector, $request->restore['arguments']);
        $this->talk('בטל');
        $this->assertSame(SiteAgentRequest::REVERTED, $request->refresh()->state);
        $this->assertSame($this->selector, array_intersect_key(end($this->calls)[1], $this->selector));
    }

    public function test_canceling_or_disabling_student_management_never_changes_enrollment(): void
    {
        $request = $this->offer();
        $this->talk('לא');
        $this->assertSame(SiteAgentRequest::CANCELED, $request->refresh()->state);
        $this->talk('כן');
        $this->assertSame(0, $this->remote['writes']);

        $request = $this->offer();
        config(['siteagent.assistant.disabled_permissions' => ['learndash_students']]);
        $this->calls = [];
        $this->talk('כן');
        $this->assertNotSame(SiteAgentRequest::APPLIED, $request->refresh()->state);
        $this->assertSame(0, $this->remote['writes']);
        $this->assertSame([], $this->calls);
    }

    public function test_stale_confirmation_preserves_membership_changed_on_the_site_and_is_not_retried(): void
    {
        $request = $this->offer();
        $this->remote['current'] = $this->state(false, true, ['group:19']);
        $this->remote['current_snapshot'] = $this->seal('dashboard');
        $this->calls = [];
        $reply = $this->talk('כן');
        $this->assertSame(SiteAgentRequest::FAILED, $request->refresh()->state);
        $this->assertNull($request->restore);
        $this->assertNull($request->applied_at);
        $this->assertStringNotContainsString('✅ בוצע', $reply);
        $this->assertSame(['group:19'], $this->remote['current']['access_sources']);
        $this->assertSame(0, $this->remote['writes']);
        $this->assertSame(['ld_membership_apply'], array_column($this->calls, 0));
        $this->talk('כן');
        $this->assertSame(['ld_membership_apply'], array_column($this->calls, 0));
        $this->assertSame(1, SiteAgentRequest::count());
    }

    public function test_undo_does_not_override_membership_changed_after_the_bot_action(): void
    {
        $request = $this->offer();
        $this->talk('כן');
        $this->remote['current'] = $this->state(true, true, ['direct', 'group:19']);
        $this->remote['current_snapshot'] = $this->seal('later-editor');
        $reply = $this->talk('בטל');
        $this->assertStringNotContainsString('הוחזר לקדמותו', $reply);
        $this->assertSame(SiteAgentRequest::APPLIED, $request->refresh()->state);
        $this->assertNull($request->reverted_at);
        $this->assertSame(['direct', 'group:19'], $this->remote['current']['access_sources']);
        $this->assertSame(1, $this->remote['writes']);
    }

    public function test_follow_up_keeps_the_target_but_requires_a_fresh_read_without_leaking_tokens_or_emails(): void
    {
        $this->offer();
        $this->talk('כן');
        $this->calls = [];
        $this->nextTurn = function (callable $tool): string {
            foreach ([$this->seal('before')['token'], $this->seal('prepared')['token'], $this->seal('after')['token'], 'private.student@example.test'] as $secret) {
                $this->assertStringNotContainsString($secret, json_encode($this->modelInput));
                $this->assertStringNotContainsString($secret, json_encode($this->toolResults));
            }
            $this->assertStringContainsString('learndash_membership', $this->modelInput[1]);
            $actions = array_values(array_filter(array_map(fn (string $line): mixed => json_decode($line, true), explode("\n", $this->modelInput[1])),
                fn ($entry): bool => is_array($entry) && ($entry['operation'] ?? '') === 'learndash_membership'));
            $this->assertCount(1, $actions);
            $this->assertSame('applied', $actions[0]['state']);
            $this->assertSame(8, $actions[0]['target']['user_id']);
            $this->assertSame(74, $actions[0]['target']['target_id']);
            $this->assertSame('course', $actions[0]['target']['kind']);
            $this->assertTrue($tool('propose_ld_membership', $this->selector + ['action' => 'remove'])['is_error']);
            $this->assertSame([], $this->calls);
            $this->assertFalse($tool('get_ld_membership', $this->selector)['is_error']);

            return 'בדקתי את ההרשמה שלו לקורס.';
        };
        $this->talk('לאיזה קורס צירפת אותו?');
        $this->assertSame(['ld_membership_get'], array_column($this->calls, 0));
        $this->assertSame(1, $this->remote['writes']);
    }

    private function editor(array $before, array $after): void
    {
        $this->remote = ['before' => $before, 'after' => $after, 'current' => $before,
            'target' => ['id' => 74, 'title' => 'קורס צילום', 'type' => 'course'],
            'current_snapshot' => $this->seal('before'), 'writes' => 0, 'notes' => [], 'impacts' => []];
    }

    private function remoteCall(string $tool, array $args): array
    {
        $this->assertSame($this->selector, array_intersect_key($args, $this->selector));
        $context = ['selector' => $this->selector,
            'user' => ['id' => 8, 'display_name' => 'תלמיד לדוגמה', 'email' => 'private.student@example.test'],
            'target' => $this->remote['target']];
        if ($tool === 'ld_membership_get') {
            return $context + ['state' => $this->remote['current'], 'snapshot' => $this->remote['current_snapshot'], 'writable' => true, 'reason' => null,
                'impacts' => array_map(fn (array $impact): array => ['course_id' => $impact['course_id'], 'title' => $impact['title'], 'effective_access' => $impact['before_access']], $this->remote['impacts'])];
        }
        if ($tool === 'ld_membership_prepare') {
            $this->assertSame($this->remote['current_snapshot'], $args['expected']);

            return $context + ['before' => $this->remote['before'], 'after' => $this->remote['after'], 'changed' => true,
                'expected' => $args['expected'], 'prepared' => $this->seal('prepared'), 'notes' => $this->remote['notes'], 'impacts' => $this->remote['impacts']];
        }
        if (! in_array($tool, ['ld_membership_apply', 'ld_membership_revert'], true)) {
            throw new RuntimeException('Unexpected LearnDash tool: '.$tool);
        }
        if (($args['expected'] ?? null) !== $this->remote['current_snapshot']) {
            throw new McpError(SiteChangeApplier::STALE);
        }
        $before = $this->remote['current_snapshot'];
        if ($tool === 'ld_membership_apply') {
            $this->assertSame($this->seal('prepared'), $args['prepared']);
            $this->assertArrayNotHasKey('action', $args);
            $this->remote['current'] = $this->remote['after'];
            $this->remote['current_snapshot'] = $this->seal('after');
        } else {
            $this->assertSame($this->seal('before'), $args['restore']);
            $this->remote['current'] = $this->remote['before'];
            $this->remote['current_snapshot'] = $this->seal('before');
        }
        $this->remote['writes']++;

        return ['changed' => true, 'selector' => $this->selector, 'expected' => $args['expected'], 'before' => $before, 'after' => $this->remote['current_snapshot']];
    }

    private function offer(string $action = 'add'): SiteAgentRequest
    {
        $this->nextTurn = function (callable $tool) use ($action): string {
            $this->assertContains('get_ld_membership', array_column($this->modelInput[2], 'name'));
            $this->assertContains('propose_ld_membership', array_column($this->modelInput[2], 'name'));
            $read = $tool('get_ld_membership', $this->selector);
            $this->assertFalse($read['is_error'], $read['content']);
            $proposal = $tool('propose_ld_membership', $this->selector + ['action' => $action]);
            $this->assertFalse($proposal['is_error'] ?? false, $proposal['content']);

            return 'This generated answer must not replace the real preview.';
        };
        $reply = $this->talk($action === 'add' ? 'צרף את התלמיד לקורס שביקשתי' : 'הסר את ההרשמה הישירה שלו לקורס');
        $request = SiteAgentRequest::latest('id')->firstOrFail();
        $this->assertStringContainsString($request->preview, $reply);
        $this->assertStringContainsString(SiteAgentConversation::CONFIRM_PROMPT, $reply);
        $this->assertSame($this->subscriber->id, $request->site_agent_subscriber_id);
        $this->assertSame($this->subscriber->site_id, $request->site_id);
        $this->nextTurn = fn (): string => 'אין כרגע הצעה לאישור.';

        return $request;
    }

    private function state(bool $direct, ?bool $access, array $sources): array
    {
        return ['direct_member' => $direct, 'effective_access' => $access, 'access_sources' => $sources, 'access_from' => null, 'expires_at' => null];
    }

    private function seal(string $tag): array
    {
        return ['version' => hash('sha256', $tag), 'token' => base64_encode('opaque-learndash-remote-'.$tag)];
    }

    private function talk(string $message): string
    {
        return app(SiteAgentConversation::class)->handle($this->subscriber, $message, 'learndash-conversation-'.++$this->messageNumber);
    }
}
