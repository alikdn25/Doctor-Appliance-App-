<?php

namespace App\Support\Billing;

/**
 * Formats cents as money for messages, e.g. 12345 → "$123.45". Companies use CAD or USD.
 */
class Money
{
    public static function format(int $cents): string
    {
        return ($cents < 0 ? '-' : '').'$'.number_format(abs($cents) / 100, 2);
    }
}
