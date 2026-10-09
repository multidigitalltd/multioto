<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\SiteAgentRequest;
use App\Services\Agent\McpClient;
use App\Services\SiteAgent\SiteActionProposer;
use App\Services\SiteAgent\SiteAgentExtendedCatalogue;
use App\Services\SiteAgent\SiteAgentPermissions;
use App\Services\SiteAgent\SiteAgentToolbox;
use App\Services\SiteAgent\SiteChangeApplier;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/** Exercise the public proposer/applier contract, including exact MCP arguments. */
class SiteAgentExtendedActionsTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private array $answers = [];

    private array $calls = [];

    private array $live = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['siteagent.enabled' => true, 'siteagent.assistant.disabled_permissions' => []]);
        $this->site = Site::factory()->create([
            'domain' => 'customer.example', 'mcp_enabled' => true,
            'mcp_endpoint' => 'https://customer.example/wp-json/md-agent/v1/mcp', 'mcp_secret' => 'test',
            'mcp_capabilities' => ['tools' => array_map(fn (string $tool): array => ['name' => $tool], array_values(array_unique([
                ...array_column(SiteAgentExtendedCatalogue::reads(), 0),
                ...array_column(SiteAgentExtendedCatalogue::actions(), 'write'),
                'wp_theme_list', 'wp_content_get',
            ])))],
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
        $mcp->shouldReceive('textContent')->andReturnUsing(fn (array $answer): string => json_encode($answer, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $this->app->instance(McpClient::class, $mcp);
        $this->answers['jet_cct_types'] = $this->cctTypes();
    }

    public function test_cct_read_references_are_scoped_to_type_and_never_become_wordpress_ids(): void
    {
        $this->answers['jet_cct_list'] = ['items' => [
            ['type' => 'houses', 'id' => 7, 'values' => ['related_id' => 99]],
            ['type' => 'cars', 'id' => 7, 'values' => []],
        ]];
        $read = app(SiteAgentToolbox::class)->read($this->site, 'find_cct', ['type' => 'houses', 'limit' => 10, 'values' => ['title' => 'Ignored']]);

        $this->assertSame(['cct:houses:7'], $read['ids']);
        $this->assertSame(['jet_cct_list', ['type' => 'houses', 'limit' => 10]], $this->calls[0]);
        $types = app(SiteAgentToolbox::class)->read($this->site, 'list_cct_types', []);
        $this->assertSame(['cct-type:houses'], $types['ids']);

        foreach ([[7], ['cct:cars:7'], ['cct-type:houses']] as $seen) {
            $this->assertArrayHasKey('error', $this->propose('propose_cct_update', ['type' => 'houses', 'id' => 7, 'values' => ['title' => 'New']], $seen));
        }
        $this->assertNotContains('jet_cct_get', array_column($this->calls, 0));
        $this->assertNotContains('jet_cct_update', array_column($this->calls, 0));
    }

    public function test_cct_preview_uses_a_fresh_read_and_proposals_cannot_mutate_the_site(): void
    {
        $this->editor('jet_cct_get', 'jet_cct_update', ['id' => 7, 'type' => 'houses', 'label' => 'Live house', 'values' => ['title' => 'Original', 'price' => 10, 'cct_status' => 'publish']]);
        $seen = app(SiteAgentToolbox::class)->read($this->site, 'get_cct', ['type' => 'houses', 'id' => 7])['ids'];
        $this->live['jet_cct_update']['values']['title'] = 'Changed in dashboard';

        $offer = $this->propose('propose_cct_update', ['type' => 'houses', 'id' => 7, 'values' => ['title' => 'Requested'], 'expected' => ['title' => 'Invented'], 'label' => 'Invented label'], $seen);
        $this->assertArrayHasKey('plan', $offer);
        $this->assertSame(['title' => 'Changed in dashboard'], $offer['plan']['expected']);
        $this->assertStringContainsString('Changed in dashboard', $offer['preview']);
        $this->assertStringContainsString('Live house', $offer['preview']);
        $this->assertStringNotContainsString('Invented', $offer['preview']);
        $this->assertNotContains('jet_cct_update', array_column($this->calls, 0));

        $request = $this->request($offer);
        $applied = app(SiteChangeApplier::class)->apply($request);
        $this->assertTrue($applied['ok'], json_encode($applied));
        $this->assertSame(['title' => 'Changed in dashboard'], $applied['restore']['before']);
        $this->assertSame(['title' => 'Requested'], $applied['restore']['after']);
        $this->assertSame('Requested', $this->live['jet_cct_update']['values']['title']);

        $request->restore = $applied['restore'];
        $this->assertTrue(app(SiteChangeApplier::class)->revert($request)['ok']);
        $this->assertSame('Changed in dashboard', $this->live['jet_cct_update']['values']['title']);
    }

    public function test_stale_apply_and_stale_undo_preserve_external_changes(): void
    {
        $this->editor('wp_media_get', 'wp_media_update', ['id' => 7, 'label' => 'Picture', 'values' => ['title' => 'Original']]);
        $offer = $this->propose('propose_media_update', ['id' => 7, 'values' => ['title' => 'Requested']], [7]);
        $request = $this->request($offer);
        $this->live['wp_media_update']['values']['title'] = 'External edit';
        $this->assertFalse(app(SiteChangeApplier::class)->apply($request)['ok']);
        $this->assertSame('External edit', $this->live['wp_media_update']['values']['title']);

        $offer = $this->propose('propose_media_update', ['id' => 7, 'values' => ['title' => 'Requested']], [7]);
        $request = $this->request($offer);
        $applied = app(SiteChangeApplier::class)->apply($request);
        $this->assertTrue($applied['ok']);
        $request->restore = $applied['restore'];
        $this->live['wp_media_update']['values']['title'] = 'Later external edit';
        $this->assertFalse(app(SiteChangeApplier::class)->revert($request)['ok']);
        $this->assertSame('Later external edit', $this->live['wp_media_update']['values']['title']);
    }

    public function test_cct_schema_protected_and_complex_fields_are_never_proposed(): void
    {
        $this->editor('jet_cct_get', 'jet_cct_update', ['id' => 7, 'type' => 'houses', 'values' => ['title' => 'Old', 'private_token' => 'hidden', 'gallery' => []]]);
        foreach ([['private_token' => 'replacement'], ['unknown' => 'bad'], ['gallery' => [1, 2]], ['cct_status' => 'trash']] as $values) {
            $this->assertArrayHasKey('error', $this->propose('propose_cct_update', ['type' => 'houses', 'id' => 7, 'values' => $values], ['cct:houses:7']));
        }
        $this->assertNotContains('jet_cct_update', array_column($this->calls, 0));
    }

    #[DataProvider('cctSwitcherProvider')]
    public function test_cct_switcher_aliases_are_normalized_before_preview_and_creation(mixed $value, bool $expected): void
    {
        $this->answers['jet_cct_create'] = function (array $args) use ($expected): array {
            $this->assertSame($expected, $args['values']['available']);
            $this->assertSame(0, $args['values']['price']);

            return ['id' => 12, 'type' => 'houses', 'values' => $args['values'], 'created' => true, 'changed' => true];
        };
        $offer = $this->propose('propose_cct_create', ['type' => 'houses', 'values' => [
            'title' => 'Test house', 'price' => 0, 'available' => $value,
        ]], ['cct-type:houses']);
        $this->assertArrayHasKey('plan', $offer, json_encode($offer));
        $this->assertSame($expected, $offer['plan']['values']['available']);
        $this->assertStringContainsString('available: '.($expected ? 'כן' : 'לא'), $offer['preview']);
        $this->assertNotContains('jet_cct_create', array_column($this->calls, 0));
        $result = app(SiteChangeApplier::class)->apply($this->request($offer));
        $this->assertTrue($result['ok'], json_encode($result));
    }

    public static function cctSwitcherProvider(): array
    {
        return [[true, true], [false, false], [1, true], [0, false], ['1', true], ['0', false], ['true', true], ['false', false]];
    }

    public function test_cct_switcher_update_preserves_native_string_storage_for_noop_and_undo(): void
    {
        $this->editor('jet_cct_get', 'jet_cct_update', ['id' => 7, 'type' => 'houses', 'values' => ['available' => 'true']]);
        $noop = $this->propose('propose_cct_update', ['type' => 'houses', 'id' => 7, 'values' => ['available' => 1]], ['cct:houses:7']);
        $this->assertArrayHasKey('error', $noop);
        $this->assertStringContainsString('אין מה לשנות', $noop['error']);
        $offer = $this->propose('propose_cct_update', ['type' => 'houses', 'id' => 7, 'values' => ['available' => 0]], ['cct:houses:7']);
        $this->assertArrayHasKey('plan', $offer, json_encode($offer));
        $this->assertSame(['available' => 'true'], $offer['plan']['expected']);
        $this->assertSame(['available' => 'false'], $offer['plan']['values']);
        $request = $this->request($offer);
        $result = app(SiteChangeApplier::class)->apply($request);
        $this->assertTrue($result['ok']);
        $this->assertSame('false', $this->live['jet_cct_update']['values']['available']);
        $request->restore = $result['restore'];
        $this->assertTrue(app(SiteChangeApplier::class)->revert($request)['ok']);
        $this->assertSame('true', $this->live['jet_cct_update']['values']['available']);
    }

    public function test_cct_live_schema_rejects_invalid_field_values_before_consent(): void
    {
        $this->answers['jet_cct_types']['types'][0]['fields'] = [
            ['key' => 'title', 'type' => 'text', 'writable' => true, 'required' => true],
            ['key' => 'price', 'type' => 'number', 'writable' => true, 'min' => 0, 'max' => 500],
            ['key' => 'available', 'type' => 'switcher', 'writable' => true],
            ['key' => 'kind', 'type' => 'select', 'writable' => true, 'choices' => ['home', 'office']],
            ['key' => 'date', 'type' => 'date', 'writable' => true],
            ['key' => 'color', 'type' => 'colorpicker', 'writable' => true],
        ];
        foreach ([['title' => ''], ['title' => 123], ['price' => -1], ['price' => 501], ['price' => true], ['price' => '1e9999'],
            ['available' => 'anything'], ['available' => 2], ['kind' => 'warehouse'], ['date' => '2026-02-30'], ['color' => 'red']] as $values) {
            $offer = $this->propose('propose_cct_create', ['type' => 'houses', 'values' => $values + ['title' => 'Test']], ['cct-type:houses']);
            $this->assertArrayHasKey('error', $offer, json_encode($values));
        }
        $this->assertNotContains('jet_cct_create', array_column($this->calls, 0));
        $offer = $this->propose('propose_cct_create', ['type' => 'houses', 'values' => [
            'title' => 'Test', 'price' => 0, 'available' => false, 'kind' => 'home', 'date' => '2026-02-28', 'color' => '#fF0011',
        ]], ['cct-type:houses']);
        $this->assertArrayHasKey('plan', $offer, json_encode($offer));
    }

    public function test_sensitive_fields_and_free_html_replacement_are_not_exposed_through_bounded_editors(): void
    {
        foreach ([
            ['propose_site_settings', [], ['siteurl' => 'https://attacker.example']],
            ['propose_user_profile', ['id' => 7], ['user_pass' => 'replacement']],
            ['propose_media_update', ['id' => 7], ['file' => '/tmp/payload.php']],
            ['propose_content_manage', ['id' => 7], ['content' => '<script>bad()</script>']],
            ['propose_internal_link', ['id' => 7], ['content' => '<p>Replacement</p>']],
        ] as [$action, $identity, $values]) {
            $this->assertArrayHasKey('error', $this->propose($action, $identity + ['values' => $values], [7]));
        }
        $this->assertSame([], $this->calls);
    }

    public function test_seo_provider_and_expected_values_come_from_the_site_not_model_input(): void
    {
        $this->editor('wp_seo_get', 'wp_seo_update', ['id' => 7, 'label' => 'Home', 'provider' => 'yoast', 'values' => ['title' => null, 'description' => 'Current']]);
        $offer = $this->propose('propose_seo_update', ['id' => 7, 'provider' => 'rank_math', 'expected' => ['description' => 'Invented'], 'values' => ['description' => 'New description']], [7]);
        $this->assertArrayHasKey('plan', $offer);
        $this->assertSame(['id' => 7, 'provider' => 'yoast'], $offer['plan']['arguments']);
        $this->assertSame(['description' => 'Current'], $offer['plan']['expected']);
        $this->assertSame(['wp_seo_get', ['id' => 7]], $this->calls[0]);
        $applied = app(SiteChangeApplier::class)->apply($this->request($offer));
        $this->assertTrue($applied['ok']);
        $this->assertSame('yoast', $this->calls[1][1]['provider']);
    }

    #[DataProvider('reversibleCases')]
    public function test_media_seo_settings_profiles_and_theme_changes_round_trip_through_the_public_applier(string $action, string $read, string $write, array $identity, array $record, array $values, array $seen): void
    {
        $this->editor($read, $write, $record);
        $offer = $this->propose($action, $identity + ['values' => $values], $seen);
        $this->assertArrayHasKey('plan', $offer, json_encode($offer));
        $this->assertNotContains($write, array_column($this->calls, 0));
        $request = $this->request($offer);
        $applied = app(SiteChangeApplier::class)->apply($request);
        $this->assertTrue($applied['ok'], json_encode($applied));
        $this->assertSame(array_intersect_key($record['values'], $values), $applied['restore']['before']);
        $this->assertSame($values, $applied['restore']['after']);
        $request->restore = $applied['restore'];
        $this->assertTrue(app(SiteChangeApplier::class)->revert($request)['ok']);
        $this->assertSame($record['values'], $this->live[$write]['values']);
        $last = end($this->calls);
        $this->assertSame($values, $last[1]['expected']);
        $this->assertSame($applied['restore']['before'], $last[1]['values']);
    }

    public static function reversibleCases(): array
    {
        return [
            'media metadata' => ['propose_media_update', 'wp_media_get', 'wp_media_update', ['id' => 7], ['id' => 7, 'label' => 'Picture', 'values' => ['title' => 'Old', 'alt' => 'Unchanged']], ['title' => 'New'], [7]],
            'SEO defaults' => ['propose_seo_update', 'wp_seo_get', 'wp_seo_update', ['id' => 7], ['id' => 7, 'provider' => 'yoast', 'label' => 'Home', 'values' => ['title' => null, 'description' => 'Old']], ['description' => null], [7]],
            'settings' => ['propose_site_settings', 'wp_site_settings_get', 'wp_site_settings_update', [], ['label' => 'Site settings', 'values' => ['blogname' => 'Old', 'blogdescription' => 'Unchanged']], ['blogname' => 'New'], []],
            'profile' => ['propose_user_profile', 'wp_user_profile_get', 'wp_user_profile_update', ['id' => 7], ['id' => 7, 'label' => 'Editor', 'values' => ['first_name' => 'Old', 'last_name' => 'Unchanged']], ['first_name' => 'New'], [7]],
            'theme' => ['propose_theme_switch', 'wp_theme_active_get', 'wp_theme_active_set', [], ['id' => 'old-theme', 'label' => 'Old theme', 'values' => ['stylesheet' => 'old-theme']], ['stylesheet' => 'new-theme'], ['theme:new-theme']],
        ];
    }

    public function test_theme_choices_must_come_from_the_current_site_list(): void
    {
        $this->answers['wp_theme_list'] = [['stylesheet' => 'old-theme', 'name' => 'Old'], ['stylesheet' => 'new-theme', 'name' => 'New']];
        $this->editor('wp_theme_active_get', 'wp_theme_active_set', ['label' => 'Old', 'values' => ['stylesheet' => 'old-theme']]);
        $seen = app(SiteAgentToolbox::class)->read($this->site, 'list_themes', [])['ids'];
        $this->assertContains('theme:new-theme', $seen);
        $this->assertArrayHasKey('error', $this->propose('propose_theme_switch', ['values' => ['stylesheet' => 'unknown-theme']], $seen));
        $this->assertArrayHasKey('plan', $this->propose('propose_theme_switch', ['values' => ['stylesheet' => 'new-theme']], $seen));
        $this->assertNotContains('wp_theme_active_set', array_column($this->calls, 0));
    }

    public function test_optimole_requires_a_live_connection_and_editable_settings(): void
    {
        $this->editor('wp_optimole_get', 'wp_optimole_update', ['id' => 1, 'label' => 'Optimole', 'active' => true, 'connected' => false, 'editable_fields' => ['quality'], 'values' => ['quality' => 80, 'lazyload' => 'enabled']]);
        $this->assertArrayHasKey('error', $this->propose('propose_optimole_update', ['values' => ['quality' => 90]], []));
        $this->live['wp_optimole_update']['connected'] = true;
        $this->assertArrayHasKey('error', $this->propose('propose_optimole_update', ['values' => ['lazyload' => 'disabled']], []));
        $offer = $this->propose('propose_optimole_update', ['values' => ['quality' => 90]], []);
        $this->assertArrayHasKey('plan', $offer);
        $applied = app(SiteChangeApplier::class)->apply($this->request($offer));
        $this->assertTrue($applied['ok']);
        $this->assertSame(1, end($this->calls)[1]['id']);
    }

    public function test_cct_draft_creation_is_retained_and_published_creation_undo_only_reverts_publication(): void
    {
        foreach (['draft', 'publish'] as $status) {
            $this->answers['jet_cct_create'] = function (array $args): array {
                $record = ['id' => 12, 'type' => $args['type'], 'label' => 'Created house', 'values' => $args['values']];
                $this->editor('jet_cct_get', 'jet_cct_update', $record);

                return $record + ['changed' => true, 'created' => true, 'before' => [], 'after' => $args['values']];
            };
            $input = ['type' => 'houses', 'values' => ['title' => 'New house']];
            if ($status === 'publish') {
                $input['values']['cct_status'] = $status;
            }
            $offer = $this->propose('propose_cct_create', $input, ['cct-type:houses']);
            $this->assertArrayHasKey('plan', $offer);
            $this->assertSame($status, $offer['plan']['values']['cct_status']);
            $this->assertStringContainsString('טיוטה', $offer['preview']);
            $request = $this->request($offer);
            $applied = app(SiteChangeApplier::class)->apply($request);
            $this->assertTrue($applied['ok'], json_encode($applied));
            if ($status === 'draft') {
                $this->assertNull($applied['restore']);
                $this->assertSame('draft', $this->live['jet_cct_update']['values']['cct_status']);
            } else {
                $request->restore = $applied['restore'];
                $this->assertTrue(app(SiteChangeApplier::class)->revert($request)['ok']);
                $this->assertSame(['title' => 'New house', 'cct_status' => 'draft'], $this->live['jet_cct_update']['values']);
                $this->assertSame(12, $this->live['jet_cct_update']['id']);
            }
        }
        $this->assertSame([], array_values(array_filter(array_column($this->calls, 0), fn (string $tool): bool => str_contains($tool, 'delete'))));
    }

    public function test_created_cct_changed_after_publication_is_not_unpublished_by_undo(): void
    {
        $this->editor('jet_cct_get', 'jet_cct_update', ['id' => 12, 'type' => 'houses', 'values' => ['title' => 'Edited later', 'cct_status' => 'publish']]);
        $request = new SiteAgentRequest(['operation' => SiteAgentRequest::OP_CCT_CREATE, 'restore' => ['kind' => 'extended_cct_created', 'type' => 'houses', 'id' => 12, 'after' => ['title' => 'Original', 'cct_status' => 'publish']]]);
        $request->setRelation('site', $this->site);
        $this->assertFalse(app(SiteChangeApplier::class)->revert($request)['ok']);
        $this->assertNotContains('jet_cct_update', array_column($this->calls, 0));
    }

    public function test_permissions_are_checked_again_when_an_already_prepared_offer_is_applied(): void
    {
        $this->editor('wp_user_profile_get', 'wp_user_profile_update', ['id' => 7, 'label' => 'Editor', 'values' => ['display_name' => 'Old']]);
        $input = ['id' => 7, 'values' => ['display_name' => 'New']];
        $offer = $this->propose('propose_user_profile', $input, [7]);
        $this->assertArrayHasKey('plan', $offer);
        config(['siteagent.assistant.disabled_permissions' => ['users']]);
        $this->assertArrayHasKey('error', $this->propose('propose_user_profile', $input, [7]));
        $this->assertFalse(app(SiteChangeApplier::class)->apply($this->request($offer))['ok']);
        $this->assertNotContains('wp_user_profile_update', array_column($this->calls, 0));

        foreach (SiteAgentExtendedCatalogue::actions() as $name => $spec) {
            $groups = array_filter(SiteAgentPermissions::GROUPS, fn (array $group): bool => in_array($name, $group[2], true) && in_array($spec['operation'], $group[1], true));
            $this->assertNotEmpty($groups, $name.' must belong to an enforceable permission group.');
        }
    }

    public function test_older_site_capabilities_hide_new_tools_and_refuse_direct_proposals(): void
    {
        $this->site->mcp_capabilities = ['server' => ['version' => '1.8.5'], 'tools' => [['name' => 'wp_content_get']]];
        $readNames = array_column(app(SiteAgentToolbox::class)->definitions($this->site), 'name');
        $proposalNames = array_column(app(SiteActionProposer::class)->definitions($this->site), 'name');
        $this->assertNotContains('get_media_details', $readNames);
        $this->assertNotContains('propose_media_update', $proposalNames);
        $this->editor('wp_media_get', 'wp_media_update', ['id' => 7, 'label' => 'Picture', 'values' => ['title' => 'Old']]);
        $this->assertArrayHasKey('error', $this->propose('propose_media_update', ['id' => 7, 'values' => ['title' => 'New']], [7]));
        $this->assertSame([], $this->calls);
    }

    public function test_internal_link_target_must_be_observed_and_content_snapshot_comes_from_live_page(): void
    {
        $this->answers['wp_internal_links_get'] = ['id' => 7, 'label' => 'Home', 'values' => ['content' => '<p>Learn more here</p>']];
        $this->answers['wp_content_get'] = ['id' => 8, 'title' => 'More information'];
        $input = ['id' => 7, 'values' => ['text' => 'Learn more', 'target_id' => 8], 'expected' => ['content' => 'Invented']];
        $this->assertArrayHasKey('error', $this->propose('propose_internal_link', $input, [7]));
        $offer = $this->propose('propose_internal_link', $input, [7, 8]);
        $this->assertArrayHasKey('plan', $offer);
        $this->assertSame(['content' => '<p>Learn more here</p>'], $offer['plan']['expected']);
        $this->assertStringContainsString('More information', $offer['preview']);
        $this->assertNotContains('wp_internal_link_update', array_column($this->calls, 0));
    }

    private function propose(string $name, array $input, array $seen): array
    {
        return app(SiteActionProposer::class)->propose($this->site, $name, $input, $seen);
    }

    private function request(array $offer): SiteAgentRequest
    {
        $this->assertArrayHasKey('plan', $offer, json_encode($offer));
        $request = new SiteAgentRequest(['operation' => $offer['plan']['operation'], 'plan' => $offer['plan'], 'preview' => $offer['preview']]);
        $request->setRelation('site', $this->site);

        return $request;
    }

    /** Fake remote plugin: strict expected values, real state, real before/after snapshots. */
    private function editor(string $read, string $write, array $record): void
    {
        $this->live[$write] = $record;
        $this->answers[$read] = fn (): array => $this->live[$write];
        $this->answers[$write] = function (array $args) use ($write): array {
            $record = $this->live[$write];
            foreach ($args['expected'] ?? [] as $field => $expected) {
                if (! array_key_exists($field, $record['values']) || $record['values'][$field] !== $expected) {
                    throw new RuntimeException(SiteChangeApplier::STALE);
                }
            }
            $before = array_intersect_key($record['values'], $args['values']);
            $this->live[$write]['values'] = array_replace($record['values'], $args['values']);

            return $this->live[$write] + ['before' => $before, 'after' => $args['values'], 'changed' => $before !== $args['values']];
        };
    }

    private function cctTypes(): array
    {
        return ['available' => true, 'types' => [['type' => 'houses', 'label' => 'Houses', 'writable' => true, 'create_supported' => true, 'fields' => [
            ['key' => 'title', 'label' => 'Title', 'type' => 'text', 'writable' => true, 'required' => true],
            ['key' => 'price', 'label' => 'Price', 'type' => 'number', 'writable' => true, 'required' => false],
            ['key' => 'available', 'label' => 'Available', 'type' => 'switcher', 'writable' => true, 'required' => false],
            ['key' => 'private_token', 'type' => 'text', 'writable' => false, 'required' => false],
            ['key' => 'gallery', 'type' => 'repeater', 'writable' => false, 'required' => false],
        ]]]];
    }
}
