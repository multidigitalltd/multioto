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
use Tests\Concerns\FakesSiteAgentProposalFidelity;
use Tests\TestCase;

/** Real conversation/approval persistence; only the AI and remote MCP boundary are mocked. */
class SiteAgentAcfConversationTest extends TestCase
{
    use FakesSiteAgentProposalFidelity;
    use RefreshDatabase;

    private SiteAgentSubscriber $subscriber;

    private array $remote = [];

    private array $calls = [];

    private array $toolResults = [];

    private array $modelInput = [];

    private Closure $nextTurn;

    private int $messageNumber = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeProposalFidelity();
        config([
            'siteagent.enabled' => true, 'siteagent.assistant.enabled' => true,
            'siteagent.assistant.disabled_permissions' => [],
            'siteagent.assistant.history_messages' => 4,
            'siteagent.confirmation_minutes' => 30, 'siteagent.undo_minutes' => 1440,
        ]);
        Cache::flush();
        $customer = Customer::factory()->create();
        $site = Site::factory()->create([
            'customer_id' => $customer->id, 'domain' => 'acf-conversation.example',
            'mcp_enabled' => true, 'mcp_secret' => 'test-site-secret',
            'mcp_endpoint' => 'https://acf-conversation.example/wp-json/md-agent/v1/mcp',
            'mcp_capabilities' => ['tools' => array_map(fn (string $tool): array => ['name' => $tool], [
                'wp_acf_get', 'wp_acf_schema', 'wp_acf_options_pages', 'wp_acf_prepare', 'wp_acf_update',
            ])],
        ]);
        $this->subscriber = SiteAgentSubscriber::create([
            'phone' => '972501234567', 'customer_id' => $customer->id,
            'site_id' => $site->id, 'verified_at' => now(),
        ]);

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

    public function test_nested_repeater_change_requires_consent_and_round_trips_through_confirmation_and_undo(): void
    {
        $before = [
            ['field_heading' => 'כותרת מקורית', 'field_text' => 'פסקה ראשונה'],
            ['field_heading' => 'כותרת שנייה', 'field_text' => 'פסקה שלא משתנה'],
        ];
        $after = $before;
        $after[0]['field_heading'] = 'כותרת מעודכנת';
        $this->editor(['context' => 'post', 'field_key' => 'field_sections', 'id' => 7], [
            'key' => 'field_sections', 'label' => 'מקטעי תוכן', 'type' => 'repeater', 'editable' => true,
            'sub_fields' => [['key' => 'field_heading', 'label' => 'כותרת', 'type' => 'text'], ['key' => 'field_text', 'label' => 'טקסט', 'type' => 'textarea']],
        ], $before, $after);
        $operation = ['op' => 'set', 'path' => [0, 'field_heading'], 'value' => 'כותרת מעודכנת'];

        $request = $this->offer([$operation], 'עדכן את הכותרת בשורה הראשונה במקטעי התוכן');

        $this->assertSame(SiteAgentRequest::AWAITING, $request->state);
        $this->assertSame(SiteAgentRequest::OP_ACF, $request->operation);
        $this->assertStringContainsString('כותרת מקורית', $request->preview);
        $this->assertStringContainsString('כותרת מעודכנת', $request->preview);
        $this->assertStringContainsString('שורה 1', $request->preview);
        $this->assertStringNotContainsString('field_heading', $request->preview);
        $this->assertSame($before, $this->remote['current']);
        $this->assertSame(['wp_acf_get', 'wp_acf_get', 'wp_acf_prepare'], array_column($this->calls, 0));
        $this->assertSame([$operation], end($this->calls)[1]['operations']);
        $this->assertSame(0, $this->remote['writes']);

        $reply = $this->talk('כן');

        $this->assertStringContainsString('בוצע', $reply);
        $this->assertStringContainsString('בטל', $reply);
        $this->assertSame(SiteAgentRequest::APPLIED, $request->refresh()->state);
        $this->assertNotNull($request->applied_at);
        $this->assertSame($after, $this->remote['current']);
        $this->assertSame('acf', $request->restore['kind']);
        $this->assertSame($this->seal('before'), $request->restore['before']);
        $this->assertSame($this->seal('after'), $request->restore['after']);
        $this->assertSame(['wp_acf_update', $this->remote['selector'] + ['expected' => $this->seal('before'), 'prepared' => $this->seal('proposal')]], end($this->calls));

        $this->talk('כן');
        $this->assertSame(1, $this->remote['writes'], 'A second confirmation must never repeat the write.');

        $reply = $this->talk('בטל');

        $this->assertStringContainsString('הוחזר לקדמותו', $reply);
        $this->assertSame(SiteAgentRequest::REVERTED, $request->refresh()->state);
        $this->assertNotNull($request->reverted_at);
        $this->assertSame($before, $this->remote['current']);
        $this->assertSame(['wp_acf_update', $this->remote['selector'] + ['expected' => $this->seal('after'), 'restore' => $this->seal('before')]], end($this->calls));
        $this->assertSame(2, $this->remote['writes']);
        $this->talk('בטל');
        $this->assertSame(2, $this->remote['writes'], 'Undo is settled once.');
    }

