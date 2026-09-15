<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrderLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->withCookie(CartService::TOKEN_COOKIE, str_repeat('o', 40));
    }

    /** Places a COD order for two units of a fresh product (stock 10). */
    protected function placeOrder(?User $customer = null, ?string $coupon = null): Order
    {
        $category = Category::firstOrCreate(['name' => 'Kurti'], ['is_active' => true]);
        $product = Product::create([
            'category_id' => $category->id, 'name' => 'Cotton Kurti ' . Str::random(5),
            'price' => 1000, 'stock' => 10, 'is_active' => true,
        ]);

        if ($customer) {
            $this->actingAs($customer);
        }

        $this->post(route('cart.store', $product), ['quantity' => 2]);

        if ($coupon) {
            $this->post(route('cart.coupon.apply'), ['code' => $coupon]);
        }

        $this->post(route('checkout.store'), [
            'customer_name' => 'Shirin',
            'customer_email' => 'shirin@example.com',
            'customer_phone' => '01766666666',
            'shipping_address' => 'Khilgaon, Dhaka',
            'payment_method' => 'cod',
        ])->assertRedirect();

        return Order::latest('id')->firstOrFail();
    }

    protected function history(Order $order): array
    {
        return $order->statusHistories()->reorder('id')->pluck('to_status')->all();
    }

    public function test_staff_move_an_order_through_fulfilment_and_every_change_is_recorded(): void
    {
        $order = $this->placeOrder();
        $this->assertSame(['pending'], $this->history($order));

        $admin = $this->staff();

        foreach (['confirmed', 'packed', 'shipped', 'delivered'] as $status) {
            $this->actingAs($admin)->patch(route('admin.orders.status', $order), ['status' => $status, 'note' => 'to ' . $status])
                ->assertSessionHas('success');
        }

        $order->refresh();
        $this->assertSame('delivered', $order->status);
        $this->assertNotNull($order->confirmed_at);
        $this->assertNotNull($order->shipped_at);
        $this->assertNotNull($order->delivered_at);
        $this->assertSame(['pending', 'confirmed', 'packed', 'shipped', 'delivered'], $this->history($order));
        $this->assertSame($admin->id, $order->statusHistories()->where('to_status', 'shipped')->value('user_id'));
        $this->assertDatabaseHas('activity_logs', ['action' => 'status_changed', 'subject_id' => $order->id]);

        // A delivered order is closed: it cannot be reopened or cancelled by hand.
        $this->actingAs($admin)->patch(route('admin.orders.status', $order), ['status' => 'pending'])->assertSessionHas('error');
        $this->actingAs($admin)->patch(route('admin.orders.status', $order), ['status' => 'cancelled'])->assertSessionHas('error');
        $this->assertSame('delivered', $order->fresh()->status);

        $this->actingAs($admin)->get(route('admin.orders.show', $order))->assertOk()
            ->assertSee('to shipped')->assertSee('No further status changes');
    }

    public function test_steps_that_are_not_allowed_are_rejected(): void
    {
        $order = $this->placeOrder();
        $admin = $this->staff();

        $this->actingAs($admin)->patch(route('admin.orders.status', $order), ['status' => 'delivered'])->assertSessionHas('error');
        $this->actingAs($admin)->patch(route('admin.orders.status', $order), ['status' => 'shipped'])->assertSessionHas('error');
        $this->actingAs($admin)->patch(route('admin.orders.status', $order), ['status' => 'returned'])->assertSessionHas('error');
        $this->actingAs($admin)->patch(route('admin.orders.status', $order), ['status' => 'bogus'])->assertSessionHasErrors('status');

        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame(['pending'], $this->history($order));
    }

    public function test_cancelling_restocks_exactly_once_and_gives_the_coupon_use_back(): void
    {
        Coupon::create(['code' => 'EID10', 'type' => 'percent', 'value' => 10, 'min_order' => 0, 'usage_limit' => 5, 'is_active' => true]);

        $order = $this->placeOrder(coupon: 'EID10');
        $variant = ProductVariant::where('product_id', $order->items()->value('product_id'))->firstOrFail();

        $this->assertSame(8, $variant->stock);
        $this->assertSame(1, Coupon::firstOrFail()->used_count);

        $admin = $this->staff();
        $this->actingAs($admin)->patch(route('admin.orders.status', $order), ['status' => 'cancelled', 'note' => 'Customer called'])
            ->assertSessionHas('success');

        // A second submit (double click, second tab) changes nothing.
        $this->actingAs($admin)->patch(route('admin.orders.status', $order), ['status' => 'cancelled'])->assertSessionHas('error');

        $this->assertSame(10, $variant->fresh()->stock);
        $this->assertSame(1, StockMovement::where('reason', 'order_cancelled')->count());
        $this->assertSame(0, Coupon::firstOrFail()->used_count);
        $this->assertNotNull($order->fresh()->cancelled_at);
        $this->assertSame('Customer called', $order->statusHistories()->where('to_status', 'cancelled')->value('note'));
    }

    public function test_customers_can_cancel_only_before_the_parcel_is_packed(): void
    {
        $customer = User::create(['name' => 'Shirin', 'email' => 'shirin@example.com', 'password' => 'password123']);
        $early = $this->placeOrder($customer);
        $late = $this->placeOrder($customer);

        $admin = $this->staff();
        $this->actingAs($admin)->patch(route('admin.orders.status', $early), ['status' => 'processing']);
        foreach (['processing', 'packed', 'shipped'] as $status) {
            $this->actingAs($admin)->patch(route('admin.orders.status', $late), ['status' => $status]);
        }
        $this->assertSame('shipped', $late->fresh()->status);

        $this->actingAs($customer)->post(route('orders.cancel', $late))->assertSessionHas('error');
        $this->assertSame('shipped', $late->fresh()->status);
        $this->actingAs($customer)->get(route('orders.show', $late))->assertOk()->assertDontSee('Cancel order')->assertSee('Shipped');

        $this->actingAs($customer)->post(route('orders.cancel', $early))->assertSessionHas('success');
        $this->assertSame('cancelled', $early->fresh()->status);
        $this->assertSame($customer->id, $early->statusHistories()->where('to_status', 'cancelled')->value('user_id'));
    }

    public function test_courier_tracking_and_admin_note_are_saved_searchable_and_permissioned(): void
    {
        $order = $this->placeOrder();
        $admin = $this->staff();

        $this->actingAs($admin)->patch(route('admin.orders.details', $order), [
            'courier_name' => 'Steadfast',
            'tracking_number' => 'SF-778899',
            'admin_note' => 'Fragile, call before delivery',
        ])->assertSessionHas('success');

        $order->refresh();
        $this->assertSame('Steadfast', $order->courier_name);
        $this->assertSame('SF-778899', $order->tracking_number);
        $this->assertSame('Fragile, call before delivery', $order->admin_note);
        $this->assertDatabaseHas('activity_logs', ['action' => 'details_updated', 'subject_id' => $order->id]);

        $this->actingAs($admin)->get(route('admin.orders.index', ['q' => 'SF-778899']))->assertOk()->assertSee($order->order_number);
        $this->actingAs($admin)->get(route('admin.orders.index', ['q' => 'SF-000000']))->assertOk()->assertDontSee($order->order_number);
        $this->actingAs($admin)->get(route('admin.orders.index', ['status' => 'pending']))->assertOk()->assertSee($order->order_number);

        $this->actingAs($this->staff('warehouse_staff'))->patch(route('admin.orders.details', $order), ['tracking_number' => 'SF-1'])
            ->assertSessionHas('success');
        $this->actingAs($this->staff('accountant'))->patch(route('admin.orders.details', $order), ['tracking_number' => 'SF-2'])
            ->assertForbidden();
        $this->assertSame('SF-1', $order->fresh()->tracking_number);
    }
}
