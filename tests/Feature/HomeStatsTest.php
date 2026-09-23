<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeStatsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    protected function payload(array $tiles = []): array
    {
        return [
            'home_stats_enabled' => '1',
            'stats' => $tiles ?: [
                ['label' => 'Sarees', 'icon' => 'box', 'source' => 'products', 'value' => ''],
                ['label' => 'Shoppers', 'icon' => 'users', 'source' => 'customers', 'value' => ''],
            ],
        ];
    }

    public function test_a_tile_counts_the_catalogue_under_its_own_label(): void
    {
        $category = Category::create(['name' => 'Saree', 'is_active' => true]);

        foreach (['Katan', 'Jamdani', 'Silk'] as $name) {
            Product::create([
                'category_id' => $category->id,
                'name' => $name,
                'price' => 2500,
                'stock' => 2,
                'is_active' => true,
            ]);
        }

        $this->actingAs($this->staff())
            ->put(route('admin.settings.stats.update'), $this->payload())
            ->assertSessionHas('success');

        $this->get(route('home'))->assertOk()->assertSee('Sarees')->assertSee('Shoppers');

        $this->assertSame('3', $this->statValue('Sarees'));
        $this->assertSame('0', $this->statValue('Shoppers'));

        Customer::create(['name' => 'Rijon', 'phone' => '01812345678']);

        $this->assertSame('1', $this->statValue('Shoppers'));
    }

    public function test_a_tile_can_show_fixed_text_instead_of_a_count(): void
    {
        $this->actingAs($this->staff())->put(route('admin.settings.stats.update'), $this->payload([
            ['label' => 'Years', 'icon' => 'chart', 'source' => 'manual', 'value' => '12+'],
        ]));

        $this->assertSame('12+', $this->statValue('Years'));
    }

    public function test_fixed_text_must_actually_be_given(): void
    {
        $this->actingAs($this->staff())
            ->from(route('admin.settings.stats.edit'))
            ->put(route('admin.settings.stats.update'), $this->payload([
                ['label' => 'Years', 'icon' => 'chart', 'source' => 'manual', 'value' => ''],
            ]))
            ->assertRedirect(route('admin.settings.stats.edit'))
            ->assertSessionHas('error');

        $this->assertSame('Product', Settings::get('home_stats')[0]['label']);
    }

    public function test_the_whole_strip_can_be_hidden(): void
    {
        $this->actingAs($this->staff())->put(route('admin.settings.stats.update'),
            ['home_stats_enabled' => '0'] + $this->payload());

        $this->get(route('home'))->assertOk()->assertDontSee('Sarees');
    }

    public function test_staff_without_the_settings_permission_cannot_edit_the_strip(): void
    {
        $sales = $this->staff('sales_staff');

        $this->actingAs($sales)->get(route('admin.settings.stats.edit'))->assertForbidden();
        $this->actingAs($sales)->put(route('admin.settings.stats.update'), $this->payload())->assertForbidden();
    }

    /** The figure rendered in the tile with the given label. */
    protected function statValue(string $label): string
    {
        $html = $this->get(route('home'))->assertOk()->getContent();

        preg_match('/df-stat__label">' . preg_quote($label, '/') . '<\/p>\s*<p class="df-stat__value">([^<]*)</', $html, $matches);

        return trim($matches[1] ?? '');
    }
}
