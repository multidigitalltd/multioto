<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class AgentWooProductCountsTest extends TestCase
{
    protected function setUp(): void
    {
        require __DIR__.'/../Support/wordpress-woo-product-counts.php';
        foreach (['sale_options', 'sale_meta', 'sale_products', 'sale_events', 'sale_saves', 'virtual_setters', 'virtual_queries', 'count_calls', 'count_rows'] as $key) {
            $GLOBALS[$key] = [];
        }
    }

    public function test_whole_store_counts_exceed_thirty_and_keep_variations_trash_and_drafts_distinct(): void
    {
        $GLOBALS['count_rows'] = [
            'product' => (object) ['publish' => 37, 'draft' => 4, 'private' => 2, 'pending' => 1, 'future' => 3, 'trash' => 6, 'auto-draft' => 2],
            'product_variation' => (object) ['publish' => 81, 'draft' => 9, 'trash' => 5],
        ];

        $counts = \Multioto_Agent_Woo_Writer::counts();

        self::assertSame(47, $counts['products']['total']);
        self::assertSame(37, $counts['products']['by_status']['publish']);
        self::assertSame(4, $counts['products']['by_status']['draft']);
        self::assertSame(6, $counts['products']['by_status']['trash']);
        self::assertSame(90, $counts['variations']['total']);
        self::assertSame(0, $counts['variations']['by_status']['private']);
        self::assertSame(['trash', 'auto-draft'], $counts['excluded_from_total']);
        self::assertSame([['product', ''], ['product_variation', '']], $GLOBALS['count_calls']);
        self::assertSame([], $GLOBALS['virtual_queries']);
        self::assertSame([], $GLOBALS['sale_saves']);
    }

    public function test_a_store_with_only_trash_or_auto_drafts_has_zero_inventory_total(): void
    {
        $GLOBALS['count_rows']['product'] = (object) ['trash' => 100, 'auto-draft' => 18];
        $counts = \Multioto_Agent_Woo_Writer::counts();
        self::assertSame(0, $counts['products']['total']);
        self::assertSame(100, $counts['products']['by_status']['trash']);
        self::assertSame(0, $counts['variations']['total']);
        self::assertSame(['publish' => 0, 'draft' => 0, 'private' => 0, 'pending' => 0, 'future' => 0, 'trash' => 0, 'auto-draft' => 0], $counts['variations']['by_status']);
    }

    public function test_custom_statuses_are_visible_and_counted_instead_of_silently_discarded(): void
    {
        $GLOBALS['count_rows']['product'] = (object) ['publish' => '31', 'review-needed' => '7'];
        $counts = \Multioto_Agent_Woo_Writer::counts();
        self::assertSame(38, $counts['products']['total']);
        self::assertSame(7, $counts['products']['by_status']['review-needed']);
        self::assertSame(31, $counts['products']['by_status']['publish']);
    }

    public function test_missing_registered_product_types_do_not_turn_into_an_invented_zero(): void
    {
        $GLOBALS['count_registered_types'] = ['product'];
        $this->refuses(fn () => \Multioto_Agent_Woo_Writer::counts(), -32602);
        self::assertSame([['product', '']], $GLOBALS['count_calls']);
    }

    #[DataProvider('invalidCounts')]
    public function test_invalid_aggregate_results_refuse_instead_of_reporting_a_total(mixed $value): void
    {
        $GLOBALS['count_rows']['product'] = $value;
        $this->refuses(fn () => \Multioto_Agent_Woo_Writer::counts(), -32000);
    }

    public static function invalidCounts(): array
    {
        return [
            'not aggregate object' => [[]],
            'negative count' => [(object) ['publish' => -1]],
            'fractional count' => [(object) ['publish' => 2.5]],
            'boolean count' => [(object) ['publish' => true]],
            'unknown count' => [(object) ['publish' => null]],
            'malformed status' => [(object) ['<private>' => 2]],
            'overflow value' => [(object) ['publish' => '99999999999999999999999999999']],
            'overflow total' => [(object) ['publish' => PHP_INT_MAX, 'draft' => 1]],
        ];
    }

    public function test_search_page_size_is_not_the_store_total_or_the_search_total(): void
    {
        $this->products(37);
        $GLOBALS['count_rows']['product'] = (object) ['publish' => 37, 'draft' => 4];
        $search = \Multioto_Agent_Woo_Writer::search('Alpha', 10);
        self::assertSame(10, $search['returned']);
        self::assertSame(37, $search['total']);
        self::assertSame(4, $search['pages']);
        self::assertSame(41, \Multioto_Agent_Woo_Writer::counts()['products']['total']);
    }

    public function test_a_later_matching_sku_result_is_not_double_counted_or_repeated_across_pages(): void
    {
        $this->products(32);
        $GLOBALS['sale_products'][25]->values['sku'] = 'Alpha';
        $ids = [];
        foreach (range(1, 4) as $page) {
            $result = \Multioto_Agent_Woo_Writer::search('Alpha', 10, $page);
            self::assertSame(32, $result['total']);
            self::assertSame(4, $result['pages']);
            self::assertSame([25], $GLOBALS['virtual_queries'][$page - 1]['exclude']);
            if ($page === 1) {
                self::assertSame(25, $result['products'][0]['id']);
                self::assertSame(11, $result['returned']);
            }
            $ids = [...$ids, ...array_column($result['products'], 'id')];
        }
        self::assertCount(32, $ids);
        self::assertCount(32, array_unique($ids));
    }

    public function test_a_sku_only_match_contributes_one_to_the_same_total_on_every_page(): void
    {
        $this->products(32);
        $GLOBALS['sale_products'][33] = new \WooVirtualFixtureProduct(33);
        $GLOBALS['sale_products'][33]->values['name'] = 'Unrelated';
        $GLOBALS['sale_products'][33]->values['sku'] = 'Alpha';
        $ids = [];
        foreach (range(1, 4) as $page) {
            $result = \Multioto_Agent_Woo_Writer::search('Alpha', 10, $page);
            self::assertSame(33, $result['total']);
            $ids = [...$ids, ...array_column($result['products'], 'id')];
        }
        self::assertCount(33, array_unique($ids));
        self::assertCount(33, $ids);
    }

    public function test_a_trashed_sku_match_is_not_inserted_into_an_active_product_search(): void
    {
        $this->products(3);
        $GLOBALS['sale_products'][3]->values['sku'] = 'Alpha';
        $GLOBALS['sale_products'][3]->values['status'] = 'trash';
        $result = \Multioto_Agent_Woo_Writer::search('Alpha', 10);
        self::assertSame(2, $result['total']);
        self::assertSame([1, 2], array_column($result['products'], 'id'));
    }

    private function products(int $count): void
    {
        foreach (range(1, $count) as $id) {
            $GLOBALS['sale_products'][$id] = new \WooVirtualFixtureProduct($id);
            $GLOBALS['sale_products'][$id]->values['name'] = 'Alpha '.$id;
        }
    }

    private function refuses(callable $callback, int $code): void
    {
        try {
            $callback();
            self::fail('Expected an explicit refusal.');
        } catch (\Multioto_Agent_Rpc_Error $error) {
            self::assertSame($code, $error->getCode());
        }
    }
}
