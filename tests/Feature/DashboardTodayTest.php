<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTodayTest extends TestCase
{
    use RefreshDatabase;

    protected function variant(): ProductVariant
    {
        $category = Category::create(['name' => 'Kurti', 'is_active' => true]);

        $product = new Product([
            'category_id' => $category->id, 'name' => 'Premium Kurti', 'price' => 1000,
            'cost_price' => 600, 'is_active' => true, 'has_variants' => true,
        ]);
        $product->withoutDefaultVariant = true;
        $product->save();

        $variant = new ProductVariant(['sku' => 'KUR-M', 'size' => 'M', 'is_active' => true]);
        $variant->product_id = $product->id;
        $variant->stock = 10;
        $variant->save();

        return $variant;
    }

    protected function order(float $total, float $paid, string $status, $at = null): Order
    {
        $order = Order::create([
            'customer_name' => 'Lima', 'customer_email' => 'lima@example.com', 'customer_phone' => '01777777777',
            'shipping_address' => 'Mirpur, Dhaka', 'subtotal' => $total, 'total' => $total, 'paid_amount' => $paid,
            'status' => $status, 'payment_method' => 'cod',
        ]);

        if ($at) {
            $order->forceFill(['created_at' => $at])->save();
        }

        return $order;
    }

    public function test_today_figures_count_only_todays_sales_money_and_stock(): void
    {
        $variant = $this->variant();
        $admin = $this->staff();

        // Counter sale: 2 × 1,000 paid in full, costing 2 × 600.
        $this->actingAs($admin)->post(route('admin.pos.store'), [
            'items' => [['variant_id' => $variant->id, 'quantity' => 2]],
            'payments' => [['method' => 'cash', 'account_id' => Account::where('code', 'cash')->value('id'), 'amount' => 2000]],
        ])->assertSessionHas('success');

        $this->order(1000, 400, 'confirmed');                   // counted: 600 still due
        $this->order(5000, 0, 'pending');                       // not a sale yet
        $this->order(7000, 0, 'confirmed', now()->subDay());    // yesterday

        app(StockService::class)->move($variant->fresh(), 'in', 5, 'manual_in');

        $response = $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee("Today's sales")
            ->assertSee('Present stock')
            ->assertSee('Total income');
        $today = $response->viewData('today');
        $overall = $response->viewData('overall');

        // All time adds yesterday's unpaid 7,000 order; pending orders never count.
        $this->assertSame(10000.0, $overall['sales']);
        $this->assertSame(3, $overall['sales_count']);
        $this->assertSame(7600.0, $overall['due']);
        $this->assertSame(2000.0, $overall['paid']);
        $this->assertSame(1200.0, $overall['cost']);
        $this->assertSame(8800.0, $overall['income']);
        $this->assertSame(0.0, $overall['purchase']);

        $this->assertSame(3000.0, $today['sales']);
        $this->assertSame(2, $today['sales_count']);
        $this->assertSame(600.0, $today['due']);
        $this->assertSame(2000.0, $today['paid']);
        $this->assertSame(1200.0, $today['cost']);
        $this->assertSame(1800.0, $today['income']);
        $this->assertSame(0.0, $today['purchase']);
        $this->assertSame(5, $today['stock_in']);
        $this->assertSame(2, $today['stock_out']);
        $this->assertSame(13, $today['stock_units']);
        $this->assertSame(7800.0, $today['stock_value']);
    }

    public function test_staff_without_reports_see_stock_but_not_money(): void
    {
        $this->variant();

        $response = $this->actingAs($this->staff('sales_staff'))->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Restricted');
        $today = $response->viewData('today');

        $this->assertNull($response->viewData('overall')['sales']);
        $this->assertNull($response->viewData('overall')['income']);

        $this->assertNull($today['sales']);
        $this->assertNull($today['income']);
        $this->assertNull($today['stock_value']);
        $this->assertSame(10, $today['stock_units']);
    }
}
