<?php

namespace App\Support;

use Illuminate\Support\Js;

class Money
{
    /** Currency symbol from Admin → Settings (Tk by default). */
    public static function symbol(): string
    {
        return (string) Settings::get('currency_symbol', 'Tk');
    }

    /** "$ 1,500.00" puts the dollar sign first; every other symbol follows the amount: "1,500.00 Tk". */
    public static function symbolFirst(): bool
    {
        return trim(self::symbol()) === '$';
    }

    public static function format(float|int|string|null $amount, bool $decimals = true): string
    {
        $number = number_format((float) $amount, $decimals ? 2 : 0);

        return self::symbolFirst() ? self::symbol() . ' ' . $number : $number . ' ' . self::symbol();
    }

    /**
     * The same formatting for Alpine components, as a JavaScript function
     * expression: `money(value) { return ({!! Money::jsFormatter() !!})(value); }`.
     * Holds no double quotes, so it is safe inside an x-data attribute.
     */
    public static function jsFormatter(): string
    {
        $symbol = Js::from(self::symbol());
        $first = self::symbolFirst() ? 'true' : 'false';

        return "(value) => { const n = (Number(value) || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); "
            . "return {$first} ? {$symbol} + ' ' + n : n + ' ' + {$symbol}; }";
    }
}
