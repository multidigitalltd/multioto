<?php

namespace Tests\Unit;

use App\Services\SiteAgent\ProductSaleWindow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProductSaleWindowTest extends TestCase
{
    #[DataProvider('windows')]
    public function test_sale_windows_use_the_complete_effective_shop_schedule(array $fields, array $product, bool $valid): void
    {
        $problem = ProductSaleWindow::problem($fields, $product);
        $valid ? $this->assertNull($problem) : $this->assertIsString($problem);
    }

    public static function windows(): array
    {
        $shop = ['timezone' => 'Asia/Jerusalem', 'sale_from' => '', 'sale_to' => ''];

        return [
            'inverted' => [['sale_from' => '2032-03-10 18:00', 'sale_to' => '2032-03-09 18:00'], $shop, false],
            'equal' => [['sale_from' => '2032-03-10 18:00', 'sale_to' => '2032-03-10 18:00'], $shop, false],
            'valid date only' => [['sale_from' => '2032-03-10', 'sale_to' => '2032-03-11'], $shop, true],
            'equal date only is midnight twice' => [['sale_from' => '2032-03-10', 'sale_to' => '2032-03-10'], $shop, false],
            'earlier end against persisted start' => [['sale_to' => '2032-03-09 18:00'], [...$shop, 'sale_from' => '2032-03-10T18:00:00+02:00'], false],
            'later start against persisted end' => [['sale_from' => '2032-03-11 18:00'], [...$shop, 'sale_to' => '2032-03-10T18:00:00+02:00'], false],
            'offset receipt is compared as an instant' => [['sale_to' => '2032-07-01 11:00'], [...$shop, 'sale_from' => '2032-07-01T09:00:00+00:00'], false],
            'offset receipt valid in shop timezone' => [['sale_to' => '2032-07-01 13:00'], [...$shop, 'sale_from' => '2032-07-01T09:00:00+00:00'], true],
            'open ended' => [['sale_from' => '2032-07-01 09:00'], $shop, true],
            'end only' => [['sale_to' => '2032-07-01 09:00'], $shop, true],
            'no server timezone fallback' => [['sale_to' => '2032-07-01 09:00'], ['sale_from' => '', 'sale_to' => ''], false],
            'clear both without timezone' => [['sale_from' => '', 'sale_to' => ''], [], true],
            'end sale clears persisted schedule' => [['sale_price' => ''], [...$shop, 'sale_from' => 'bad', 'sale_to' => 'bad'], true],
            'ending sale clears untouched boundary first' => [['sale_price' => '', 'sale_from' => '2032-07-01 09:00'], [...$shop, 'sale_to' => '2032-06-01'], true],
            'missing unedited boundary is not assumed empty' => [['sale_to' => '2032-07-01 09:00'], ['timezone' => 'Asia/Jerusalem'], false],
            'nonexistent DST minute' => [['sale_from' => '2032-03-28 02:30', 'sale_to' => '2032-03-28 04:00'], [...$shop, 'timezone' => 'Europe/Berlin'], false],
            'ambiguous DST minute' => [['sale_from' => '2032-10-31 02:30', 'sale_to' => '2032-10-31 04:00'], [...$shop, 'timezone' => 'Europe/Berlin'], false],
            'saved explicit DST offset is unambiguous' => [['sale_to' => '2032-10-31 04:00'], [...$shop, 'timezone' => 'Europe/Berlin', 'sale_from' => '2032-10-31T02:30:00+02:00'], true],
        ];
    }
}
