<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\CartService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return $this->staff();
    }

    protected function product(int $stock = 10): Product
    {
        $category = Category::create(['name' => 'Gadgets', 'is_active' => true]);

        return Product::create([
            'category_id' => $category->id,
            'name' => 'Test Headphones',
            'price' => 1000,
            'stock' => $stock,
            'is_active' => true,
        ]);
    }

    public function test_stock_pages_are_admin_only(): void
    {
        $customer = User::create(['name' => 'Guest', 'email' => 'g@example.com', 'password' => 'password123']);

        $this->actingAs($customer)->get(route('admin.stock.index'))->assertForbidden();
        $this->actingAs($this->admin())->get(route('admin.stock.index'))->assertOk();
    }

    public function test_admin_can_record_stock_in_and_out(): void
    {
        $admin = $this->admin();
        $product = $this->product(10);

        $this->actingAs($admin)->get(route('admin.stock.create', ['product' => $product->id]))->assertOk();

        $this->actingAs($admin)->post(route('admin.stock.store'), [
            'variant_id' => $product->variants()->value('id'),
            'type' => 'in',
            'quantity' => 15,
            'reason' => 'purchase',
            'note' => 'Invoice #42',
        ])->assertRedirect(route('admin.stock.index'));

        $this->actingAs($admin)->post(route('admin.stock.store'), [
            'variant_id' => $product->variants()->value('id'),
            'type' => 'out',
            'quantity' => 5,
            'reason' => 'damaged',
        ])->assertRedirect(route('admin.stock.index'));

        $this->assertSame(20, $product->fresh()->stock);
        $this->assertDatabaseHas('stock_movements', [
            'variant_id' => $product->variants()->value('id'), 'user_id' => $admin->id, 'type' => 'in',
            'quantity' => 15, 'stock_before' => 10, 'stock_after' => 25, 'note' => 'Invoice #42',
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'type' => 'out', 'reason' => 'damaged', 'stock_before' => 25, 'stock_after' => 20,
        ]);

        $this->actingAs($admin)->get(route('admin.stock.index', ['product' => $product->id]))
            ->assertOk()->assertSee('Invoice #42')->assertSee('Damaged');
    }

    public function test_stock_out_cannot_exceed_available_stock(): void
    {
        $product = $this->product(3);

        $this->actingAs($this->admin())->post(route('admin.stock.store'), [
            'variant_id' => $product->variants()->value('id'),
            'type' => 'out',
            'quantity' => 4,
            'reason' => 'lost',
        ])->assertSessionHas('error');

        $this->assertSame(3, $product->fresh()->stock);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_reason_must_match_the_movement_type(): void
    {
        $product = $this->product(3);

        $this->actingAs($this->admin())->post(route('admin.stock.store'), [
            'variant_id' => $product->variants()->value('id'),
            'type' => 'in',
            'quantity' => 1,
            'reason' => 'damaged',
        ])->assertSessionHasErrors('reason');
    }

    public function test_product_form_and_checkout_are_logged(): void
    {
        $admin = $this->admin();
        $category = Category::create(['name' => 'Books', 'is_active' => true]);
        $fields = ['category_id' => $category->id, 'name' => 'Laravel in Action', 'short_description' => 'Short text', 'description' => 'Full text', 'cost_price' => 500, 'price' => 1200, 'is_active' => 1];

        $this->actingAs($admin)->post(route('admin.products.store'), $fields + ['variants' => [['id' => null]]]);
        $product = Product::firstOrFail();
        $variant = $product->variants()->firstOrFail();
        app(StockService::class)->move($variant, 'in', 7, 'opening');

        // Saving the product form again never changes stock, whatever number is sent.
        $this->actingAs($admin)->put(route('admin.products.update', $product), $fields + ['variants' => [['id' => $variant->id, 'opening_stock' => 4]]]);

        $this->assertSame(7, $product->fresh()->stock);
        $this->assertSame(
            [['in', 'opening', 7]],
            StockMovement::orderBy('id')->get()->map(fn ($m) => [$m->type, $m->reason, $m->quantity])->all()
        );

        auth()->logout();
        $this->withCookie(CartService::TOKEN_COOKIE, str_repeat('t', 40));
        $this->post(route('cart.store', $product), ['quantity' => 2]);
        $this->post(route('checkout.store'), [
            'customer_name' => 'Rahim',
            'customer_email' => 'rahim@example.com',
            'customer_phone' => '01700000001',
            'shipping_address' => 'Mirpur, Dhaka',
            'payment_method' => 'cod',
        ]);

        $this->assertSame(5, $product->fresh()->stock);
        $this->assertDatabaseHas('stock_movements', ['variant_id' => $variant->id, 'type' => 'out', 'reason' => 'sale', 'quantity' => 2, 'stock_after' => 5]);
        $this->assertNotNull(StockMovement::where('reason', 'sale')->value('order_id'));
    }
}
