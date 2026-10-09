<?php

require __DIR__.'/wordpress-woo-virtual.php';

function post_type_exists($type)
{
    return in_array($type, $GLOBALS['count_registered_types'] ?? ['product', 'product_variation'], true);
}

function wp_count_posts($type, $permission = '')
{
    $GLOBALS['count_calls'][] = [$type, $permission];

    return $GLOBALS['count_rows'][$type] ?? (object) [];
}
