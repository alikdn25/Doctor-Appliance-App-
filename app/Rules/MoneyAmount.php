<?php

namespace App\Rules;

use App\Support\Locale\Currencies;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An amount in major units of the currency ("15.50"), with as many decimals as the currency has.
 * The message says how to type it instead of "format is invalid".
 */
class MoneyAmount implements ValidationRule
{
    public function __construct(private readonly string $currency, private readonly bool $negative = false) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $decimals = Currencies::decimals($this->currency);
        $fraction = $decimals > 0 ? '(\.\d{1,'.$decimals.'})?' : '';

        if (! is_scalar($value) || ! preg_match('/^'.($this->negative ? '-?' : '').'\d{1,9}'.$fraction.'$/', (string) $value)) {
            $fail($decimals > 0 ? 'validation.money_amount' : 'validation.money_amount_whole')->translate([
                'example' => $decimals > 0 ? '15.'.str_repeat('5', $decimals) : '1500',
            ]);
        }
    }
}
