<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\Barcode;
use App\Support\BarcodeRenderer;
use App\Support\Money;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BarcodeLabelTest extends TestCase
{
    use RefreshDatabase;

    protected const EAN = '4006381333931';

    protected function product(): Product
    {
        $category = Category::create(['name' => 'Kurti', 'is_active' => true]);

        $product = new Product(['category_id' => $category->id, 'name' => 'Premium Kurti', 'price' => 1200, 'is_active' => true, 'has_variants' => true]);
        $product->withoutDefaultVariant = true;
        $product->save();

        foreach ([['Black', 'M', self::EAN], ['Black', 'L', null], ['White', 'M', 'KRT-WHT-M-001']] as $i => [$color, $size, $barcode]) {
            $variant = new ProductVariant(['sku' => 'KRT-' . $color . '-' . $size, 'color' => $color, 'size' => $size, 'barcode' => $barcode, 'is_active' => true, 'sort_order' => $i]);
            $variant->product_id = $product->id;
            $variant->save();
        }

        return $product;
    }

    protected function variant(Product $product, string $color, string $size): ProductVariant
    {
        return $product->variants()->where('color', $color)->where('size', $size)->firstOrFail();
    }

    public function test_the_renderer_uses_ean13_for_valid_codes_and_code128_otherwise(): void
    {
        $this->assertSame('Asia/Dhaka', config('app.timezone'));

        $this->assertSame('EAN-13', BarcodeRenderer::symbology(self::EAN));
        $this->assertSame('Code 128', BarcodeRenderer::symbology('KRT-WHT-M-001'));
        $this->assertSame('Code 128', BarcodeRenderer::symbology('4006381333932'));

        $svg = BarcodeRenderer::svg(self::EAN);
        $this->assertStringContainsString('<svg preserveAspectRatio="none"', $svg);
        $this->assertStringContainsString('<rect', $svg);
        $this->assertStringNotContainsString('<?xml', $svg);

        $generated = Barcode::generateUnique();
        $this->assertTrue(Barcode::isValidEan13($generated));
        $this->assertStringStartsWith('2', $generated);
    }

    public function test_labels_show_store_product_variant_sku_price_and_barcode(): void
    {
        $product = $this->product();
        $blackM = $this->variant($product, 'Black', 'M');
        $whiteM = $this->variant($product, 'White', 'M');

        $response = $this->actingAs($this->staff('sales_staff'))->post(route('admin.barcodes.print'), [
            'items' => [
                ['variant_id' => $blackM->id, 'quantity' => 2],
                ['variant_id' => $whiteM->id, 'quantity' => 1],
            ],
            'size' => 'roll_50x30',
            'show_price' => 1,
        ]);

        $response->assertOk()
            ->assertSee(Settings::get('store_name'))
            ->assertSee('Premium Kurti')
            ->assertSee('Black / M')
            ->assertSee($blackM->sku)
            ->assertSee(self::EAN)
            ->assertSee('KRT-WHT-M-001')
            ->assertSee(Money::format(1200, false))
            ->assertSee('<svg preserveAspectRatio="none"', false)
            ->assertSee('size: 50mm 30mm', false);

        $this->assertSame(3, substr_count($response->getContent(), '<div class="label">'));
    }

    public function test_items_without_a_barcode_cannot_be_printed(): void
    {
        $product = $this->product();
        $blackL = $this->variant($product, 'Black', 'L');

        $this->actingAs($this->staff())
            ->from(route('admin.barcodes.index'))
            ->post(route('admin.barcodes.print'), ['items' => [['variant_id' => $blackL->id, 'quantity' => 1]], 'size' => 'a4_3x8'])
            ->assertRedirect(route('admin.barcodes.index'))
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'Black / L'));
    }

    public function test_generating_fills_only_missing_barcodes_and_needs_edit_permission(): void
    {
        $product = $this->product();
        $blackM = $this->variant($product, 'Black', 'M');
        $blackL = $this->variant($product, 'Black', 'L');

        $sales = $this->staff('sales_staff');
        $this->actingAs($sales)->get(route('admin.barcodes.index', ['product' => $product->id]))->assertOk()->assertSee($blackL->sku);
        $this->actingAs($sales)->post(route('admin.barcodes.generate'), ['scope' => 'product', 'product_id' => $product->id])->assertForbidden();
        $this->assertNull($blackL->fresh()->barcode);

        $this->actingAs($this->staff())
            ->post(route('admin.barcodes.generate'), ['scope' => 'product', 'product_id' => $product->id])
            ->assertRedirect(route('admin.barcodes.index', ['product' => $product->id]))
            ->assertSessionHas('success');

        $this->assertTrue(Barcode::isValidEan13($blackL->fresh()->barcode));
        $this->assertSame(self::EAN, $blackM->fresh()->barcode);
        $this->assertDatabaseHas('activity_logs', ['action' => 'barcodes_generated']);

        // Nothing left to generate.
        $this->actingAs($this->staff())
            ->post(route('admin.barcodes.generate'), ['scope' => 'selected', 'variant_ids' => [$blackL->id]])
            ->assertSessionHas('warning');
    }

    public function test_label_links_appear_on_product_and_stock_screens(): void
    {
        $product = $this->product();
        $admin = $this->staff();

        $labelsUrl = route('admin.barcodes.index', ['product' => $product->id]);

        $this->actingAs($admin)->get(route('admin.products.index'))->assertOk()->assertSee($labelsUrl, false);
        $this->actingAs($admin)->get(route('admin.products.edit', $product))->assertOk()->assertSee('Print barcode labels');
        $this->actingAs($admin)->get(route('admin.stock.index', ['product' => $product->id]))->assertOk()->assertSee('Print labels');
        $this->actingAs($admin)->get(route('admin.barcodes.index'))->assertOk()->assertSee('active item(s) in the catalogue have no barcode')
            ->assertSee('Generate all missing barcodes');
    }
}
