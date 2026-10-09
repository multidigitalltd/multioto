<?php

namespace Tests\Unit;

use Jet_Engine\Modules\Custom_Content_Types\Module;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class JetEngineCctAdapterTest extends TestCase
{
    private \CctContractFactory $factory;

    private \CctContractStore $store;

    protected function setUp(): void
    {
        require __DIR__.'/../Support/wordpress-cct-stubs.php';
        require __DIR__.'/../../wordpress-plugin/multioto-agent/includes/class-cct.php';

        $this->store = new \CctContractStore;
        $this->store->items[7] = ['_ID' => 7, 'title' => 'Home', 'price' => '250', 'notes' => 'Original',
            'cct_status' => 'publish', 'api_token' => 'never-return-this', 'cct_author_id' => 1, 'gallery' => ['secret.png']];
        $this->factory = new \CctContractFactory($this->store);
        $manager = new class($this->factory)
        {
            public function __construct(private $factory) {}

            public function get_content_types($slug = null)
            {
                return $slug === null ? ['properties' => $this->factory] : ($slug === 'properties' ? $this->factory : false);
            }
        };
        Module::$module = (object) ['manager' => $manager];
        $GLOBALS['cct_contract_engine'] = (object) ['modules' => new class
        {
            public bool $active = true;

            public function is_module_active($slug): bool
            {
                return $this->active && $slug === 'custom-content-types';
            }
        }];
    }

    public function test_discovery_reports_schema_and_unsupported_fields_without_exposing_secret_fields(): void
    {
        $response = \Multioto_Agent_Cct::call('jet_cct_types', []);

        $this->assertTrue($response['available']);
        $type = $response['types'][0];
        $this->assertSame('properties', $type['type']);
        $fields = array_column($type['fields'], null, 'key');
        $this->assertTrue($fields['title']['writable']);
        $this->assertFalse($fields['gallery']['writable']);
        $this->assertSame(['publish', 'draft'], $fields['cct_status']['choices']);
        $this->assertArrayNotHasKey('api_token', $fields);
        $this->assertArrayNotHasKey('cct_author_id', $fields);
        $this->assertFalse($response['deletion_supported']);
    }

    public function test_module_inactive_is_reported_and_reads_fail_closed(): void
    {
        $GLOBALS['cct_contract_engine']->modules->active = false;
        $this->assertSame(['available' => false, 'types' => [], 'deletion_supported' => false], \Multioto_Agent_Cct::call('jet_cct_types', []));
        $this->expectException(\Multioto_Agent_Rpc_Error::class);
        \Multioto_Agent_Cct::call('jet_cct_get', ['type' => 'properties', 'id' => 7]);
    }

    public function test_discovery_exposes_registered_numeric_bounds_used_by_the_native_setter(): void
    {
        $fields = array_column(\Multioto_Agent_Cct::call('jet_cct_types', [])['types'][0]['fields'], null, 'key');
        $this->assertSame(0, $fields['price']['min']);
        $this->assertSame(999999999, $fields['price']['max']);
        $this->assertArrayNotHasKey('min', $fields['title']);
        $this->assertArrayNotHasKey('max', $fields['active']);

        foreach ([-1, 1000000000] as $price) {
            try {
                $this->update(['price' => $price], ['price' => '250']);
                $this->fail('Out-of-schema price must be rejected.');
            } catch (\Multioto_Agent_Rpc_Error $error) {
                $this->assertStringContainsString('בטווח', $error->getMessage());
                $this->assertSame('250', $this->store->items[7]['price']);
                $this->assertSame([], $this->store->writes);
            }
        }
    }

    public function test_discovery_does_not_invent_bounds_or_expose_non_numeric_field_settings(): void
    {
        $this->factory->fields['price']['min_value'] = 'not-a-number';
        $this->factory->fields['price']['max_value'] = '1e9999';
        $this->factory->fields['title']['min_value'] = 10;
        $fields = array_column(\Multioto_Agent_Cct::call('jet_cct_types', [])['types'][0]['fields'], null, 'key');
        foreach (['price', 'title'] as $key) {
            $this->assertArrayNotHasKey('min', $fields[$key]);
            $this->assertArrayNotHasKey('max', $fields[$key]);
        }
    }

    public function test_get_uses_registered_type_and_preserves_global_result_format(): void
    {
        $item = \Multioto_Agent_Cct::call('jet_cct_get', ['type' => 'properties', 'id' => 7]);
        $this->assertSame(7, $item['id']);
        $this->assertSame('250', $item['values']['price']);
        $this->assertArrayNotHasKey('api_token', $item['values']);
        $this->assertArrayNotHasKey('gallery', $item['values']);
        $this->assertArrayNotHasKey('_ID', $item['values']);
        $this->assertSame('OBJECT', $this->factory->db->format);
    }

    public function test_bounded_pagination_fetches_one_extra_row_and_uses_vendor_preparation(): void
    {
        $this->store->items[8] = ['_ID' => 8, 'title' => 'Second', 'cct_status' => 'publish'];
        $this->store->items[9] = ['_ID' => 9, 'title' => 'Third', 'cct_status' => 'draft'];
        $page = \Multioto_Agent_Cct::call('jet_cct_list', ['type' => 'properties', 'page' => 1, 'limit' => 1, 'filters' => ['cct_status' => 'publish']]);
        $this->assertSame(1, $page['returned']);
        $this->assertTrue($page['has_more']);
        $this->assertSame(7, $page['items'][0]['id']);
        $this->assertSame([['field' => 'cct_status', 'operator' => '=', 'value' => 'publish', 'type' => 'CHAR']], $this->store->prepared[0]);
        $this->assertSame(2, $this->store->queries[0]['limit']);
        $this->assertSame(0, $this->store->queries[0]['offset']);

        $page = \Multioto_Agent_Cct::call('jet_cct_list', ['type' => 'properties', 'page' => 2, 'limit' => 1, 'filters' => ['cct_status' => 'publish']]);
        $this->assertSame(8, $page['items'][0]['id']);
        $this->assertFalse($page['has_more']);
    }

    #[DataProvider('invalidReadProvider')]
    public function test_reads_reject_unknown_types_ids_and_untrusted_filter_keys(string $tool, array $args): void
    {
        $this->expectException(\Multioto_Agent_Rpc_Error::class);
        \Multioto_Agent_Cct::call($tool, $args);
    }

    public static function invalidReadProvider(): array
    {
        return [
            ['jet_cct_get', ['type' => 'wp_users', 'id' => 7]],
            ['jet_cct_get', ['type' => 'properties WHERE 1=1', 'id' => 7]],
            ['jet_cct_get', ['type' => 'Properties', 'id' => 7]],
            ['jet_cct_get', ['type' => 'properties', 'id' => 99]],
            ['jet_cct_get', ['type' => 'properties', 'id' => -7]],
            ['jet_cct_list', ['type' => 'properties', 'limit' => 101]],
            ['jet_cct_list', ['type' => 'properties', 'filters' => ['api_token' => 'x']]],
            ['jet_cct_list', ['type' => 'properties', 'filters' => ['title OR 1=1' => 'x']]],
            ['jet_cct_list', ['type' => 'properties', 'filters' => ['title' => ['operator' => 'LIKE']]]],
        ];
    }

    public function test_update_returns_restorable_snapshots_and_undo_checks_current_values(): void
    {
        $result = $this->update(['title' => '<b>New home</b>', 'cct_status' => 'draft'], ['title' => 'Home', 'cct_status' => 'publish']);
        $this->assertSame(['title' => 'Home', 'cct_status' => 'publish'], $result['before']);
        $this->assertSame(['title' => 'New home', 'cct_status' => 'draft'], $result['after']);
        $this->assertTrue($result['changed']);
        $this->assertSame('250', $this->store->items[7]['price']);
        $this->assertSame(7, $this->store->writes[0]['_ID']);
        $this->update($result['before'], $result['after']);
        $this->assertSame('Home', $this->store->items[7]['title']);
        $this->assertSame('publish', $this->store->items[7]['cct_status']);
    }

    public function test_noop_does_not_call_vendor_mutation_hooks(): void
    {
        $result = $this->update(['title' => 'Home'], ['title' => 'Home']);
        $this->assertFalse($result['changed']);
        $this->assertSame([], $this->store->writes);
    }

    #[DataProvider('unsafeLegacyValuesProvider')]
    public function test_change_is_refused_when_undo_cannot_preserve_legacy_stored_value(string $key, $before, $next): void
    {
        $this->store->items[7][$key] = $before;
        try {
            // A valid first field must not be written when a later field cannot
            // be restored; the entire proposal is refused before vendor hooks.
            $this->update(['price' => '300', $key => $next], ['price' => '250', $key => $before]);
            $this->fail('The adapter accepted a change whose undo would lose data.');
        } catch (\Multioto_Agent_Rpc_Error $e) {
            $this->assertSame([], $this->store->writes);
            $this->assertSame('250', $this->store->items[7]['price']);
            $this->assertSame($before, $this->store->items[7][$key]);
        }
    }

    public static function unsafeLegacyValuesProvider(): array
    {
        return [
            'legacy HTML in text' => ['title', '<b>Original home</b>', 'New home'],
            'legacy whitespace' => ['title', '  Original home  ', 'New home'],
            'legacy HTML in textarea' => ['notes', '<i>Original notes</i>', 'New notes'],
            'unsupported legacy rich HTML' => ['body', '<iframe src="https://example.test"></iframe>', '<p>New content</p>'],
            'option removed from schema' => ['category', 'retired-option', 'house'],
            'boolean stored instead of switcher string' => ['active', true, 'false'],
        ];
    }

    public function test_valid_numeric_database_strings_roundtrip_without_normalizing_representation(): void
    {
        $this->store->items[7]['price'] = '0250.00';
        $result = $this->update(['price' => '300.50'], ['price' => '0250.00']);
        $this->assertSame(['price' => '0250.00'], $result['before']);
        $this->update($result['before'], $result['after']);
        $this->assertSame('0250.00', $this->store->items[7]['price']);
    }

    public function test_safe_rich_text_multiline_text_and_datetime_can_be_restored(): void
    {
        $this->store->items[7]['body'] = '<p>Original <strong>content</strong></p>';
        $this->store->items[7]['notes'] = "First line\nSecond line";
        $this->store->items[7]['open_at'] = '2026-10-08T18:30';
        $expected = array_intersect_key($this->store->items[7], array_flip(['body', 'notes', 'open_at']));
        $result = $this->update(['body' => '<p>New content</p>', 'notes' => 'New notes', 'open_at' => '2026-10-09T19:30'], $expected);
        $this->update($result['before'], $result['after']);
        $this->assertSame($expected, array_intersect_key($this->store->items[7], $expected));
    }

    public function test_receipts_keep_the_requested_field_order_on_both_sides(): void
    {
        $result = $this->update(['cct_status' => 'draft', 'price' => '300', 'title' => 'New home'], ['cct_status' => 'publish', 'price' => '250', 'title' => 'Home']);
        $this->assertSame(['cct_status', 'price', 'title'], array_keys($result['before']));
        $this->assertSame(array_keys($result['before']), array_keys($result['after']));
    }

    #[DataProvider('invalidWriteProvider')]
    public function test_invalid_field_or_stale_snapshot_refuses_entire_write(array $values, ?array $expected): void
    {
        try {
            $this->update($values, $expected);
            $this->fail('Invalid update was accepted');
        } catch (\Multioto_Agent_Rpc_Error $e) {
            $this->assertSame([], $this->store->writes);
            $this->assertSame('Home', $this->store->items[7]['title']);
        }
    }

    public static function invalidWriteProvider(): array
    {
        return [
            [['title' => 'New'], null],
            [['title' => 'New'], []],
            [['title' => 'New'], ['title' => 'Older value']],
            [['title' => 'New', 'price' => 100], ['title' => 'Home']],
            [['title' => 'New', 'api_token' => 'x'], ['title' => 'Home', 'api_token' => 'never-return-this']],
            [['_ID' => 8], ['_ID' => 7]],
            [['cct_author_id' => 8], ['cct_author_id' => 1]],
            [['cct_single_post_id' => 8], ['cct_single_post_id' => null]],
            [['unknown' => 'value'], ['unknown' => null]],
            [['title' => ['nested' => 'value']], ['title' => 'Home']],
            [['title' => ''], ['title' => 'Home']],
            [['gallery' => [4, 5]], ['gallery' => null]],
            [['price' => 'NaN'], ['price' => '250']],
            [['price' => -1], ['price' => '250']],
            [['category' => 'admin'], ['category' => null]],
            [['cct_status' => 'trash'], ['cct_status' => 'publish']],
            [['date' => '2026-02-30'], ['date' => null]],
            [['active' => 'yes'], ['active' => null]],
            [['color' => 'javascript:foo'], ['color' => null]],
        ];
    }

    public function test_create_defaults_to_draft_and_never_accepts_caller_chosen_id(): void
    {
        $created = \Multioto_Agent_Cct::call('jet_cct_create', ['type' => 'properties', 'values' => ['title' => 'New home', 'price' => 90]]);
        $this->assertSame(8, $created['id']);
        $this->assertTrue($created['created']);
        $this->assertSame('draft', $created['values']['cct_status']);
        $this->assertSame('draft', $created['undo_mode']);
        $this->assertFalse($created['deletion_supported']);
        $this->assertArrayNotHasKey('_ID', $this->store->writes[0]);
        $this->assertFalse(\Multioto_Agent_Cct::handles('jet_cct_delete'));
    }

    public function test_create_refuses_missing_required_field(): void
    {
        $this->expectException(\Multioto_Agent_Rpc_Error::class);
        \Multioto_Agent_Cct::call('jet_cct_create', ['type' => 'properties', 'values' => ['price' => 90]]);
    }

    public function test_required_secret_or_complex_field_prevents_unsafe_creation(): void
    {
        $this->factory->fields['api_token']['required'] = true;
        $type = \Multioto_Agent_Cct::call('jet_cct_types', [])['types'][0];
        $this->assertFalse($type['create_supported']);
        $this->expectException(\Multioto_Agent_Rpc_Error::class);
        \Multioto_Agent_Cct::call('jet_cct_create', ['type' => 'properties', 'values' => ['title' => 'Home']]);
    }

    public function test_linked_post_type_stays_read_only_to_preserve_cross_object_undo(): void
    {
        $this->factory->args['has_single'] = 'true';
        $type = \Multioto_Agent_Cct::call('jet_cct_types', [])['types'][0];
        $this->assertFalse($type['writable']);
        $this->assertFalse($type['create_supported']);
        $this->expectException(\Multioto_Agent_Rpc_Error::class);
        $this->update(['title' => 'New'], ['title' => 'Home']);
    }

    public function test_existing_single_post_link_cannot_be_bypassed_with_type_setting(): void
    {
        $this->store->items[7]['cct_single_post_id'] = 30;
        $item = \Multioto_Agent_Cct::call('jet_cct_get', ['type' => 'properties', 'id' => 7]);
        $this->assertFalse($item['writable']);
        $this->expectException(\Multioto_Agent_Rpc_Error::class);
        $this->update(['title' => 'New'], ['title' => 'Home']);
    }

    public function test_dynamic_choices_arrays_and_timestamp_storage_are_not_guessed(): void
    {
        $this->factory->fields['category']['options_from_glossary'] = true;
        $this->factory->fields['date']['is_timestamp'] = true;
        $this->factory->fields['notes']['is_array'] = true;
        $fields = array_column(\Multioto_Agent_Cct::call('jet_cct_types', [])['types'][0]['fields'], null, 'key');
        $this->assertFalse($fields['category']['writable']);
        $this->assertFalse($fields['date']['writable']);
        $this->assertFalse($fields['notes']['writable']);
    }

    private function update(array $values, ?array $expected): array
    {
        return \Multioto_Agent_Cct::call('jet_cct_update', ['type' => 'properties', 'id' => 7, 'values' => $values, 'expected' => $expected]);
    }
}
