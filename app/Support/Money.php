<?php

namespace App\Support;

class Money
{
    /** Currency symbol from Admin → Settings (Tk by default). */
    public static function symbol(): string
    {
        return (string) Settings::get('currency_symbol', 'Tk');
    }

    public static function format(float|int|string|null $amount, bool $decimals = true): string
    {
        return self::symbol() . ' ' . number_format((float) $amount, $decimals ? 2 : 0);
    }
}
