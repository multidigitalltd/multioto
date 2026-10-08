<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\SiteAgentRequest;
use App\Services\Agent\McpClient;
use App\Services\SiteAgent\SiteActionApplier;
use App\Services\SiteAgent\SiteActionProposer;
use App\Services\SiteAgent\SiteAgentAcfActions;
use App\Services\SiteAgent\SiteAgentPermissions;
use App\Services\SiteAgent\SiteAgentToolbox;
use App\Services\SiteAgent\SiteAgentUsageMeter;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/** The WhatsApp bridge preserves the native ACF consent and opaque undo contract. */
class SiteAgentAcfActionsTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private array $answers = [];

    private array $calls = [];

    private array $target = ['context' => 'post', 'id' => 7, 'options_page' => null, 'acf_id' => 7, 'label' => 'Live project'];

    private array $selector = ['context' => 'post', 'field_key' => 'field_title', 'id' => 7];

    protected function setUp(): void
    {
        parent::setUp();
        config(['siteagent.enabled' => true, 'siteagent.assistant.disabled_permissions' => [], 'siteagent.writing.min_words' => 300]);
        $this->site = Site::factory()->create([
            'domain' => 'acf.example', 'mcp_enabled' => true, 'mcp_secret' => 'site-secret',
            'mcp_endpoint' => 'https://acf.example/wp-json/md-agent/v1/mcp',
            'mcp_capabilities' => ['tools' => array_map(fn (string $name): array => ['name' => $name], [
                'wp_acf_get', 'wp_acf_schema', 'wp_acf_options_pages', 'wp_acf_prepare', 'wp_acf_update',
            ])],
        ]);
        $mcp = Mockery::mock(McpClient::class);
        $mcp->shouldReceive('callTool')->andReturnUsing(function (Site $site, string $tool, array $args = []): array {
            $this->assertSame($this->site->id, $site->id);
            $this->calls[] = [$tool, $args];
            if (! array_key_exists($tool, $this->answers)) {
                throw new RuntimeException('Unexpected tool: '.$tool);
            }
            $answer = $this->answers[$tool];

            return $answer instanceof Closure ? $answer($args) : $answer;
        });
        $mcp->shouldReceive('textContent')->andReturnUsing(fn (array $answer): string => $answer['_wire_text'] ?? json_encode($answer, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $this->app->instance(McpClient::class, $mcp);
        $this->answers['wp_acf_get'] = $this->record();
        $this->answers['wp_acf_prepare'] = $this->prepared();
    }

    private function seal(string $tag): array
    {
        return ['version' => hash('sha256', $tag), 'token' => base64_encode('opaque-encrypted-'.$tag)];
    }

    private function record(): array
    {
        return ['target' => $this->target, 'fields' => [['key' => 'field_title', 'label' => 'Project title', 'type' => 'text', 'editable' => true]],
            'values' => ['field_title' => 'Original'], 'snapshots' => ['field_title' => $this->seal('original')]];
    }

    private function prepared(): array
    {
        return ['target' => $this->target, 'field_key' => 'field_title', 'changed' => true,
            'before' => 'Original', 'after' => 'Requested', 'expected' => $this->seal('original'),
            'prepared' => $this->seal('proposal'), 'notes' => [], 'writing_text' => ''];
    }

    private function propose(array $input = [], ?array $seen = null): array
    {
        return app(SiteActionProposer::class)->propose($this->site, SiteAgentAcfActions::TOOL,
            $input + $this->selector + ['operations' => [['op' => 'set', 'path' => [], 'value' => 'Requested']]],
            $seen ?? ['acf:post:7:field_title']);
    }

    private function request(array $offer): SiteAgentRequest
    {
        $this->assertArrayHasKey('plan', $offer, json_encode($offer));

        return new SiteAgentRequest(['operation' => $offer['plan']['operation'], 'plan' => $offer['plan'], 'preview' => $offer['preview']]);
    }

    public function test_acf_reads_hide_snapshot_tokens_and_grant_only_matching_field_references(): void
    {
        $this->answers['wp_acf_get']['values']['field_title'] = ['related' => ['id' => 991]];
        $read = app(SiteAgentToolbox::class)->read($this->site, 'get_acf', $this->selector + ['expected' => $this->seal('forged')]);
        $this->assertFalse($read['is_error']);
        $this->assertSame(['acf:post:7:field_title'], $read['ids']);
        $this->assertSame(['wp_acf_get', $this->selector], $this->calls[0]);
        $this->assertStringNotContainsString($this->seal('original')['token'], $read['content']);
        $this->assertStringNotContainsString('snapshots', $read['content']);
        $this->assertStringNotContainsString('acf_id', $read['content']);
        $this->assertArrayHasKey('field_title', json_decode($read['content'], true)['values']);

        $this->answers['wp_acf_schema'] = ['target' => $this->target, 'fields' => $this->record()['fields']];
        $schema = app(SiteAgentToolbox::class)->read($this->site, 'acf_schema', $this->selector);
        $this->assertSame([], $schema['ids'], 'A schema lookup cannot replace reading the current field value.');
    }

    public function test_truncated_or_malformed_acf_reads_do_not_authorize_edits_or_leak_tokens(): void
    {
        config(['siteagent.assistant.tool_result_chars' => 1000]);
        $this->answers['wp_acf_get']['values']['field_title'] = str_repeat('Long repeater row ', 5000);
        $large = app(SiteAgentToolbox::class)->read($this->site, 'get_acf', $this->selector);
        $this->assertTrue($large['is_error']);
        $this->assertSame([], $large['ids']);
        $this->assertStringNotContainsString($this->seal('original')['token'], $large['content']);

        $this->answers['wp_acf_get'] = ['_wire_text' => '{"snapshots":{"field_title":{"token":"UNPARSEABLE-SECRET-TOKEN"}}'];
        $broken = app(SiteAgentToolbox::class)->read($this->site, 'get_acf', $this->selector);
        $this->assertTrue($broken['is_error']);
        $this->assertSame([], $broken['ids']);
        $this->assertStringNotContainsString('UNPARSEABLE-SECRET-TOKEN', $broken['content']);
    }

    public function test_options_context_is_scoped_to_its_registered_page_and_hides_storage_ids(): void
    {
        $target = ['context' => 'options', 'id' => null, 'options_page' => 'brand-settings', 'acf_id' => 'private_storage', 'label' => 'Brand settings'];
        $this->answers['wp_acf_get']['target'] = $target;
        $this->answers['wp_acf_prepare']['target'] = $target;
        $input = ['context' => 'options', 'field_key' => 'field_title', 'options_page' => 'brand-settings'];
        $read = app(SiteAgentToolbox::class)->read($this->site, 'get_acf', $input + ['acf_id' => 'forged_storage']);
        $this->assertSame(['acf:options:brand-settings:field_title'], $read['ids']);
        $this->assertStringNotContainsString('private_storage', $read['content']);
        $this->assertArrayHasKey('error', $this->propose($input, ['acf:options:other-settings:field_title']));
        $offer = $this->propose($input, $read['ids']);
        $this->assertArrayHasKey('plan', $offer, json_encode($offer));
        $this->assertSame($input, $offer['plan']['arguments']);
        $this->assertArrayNotHasKey('id', $offer['plan']['arguments']);
        $this->assertArrayNotHasKey('acf_id', $offer['plan']['arguments']);

        $this->answers['wp_acf_options_pages'] = ['pages' => [['options_page' => 'brand-settings', 'label' => 'Brand settings', 'acf_id' => 'private_storage']]];
        $pages = app(SiteAgentToolbox::class)->read($this->site, 'list_acf_options', []);
        $this->assertSame([], $pages['ids']);
        $this->assertStringNotContainsString('private_storage', $pages['content']);
    }

    public function test_generic_ids_other_contexts_items_and_fields_do_not_authorize_a_proposal(): void
    {
        foreach ([[], [7], ['acf:user:7:field_title'], ['acf:post:8:field_title'], ['acf:post:7:field_body']] as $seen) {
            $this->assertArrayHasKey('error', $this->propose([], $seen));
        }
        $this->assertSame([], $this->calls);
    }

    public function test_preparation_uses_a_fresh_snapshot_ignores_forged_tokens_and_never_writes(): void
    {
        $read = app(SiteAgentToolbox::class)->read($this->site, 'get_acf', $this->selector);
        $this->answers['wp_acf_get']['snapshots']['field_title'] = $this->seal('dashboard-change');
        $this->answers['wp_acf_get']['values']['field_title'] = 'Changed in dashboard';
        $this->answers['wp_acf_prepare']['expected'] = $this->seal('dashboard-change');
        $this->answers['wp_acf_prepare']['before'] = 'Changed in dashboard';

        $offer = $this->propose(['expected' => $this->seal('forged'), 'prepared' => $this->seal('forged'), 'label' => 'Invented'], $read['ids']);
        $this->assertArrayHasKey('plan', $offer);
        $this->assertSame($this->seal('dashboard-change'), $offer['plan']['expected']);
        $this->assertStringContainsString('Changed in dashboard', $offer['preview']);
        $this->assertStringContainsString('Live project', $offer['preview']);
        $this->assertStringNotContainsString('Invented', $offer['preview']);
        $this->assertSame(['wp_acf_get', 'wp_acf_get', 'wp_acf_prepare'], array_column($this->calls, 0));
        $this->assertSame($this->seal('dashboard-change'), $this->calls[2][1]['expected']);
        $this->assertArrayNotHasKey('operations', $offer['plan']);
    }

    public function test_password_proposals_store_only_opaque_tokens_and_redacted_consent(): void
    {
        $password = 'A-private-password-that-must-not-be-persisted';
        $this->answers['wp_acf_get']['fields'][0]['type'] = 'password';
        $this->answers['wp_acf_get']['values']['field_title'] = '[מוסתר]';
        $this->answers['wp_acf_prepare']['before'] = ['redacted' => true, 'has_value' => true];
        $this->answers['wp_acf_prepare']['after'] = ['redacted' => true, 'has_value' => true];
        $offer = $this->propose(['operations' => [['op' => 'set', 'path' => [], 'value' => $password]]]);
        $this->assertArrayHasKey('plan', $offer);
        $this->assertStringNotContainsString($password, json_encode($offer));
        $this->assertStringContainsString('מוסתר', $offer['preview']);
        $this->assertSame(0, SiteAgentUsageMeter::writingWords($offer['plan']));
        $this->assertSame($password, end($this->calls)[1]['operations'][0]['value']);
    }

    public function test_apply_and_undo_use_native_sealed_snapshots_in_the_correct_direction(): void
    {
        $request = $this->request($this->propose());
        $this->answers['wp_acf_update'] = ['changed' => true, 'before' => $this->seal('original'), 'after' => $this->seal('applied')];
        $applier = app(SiteActionApplier::class);
        $applied = $applier->apply($this->site, $request);
        $this->assertTrue($applied['ok'], json_encode($applied));
        $this->assertSame(['wp_acf_update', $this->selector + ['expected' => $this->seal('original'), 'prepared' => $this->seal('proposal')]], end($this->calls));
        $this->assertTrue($applier->reverts('acf'));
        $this->assertSame('acf', $applied['restore']['kind']);
        $this->assertTrue($applier->revert($this->site, $applied['restore'])['ok']);
        $this->assertSame(['wp_acf_update', $this->selector + ['expected' => $this->seal('applied'), 'restore' => $this->seal('original')]], end($this->calls));
    }

    public function test_disabling_structure_refuses_new_proposals_already_approved_changes_and_undo(): void
    {
        $request = $this->request($this->propose());
        $restore = ['kind' => 'acf', 'arguments' => $this->selector, 'before' => $this->seal('original'), 'after' => $this->seal('applied')];
        config(['siteagent.assistant.disabled_permissions' => ['structure']]);
        $this->calls = [];
        $this->assertFalse(app(SiteAgentPermissions::class)->allowsTool(SiteAgentAcfActions::TOOL));
        $this->assertArrayHasKey('error', $this->propose());
        $this->assertFalse(app(SiteActionApplier::class)->apply($this->site, $request)['ok']);
        $this->assertFalse(app(SiteActionApplier::class)->revert($this->site, $restore)['ok']);
        $this->assertSame([], $this->calls);
    }

    public function test_old_site_capabilities_hide_acf_and_refuse_direct_proposals(): void
    {
        $this->site->mcp_capabilities = ['tools' => [['name' => 'wp_fields_get'], ['name' => 'wp_fields_update']]];
        $this->assertNotContains('get_acf', array_column(app(SiteAgentToolbox::class)->definitions($this->site), 'name'));
        $this->assertNotContains(SiteAgentAcfActions::TOOL, array_column(app(SiteActionProposer::class)->definitions($this->site), 'name'));
        $this->assertArrayHasKey('error', $this->propose());
        $this->assertSame([], $this->calls);
    }

    public function test_a_missing_current_capability_refuses_apply_without_a_remote_call(): void
    {
        $request = $this->request($this->propose());
        $this->site->mcp_capabilities = ['tools' => [['name' => 'wp_acf_get']]];
        $this->calls = [];
        $this->assertFalse(app(SiteActionApplier::class)->apply($this->site, $request)['ok']);
        $this->assertSame([], $this->calls);
    }

    public function test_malformed_or_mismatched_live_reads_are_refused_before_preparing(): void
    {
        foreach ([
            ['fields' => []], ['snapshots' => []], ['snapshots' => ['field_title' => ['version' => 'version']]],
            ['target' => ['context' => 'user', 'id' => 7]], ['target' => ['context' => 'post', 'id' => 8]],
        ] as $corruption) {
            $this->answers['wp_acf_get'] = array_replace($this->record(), $corruption);
            $this->calls = [];
            $this->assertArrayHasKey('error', $this->propose());
            $this->assertNotContains('wp_acf_prepare', array_column($this->calls, 0));
        }
    }

    public function test_malformed_or_mismatched_prepared_responses_never_create_an_offer(): void
    {
        foreach ([
            ['prepared' => []], ['expected' => []], ['expected' => $this->seal('different-snapshot')],
            ['target' => ['context' => 'post', 'id' => 999]], ['field_key' => 'field_wrong'], ['changed' => false],
        ] as $corruption) {
            $this->answers['wp_acf_prepare'] = array_replace($this->prepared(), $corruption);
            $this->assertArrayHasKey('error', $this->propose(), json_encode($corruption));
        }
        $this->assertNotContains('wp_acf_update', array_column($this->calls, 0));
    }

    public function test_stale_native_changes_and_malformed_write_results_do_not_claim_success(): void
    {
        $request = $this->request($this->propose());
        $this->answers['wp_acf_update'] = function (): array {
            throw new RuntimeException('ACF changed since approval');
        };
        $failed = app(SiteActionApplier::class)->apply($this->site, $request);
        $this->assertFalse($failed['ok']);
        $this->assertNull($failed['restore']);
        $this->assertFalse(app(SiteActionApplier::class)->revert($this->site, ['kind' => 'acf', 'arguments' => $this->selector, 'before' => $this->seal('original'), 'after' => $this->seal('applied')])['ok']);

        foreach ([['changed' => false], ['changed' => true], ['changed' => true, 'before' => $this->seal('original'), 'after' => ['token' => 'invalid']]] as $result) {
            $this->answers['wp_acf_update'] = $result;
            $this->assertFalse(app(SiteActionApplier::class)->apply($this->site, $request)['ok']);
        }
    }

    public function test_large_changes_require_a_complete_preview_and_are_never_silently_truncated(): void
    {
        $this->answers['wp_acf_prepare']['after'] = str_repeat('שינוי ', 1000);
        $this->assertArrayHasKey('error', $this->propose());
        $this->assertNotContains('wp_acf_update', array_column($this->calls, 0));
    }

    public function test_only_provider_identified_new_writing_is_counted_not_the_encrypted_payload(): void
    {
        $writing = implode(' ', array_map(fn (int $i): string => 'מילה'.$i, range(1, 310)));
        $this->answers['wp_acf_prepare']['writing_text'] = $writing;
        $this->answers['wp_acf_prepare']['after'] = $writing;
        $offer = $this->propose();
        $this->assertArrayHasKey('plan', $offer);
        $this->assertSame(310, SiteAgentUsageMeter::writingWords($offer['plan']));
        $request = $this->request($offer);
        $request->message = 'כתוב תוכן חדש לשדה';
        $this->assertSame(310, SiteAgentUsageMeter::writtenByUs($request));
        $request->message = $writing;
        $this->assertSame(0, SiteAgentUsageMeter::writtenByUs($request), 'Owner supplied text is not generated writing.');
        $this->answers['wp_acf_prepare']['writing_text'] = '';
        $this->assertSame(0, SiteAgentUsageMeter::writingWords($this->propose()['plan']), 'A rearranged existing paragraph is not new writing.');
    }
}
