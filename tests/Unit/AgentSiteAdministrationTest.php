<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class AgentSiteAdministrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__.'/../Support/wordpress-site-administration-stubs.php';

        $GLOBALS['ma_users'] = [7 => new \WP_User];
        $GLOBALS['ma_multisite'] = false;
        $GLOBALS['ma_superadmins'] = [];
        $GLOBALS['ma_user_writes'] = [];
        $GLOBALS['ma_filter_description'] = false;
        $GLOBALS['ma_theme_writes'] = [];
        $GLOBALS['ma_switch_succeeds'] = true;
        $GLOBALS['ma_stylesheet'] = 'original';
        $GLOBALS['ma_themes'] = ['original' => new \WP_Theme, 'new-theme' => new \WP_Theme];
        $GLOBALS['ma_themes']['original']->headers['Name'] = 'Original theme';
        $GLOBALS['ma_themes']['new-theme']->headers['Name'] = 'New theme';
    }

    public function test_profile_read_only_exposes_the_public_editable_fields(): void
    {
        $result = $this->call('wp_user_profile_get', ['id' => 7]);

        $this->assertSame(['id', 'label', 'values'], array_keys($result));
        $this->assertSame(['display_name' => 'Editor', 'first_name' => 'First', 'last_name' => 'Last', 'description' => 'Bio'], $result['values']);
        $this->assertStringNotContainsString('never-return-this', json_encode($result));
        $this->assertStringNotContainsString('private@example.test', json_encode($result));
    }

    public function test_profile_changes_preserve_other_fields_and_can_be_undone(): void
    {
        $result = $this->call('wp_user_profile_update', ['id' => 7, 'values' => ['display_name' => 'New editor'], 'expected' => ['display_name' => 'Editor']]);

        $this->assertSame(['display_name' => 'Editor'], $result['before']);
        $this->assertSame(['display_name' => 'New editor'], $result['after']);
        $this->assertTrue($result['changed']);
        $this->assertSame([['ID' => 7, 'display_name' => 'New editor']], $GLOBALS['ma_user_writes']);
        $this->assertSame('private@example.test', $GLOBALS['ma_users'][7]->user_email);
        $this->assertSame(['editor'], $GLOBALS['ma_users'][7]->roles);

        $undo = $this->call('wp_user_profile_update', ['id' => 7, 'values' => $result['before'], 'expected' => $result['after']]);
        $this->assertSame(['display_name' => 'Editor'], $undo['after']);
    }

    public function test_external_changes_block_profile_update_and_undo(): void
    {
        $GLOBALS['ma_users'][7]->display_name = 'Changed in dashboard';
        $this->assertRefused('wp_user_profile_update', ['id' => 7, 'values' => ['display_name' => 'New'], 'expected' => ['display_name' => 'Editor']]);
        $this->assertSame([], $GLOBALS['ma_user_writes']);
        $this->assertSame('Changed in dashboard', $GLOBALS['ma_users'][7]->display_name);
    }

    public function test_profile_fields_cannot_change_credentials_roles_or_unknown_metadata(): void
    {
        foreach (['user_email', 'user_pass', 'role', 'wp_capabilities', 'anything'] as $field) {
            $this->assertRefused('wp_user_profile_update', ['id' => 7, 'values' => [$field => 'bad'], 'expected' => [$field => 'old']]);
        }
        $this->assertSame([], $GLOBALS['ma_user_writes']);
    }

    public function test_administrators_and_custom_privileged_roles_are_protected_on_read_and_write(): void
    {
        $GLOBALS['ma_users'][7]->roles = ['administrator'];
        $this->assertRefused('wp_user_profile_get', ['id' => 7]);
        $this->assertRefused('wp_user_profile_update', ['id' => 7, 'values' => ['display_name' => 'New'], 'expected' => ['display_name' => 'Editor']]);

        $GLOBALS['ma_users'][7]->roles = ['custom'];
        $GLOBALS['ma_users'][7]->caps = ['manage_options'];
        $this->assertRefused('wp_user_profile_get', ['id' => 7]);
        $this->assertSame([], $GLOBALS['ma_user_writes']);
    }

    public function test_multisite_superadministrators_are_protected_even_without_a_local_admin_role(): void
    {
        $GLOBALS['ma_multisite'] = true;
        $GLOBALS['ma_superadmins'] = [7];
        $this->assertRefused('wp_user_profile_get', ['id' => 7]);
    }

    public function test_values_are_sanitized_and_snapshots_use_actual_saved_text(): void
    {
        $GLOBALS['ma_filter_description'] = true;
        $result = $this->call('wp_user_profile_update', ['id' => 7, 'values' => ['display_name' => '<b>Alice</b>', 'description' => '<em>Bio</em>'], 'expected' => ['display_name' => 'Editor', 'description' => 'Bio']]);

        $this->assertSame(['display_name' => 'Alice', 'description' => '<EM>BIO</EM>'], $result['after']);
        $this->assertSame('First', $GLOBALS['ma_users'][7]->first_name);
    }

    public function test_missing_or_incomplete_expected_and_nonstring_values_are_refused(): void
    {
        foreach ([
            ['values' => ['display_name' => 'New']],
            ['values' => ['display_name' => 'New', 'first_name' => 'Another'], 'expected' => ['display_name' => 'Editor']],
            ['values' => ['display_name' => ['injected']], 'expected' => ['display_name' => 'Editor']],
            ['values' => ['display_name' => 'New'], 'expected' => ['display_name' => null]],
            ['values' => [], 'expected' => []],
            ['values' => ['display_name' => ''], 'expected' => ['display_name' => 'Editor']],
        ] as $args) {
            $this->assertRefused('wp_user_profile_update', ['id' => 7] + $args);
        }
        $this->assertSame([], $GLOBALS['ma_user_writes']);
    }

    public function test_profile_noop_does_not_trigger_a_write(): void
    {
        $result = $this->call('wp_user_profile_update', ['id' => 7, 'values' => ['description' => 'Bio', 'display_name' => 'Editor'], 'expected' => ['display_name' => 'Editor', 'description' => 'Bio']]);
        $this->assertFalse($result['changed']);
        $this->assertSame([], $GLOBALS['ma_user_writes']);
    }

    public function test_existing_values_that_cannot_be_restored_are_not_replaced(): void
    {
        $GLOBALS['ma_users'][7]->display_name = '<b>Legacy formatting</b>';
        $this->assertRefused('wp_user_profile_update', ['id' => 7, 'values' => ['display_name' => 'New'], 'expected' => ['display_name' => '<b>Legacy formatting</b>']]);
        $GLOBALS['ma_users'][7]->description = str_repeat('x', 10001);
        $this->assertRefused('wp_user_profile_update', ['id' => 7, 'values' => ['description' => 'New'], 'expected' => ['description' => $GLOBALS['ma_users'][7]->description]]);
        $this->assertSame([], $GLOBALS['ma_user_writes']);
    }

    public function test_theme_switch_returns_the_previous_theme_and_supports_guarded_undo(): void
    {
        $current = $this->call('wp_theme_active_get', []);
        $this->assertSame(['stylesheet' => 'original'], $current['values']);
        $result = $this->call('wp_theme_active_set', ['values' => ['stylesheet' => 'new-theme'], 'expected' => $current['values']]);
        $this->assertTrue($result['changed']);
        $this->assertSame('new-theme', $GLOBALS['ma_stylesheet']);

        $undo = $this->call('wp_theme_active_set', ['values' => $result['before'], 'expected' => $result['after']]);
        $this->assertSame(['stylesheet' => 'original'], $undo['after']);
        $this->assertSame(['new-theme', 'original'], $GLOBALS['ma_theme_writes']);
    }

    public function test_stale_theme_snapshot_is_refused_before_switching(): void
    {
        $this->assertRefused('wp_theme_active_set', ['values' => ['stylesheet' => 'new-theme'], 'expected' => ['stylesheet' => 'some-old-theme']]);
        $this->assertSame([], $GLOBALS['ma_theme_writes']);
    }

    public function test_missing_broken_and_unsafe_themes_are_rejected_before_switching(): void
    {
        foreach (['not-installed', '../new-theme', '/new-theme', 'new-theme.php'] as $stylesheet) {
            $this->assertRefused('wp_theme_active_set', ['values' => ['stylesheet' => $stylesheet], 'expected' => ['stylesheet' => 'original']]);
        }
        $GLOBALS['ma_themes']['new-theme']->broken = true;
        $this->assertRefused('wp_theme_active_set', ['values' => ['stylesheet' => 'new-theme'], 'expected' => ['stylesheet' => 'original']]);
        $this->assertSame([], $GLOBALS['ma_theme_writes']);
    }

    public function test_switch_is_refused_when_the_current_theme_cannot_be_restored(): void
    {
        $GLOBALS['ma_themes']['original']->installed = false;
        $this->assertRefused('wp_theme_active_set', ['values' => ['stylesheet' => 'new-theme'], 'expected' => ['stylesheet' => 'original']]);
        $this->assertSame([], $GLOBALS['ma_theme_writes']);
    }

    public function test_missing_parent_or_incompatible_theme_requirements_are_refused(): void
    {
        $parent = new \WP_Theme;
        $parent->installed = false;
        $GLOBALS['ma_themes']['new-theme']->parentTheme = $parent;
        $this->assertRefused('wp_theme_active_set', ['values' => ['stylesheet' => 'new-theme'], 'expected' => ['stylesheet' => 'original']]);

        $parent->installed = true;
        $parent->headers['RequiresPHP'] = '99.0';
        $this->assertRefused('wp_theme_active_set', ['values' => ['stylesheet' => 'new-theme'], 'expected' => ['stylesheet' => 'original']]);
        $parent->headers = [];
        $GLOBALS['ma_themes']['new-theme']->headers['RequiresWP'] = '99.0';
        $this->assertRefused('wp_theme_active_set', ['values' => ['stylesheet' => 'new-theme'], 'expected' => ['stylesheet' => 'original']]);
        $this->assertSame([], $GLOBALS['ma_theme_writes']);
    }

    public function test_network_disallowed_theme_is_refused(): void
    {
        $GLOBALS['ma_multisite'] = true;
        $GLOBALS['ma_themes']['new-theme']->allowed = false;
        $this->assertRefused('wp_theme_active_set', ['values' => ['stylesheet' => 'new-theme'], 'expected' => ['stylesheet' => 'original']]);
        $this->assertSame([], $GLOBALS['ma_theme_writes']);
    }

    public function test_failed_theme_switch_does_not_report_success(): void
    {
        $GLOBALS['ma_switch_succeeds'] = false;
        $this->assertRefused('wp_theme_active_set', ['values' => ['stylesheet' => 'new-theme'], 'expected' => ['stylesheet' => 'original']]);
        $this->assertSame('original', $GLOBALS['ma_stylesheet']);
    }

    public function test_theme_noop_does_not_execute_activation_hooks(): void
    {
        $result = $this->call('wp_theme_active_set', ['values' => ['stylesheet' => 'original'], 'expected' => ['stylesheet' => 'original']]);
        $this->assertFalse($result['changed']);
        $this->assertSame([], $GLOBALS['ma_theme_writes']);
    }

    private function call(string $tool, array $args): array
    {
        return \Multioto_Agent_Site_Administration::call($tool, $args);
    }

    private function assertRefused(string $tool, array $args): void
    {
        try {
            $this->call($tool, $args);
            $this->fail('Expected the unsafe or stale operation to be refused.');
        } catch (\Multioto_Agent_Rpc_Error $error) {
            $this->assertContains($error->getCode(), [-32602, -32000]);
        }
    }
}
