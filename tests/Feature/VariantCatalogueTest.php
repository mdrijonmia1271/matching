<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Services\CartService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class VariantCatalogueTest extends TestCase
{
    use RefreshDatabase;

    /** A real EAN-13 with a correct check digit. */
    protected const EAN = '4006381333931';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->withCookie(CartService::TOKEN_COOKIE, str_repeat('v', 40));
    }

    protected function payload(array $overrides = []): array
    {
        $category = Category::firstOrCreate(['name' => 'Kurti'], ['is_active' => true]);

        return array_merge([
            'category_id' => $category->id,
            'name' => 'Premium Kurti',
            'cost_price' => 800,
            'price' => 1200,
            'is_active' => 1,
            'has_variants' => 1,
            'variants' => [
                ['color' => 'Black', 'size' => 'S', 'opening_stock' => 3, 'is_active' => 1],
                ['color' => 'Black', 'size' => 'M', 'opening_stock' => 5, 'is_active' => 1, 'barcode' => self::EAN],
                ['color' => 'White', 'size' => 'M', 'opening_stock' => 0, 'is_active' => 1, 'price' => 1300, 'cost_price' => 850],
            ],
        ], $overrides);
    }

    protected function createKurti(): Product
    {
        $this->actingAs($this->staff())
            ->post(route('admin.products.store'), $this->payload())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.products.index'));

        auth()->logout();

        return Product::where('name', 'Premium Kurti')->firstOrFail();
    }

    protected function variant(Product $product, string $color, string $size): ProductVariant
    {
        return $product->variants()->where('color', $color)->where('size', $size)->firstOrFail();
    }

    protected function checkoutFields(): array
    {
        return [
            'customer_name' => 'Rahima',
            'customer_email' => 'rahima@example.com',
            'customer_phone' => '01755555555',
            'shipping_address' => 'Mohammadpur, Dhaka',
            'payment_method' => 'cod',
        ];
    }

    public function test_admin_creates_a_product_with_colour_and_size_variants(): void
    {
        $product = $this->createKurti();

        $this->assertTrue($product->has_variants);
        $this->assertSame(3, $product->variants()->count());
        $this->assertSame(8, $product->stock);

        $blackS = $this->variant($product, 'Black', 'S');
        $blackM = $this->variant($product, 'Black', 'M');
        $whiteM = $this->variant($product, 'White', 'M');

        $this->assertMatchesRegularExpression('/^KRT-BLK-S-\d{3}$/', $blackS->sku);
        $this->assertSame(3, ProductVariant::distinct()->count('sku'));
        $this->assertSame(self::EAN, $blackM->barcode);

        $this->assertSame(3, $blackS->stock);
        $this->assertSame(0, $whiteM->stock);
        $this->assertSame(1300.0, $whiteM->current_price);
        $this->assertSame(850.0, $whiteM->effective_cost);
        $this->assertSame(800.0, $blackS->effective_cost);

        // Opening stock goes through the ledger, per variant.
        $this->assertSame(
            [[$blackS->id, 'opening', 3], [$blackM->id, 'opening', 5]],
            StockMovement::orderBy('id')->get()->map(fn ($m) => [$m->variant_id, $m->reason, $m->quantity])->all()
        );
    }

    public function test_duplicate_and_invalid_skus_and_barcodes_are_rejected(): void
    {
        $existing = $this->createKurti();
        $admin = $this->staff();

        $this->actingAs($admin)->post(route('admin.products.store'), $this->payload([
            'name' => 'Second Kurti',
            'variants' => [['color' => 'Red', 'size' => 'M', 'barcode' => self::EAN, 'is_active' => 1]],
        ]))->assertSessionHasErrors('variants.0.barcode');

        $this->actingAs($admin)->post(route('admin.products.store'), $this->payload([
            'name' => 'Second Kurti',
            'variants' => [['color' => 'Red', 'size' => 'M', 'barcode' => '4006381333932', 'is_active' => 1]],
        ]))->assertSessionHasErrors('variants.0.barcode');

        $this->actingAs($admin)->post(route('admin.products.store'), $this->payload([
            'name' => 'Second Kurti',
            'variants' => [
                ['color' => 'Red', 'size' => 'M', 'sku' => 'DUP-1', 'is_active' => 1],
                ['color' => 'Red', 'size' => 'L', 'sku' => 'DUP-1', 'is_active' => 1],
            ],
        ]))->assertSessionHasErrors('variants.1.sku');

        $this->actingAs($admin)->post(route('admin.products.store'), $this->payload([
            'name' => 'Second Kurti',
            'variants' => [
                ['color' => 'Red', 'size' => 'M', 'is_active' => 1],
                ['color' => 'red', 'size' => 'm', 'is_active' => 1],
            ],
        ]))->assertSessionHasErrors('variants.1.size');

        $this->actingAs($admin)->post(route('admin.products.store'), $this->payload([
            'name' => 'Second Kurti',
            'has_variants' => 0,
            'sku' => $existing->variants()->first()->sku,
            'variants' => [['opening_stock' => 1]],
        ]))->assertSessionHasErrors('sku');

        $this->assertSame(1, Product::count());
    }

    public function test_the_storefront_sells_the_chosen_variant_only(): void
    {
        $product = $this->createKurti();
        $blackM = $this->variant($product, 'Black', 'M');
        $blackS = $this->variant($product, 'Black', 'S');

        $this->get(route('shop.show', $product))->assertOk()->assertSee('Colour')->assertSee('Choose');

        $this->post(route('cart.store', $product))->assertSessionHas('error');
        $this->assertDatabaseCount('cart_items', 0);

        $this->post(route('cart.store', $product), ['variant_id' => $blackM->id, 'quantity' => 10])->assertSessionHas('warning');
        $this->assertDatabaseHas('cart_items', ['variant_id' => $blackM->id, 'quantity' => 5]);

        $this->get(route('cart.index'))->assertOk()->assertSee('Black / M');

        $this->post(route('checkout.store'), $this->checkoutFields())->assertRedirect();

        $item = Order::firstOrFail()->items()->firstOrFail();
        $this->assertSame($blackM->id, $item->variant_id);
        $this->assertSame($blackM->sku, $item->sku);
        $this->assertSame('Black / M', $item->variant_label);
        $this->assertEquals(800.0, (float) $item->unit_cost);

        $this->assertSame(0, $blackM->fresh()->stock);
        $this->assertSame(3, $blackS->fresh()->stock);
        $this->assertSame(3, $product->fresh()->stock);
        $this->assertDatabaseHas('stock_movements', ['variant_id' => $blackM->id, 'reason' => 'sale', 'quantity' => 5, 'stock_after' => 0]);
    }

    public function test_price_changes_are_shown_to_the_customer_before_the_order_is_placed(): void
    {
        $product = $this->createKurti();
        $blackS = $this->variant($product, 'Black', 'S');

        $this->post(route('cart.store', $product), ['variant_id' => $blackS->id]);
        $product->update(['price' => 1500]);

        $this->post(route('checkout.store'), $this->checkoutFields())->assertSessionHas('error');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseHas('cart_items', ['variant_id' => $blackS->id, 'price' => 1500]);

        $this->post(route('checkout.store'), $this->checkoutFields())->assertRedirect();
        $this->assertEquals(1500.0, (float) Order::firstOrFail()->subtotal);
    }

    public function test_saving_the_product_form_never_changes_stock(): void
    {
        $admin = $this->staff();
        $category = Category::create(['name' => 'Hijab', 'is_active' => true]);
        $fields = ['category_id' => $category->id, 'name' => 'White Chiffon Hijab', 'price' => 650, 'is_active' => 1, 'has_variants' => 0];

        $this->actingAs($admin)->post(route('admin.products.store'), $fields + ['variants' => [['opening_stock' => 10]]])
            ->assertSessionHasNoErrors();

        $product = Product::firstOrFail();
        $variant = $product->variants()->firstOrFail();
        $this->assertSame($product->sku, $variant->sku);

        app(StockService::class)->move($variant, 'out', 4, 'offline_sale');

        // The form was opened before that sale and still carries the old numbers.
        $this->actingAs($admin)->put(route('admin.products.update', $product), array_merge($fields, [
            'price' => 700,
            'variants' => [['id' => $variant->id, 'opening_stock' => 10]],
        ]))->assertSessionHasNoErrors();

        $this->assertSame(6, $variant->fresh()->stock);
        $this->assertSame(6, $product->fresh()->stock);
        $this->assertEquals(700, (float) $product->fresh()->price);
        $this->assertSame(2, StockMovement::count());
    }

    public function test_variants_with_stock_are_kept_and_unused_variants_are_deleted(): void
    {
        $product = $this->createKurti();
        $admin = $this->staff();

        $rowsWithout = fn (string $label) => $product->variants()->get()
            ->reject(fn ($variant) => $variant->label === $label)
            ->map(fn ($variant) => ['id' => $variant->id, 'color' => $variant->color, 'size' => $variant->size, 'is_active' => 1])
            ->values()->all();

        $this->actingAs($admin)->put(route('admin.products.update', $product), $this->payload(['variants' => $rowsWithout('Black / S')]))
            ->assertSessionHas('error');
        $this->assertSame(3, $product->variants()->count());

        $whiteM = $this->variant($product, 'White', 'M');

        $this->actingAs($admin)->put(route('admin.products.update', $product), $this->payload(['variants' => $rowsWithout('White / M')]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('product_variants', ['id' => $whiteM->id]);
        $this->assertSame(2, $product->variants()->count());
    }

    public function test_subcategories_belong_to_their_category_and_filter_the_shop(): void
    {
        $kurti = Category::create(['name' => 'Kurti', 'is_active' => true]);
        $saree = Category::create(['name' => 'Saree', 'is_active' => true]);
        $admin = $this->staff();

        $this->actingAs($admin)->post(route('admin.categories.store'), ['name' => 'Cotton Kurti', 'parent_id' => $kurti->id, 'is_active' => 1])
            ->assertSessionHasNoErrors();
        $cotton = Category::where('name', 'Cotton Kurti')->firstOrFail();

        $single = ['name' => 'Block Print Kurti', 'price' => 1500, 'is_active' => 1, 'has_variants' => 0, 'variants' => [['opening_stock' => 2]]];

        $this->actingAs($admin)->post(route('admin.products.store'), $single + ['category_id' => $saree->id, 'subcategory_id' => $cotton->id])
            ->assertSessionHasErrors('subcategory_id');

        $this->actingAs($admin)->post(route('admin.products.store'), $single + ['category_id' => $kurti->id, 'subcategory_id' => $cotton->id])
            ->assertSessionHasNoErrors();

        $this->get(route('shop.index', ['category' => 'cotton-kurti']))->assertOk()->assertSee('Block Print Kurti');
        $this->actingAs($admin)->get(route('admin.categories.index'))->assertOk()->assertSee('Cotton Kurti');

        $this->actingAs($admin)->delete(route('admin.categories.destroy', $kurti))->assertSessionHas('error');
    }

    public function test_products_created_in_code_get_a_default_variant(): void
    {
        $category = Category::create(['name' => 'Shawl', 'is_active' => true]);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Cream Shawl', 'price' => 1450, 'stock' => 4, 'is_active' => true]);

        $variant = $product->variants()->sole();
        $this->assertSame($product->sku, $variant->sku);
        $this->assertSame(4, $variant->stock);
        $this->assertSame('Default', $variant->label);
    }

    public function test_admin_screens_and_barcode_lookup_work_with_variants(): void
    {
        $product = $this->createKurti();
        $admin = $this->staff();
        $blackM = $this->variant($product, 'Black', 'M');

        $this->actingAs($admin)->get(route('admin.products.create'))->assertOk();
        $this->actingAs($admin)->get(route('admin.products.edit', $product))->assertOk()->assertSee($blackM->sku);
        $this->actingAs($admin)->get(route('admin.products.index', ['q' => self::EAN]))->assertOk()->assertSee('Premium Kurti');
        $this->actingAs($admin)->get(route('admin.stock.create', ['variant' => $blackM->id]))->assertOk()->assertSee('Black / M');
        $this->actingAs($admin)->get(route('admin.stock.index', ['variant' => $blackM->id]))->assertOk()->assertSee($blackM->sku);
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();

        // Faked so the brand logo this posts does not land in the real storage folder.
        Storage::fake('public');

        $this->actingAs($admin)->post(route('admin.brands.store'), [
            'name' => 'Aarong', 'logo' => UploadedFile::fake()->image('aarong.png', 240, 80),
        ])->assertSessionHas('success');
        $this->actingAs($admin)->get(route('admin.brands.index'))->assertOk()->assertSee('Aarong');

        $this->actingAs($admin)->getJson(route('admin.variants.search', ['q' => self::EAN]))
            ->assertOk()
            ->assertJsonPath('exact', true)
            ->assertJsonPath('results.0.id', $blackM->id)
            ->assertJsonPath('results.0.label', 'Black / M')
            ->assertJsonPath('results.0.stock', 5);

        // Stock entry by variant.
        $this->actingAs($admin)->post(route('admin.stock.store'), [
            'variant_id' => $blackM->id, 'type' => 'in', 'quantity' => 2, 'reason' => 'manual_in',
        ])->assertRedirect(route('admin.stock.index'));
        $this->assertSame(7, $blackM->fresh()->stock);
        $this->assertSame(10, $product->fresh()->stock);
    }
}
