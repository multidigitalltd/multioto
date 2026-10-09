<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class AgentCategorySalesTest extends TestCase
{
    protected function setUp(): void
    {
        require __DIR__.'/../Support/wordpress-category-sales-stubs.php';
        foreach (['cs_products', 'cs_members', 'cs_owned', 'cs_options', 'cs_campaigns', 'cs_cancelled', 'cs_saves', 'cs_synced', 'cs_cache_cleared'] as $key) {
            $GLOBALS[$key] = [];
        }
        $this->product(1, '100.00');
        $this->product(2, '49.99');
        $GLOBALS['cs_members'] = [1, 2];
    }

    private function product(int $id, string $regular, array $extra = []): void
    {
        $GLOBALS['cs_products'][$id] = $extra + ['id' => $id, 'parent_id' => 0, 'name' => 'Product '.$id, 'type' => 'simple', 'status' => 'publish', 'regular_price' => $regular, 'sale_price' => '', 'sale_from' => null, 'sale_to' => null, 'price' => $regular];
    }

    private function read(array $args = []): array
    {
        return $this->call('get', $args);
    }

    private function call(string $name, array $args = []): array
    {
        return \Multioto_Agent_Category_Sales::call('wc_category_sale_'.$name, $args + ['category_id' => 10, 'include_children' => true]);
    }

    private function prepare(array $extra = []): array
    {
        return $this->call('prepare', $extra + ['expected' => $this->read()['snapshot'], 'discount_type' => 'percent', 'discount_value' => '10', 'starts_at' => wp_date('Y-m-d H:i', time() + 3600), 'ends_at' => wp_date('Y-m-d H:i', time() + 86400)]);
    }

    private function apply(array $proposal): array
    {
        return $this->call('apply', ['expected' => $proposal['expected'], 'prepared' => $proposal['prepared']]);
    }

    private function undo(array $result): array
    {
        return $this->call('revert', ['expected' => $result['after'], 'restore' => $result['before']]);
    }

    private function refused(callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected refusal');
        } catch (\Multioto_Agent_Rpc_Error $error) {
            self::assertSame(-32602, $error->getCode());
        }
    }

    public function test_percentage_promotion_previews_exact_agorot_and_round_trips_without_regular_price_writes(): void
    {
        $before = $GLOBALS['cs_products'];
        $proposal = $this->prepare();
        self::assertSame('90.00', $proposal['after'][0]['sale_price']);
        self::assertSame('44.99', $proposal['after'][1]['sale_price']);
        self::assertSame($before, $GLOBALS['cs_products']);
        $result = $this->apply($proposal);
        self::assertTrue($result['changed']);
        self::assertSame('100.00', $GLOBALS['cs_products'][1]['regular_price']);
        self::assertSame('100.00', $GLOBALS['cs_products'][1]['price']);
        self::assertSame($proposal['after'][0]['sale_to'] + 1, $GLOBALS['cs_register_args']['ends_at']);
        $this->undo($result);
        self::assertSame($before, $GLOBALS['cs_products']);
        self::assertSame([], $GLOBALS['cs_options']);
    }

    public function test_fixed_discount_is_subtracted_from_regular_and_immediate_sale_updates_effective_price(): void
    {
        $proposal = $this->prepare(['discount_type' => 'fixed', 'discount_value' => '7.25', 'starts_at' => '']);
        $this->apply($proposal);
        self::assertSame('92.75', $GLOBALS['cs_products'][1]['price']);
        self::assertSame('42.74', $GLOBALS['cs_products'][2]['sale_price']);
    }

    public function test_variations_are_flattened_with_attributes_and_exclusions_are_explicit(): void
    {
        $this->product(3, '', ['type' => 'variable', 'children' => [31, 32, 33]]);
        $this->product(31, '15.00', ['type' => 'variation', 'parent_id' => 3, 'attributes' => 'Size: XL, Color: Red']);
        $this->product(32, '', ['type' => 'variation', 'parent_id' => 3]);
        $this->product(33, '20.00', ['type' => 'variation', 'parent_id' => 3, 'status' => 'private']);
        $this->product(4, '50', ['type' => 'grouped']);
        $GLOBALS['cs_members'] = [1, 3, 4];
        $read = $this->read();
        self::assertSame([1, 31], array_column($read['products'], 'id'));
        self::assertStringContainsString('Size: XL', $read['products'][1]['name']);
        self::assertCount(3, $read['excluded']);
        $result = $this->apply($this->prepare());
        self::assertContains(3, $GLOBALS['cs_synced']);
        $this->undo($result);
    }

    public function test_existing_active_or_future_sale_requires_explicit_replacement_and_undo_restores_it(): void
    {
        $GLOBALS['cs_products'][1]['sale_price'] = '80.00';
        $GLOBALS['cs_products'][1]['sale_from'] = time() + 600;
        $GLOBALS['cs_products'][1]['sale_to'] = time() + 6000;
        $before = $GLOBALS['cs_products'];
        $this->refused(fn () => $this->prepare());
        $proposal = $this->prepare(['replace_existing' => true]);
        self::assertStringContainsString('יחליף', implode(' ', $proposal['notes']));
        $result = $this->apply($proposal);
        $this->undo($result);
        self::assertSame($before, $GLOBALS['cs_products']);
    }

    public function test_changed_membership_price_and_timezone_refuse_before_any_write(): void
    {
        foreach (['membership', 'price', 'timezone'] as $change) {
            $proposal = $this->prepare();
            if ($change === 'membership') {
                $GLOBALS['cs_members'] = [1];
            } elseif ($change === 'price') {
                $GLOBALS['cs_products'][1]['regular_price'] = '110.00';
            } else {
                $GLOBALS['cs_timezone'] = 'UTC';
            }$this->refused(fn () => $this->apply($proposal));
            $GLOBALS['cs_members'] = [1, 2];
            $GLOBALS['cs_products'][1]['regular_price'] = '100.00';
            unset($GLOBALS['cs_timezone']);
        }self::assertSame([], $GLOBALS['cs_saves']);
    }

    public function test_partial_native_write_failure_rolls_back_earlier_products_and_releases_locks(): void
    {
        $before = $GLOBALS['cs_products'];
        $GLOBALS['cs_fail_product'] = 2;
        $this->refused(fn () => $this->apply($this->prepare()));
        self::assertSame($before, $GLOBALS['cs_products']);
        self::assertSame([], $GLOBALS['cs_options']);
        self::assertSame([], $GLOBALS['cs_campaigns']);
    }

    public function test_schedule_failure_rolls_back_prices_and_cancels_partial_registration(): void
    {
        $before = $GLOBALS['cs_products'];
        $GLOBALS['cs_register_fail'] = true;
        $this->refused(fn () => $this->apply($this->prepare()));
        self::assertSame($before, $GLOBALS['cs_products']);
        self::assertCount(1, $GLOBALS['cs_cancelled']);
        self::assertSame([], $GLOBALS['cs_options']);
    }

    public function test_compensation_never_overwrites_an_independent_price_edit(): void
    {
        $GLOBALS['cs_register_fail'] = true;
        $GLOBALS['cs_register_mutation'] = static function (): void {
            $GLOBALS['cs_products'][1]['sale_price'] = '71.00';
            $GLOBALS['cs_products'][1]['price'] = '71.00';
        };
        $this->refused(fn () => $this->apply($this->prepare()));
        self::assertSame('71.00', $GLOBALS['cs_products'][1]['sale_price']);
        self::assertSame('', $GLOBALS['cs_products'][2]['sale_price']);
    }

    public function test_stale_undo_is_all_or_nothing_and_ended_owned_campaign_can_restore_original_sale(): void
    {
        $result = $this->apply($this->prepare());
        $GLOBALS['cs_products'][2]['sale_price'] = '20.00';
        $this->refused(fn () => $this->undo($result));
        self::assertSame('90.00', $GLOBALS['cs_products'][1]['sale_price']);
        foreach ($GLOBALS['cs_campaigns'][$result['campaign_id']]['products'] as &$row) {
            $id = $row['id'];
            $GLOBALS['cs_products'][$id]['sale_price'] = '';
            $GLOBALS['cs_products'][$id]['sale_from'] = null;
            $GLOBALS['cs_products'][$id]['sale_to'] = null;
            $row['phase'] = 'ended';
            $row['current_expected'] = ['regular_price' => $GLOBALS['cs_products'][$id]['regular_price'], 'sale_price' => '', 'sale_from' => null, 'sale_to' => null];
        }unset($row);
        $GLOBALS['cs_campaigns'][$result['campaign_id']]['status'] = 'ended';
        self::assertTrue($this->undo($result)['changed']);
    }

    public function test_scope_limit_invalid_values_overlap_and_tampered_tokens_fail_closed(): void
    {
        foreach ([['discount_value' => '0'], ['discount_value' => '100.01'], ['discount_type' => 'fixed', 'discount_value' => '50'], ['discount_value' => '1e2'], ['ends_at' => '2020-01-01 10:00']] as $args) {
            $this->refused(fn () => $this->prepare($args));
        }
        $GLOBALS['cs_owned'] = [2];
        $this->refused(fn () => $this->prepare());
        $GLOBALS['cs_owned'] = [];
        $proposal = $this->prepare();
        $proposal['prepared']['token'][60] = $proposal['prepared']['token'][60] === 'a' ? 'b' : 'a';
        $this->refused(fn () => $this->apply($proposal));
        $GLOBALS['cs_members'] = range(1, 201);
        $this->refused(fn () => $this->read());
        self::assertSame([], $GLOBALS['cs_saves']);
    }

    public function test_explicit_descendant_scope_and_intersecting_write_lock_are_enforced(): void
    {
        $GLOBALS['cs_direct'] = [1];
        $read = $this->read(['include_children' => false]);
        self::assertSame([1], array_column($read['products'], 'id'));
        self::assertFalse($GLOBALS['cs_query']['tax_query'][0]['include_children']);
        $proposal = $this->prepare();
        $GLOBALS['cs_options']['_multioto_sale_write_2'] = ['owner' => 'other', 'expires' => time() + 100];
        $this->refused(fn () => $this->apply($proposal));
        self::assertSame('other', $GLOBALS['cs_options']['_multioto_sale_write_2']['owner']);
        self::assertArrayNotHasKey('_multioto_sale_write_1', $GLOBALS['cs_options']);
        self::assertSame([], $GLOBALS['cs_saves']);
    }

    public function test_repeated_apply_and_undo_are_idempotent_after_a_lost_transport_response(): void
    {
        $proposal = $this->prepare();
        $result = $this->apply($proposal);
        $writes = $GLOBALS['cs_saves'];
        $again = $this->apply($proposal);
        self::assertSame($result['campaign_id'], $again['campaign_id']);
        self::assertSame($result['before']['version'], $again['before']['version']);
        self::assertSame($writes, $GLOBALS['cs_saves']);
        $this->undo($result);
        $writes = $GLOBALS['cs_saves'];
        self::assertTrue($this->undo($result)['already_reverted']);
        self::assertSame($writes, $GLOBALS['cs_saves']);
        $this->refused(fn () => $this->apply($proposal));
        $fresh = $this->apply($this->prepare());
        self::assertNotSame($result['campaign_id'], $fresh['campaign_id']);
    }

    public function test_price_change_observed_on_final_prewrite_read_is_never_discarded(): void
    {
        $proposal = $this->prepare();
        $GLOBALS['cs_get_counts'] = [];
        $GLOBALS['cs_before_get'] = static function (int $id, int $count): void {
            if ($id === 1 && $count === 3) {
                $GLOBALS['cs_products'][1]['sale_price'] = '75.00';
            }
        };
        $this->refused(fn () => $this->apply($proposal));
        self::assertSame('75.00', $GLOBALS['cs_products'][1]['sale_price']);
        self::assertSame('', $GLOBALS['cs_products'][2]['sale_price']);
        self::assertSame([], $GLOBALS['cs_saves']);
    }
}
