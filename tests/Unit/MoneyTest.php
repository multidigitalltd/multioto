<?php

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_it_formats_integer_agorot_as_an_ils_string(): void
    {
        $this->assertSame('₪118.00', Money::ils(11800));
        $this->assertSame('₪0.00', Money::ils(0));
        $this->assertSame('₪1,234.56', Money::ils(123456));
    }
}