    public function test_passwords_remain_hidden_in_model_results_saved_offers_undo_and_follow_up_context(): void
    {
        $oldPassword = 'OLD-PASSWORD-DO-NOT-DISCLOSE';
        $newPassword = 'NEW-PASSWORD-DO-NOT-PERSIST';
        $this->editor(['context' => 'post', 'field_key' => 'field_access', 'id' => 7], [
            'key' => 'field_access', 'label' => 'סיסמת גישה', 'type' => 'password', 'editable' => true, 'sensitive' => true,
        ], $oldPassword, $newPassword, ['redacted' => true, 'has_value' => true], ['redacted' => true, 'has_value' => true]);
        $request = $this->offer([['op' => 'set', 'path' => [], 'value' => $newPassword]], 'עדכן את שדה סיסמת הגישה');
        $this->assertStringContainsString('מוסתר', $request->preview);
        $this->assertSecretsAbsent($request, [$oldPassword, $newPassword]);
        $this->assertSame($oldPassword, $this->remote['current']);

        $this->talk('כן');
        $this->assertSame(SiteAgentRequest::APPLIED, $request->refresh()->state);
        $this->assertSame($newPassword, $this->remote['current']);
        $this->assertSecretsAbsent($request, [$oldPassword, $newPassword]);
        $this->talk('בטל');
        $this->assertSame(SiteAgentRequest::REVERTED, $request->refresh()->state);
        $this->assertSame($oldPassword, $this->remote['current']);
        $this->assertSecretsAbsent($request, [$oldPassword, $newPassword]);

        $this->talk('מה שינית קודם?');
        foreach ([$oldPassword, $newPassword, $this->seal('before')['token'], $this->seal('proposal')['token'], $this->seal('after')['token']] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($this->modelInput));
            $this->assertStringNotContainsString($secret, json_encode($this->toolResults));
        }
        $this->assertStringContainsString('reverted', $this->modelInput[1]);
    }

    public function test_registered_options_page_keeps_its_scope_through_offer_confirmation_and_undo(): void
    {
        $selector = ['context' => 'options', 'field_key' => 'field_tagline', 'options_page' => 'brand-settings'];
        $this->editor($selector, ['key' => 'field_tagline', 'label' => 'סלוגן', 'type' => 'text', 'editable' => true], 'הסלוגן הישן', 'הסלוגן החדש');
        $request = $this->offer([['op' => 'set', 'path' => [], 'value' => 'הסלוגן החדש']], 'עדכן את הסלוגן בהגדרות המותג', true);
        $this->assertSame($selector, $request->plan['arguments']);
        $this->assertStringContainsString('הגדרות המותג', $request->preview);
        $this->assertStringContainsString('הסלוגן הישן', $request->preview);
        $this->assertSame('wp_acf_options_pages', $this->calls[0][0]);
        $this->assertStringNotContainsString('private_options_storage', json_encode($this->toolResults));
        $this->assertSame(0, $this->remote['writes']);

        $this->talk('כן');
        $this->assertSame(SiteAgentRequest::APPLIED, $request->refresh()->state);
        $this->assertSame('הסלוגן החדש', $this->remote['current']);
        $this->assertSame($selector, $request->restore['arguments']);
        $this->assertArrayNotHasKey('id', end($this->calls)[1]);
        $this->talk('בטל');
        $this->assertSame(SiteAgentRequest::REVERTED, $request->refresh()->state);
        $this->assertSame('הסלוגן הישן', $this->remote['current']);
        $this->assertSame($selector + ['expected' => $this->seal('after'), 'restore' => $this->seal('before')], end($this->calls)[1]);
    }

    public function test_declining_the_offer_never_sends_a_write_or_restoration(): void
    {
        $this->simpleEditor();
        $request = $this->offer([['op' => 'set', 'path' => [], 'value' => 'חדש']], 'שנה את הכותרת');
        $reply = $this->talk('לא');
        $this->assertStringContainsString('בוטל', $reply);
        $this->assertSame(SiteAgentRequest::CANCELED, $request->refresh()->state);
        $this->assertSame('ישן', $this->remote['current']);
        $this->talk('כן');
        $this->assertSame(0, $this->remote['writes']);
        $this->assertNotContains('wp_acf_update', array_column($this->calls, 0));
    }

    public function test_stale_confirmation_preserves_the_dashboard_edit_and_does_not_automatically_retry(): void
    {
        $this->simpleEditor();
        $request = $this->offer([['op' => 'set', 'path' => [], 'value' => 'חדש']], 'שנה את הכותרת');
        $this->remote['current'] = 'עריכה של בעל האתר';
        $this->remote['current_snapshot'] = $this->seal('dashboard');
        $this->calls = [];

        $reply = $this->talk('כן');

        $this->assertSame(SiteAgentRequest::FAILED, $request->refresh()->state);
        $this->assertNull($request->restore);
        $this->assertNull($request->applied_at);
        $this->assertStringNotContainsString('✅ בוצע', $reply);
        $this->assertSame('עריכה של בעל האתר', $this->remote['current']);
        $this->assertSame(0, $this->remote['writes']);
        $this->assertSame(['wp_acf_update'], array_column($this->calls, 0));
        $this->talk('כן');
        $this->assertSame(['wp_acf_update'], array_column($this->calls, 0), 'A failed offer must need a new proposal, never an automatic retry.');
        $this->assertSame(1, SiteAgentRequest::count());
    }

    public function test_stale_undo_preserves_later_site_work_and_does_not_mark_the_request_reverted(): void
    {
        $this->simpleEditor();
        $request = $this->offer([['op' => 'set', 'path' => [], 'value' => 'חדש']], 'שנה את הכותרת');
        $this->talk('כן');
        $this->remote['current'] = 'עריכה אחרי הבוט';
        $this->remote['current_snapshot'] = $this->seal('later-editor');
        $reply = $this->talk('בטל');
        $this->assertStringNotContainsString('הוחזר לקדמותו', $reply);
        $this->assertSame(SiteAgentRequest::APPLIED, $request->refresh()->state);
        $this->assertNull($request->reverted_at);
        $this->assertSame('עריכה אחרי הבוט', $this->remote['current']);
        $this->assertSame(1, $this->remote['writes']);
        $this->assertSame($this->seal('after'), end($this->calls)[1]['expected']);
    }

    public function test_follow_up_remembers_the_acf_target_but_requires_a_fresh_read_before_a_new_offer(): void
    {
        $this->simpleEditor();
        $this->offer([['op' => 'set', 'path' => [], 'value' => 'חדש']], 'שנה את הכותרת');
        $this->talk('כן');
        $this->simpleEditor('חדש', 'חדש יותר');
        $this->calls = [];
        $this->nextTurn = function (callable $tool): string {
            $actions = array_values(array_filter(array_map(fn (string $line): mixed => json_decode($line, true), explode("\n", $this->modelInput[1])),
                fn ($entry): bool => is_array($entry) && ($entry['operation'] ?? '') === SiteAgentRequest::OP_ACF));
            $this->assertCount(1, $actions);
            $this->assertSame('applied', $actions[0]['state']);
            $this->assertSame(['id' => 7, 'context' => 'post', 'field_key' => 'field_title'], $actions[0]['target']);
            $input = $this->remote['selector'] + ['operations' => [['op' => 'set', 'path' => [], 'value' => 'חדש יותר']]];
            $this->assertTrue($tool('propose_acf_update', $input)['is_error']);
            $this->assertSame([], $this->calls);
            $this->assertFalse($tool('get_acf', $this->remote['selector'])['is_error']);
            $this->assertFalse($tool('propose_acf_update', $input)['is_error'] ?? false);

            return '';
        };

        $preview = $this->talk('בעצם תקרא לו חדש יותר');

        $this->assertStringContainsString('חדש יותר', $preview);
        $this->assertSame(1, SiteAgentRequest::where('state', SiteAgentRequest::AWAITING)->count());
        $this->assertNotContains('wp_acf_update', array_column($this->calls, 0));
    }

    private function simpleEditor(string $before = 'ישן', string $after = 'חדש'): void
    {
        $this->editor(['context' => 'post', 'field_key' => 'field_title', 'id' => 7],
            ['key' => 'field_title', 'label' => 'כותרת', 'type' => 'text', 'editable' => true], $before, $after);
    }

    private function editor(array $selector, array $field, mixed $before, mixed $after, mixed $displayBefore = null, mixed $displayAfter = null): void
    {
        $this->remote = [
            'selector' => $selector, 'field' => $field,
            'target' => ['context' => $selector['context'], 'id' => $selector['id'] ?? null,
                'options_page' => $selector['options_page'] ?? null,
                'label' => $selector['context'] === 'options' ? 'הגדרות המותג' : 'דף הפרויקט',
                'acf_id' => $selector['context'] === 'options' ? 'private_options_storage' : $selector['id']],
            'before' => $before, 'after' => $after, 'current' => $before,
            'display_before' => $displayBefore ?? $before, 'display_after' => $displayAfter ?? $after,
            'current_snapshot' => $this->seal('before'), 'writes' => 0,
        ];
    }

    /** Stateful remote responses test Laravel's routing, not ACF's licensed implementation. */
    private function remoteCall(string $tool, array $args): array
    {
        if ($tool === 'wp_acf_options_pages') {
            return ['pages' => [['options_page' => 'brand-settings', 'label' => 'הגדרות המותג', 'acf_id' => 'private_options_storage']]];
        }
        $this->assertSame($this->remote['selector'], array_intersect_key($args, $this->remote['selector']));
        $target = $this->remote['target'];
        $fieldKey = $this->remote['field']['key'];
        if ($tool === 'wp_acf_get') {
            return ['target' => $target, 'fields' => [$this->remote['field']],
                'values' => [$fieldKey => $this->remote['current_snapshot'] === $this->seal('before') ? $this->remote['display_before'] : $this->remote['display_after']],
                'snapshots' => [$fieldKey => $this->remote['current_snapshot']]];
        }
        if ($tool === 'wp_acf_prepare') {
            $this->assertSame($this->remote['current_snapshot'], $args['expected']);

            return ['target' => $target, 'field_key' => $fieldKey, 'changed' => true,
                'before' => $this->remote['display_before'], 'after' => $this->remote['display_after'],
                'expected' => $args['expected'], 'prepared' => $this->seal('proposal'), 'notes' => [], 'writing_text' => ''];
        }
        if ($tool !== 'wp_acf_update') {
            throw new RuntimeException('Unexpected remote tool: '.$tool);
        }
        if (($args['expected'] ?? null) !== $this->remote['current_snapshot']) {
            throw new McpError(SiteChangeApplier::STALE);
        }
        $before = $this->remote['current_snapshot'];
        if (isset($args['prepared'])) {
            $this->assertSame($this->seal('proposal'), $args['prepared']);
            $this->assertArrayNotHasKey('operations', $args);
            $this->remote['current'] = $this->remote['after'];
            $this->remote['current_snapshot'] = $this->seal('after');
        } else {
            $this->assertSame($this->seal('before'), $args['restore']);
            $this->remote['current'] = $this->remote['before'];
            $this->remote['current_snapshot'] = $this->seal('before');
        }
        $this->remote['writes']++;

        return ['target' => $target, 'field_key' => $fieldKey, 'changed' => true, 'before' => $before, 'after' => $this->remote['current_snapshot']];
    }

    private function offer(array $operations, string $message, bool $options = false): SiteAgentRequest
    {
        $this->nextTurn = function (callable $tool) use ($operations, $options): string {
            if ($options) {
                $this->assertFalse($tool('list_acf_options', [])['is_error']);
            }
            $this->assertContains('get_acf', array_column($this->modelInput[2], 'name'));
            $this->assertContains('propose_acf_update', array_column($this->modelInput[2], 'name'));
            $read = $tool('get_acf', $this->remote['selector']);
            $this->assertFalse($read['is_error'], $read['content']);
            $proposal = $tool('propose_acf_update', $this->remote['selector'] + ['operations' => $operations]);
            $this->assertFalse($proposal['is_error'] ?? false, $proposal['content']);

            return 'This generated answer must not replace the real preview.';
        };
        $reply = $this->talk($message);
        $request = SiteAgentRequest::latest('id')->firstOrFail();
        $this->assertSame($request->preview."\n\n".SiteAgentConversation::CONFIRM_PROMPT, $reply);
        $this->assertSame($this->subscriber->id, $request->site_agent_subscriber_id);
        $this->assertSame($this->subscriber->site_id, $request->site_id);
        $this->nextTurn = fn (): string => 'אין כרגע הצעה לאישור.';

        return $request;
    }

    private function assertSecretsAbsent(SiteAgentRequest $request, array $secrets): void
    {
        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, json_encode([$request->plan, $request->preview, $request->restore]));
            $this->assertStringNotContainsString($secret, json_encode($this->toolResults));
            $this->assertStringNotContainsString($secret, (string) $request->getRawOriginal('plan'));
        }
    }

    private function seal(string $tag): array
    {
        return ['version' => hash('sha256', $tag), 'token' => base64_encode('opaque-remote-sealed-'.$tag)];
    }

    private function talk(string $message): string
    {
        return app(SiteAgentConversation::class)->handle($this->subscriber, $message, 'acf-conversation-'.++$this->messageNumber);
    }
}
