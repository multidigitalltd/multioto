<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class AgentSaleScheduleTest extends TestCase
{
    protected function setUp(): void
    {
        if (str_contains($this->name(), 'fallback')) {
            define('SALE_NO_AS', true);
        }
        require __DIR__.'/../Support/wordpress-sale-schedule.php';
        foreach (['sale_options', 'sale_meta', 'sale_products', 'sale_events', 'sale_unscheduled', 'sale_saves', 'sale_invalidated', 'sale_hooks'] as $key) {
            $GLOBALS[$key] = [];
        }
        $GLOBALS['sale_products'][11] = new \WC_Product(11);
    }

    private function campaign(int $start, int $end, string $id = 'campaign_test'): array
    {
        $product = \wc_get_product(11);
        $before = \Multioto_Agent_Sale_Schedule::productTuple($product);
        $product->set_sale_price('80');
        $product->set_date_on_sale_from($start);
        $product->set_date_on_sale_to($end - 1);
        $product->save();
        $after = \Multioto_Agent_Sale_Schedule::productTuple($product);

        return ['id' => $id, 'starts_at' => $start, 'ends_at' => $end, 'products' => [['id' => 11, 'before' => $before, 'after' => $after]]];
    }

    private function expired(): array
    {
        $campaign = $this->campaign(time() - 120, time() - 60);
        $campaign['status'] = 'active';
        $campaign['products'][0]['phase'] = 'active';
        $campaign['products'][0]['current_expected'] = $campaign['products'][0]['after'];
        \add_option('multioto_sale_campaign_campaign_test', $campaign);
        \update_post_meta(11, '_multioto_sale_owner', 'campaign_test');
        $product = \wc_get_product(11);
        $product->set_price('80');
        $product->save();

        return $campaign;
    }

    private function refuses(callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected refusal');
        } catch (\Multioto_Agent_Rpc_Error $error) {
            self::assertSame(-32602, $error->getCode());
        }
    }

    public function test_local_minute_dates_offsets_and_dst_round_trip_without_silent_shifts(): void
    {
        $parse = ['Multioto_Agent_Sale_Schedule', 'parse'];
        $time = $parse('2026-10-08 12:34');
        self::assertSame('2026-10-08T12:34:00+03:00', \Multioto_Agent_Sale_Schedule::format($time));
        self::assertSame($time, $parse('2026-10-08T12:34:00+03:00'));
        self::assertSame('2026-10-08', \Multioto_Agent_Sale_Schedule::format($parse('2026-10-08')));
        $GLOBALS['sale_timezone'] = 'America/New_York';
        $this->refuses(fn () => $parse('2026-03-08 02:30'));
        $this->refuses(fn () => $parse('2026-11-01 01:30'));
        self::assertSame(3600, $parse('2026-11-01T01:30:00-05:00') - $parse('2026-11-01T01:30:00-04:00'));
        $this->refuses(fn () => $parse('2026-02-30 12:00'));
    }

    public function test_register_schedules_exact_action_scheduler_events_and_rejects_overlap(): void
    {
        $start = time() + 100;
        $end = time() + 300;
        $campaign = $this->campaign($start, $end);
        \Multioto_Agent_Sale_Schedule::register($campaign);
        self::assertSame([$start, $end], array_column($GLOBALS['sale_events'], 0));
        self::assertSame('multioto-agent-sales', $GLOBALS['sale_events'][0][3]);
        self::assertSame([11], \Multioto_Agent_Sale_Schedule::conflicts([11]));
        $campaign['id'] = 'campaign_other';
        $this->refuses(fn () => \Multioto_Agent_Sale_Schedule::register($campaign));
        self::assertSame('campaign_test', \get_post_meta(11, '_multioto_sale_owner', true));
    }

    public function test_wp_cron_fallback_uses_the_same_precise_timestamps(): void
    {
        $start = time() + 100;
        $end = time() + 300;
        \Multioto_Agent_Sale_Schedule::register($this->campaign($start, $end));
        self::assertSame([$start, $end], array_column($GLOBALS['sale_events'], 0));
        self::assertSame('wp-cron', $GLOBALS['sale_events'][0][3]);
    }

    public function test_late_cron_reads_and_cart_prices_return_regular_then_callback_clears_sale_once(): void
    {
        $this->expired();
        $product = \wc_get_product(11);
        self::assertSame('100', \Multioto_Agent_Sale_Schedule::price('80', $product));
        self::assertSame('', \Multioto_Agent_Sale_Schedule::salePrice('80', $product));
        self::assertFalse(\Multioto_Agent_Sale_Schedule::onSale(true, $product));
        \Multioto_Agent_Sale_Schedule::run('campaign_test', 'end');
        $after = \wc_get_product(11);
        self::assertSame('', $after->get_sale_price('edit'));
        self::assertSame('100', $after->get_price('edit'));
        self::assertNull($after->get_date_on_sale_from('edit'));
        $writes = count($GLOBALS['sale_saves']);
        \Multioto_Agent_Sale_Schedule::run('campaign_test', 'end');
        self::assertCount($writes, $GLOBALS['sale_saves']);
        self::assertSame('ended', \Multioto_Agent_Sale_Schedule::state('campaign_test')['products'][0]['phase']);
    }

    public function test_dashboard_price_edits_or_new_owners_are_never_overwritten_at_expiry(): void
    {
        $this->expired();
        $product = \wc_get_product(11);
        $product->set_sale_price('65');
        $product->set_price('65');
        $product->save();
        self::assertSame('65', \Multioto_Agent_Sale_Schedule::price('65', \wc_get_product(11)));
        \Multioto_Agent_Sale_Schedule::run('campaign_test', 'end');
        self::assertSame('65', \wc_get_product(11)->get_sale_price('edit'));
        self::assertSame('superseded', \Multioto_Agent_Sale_Schedule::state('campaign_test')['products'][0]['phase']);
        \update_post_meta(11, '_multioto_sale_owner', 'another_campaign');
        \Multioto_Agent_Sale_Schedule::cancel('campaign_test');
        self::assertSame('another_campaign', \get_post_meta(11, '_multioto_sale_owner', true));
    }

    public function test_variable_price_cache_hash_changes_by_phase_and_runtime_cart_overrides_are_preserved(): void
    {
        $campaign = $this->campaign(time() - 30, time() + 100);
        $product = \wc_get_product(11);
        $product->set_parent_id(99);
        $product->save();
        \Multioto_Agent_Sale_Schedule::register($campaign);
        $parent = new \WC_Product(99);
        self::assertSame('active', \Multioto_Agent_Sale_Schedule::variationHash([], $parent)['multioto_campaign_test']);
        self::assertSame('80', \Multioto_Agent_Sale_Schedule::price('100', \wc_get_product(11)));
        $cart = \wc_get_product(11);
        $cart->set_price('60');
        self::assertSame('60', \Multioto_Agent_Sale_Schedule::price('60', $cart));
    }

    public function test_failed_boundary_save_keeps_pending_state_and_schedules_retry(): void
    {
        $this->expired();
        $GLOBALS['sale_save_false'] = true;
        \Multioto_Agent_Sale_Schedule::run('campaign_test', 'end');
        self::assertSame('active', \get_option('multioto_sale_campaign_campaign_test')['products'][0]['phase']);
        self::assertSame('80', \wc_get_product(11)->get_sale_price('edit'));
        self::assertSame(['campaign_test', 'end'], $GLOBALS['sale_events'][0][2]);
        unset($GLOBALS['sale_save_false']);
        \Multioto_Agent_Sale_Schedule::run('campaign_test', 'end');
        self::assertSame('', \wc_get_product(11)->get_sale_price('edit'));
    }

    public function test_failed_single_product_reschedule_preserves_external_stock_and_original_campaign_status(): void
    {
        $campaign = $this->campaign(time() - 10, time() + 900);
        \Multioto_Agent_Sale_Schedule::register($campaign);
        $GLOBALS['sale_schedule_fail'] = true;
        $GLOBALS['sale_schedule_callback'] = static function (): void {
            $product = \wc_get_product(11);
            $product->set_stock_quantity(42);
            $product->save();
        };
        $this->refuses(fn () => \Multioto_Agent_Woo_Writer::update(11, ['sale_price' => '70']));
        self::assertSame('80', \wc_get_product(11)->get_sale_price('edit'));
        self::assertSame(42, \wc_get_product(11)->get_stock_quantity());
        self::assertSame('campaign_test', \get_post_meta(11, '_multioto_sale_owner', true));
        self::assertNotSame('partial', \Multioto_Agent_Sale_Schedule::state('campaign_test')['status']);
    }

    public function test_real_category_provider_and_scheduler_apply_then_undo_after_native_woo_start_cleanup(): void
    {
        require __DIR__.'/../../wordpress-plugin/multioto-agent/includes/class-category-sales.php';
        try {
            $get = \Multioto_Agent_Category_Sales::call('wc_category_sale_get', ['category_id' => 77]);
            $proposal = \Multioto_Agent_Category_Sales::call('wc_category_sale_prepare', [
                'category_id' => 77, 'expected' => $get['snapshot'], 'discount_type' => 'percent', 'discount_value' => '20',
                'ends_at' => \wp_date('Y-m-d H:i', time() + 900, \wp_timezone()),
            ]);
            $applied = \Multioto_Agent_Category_Sales::call('wc_category_sale_apply', ['category_id' => 77, 'expected' => $proposal['expected'], 'prepared' => $proposal['prepared']]);
            self::assertSame('80.00', \wc_get_product(11)->get_sale_price('edit'));
            $product = \wc_get_product(11);
            $product->set_date_on_sale_from(null);
            $product->save();
            $state = \Multioto_Agent_Sale_Schedule::state($applied['campaign_id']);
            self::assertNull($state['products'][0]['current_expected']['sale_from']);
            \Multioto_Agent_Category_Sales::call('wc_category_sale_revert', ['category_id' => 77, 'expected' => $applied['after'], 'restore' => $applied['before']]);
            self::assertSame('', \wc_get_product(11)->get_sale_price('edit'));
            self::assertSame('100', \wc_get_product(11)->get_regular_price('edit'));
            self::assertSame('cancelled', \Multioto_Agent_Sale_Schedule::state($applied['campaign_id'])['status']);
        } catch (\Throwable $error) {
            self::fail($error->getMessage());
        }
    }

    public function test_failed_initial_product_save_reports_failure_and_preserves_owned_promotion(): void
    {
        \Multioto_Agent_Sale_Schedule::register($this->campaign(time() - 10, time() + 900));
        $GLOBALS['sale_save_false_once'] = true;
        try {
            \Multioto_Agent_Woo_Writer::update(11, ['sale_price' => '']);
            self::fail('A failed save must never report an updated product.');
        } catch (\Multioto_Agent_Rpc_Error $error) {
            self::assertSame(-32000, $error->getCode());
        }
        self::assertSame('80', \wc_get_product(11)->get_sale_price('edit'));
        self::assertSame('campaign_test', \get_post_meta(11, '_multioto_sale_owner', true));
        self::assertNotSame('partial', \Multioto_Agent_Sale_Schedule::state('campaign_test')['status']);
    }

    public function test_expiry_uses_new_regular_price_without_extending_the_owned_sale_or_allowing_stale_undo(): void
    {
        $this->expired();
        $product = \wc_get_product(11);
        $product->set_regular_price('120');
        $product->set_parent_id(99);
        $product->save();
        \update_post_meta(99, '_multioto_sale_campaigns', ['campaign_test']);
        self::assertSame('120', \Multioto_Agent_Sale_Schedule::price('80', \wc_get_product(11)));
        self::assertSame('superseded', \Multioto_Agent_Sale_Schedule::state('campaign_test')['products'][0]['phase']);
        \Multioto_Agent_Sale_Schedule::run('campaign_test', 'end');
        self::assertSame('120', \wc_get_product(11)->get_regular_price('edit'));
        self::assertSame('120', \wc_get_product(11)->get_price('edit'));
        self::assertSame('', \wc_get_product(11)->get_sale_price('edit'));
        self::assertSame([], \get_post_meta(99, '_multioto_sale_campaigns', true));
        self::assertSame('superseded', \Multioto_Agent_Sale_Schedule::state('campaign_test')['products'][0]['phase']);
    }

    public function test_future_and_active_owned_sales_respect_a_changed_regular_price_and_manual_new_dates(): void
    {
        \Multioto_Agent_Sale_Schedule::register($this->campaign(time() + 300, time() + 900));
        $product = \wc_get_product(11);
        $product->set_regular_price('120');
        $product->save();
        self::assertSame('120', \Multioto_Agent_Sale_Schedule::price('100', \wc_get_product(11)));
        $state = \get_option('multioto_sale_campaign_campaign_test');
        $state['starts_at'] = time() - 10;
        $state['products'][0]['after']['sale_from'] = time() - 10;
        \update_option('multioto_sale_campaign_campaign_test', $state);
        $product->set_date_on_sale_from($state['starts_at']);
        $product->save();
        self::assertSame('80', \Multioto_Agent_Sale_Schedule::price('100', \wc_get_product(11)));
        $product->set_regular_price('50');
        $product->save();
        self::assertSame('50', \Multioto_Agent_Sale_Schedule::price('80', \wc_get_product(11)));
        $product->set_date_on_sale_to(time() + 5000);
        $product->save();
        self::assertSame('75', \Multioto_Agent_Sale_Schedule::price('75', \wc_get_product(11)));
    }

    public function test_single_product_precise_dates_round_trip_and_failed_registration_restores_prices(): void
    {
        $from = (new \DateTimeImmutable('+2 days', new \DateTimeZone('Asia/Jerusalem')))->format('Y-m-d').' 12:34';
        $to = (new \DateTimeImmutable('+3 days', new \DateTimeZone('Asia/Jerusalem')))->format('Y-m-d').' 15:45';
        try {
            \Multioto_Agent_Woo_Writer::update(11, ['sale_price' => '80', 'sale_from' => $from, 'sale_to' => $to]);
            $saved = \Multioto_Agent_Woo_Writer::get(11);
            self::assertStringContainsString('T12:34:00', $saved['sale_from']);
            self::assertStringContainsString('T15:45:00', $saved['sale_to']);
            $previous = \Multioto_Agent_Sale_Schedule::productTuple(\wc_get_product(11));
            $GLOBALS['sale_schedule_fail'] = true;
            $this->refuses(fn () => \Multioto_Agent_Woo_Writer::update(11, ['sale_price' => '70', 'sale_from' => $from, 'sale_to' => $to]));
            self::assertSame($previous, \Multioto_Agent_Sale_Schedule::productTuple(\wc_get_product(11)));
        } catch (\Throwable $error) {
            self::fail($error->getMessage());
        }
    }
}
