<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Services\AdvanceOrderService;
use App\Services\OrderStatusService;
use App\Services\ReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class AdvanceOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function variant(int $stock = 5, float $price = 1000): ProductVariant
    {
        $category = Category::firstOrCreate(['name' => 'Saree'], ['is_active' => true]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Katan Saree ' . uniqid(),
            'price' => $price,
            'cost_price' => $price * 0.6,
            'stock' => $stock,
            'is_active' => true,
        ]);

        return $product->variants()->firstOrFail()->fresh();
    }

    protected function account(): Account
    {
        return Account::active()->firstOrFail();
    }

    protected function customer(): Customer
    {
        return Customer::firstOrCreate(['phone' => '01712345678'], ['name' => 'Rijon Islam']);
    }

    /** @param  array<string, mixed>  $overrides */
    protected function book(ProductVariant $variant, array $overrides = []): Order
    {
        return app(AdvanceOrderService::class)->book(array_merge([
            'customer_id' => $this->customer()->id,
            'items' => [['variant_id' => $variant->id, 'quantity' => 2]],
            'payments' => [['amount' => 500, 'method' => 'cash', 'account_id' => $this->account()->id]],
        ], $overrides));
    }

    public function test_booking_takes_the_money_but_leaves_the_stock_alone(): void
    {
        $this->actingAs($this->staff());
        $variant = $this->variant(5, 1000);

        $order = $this->book($variant);

        $this->assertSame('advance', $order->channel);
        $this->assertSame('confirmed', $order->status);
        $this->assertSame(2000.0, (float) $order->total);
        $this->assertSame(500.0, (float) $order->paid_amount);
        $this->assertSame(1500.0, $order->due_amount);

        // The goods have not moved: no movement row, and the shelf is untouched.
        $this->assertNull($order->stock_taken_at);
        $this->assertSame(5, (int) $variant->fresh()->stock);
        $this->assertSame(0, StockMovement::where('order_id', $order->id)->count());

        // The advance really landed in an account.
        $this->assertSame(500.0, round((float) $order->payments()->where('status', 'success')->sum('amount'), 2));
    }

    public function test_goods_can_be_booked_that_are_not_in_stock_yet(): void
    {
        $this->actingAs($this->staff());
        $variant = $this->variant(0, 1200);

        $order = $this->book($variant);

        $this->assertSame(2400.0, (float) $order->total);
        $this->assertSame(0, (int) $variant->fresh()->stock);
    }

    public function test_delivering_takes_the_stock_off_the_shelf(): void
    {
        $this->actingAs($this->staff());
        $variant = $this->variant(5, 1000);
        $order = $this->book($variant);

        $statuses = app(OrderStatusService::class);

        foreach (['processing', 'packed', 'shipped', 'delivered'] as $status) {
            $order = $statuses->transition($order, $status);
        }

        $order->refresh();

        $this->assertSame('delivered', $order->status);
        $this->assertNotNull($order->stock_taken_at);
        $this->assertSame(3, (int) $variant->fresh()->stock);

        $movement = StockMovement::where('order_id', $order->id)->sole();

        $this->assertSame('out', $movement->type);
        $this->assertSame('advance_delivery', $movement->reason);
        $this->assertSame(2, (int) $movement->quantity);
        $this->assertSame(5, (int) $movement->stock_before);
        $this->assertSame(3, (int) $movement->stock_after);
    }

    public function test_cancelling_before_delivery_puts_nothing_back(): void
    {
        $this->actingAs($this->staff());
        $variant = $this->variant(5, 1000);
        $order = $this->book($variant);

        app(OrderStatusService::class)->transition($order, 'cancelled');

        // The dangerous case: restocking here would invent two units that never left.
        $this->assertSame(5, (int) $variant->fresh()->stock);
        $this->assertSame(0, StockMovement::where('order_id', $order->id)->count());
        $this->assertNull($order->fresh()->stock_taken_at);
    }

    public function test_an_online_order_still_restocks_when_cancelled(): void
    {
        $this->actingAs($this->staff());
        $variant = $this->variant(5, 1000);

        $order = Order::create([
            'customer_name' => 'Web buyer', 'customer_email' => 'web@example.com', 'customer_phone' => '01799999999',
            'shipping_address' => 'Dhaka', 'subtotal' => 1000, 'discount' => 0, 'shipping_cost' => 0, 'total' => 1000,
            'payment_method' => 'cod', 'status' => 'confirmed', 'payment_status' => 'unpaid', 'stock_taken_at' => now(),
        ]);
        $order->items()->create([
            'product_id' => $variant->product_id, 'variant_id' => $variant->id,
            'product_name' => 'Katan', 'sku' => $variant->sku, 'price' => 1000, 'unit_cost' => 600,
            'quantity' => 1, 'subtotal' => 1000,
        ]);
        app(\App\Services\StockService::class)->move($variant, 'out', 1, 'sale', null, $order);

        $this->assertSame(4, (int) $variant->fresh()->stock);

        app(OrderStatusService::class)->transition($order, 'cancelled');

        $this->assertSame(5, (int) $variant->fresh()->stock);
        $this->assertNull($order->fresh()->stock_taken_at);
    }

    public function test_a_booking_cannot_be_returned_before_it_is_handed_over(): void
    {
        $this->actingAs($this->staff());
        $variant = $this->variant(5, 1000);
        $order = $this->book($variant);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has not been handed over yet');

        app(ReturnService::class)->request($order, [
            ['order_item_id' => $order->items()->value('id'), 'quantity' => 1],
        ], 'changed_mind');
    }

    public function test_a_delivered_booking_can_be_returned_like_any_other_order(): void
    {
        $this->actingAs($this->staff());
        $variant = $this->variant(5, 1000);
        $order = $this->book($variant);

        $statuses = app(OrderStatusService::class);

        foreach (['processing', 'packed', 'shipped', 'delivered'] as $status) {
            $order = $statuses->transition($order, $status);
        }

        $return = app(ReturnService::class)->request($order->refresh(), [
            ['order_item_id' => $order->items()->value('id'), 'quantity' => 1],
        ], 'changed_mind');

        $this->assertSame('requested', $return->status);
    }

    public function test_a_booking_needs_a_customer(): void
    {
        $this->actingAs($this->staff());
        $variant = $this->variant();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('needs a customer');

        app(AdvanceOrderService::class)->book([
            'items' => [['variant_id' => $variant->id, 'quantity' => 1]],
        ]);
    }

    public function test_the_advance_cannot_be_more_than_the_booking(): void
    {
        $this->actingAs($this->staff());
        $variant = $this->variant(5, 1000);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('more than the');

        $this->book($variant, ['payments' => [['amount' => 5000, 'method' => 'cash', 'account_id' => $this->account()->id]]]);
    }

    public function test_an_agreed_price_overrides_todays_price_and_a_blank_one_does_not(): void
    {
        $this->actingAs($this->staff());
        $variant = $this->variant(5, 1000);

        $order = $this->book($variant, [
            'items' => [
                ['variant_id' => $variant->id, 'quantity' => 1, 'price' => 850],
            ],
            'payments' => [],
        ]);

        $this->assertSame(850.0, (float) $order->items()->value('price'));
        $this->assertSame(850.0, (float) $order->total);

        $blank = $this->book($variant, [
            'items' => [['variant_id' => $variant->id, 'quantity' => 1, 'price' => '']],
            'payments' => [],
        ]);

        $this->assertSame(1000.0, (float) $blank->items()->value('price'));
    }

    public function test_the_booking_shows_up_as_a_customer_due(): void
    {
        $this->actingAs($this->staff());
        $variant = $this->variant(5, 1000);
        $customer = $this->customer();

        $this->book($variant, ['customer_id' => $customer->id]);

        $due = Customer::withTotals()->whereKey($customer->id)->sole()->current_due;

        $this->assertSame(1500.0, round((float) $due, 2));
    }

    public function test_staff_can_book_from_the_screen(): void
    {
        $variant = $this->variant(5, 1000);
        $customer = $this->customer();

        $this->actingAs($this->staff())
            ->post(route('admin.advance-orders.store'), [
                'customer_id' => $customer->id,
                'expected_at' => now()->addWeek()->toDateString(),
                'items' => [['variant_id' => $variant->id, 'quantity' => 2, 'price' => 900]],
                'payments' => [['amount' => 600, 'method' => 'cash', 'account_id' => $this->account()->id]],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $order = Order::advance()->sole();

        $this->assertSame(1800.0, (float) $order->total);
        $this->assertSame(600.0, (float) $order->paid_amount);
        $this->assertNotNull($order->expected_at);
        $this->assertSame(5, (int) $variant->fresh()->stock);
    }

    public function test_the_list_and_form_are_behind_the_advance_permission(): void
    {
        $this->actingAs($this->staff())->get(route('admin.advance-orders.index'))->assertOk();
        $this->actingAs($this->staff())->get(route('admin.advance-orders.create'))->assertOk();

        // Warehouse staff may see orders but may not take bookings or money.
        $this->actingAs($this->staff('warehouse_staff'))->get(route('admin.advance-orders.index'))->assertOk();
        $this->actingAs($this->staff('warehouse_staff'))->get(route('admin.advance-orders.create'))->assertForbidden();

        $this->actingAs($this->staff('accountant'))->get(route('admin.advance-orders.create'))->assertForbidden();
    }
}
