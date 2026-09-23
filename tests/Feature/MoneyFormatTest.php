<?php

namespace Tests\Feature;

use App\Services\SettingsRepository;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MoneyFormatTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_dollar_sign_goes_before_the_amount_and_any_other_symbol_after_it(): void
    {
        $settings = app(SettingsRepository::class);

        foreach (['Tk' => '1,500.00 Tk', 'TK' => '1,500.00 TK', '৳' => '1,500.00 ৳', '$' => '$ 1,500.00'] as $symbol => $expected) {
            $settings->set(['currency_symbol' => $symbol]);

            $this->assertSame($expected, Money::format(1500), 'symbol ' . $symbol);
        }

        $this->assertSame('$ 1,500', Money::format(1500, false));
        $this->assertStringContainsString('return true ?', Money::jsFormatter());

        $settings->set(['currency_symbol' => 'TK']);
        $this->assertSame('-100.09 TK', Money::format(-100.09));
        $this->assertStringContainsString('return false ?', Money::jsFormatter());
        // Safe inside an Alpine x-data="..." attribute.
        $this->assertStringNotContainsString('"', Money::jsFormatter());
    }
}
