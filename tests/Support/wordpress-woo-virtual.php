<?php

require __DIR__.'/wordpress-sale-schedule.php';

/** Woo CRUD fixture: only persistence hooks differ from the scheduler fixture. */
class WooVirtualFixtureProduct extends WC_Product
{
    public function __call($name, $args)
    {
        if (strpos($name, 'set_') === 0) {
            $GLOBALS['virtual_setters'][] = [$name, $args[0]];
        }
        if ($name === 'get_virtual' && ! empty($GLOBALS['virtual_readback_fail'])) {
            throw new RuntimeException('private readback error');
        }

        return parent::__call($name, $args);
    }

    public function save()
    {
        if (! empty($GLOBALS['virtual_ignore_on_save'])) {
            $this->values['virtual'] = false;
        }
        $id = parent::save();
        if (! empty($GLOBALS['virtual_fail_read_after_save'])) {
            $GLOBALS['virtual_readback_fail'] = true;
        }

        return $id;
    }
}

class WC_Product_Simple extends WooVirtualFixtureProduct
{
    public function __construct()
    {
        $GLOBALS['virtual_constructed'] = ($GLOBALS['virtual_constructed'] ?? 0) + 1;
        parent::__construct(100 + $GLOBALS['virtual_constructed']);
        $this->values['regular_price'] = '';
        $this->values['price'] = '';
    }
}

function wc_get_product_id_by_sku($sku)
{
    foreach ($GLOBALS['sale_products'] as $id => $product) {
        if ($product->values['sku'] === $sku) {
            return $id;
        }
    }

    return 0;
}

function wc_get_products($args)
{
    $GLOBALS['virtual_queries'][] = $args;
    $products = array_filter($GLOBALS['sale_products'], static function ($product) use ($args) {
        return ! in_array($product->get_id(), $args['exclude'] ?? [], true)
            && in_array($product->values['status'], $args['status'], true)
            && stripos($product->values['name'], $args['s']) !== false;
    });
    ksort($products);
    $total = count($products);
    $products = array_map(static function ($product) {
        return clone $product;
    }, array_values(array_slice($products, ($args['page'] - 1) * $args['limit'], $args['limit'], true)));

    return (object) ['products' => $products, 'total' => $total, 'max_num_pages' => (int) ceil($total / $args['limit'])];
}
