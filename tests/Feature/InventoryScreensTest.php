<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Services\StockService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryScreensTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{plenty: ProductVariant, low: ProductVariant, out: ProductVariant, inactive: ProductVariant} */
    protected function catalogue(): array
    {
        $category = Category::create(['name' => 'Saree', 'is_active' => true]);

        $product = new Product([
            'category_id' => $category->id, 'name' => 'Jamdani Saree', 'price' => 5000,
            'cost_price' => 3000, 'is_active' => true, 'has_variants' => true,
        ]);
        $product->withoutDefaultVariant = true;
        $product->save();

        $make = function (string $color, int $stock, bool $active = true, ?string $barcode = null) use ($product) {
            $variant = new ProductVariant(['sku' => 'SAR-' . strtoupper($color), 'color' => $color, 'barcode' => $barcode, 'is_active' => $active]);
            $variant->product_id = $product->id;
            $variant->stock = $stock;
            $variant->save();

            return $variant;
        };

        return [
            'plenty' => $make('Red', 10, barcode: '4006381333931'),
            'low' => $make('Blue', 3),
            'out' => $make('Green', 0),
            'inactive' => $make('Black', 7, false),
        ];
    }

    public function test_stock_overview_shows_totals_and_filters_by_status_and_search(): void
    {
        $this->catalogue();
        $admin = $this->staff();

        // 20 units at a purchase price of 3,000 each (inactive stock is still on the shelf).
        $this->actingAs($admin)->get(route('admin.inventory.index'))->assertOk()
            ->assertSee('SAR-RED')->assertSee('SAR-GREEN')
            ->assertSee(Money::format(60000));

        $this->actingAs($admin)->get(route('admin.inventory.index', ['status' => 'low']))->assertOk()
            ->assertSee('SAR-BLUE')->assertDontSee('SAR-RED')->assertDontSee('SAR-GREEN');

        $this->actingAs($admin)->get(route('admin.inventory.index', ['status' => 'out']))->assertOk()
            ->assertSee('SAR-GREEN')->assertDontSee('SAR-BLUE');

        $this->actingAs($admin)->get(route('admin.inventory.index', ['status' => 'inactive']))->assertOk()
            ->assertSee('SAR-BLACK')->assertDontSee('SAR-RED');

        $this->actingAs($admin)->get(route('admin.inventory.index', ['q' => '4006381333931', 'sort' => 'value_desc']))->assertOk()
            ->assertSee('SAR-RED')->assertDontSee('SAR-BLUE');
    }

    public function test_inventory_export_needs_export_permission_and_is_spreadsheet_safe(): void
    {
        $variants = $this->catalogue();
        $variants['low']->forceFill(['sku' => '=HYPERLINK("x")'])->save();

        $this->actingAs($this->staff('warehouse_staff'))->get(route('admin.inventory.export'))->assertForbidden();

        $response = $this->actingAs($this->staff())->get(route('admin.inventory.export', ['status' => 'low']));
        $response->assertOk();
        $this->assertStringStartsWith('text/csv', $response->headers->get('content-type'));

        $csv = $response->streamedContent();
        $this->assertStringContainsString('Stock value (cost)', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString('SAR-RED', $csv);
    }

    public function test_a_stock_count_records_only_differences_and_keeps_sales_made_meanwhile(): void
    {
        $variants = $this->catalogue();
        $counter = $this->staff('warehouse_staff');

        $this->actingAs($counter)->get(route('admin.inventory.count'))->assertOk()->assertSee('Choose what you are counting');
        $this->actingAs($counter)->get(route('admin.inventory.count', ['category' => $variants['plenty']->product->category_id]))
            ->assertOk()->assertSee('SAR-RED')->assertSee('SAR-BLUE');

        // A sale happens while the shelf is being counted.
        app(StockService::class)->move($variants['plenty'], 'out', 1, 'offline_sale');

        $this->actingAs($counter)->post(route('admin.inventory.count.store'), [
            'counts' => [
                ['variant_id' => $variants['plenty']->id, 'expected' => 10, 'counted' => 8],
                ['variant_id' => $variants['low']->id, 'expected' => 3, 'counted' => 3],
                ['variant_id' => $variants['out']->id, 'expected' => 0, 'counted' => null],
            ],
            'note' => 'Monthly count',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame(7, $variants['plenty']->fresh()->stock);
        $this->assertSame(3, $variants['low']->fresh()->stock);
        $this->assertDatabaseHas('stock_movements', [
            'variant_id' => $variants['plenty']->id, 'reason' => 'adjustment', 'type' => 'out', 'quantity' => 2, 'user_id' => $counter->id,
        ]);
        $this->assertSame(0, StockMovement::where('variant_id', $variants['low']->id)->count());
        $this->assertDatabaseHas('activity_logs', ['action' => 'stock_count']);

        $this->actingAs($counter)->post(route('admin.inventory.count.store'), [
            'counts' => [['variant_id' => $variants['low']->id, 'expected' => 3, 'counted' => 3]],
        ])->assertSessionHas('warning');

        $this->actingAs($this->staff('sales_staff'))->get(route('admin.inventory.count'))->assertForbidden();
    }

    public function test_movement_details_page_and_history_export(): void
    {
        $variants = $this->catalogue();
        $admin = $this->staff();
        $this->actingAs($admin);

        $movement = app(StockService::class)->move($variants['plenty'], 'in', 5, 'manual_in', 'Found in the back room');

        $this->get(route('admin.stock.show', $movement))->assertOk()
            ->assertSee('Found in the back room')->assertSee('Manual stock in')
            ->assertSee('SAR-RED')->assertSee($admin->name)->assertSee('10 &rarr; 15', false);

        $this->get(route('admin.stock.index'))->assertOk()->assertSee(route('admin.stock.show', $movement), false);

        $csv = $this->get(route('admin.stock.export', ['reason' => 'manual_in']))->assertOk()->streamedContent();
        $this->assertStringContainsString('Found in the back room', $csv);
        $this->assertStringContainsString('Manual stock in', $csv);

        $this->actingAs($this->staff('warehouse_staff'))->get(route('admin.stock.show', $movement))->assertOk();
        $this->actingAs($this->staff('accountant'))->get(route('admin.stock.show', $movement))->assertForbidden();
    }
}
