<?php

namespace App\Support;

/**
 * Money is stored everywhere as integer agorot (ILS only). This is the single
 * place that turns agorot into a human string, so the "₪" + two-decimals format
 * isn't hand-rolled at every call site.
 */
class Money
{
    /** Format integer agorot as an ILS amount, e.g. 11800 → "₪118.00". */
    public static function ils(int $agorot): string
    {
        return '₪'.number_format($agorot / 100, 2);
    }
}
