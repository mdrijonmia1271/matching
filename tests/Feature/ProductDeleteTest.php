<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected function product(): Product
    {
        $category = Category::create(['name' => 'Kurti', 'is_active' => true]);

        $product = Product::create([
            'category_id' => $category->id, 'name' => 'Premium Kurti', 'price' => 1000,
            'cost_price' => 600, 'is_active' => true,
        ]);

        app(StockService::class)->move($product->variants()->firstOrFail(), 'in', 5, 'manual_in');

        return $product;
    }

    public function test_delete_removes_the_product_its_variants_and_stock_history_but_keeps_sales(): void
    {
        $product = $this->product();
        $variant = $product->variants()->firstOrFail();
        $admin = $this->staff();

        $this->actingAs($admin)->post(route('admin.pos.store'), [
            'items' => [['variant_id' => $variant->id, 'quantity' => 1]],
            'payments' => [['method' => 'cash', 'account_id' => Account::where('code', 'cash')->value('id'), 'amount' => 1000]],
        ])->assertSessionHas('success');

        $this->actingAs($admin)->get(route('admin.products.index'))->assertSee('Barcode')->assertDontSee('>Archive</button>', false);

        $this->actingAs($admin)->delete(route('admin.products.destroy', $product))->assertSessionHas('success');

        $this->assertNull(Product::withTrashed()->find($product->id));
        $this->assertNull(ProductVariant::withTrashed()->find($variant->id));
        $this->assertSame(0, StockMovement::where('product_id', $product->id)->count());

        // The sale is still there with its own copy of the name and price.
        $item = Order::firstOrFail()->items()->firstOrFail();
        $this->assertNull($item->product_id);
        $this->assertSame('Premium Kurti', $item->product_name);
        $this->assertSame(1000.0, (float) $item->price);
    }

    public function test_an_archived_product_can_be_deleted(): void
    {
        $product = $this->product();
        $product->delete();

        $this->actingAs($this->staff())->delete(route('admin.products.destroy', $product))->assertSessionHas('success');

        $this->assertNull(Product::withTrashed()->find($product->id));
    }

    public function test_a_product_bought_from_a_supplier_cannot_be_deleted(): void
    {
        $product = $this->product();
        $variant = $product->variants()->firstOrFail();

        $purchase = Purchase::create([
            'supplier_id' => Supplier::create(['name' => 'Karim Fabrics'])->id,
            'purchase_date' => today(), 'subtotal' => 600, 'total' => 600,
        ]);
        $purchase->items()->create([
            'product_id' => $product->id, 'variant_id' => $variant->id,
            'quantity' => 1, 'unit_cost' => 600, 'line_total' => 600,
        ]);

        $this->actingAs($this->staff())->delete(route('admin.products.destroy', $product))->assertSessionHas('error');

        $this->assertNotNull(Product::find($product->id));
        $this->assertSame(5, (int) $variant->fresh()->stock);
    }
}
