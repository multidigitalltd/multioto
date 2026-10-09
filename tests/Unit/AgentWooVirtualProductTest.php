<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class AgentWooVirtualProductTest extends TestCase
{
    protected function setUp(): void
    {
        require __DIR__.'/../Support/wordpress-woo-virtual.php';
        foreach (['sale_options', 'sale_meta', 'sale_products', 'sale_events', 'sale_unscheduled', 'sale_saves', 'sale_invalidated', 'sale_hooks', 'virtual_setters', 'virtual_queries'] as $key) {
            $GLOBALS[$key] = [];
        }
        $GLOBALS['sale_products'][11] = new \WooVirtualFixtureProduct(11);
    }

    public function test_creation_persists_a_virtual_simple_draft_at_the_requested_price(): void
    {
        $result = \Multioto_Agent_Woo_Writer::create(['name' => 'ייעוץ', 'regular_price' => '150', 'virtual' => true]);

        self::assertTrue($result['virtual']);
        self::assertTrue($result['virtual_applied']);
        self::assertSame('simple', $result['type']);
        self::assertSame('150', $result['regular_price']);
        self::assertSame('draft', $result['status']);
        self::assertSame('draft', $result['created_as']);
        self::assertTrue(\wc_get_product($result['id'])->get_virtual('edit'));
        self::assertFalse(\wc_get_product($result['id'])->get_downloadable('edit'));
    }

    public function test_omitted_or_explicit_false_creates_a_physical_simple_draft(): void
    {
        foreach ([[], ['virtual' => false]] as $flag) {
            $result = \Multioto_Agent_Woo_Writer::create(['name' => 'מוצר', ...$flag]);
            self::assertFalse($result['virtual']);
            self::assertSame('simple', $result['type']);
            self::assertSame('draft', $result['status']);
        }
    }

    #[DataProvider('supportedTypes')]
    public function test_virtual_is_independent_of_type_downloadable_price_and_stock_and_can_be_restored(string $type): void
    {
        $GLOBALS['sale_products'][11]->values['type'] = $type;
        $GLOBALS['sale_products'][11]->values['downloadable'] = true;
        $GLOBALS['sale_products'][11]->values['stock_quantity'] = 23;
        $result = \Multioto_Agent_Woo_Writer::update(11, ['virtual' => true]);

        self::assertSame(['virtual' => true], $result['changed']);
        self::assertFalse($result['previous']['virtual']);
        self::assertSame($type, $result['previous']['type']);
        $product = \wc_get_product(11);
        self::assertTrue($product->get_virtual('edit'));
        self::assertTrue($product->get_downloadable('edit'));
        self::assertSame($type, $product->get_type());
        self::assertSame('100', $product->get_regular_price('edit'));
        self::assertSame(23, $product->get_stock_quantity('edit'));
        self::assertSame([], $GLOBALS['sale_events']);
        self::assertSame([], $GLOBALS['virtual_queries']);

        $restored = \Multioto_Agent_Woo_Writer::update(11, ['virtual' => $result['previous']['virtual']]);
        self::assertSame(['virtual' => false], $restored['changed']);
        self::assertTrue($restored['previous']['virtual']);
        self::assertFalse(\wc_get_product(11)->get_virtual('edit'));
    }

    public static function supportedTypes(): array
    {
        return ['simple' => ['simple'], 'variation' => ['variation']];
    }

    public function test_omitting_virtual_on_an_update_preserves_the_existing_flag(): void
    {
        $GLOBALS['sale_products'][11]->values['virtual'] = true;
        $result = \Multioto_Agent_Woo_Writer::update(11, ['name' => 'Updated name']);
        self::assertTrue($result['previous']['virtual']);
        self::assertArrayNotHasKey('virtual', $result['changed']);
        self::assertTrue(\wc_get_product(11)->get_virtual('edit'));
        self::assertNotContains('set_virtual', array_column($GLOBALS['virtual_setters'], 0));
    }

    #[DataProvider('invalidFlags')]
    public function test_non_boolean_flags_are_rejected_before_any_create_or_update_setter(mixed $value): void
    {
        foreach ([
            fn () => \Multioto_Agent_Woo_Writer::create(['name' => 'Invalid', 'virtual' => $value]),
            fn () => \Multioto_Agent_Woo_Writer::update(11, ['name' => 'Must not change', 'virtual' => $value]),
        ] as $attempt) {
            $this->refuses($attempt, -32602);
        }
        self::assertSame([], $GLOBALS['virtual_setters']);
        self::assertSame([], $GLOBALS['sale_saves']);
        self::assertSame(0, $GLOBALS['virtual_constructed'] ?? 0);
        self::assertSame('Product', \wc_get_product(11)->get_name());
    }

    public static function invalidFlags(): array
    {
        return ['null' => [null], 'string true' => ['true'], 'string false' => ['false'], 'one' => [1],
            'zero' => [0], 'empty' => [''], 'list' => [[]], 'object' => [['value' => true]]];
    }

    #[DataProvider('unsupportedTypes')]
    public function test_unsupported_product_types_reject_an_explicit_flag_without_affecting_regular_updates(string $type): void
    {
        $GLOBALS['sale_products'][11]->values['type'] = $type;
        $this->refuses(fn () => \Multioto_Agent_Woo_Writer::update(11, ['name' => 'Must not change', 'virtual' => false]), -32602);
        self::assertSame([], $GLOBALS['virtual_setters']);
        self::assertSame([], $GLOBALS['sale_saves']);

        $result = \Multioto_Agent_Woo_Writer::update(11, ['name' => 'Allowed rename']);
        self::assertSame(['name' => 'Allowed rename'], $result['changed']);
        self::assertSame($type, \wc_get_product(11)->get_type());
    }

    public static function unsupportedTypes(): array
    {
        return ['parent' => ['variable'], 'grouped' => ['grouped'], 'external' => ['external'],
            'subscription' => ['subscription'], 'custom' => ['custom-product']];
    }

    public function test_search_and_get_return_boolean_virtual_with_type_without_writing(): void
    {
        $GLOBALS['sale_products'][11]->values['virtual'] = true;
        $GLOBALS['sale_products'][12] = new \WooVirtualFixtureProduct(12);
        $result = \Multioto_Agent_Woo_Writer::search('Product');
        self::assertSame([true, false], array_column($result['products'], 'virtual'));
        self::assertSame(['simple', 'simple'], array_column($result['products'], 'type'));
        self::assertTrue(\Multioto_Agent_Woo_Writer::get(11)['virtual']);
        self::assertFalse(\Multioto_Agent_Woo_Writer::get(12)['virtual']);
        self::assertSame([], $GLOBALS['sale_saves']);
    }

    public function test_save_failure_does_not_report_a_created_product_or_a_successful_update(): void
    {
        $GLOBALS['sale_save_false'] = true;
        $this->refuses(fn () => \Multioto_Agent_Woo_Writer::create(['name' => 'Failed draft', 'virtual' => true]), -32000);
        $this->refuses(fn () => \Multioto_Agent_Woo_Writer::update(11, ['virtual' => true]), -32000);
        self::assertCount(1, $GLOBALS['sale_products']);
        self::assertFalse(\wc_get_product(11)->get_virtual('edit'));
    }

    public function test_create_readback_mismatch_keeps_the_created_id_and_exposes_the_actual_flag(): void
    {
        $GLOBALS['virtual_ignore_on_save'] = true;
        $result = \Multioto_Agent_Woo_Writer::create(['name' => 'Review this draft', 'virtual' => true]);
        self::assertGreaterThan(0, $result['id']);
        self::assertFalse($result['virtual']);
        self::assertFalse($result['virtual_applied']);
        self::assertSame('draft', $result['status']);
    }

    public function test_create_readback_failure_retains_the_draft_id_without_claiming_a_verified_virtual_flag(): void
    {
        $GLOBALS['virtual_fail_read_after_save'] = true;
        $result = \Multioto_Agent_Woo_Writer::create(['name' => 'Review this draft', 'virtual' => true]);
        self::assertGreaterThan(0, $result['id']);
        self::assertTrue($result['verification_failed']);
        self::assertArrayNotHasKey('virtual', $result);
        self::assertArrayNotHasKey('virtual_applied', $result);
        self::assertArrayHasKey($result['id'], $GLOBALS['sale_products']);
    }

    public function test_update_readback_mismatch_does_not_report_success(): void
    {
        $GLOBALS['virtual_ignore_on_save'] = true;
        $this->refuses(fn () => \Multioto_Agent_Woo_Writer::update(11, ['virtual' => true]), -32000);
        self::assertFalse(\wc_get_product(11)->get_virtual('edit'));
    }

    public function test_failed_combined_sale_update_restores_the_previous_virtual_flag(): void
    {
        $GLOBALS['sale_products'][11]->values['virtual'] = true;
        $GLOBALS['sale_schedule_fail'] = true;

        $this->refuses(fn () => \Multioto_Agent_Woo_Writer::update(11, [
            'virtual' => false,
            'sale_price' => '80',
            'sale_to' => \wp_date('Y-m-d H:i', time() + 900, \wp_timezone()),
        ]), -32602);

        self::assertTrue(\wc_get_product(11)->get_virtual('edit'));
        self::assertSame('', \wc_get_product(11)->get_sale_price('edit'));
        self::assertSame('100', \wc_get_product(11)->get_regular_price('edit'));
    }

    private function refuses(callable $callback, int $code): void
    {
        try {
            $callback();
            self::fail('Expected the native writer to refuse.');
        } catch (\Multioto_Agent_Rpc_Error $error) {
            self::assertSame($code, $error->getCode());
        }
    }
}
