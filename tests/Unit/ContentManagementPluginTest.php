<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class ContentManagementPluginTest extends TestCase
{
    private function scenario(string $name): array
    {
        $process = new Process([PHP_BINARY, __DIR__.'/../Fixtures/content-management-wordpress.php', $name]);
        $process->mustRun();

        return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_financial_refunds_and_subscriptions_cannot_enter_either_generic_content_api(): void
    {
        foreach ($this->scenario('woocommerce_entities') as $type => $tools) {
            foreach ($tools as $tool => $result) {
                $this->assertTrue($result['rejected'], "{$tool} must reject {$type} even when its editing UI is enabled.");
                $this->assertSame(0, $result['saves']);
            }
        }
    }

    public function test_scheduling_uses_site_dst_and_restores_both_dates_and_status(): void
    {
        $result = $this->scenario('schedule');
        $this->assertTrue($result['change']['changed']);
        $this->assertSame('2099-07-01 09:30:00', $result['change']['after']['date_gmt']);
        $this->assertSame('future', $result['change']['after']['status']);
        $this->assertSame($result['before'], $result['restored']);
    }

    public function test_reorganizing_and_undo_preserve_post_body(): void
    {
        $result = $this->scenario('organization');
        $this->assertTrue($result['change']['changed']);
        $this->assertSame(['parent' => 2, 'menu_order' => 7, 'slug' => 'new-path'], $result['change']['after']);
        $this->assertSame('Do not modify me', $result['content']);
        $this->assertSame($result['before'], $result['restored']);
    }

    public function test_both_seo_providers_are_updated_before_save_hooks_and_undo_deletes_absent_meta(): void
    {
        foreach (['yoast' => '_yoast_wpseo_title', 'rank_math' => 'rank_math_title'] as $provider => $titleKey) {
            $result = $this->scenario($provider);
            $this->assertTrue($result['change']['changed']);
            $this->assertSame('Title \\ original', $result['metadata'][$titleKey]);
            $this->assertSame($result['metadata'], $result['at_save']);
            $this->assertSame(['title' => null, 'description' => null], $result['restored']);
            $this->assertSame([], $result['meta']);
        }
    }

    public function test_internal_links_preserve_hebrew_gutenberg_comments_and_unrelated_html_exactly(): void
    {
        $result = $this->scenario('link');
        $this->assertTrue($result['change']['changed']);
        $this->assertSame(str_replace('בחנות שלנו', '<a href="https://example.test/content/2">בחנות שלנו</a>', $result['before']), $result['change']['after']['content']);
        $this->assertSame([['text' => 'בחנות שלנו', 'url' => 'https://example.test/content/2']], $result['links']);
        $this->assertSame($result['before'], $result['restored']);
    }

    public function test_site_settings_update_and_undo_restore_original_values(): void
    {
        $result = $this->scenario('settings');
        $this->assertTrue($result['change']['changed']);
        $this->assertSame('האתר שלי', $result['change']['after']['blogname']);
        $this->assertSame($result['before'], $result['restored']);
    }

    public function test_integer_json_offsets_can_compare_to_wordpress_float_values(): void
    {
        $result = $this->scenario('gmt_json_round_trip');
        $this->assertSame(5.5, $result['after']['gmt_offset']);
    }

    public function test_unchanged_updates_report_false_without_firing_post_save_hooks(): void
    {
        $result = $this->scenario('no_changes');
        foreach ($result['results'] as $change) {
            $this->assertFalse($change['changed']);
            $this->assertSame($change['before'], $change['after']);
        }
        $this->assertSame(0, $result['saves']);
    }

    public function test_partial_seo_failure_restores_written_meta_but_preserves_a_concurrent_edit(): void
    {
        $failed = $this->scenario('seo_partial_failure');
        $this->assertTrue($failed['rejected']);
        $this->assertSame(['title' => null, 'description' => null], $failed['current']);
        $concurrent = $this->scenario('seo_concurrent_failure');
        $this->assertTrue($concurrent['rejected']);
        $this->assertSame(['title' => 'Concurrent editor', 'description' => null], $concurrent['current']);
    }

    public function test_partial_settings_failure_restores_written_options_but_preserves_a_concurrent_edit(): void
    {
        $failed = $this->scenario('settings_partial_failure');
        $this->assertTrue($failed['rejected']);
        $this->assertSame('', $failed['current']['blogname']);
        $this->assertSame('', $failed['current']['blogdescription']);
        $concurrent = $this->scenario('settings_concurrent_failure');
        $this->assertTrue($concurrent['rejected']);
        $this->assertSame('Concurrent editor', $concurrent['current']['blogname']);
        $this->assertSame('', $concurrent['current']['blogdescription']);
    }

    public static function refusedScenarios(): array
    {
        return array_map(static fn (string $name): array => [$name], ['stale_schedule', 'parent_cycle', 'no_provider', 'seo_stale', 'external_link', 'private_link', 'elementor', 'stale_link', 'settings_stale']);
    }

    #[DataProvider('refusedScenarios')]
    public function test_unsafe_or_conflicting_change_is_rejected_before_write(string $scenario): void
    {
        $result = $this->scenario($scenario);
        $this->assertTrue($result['rejected']);
        $this->assertSame(0, $result['saves']);
    }

    public static function refusedBatches(): array
    {
        return [['bad_dates'], ['bad_content_changes'], ['bad_links'], ['unsafe_settings']];
    }

    #[DataProvider('refusedBatches')]
    public function test_all_invalid_values_in_a_batch_are_rejected(string $scenario): void
    {
        foreach ($this->scenario($scenario) as $result) {
            $this->assertTrue($result['rejected']);
            $this->assertSame(0, $result['saves']);
        }
    }
}
