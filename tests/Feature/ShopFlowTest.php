<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class ShopFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A browser keeps the guest cart cookie between requests; the test client
        // only sends the cookies we register up front.
        $this->withCookie(CartService::TOKEN_COOKIE, str_repeat('t', 40));
    }

    protected function product(array $attributes = []): Product
    {
        $category = Category::create(['name' => 'Gadgets', 'is_active' => true]);

        return Product::create(array_merge([
            'category_id' => $category->id,
            'name' => 'Test Headphones',
            'price' => 1000,
            'stock' => 10,
            'is_active' => true,
        ], $attributes));
    }

    public function test_storefront_pages_render(): void
    {
        $product = $this->product();

        $this->get('/')->assertOk();
        $this->get('/shop')->assertOk()->assertSee($product->name);
        $this->get(route('shop.show', $product))->assertOk()->assertSee('Add to cart');
    }

    public function test_shop_search_and_filters_narrow_the_list(): void
    {
        $this->product(['name' => 'Blue Lamp', 'price' => 500]);
        $this->product(['name' => 'Red Chair', 'price' => 5000]);

        $this->get('/shop?q=Lamp')->assertOk()->assertSee('Blue Lamp')->assertDontSee('Red Chair');
        $this->get('/shop?max_price=1000')->assertOk()->assertSee('Blue Lamp')->assertDontSee('Red Chair');
    }

    public function test_a_guest_can_add_to_cart_and_quantity_is_capped_at_stock(): void
    {
        $product = $this->product(['stock' => 3]);

        $this->post(route('cart.store', $product), ['quantity' => 5])->assertRedirect();

        $this->assertDatabaseHas('cart_items', [
            'product_id' => $product->id,
            'quantity' => 3,
        ]);
    }

    public function test_sale_price_is_the_price_charged(): void
    {
        $product = $this->product(['price' => 1000, 'sale_price' => 800]);

        $this->post(route('cart.store', $product));

        $this->assertDatabaseHas('cart_items', ['product_id' => $product->id, 'price' => 800]);
    }

    public function test_coupon_below_minimum_order_is_rejected(): void
    {
        $product = $this->product(['price' => 100]);
        Coupon::create(['code' => 'BIG', 'type' => 'percent', 'value' => 10, 'min_order' => 5000, 'is_active' => true]);

        $this->post(route('cart.store', $product));
        $this->post(route('cart.coupon.apply'), ['code' => 'BIG'])->assertSessionHas('error');

        $this->assertDatabaseMissing('carts', ['coupon_code' => 'BIG']);
    }

    public function test_guest_checkout_places_an_order_and_decrements_stock(): void
    {
        Mail::fake();

        $product = $this->product(['price' => 1000, 'stock' => 5]);
        Coupon::create(['code' => 'SAVE10', 'type' => 'percent', 'value' => 10, 'min_order' => 0, 'is_active' => true]);

        $this->post(route('cart.store', $product), ['quantity' => 2]);
        $this->post(route('cart.coupon.apply'), ['code' => 'SAVE10']);

        $this->post(route('checkout.store'), [
            'customer_name' => 'Rahim',
            'customer_email' => 'rahim@example.com',
            'customer_phone' => '01700000000',
            'shipping_address' => 'Dhanmondi, Dhaka',
            'payment_method' => 'cod',
        ])->assertRedirect();

        $order = Order::firstOrFail();

        // 2000 subtotal - 200 coupon + 60 shipping.
        $this->assertEquals(2000.0, (float) $order->subtotal);
        $this->assertEquals(200.0, (float) $order->discount);
        $this->assertEquals(1860.0, (float) $order->total);
        $this->assertSame('cod', $order->payment_method);

        $this->assertSame(3, $product->fresh()->stock);
        $this->assertDatabaseCount('cart_items', 0);
        $this->assertSame(1, Coupon::firstOrFail()->used_count);
    }

    public function test_online_payment_redirects_to_the_gateway_and_marks_the_order_paid(): void
    {
        $product = $this->product();
        $this->post(route('cart.store', $product));

        $response = $this->post(route('checkout.store'), [
            'customer_name' => 'Karim',
            'customer_email' => 'karim@example.com',
            'customer_phone' => '01700000001',
            'shipping_address' => 'Uttara, Dhaka',
            'payment_method' => 'online',
        ]);

        $order = Order::firstOrFail();
        $payment = $order->payments()->firstOrFail();

        // The gateway hands out a signed URL; the unsigned route is rejected.
        $response->assertRedirectContains(route('payment.demo.show', ['order' => $order->order_number, 'payment' => $payment->id]));
        $this->get($response->headers->get('Location'))->assertOk();

        $this->post(URL::temporarySignedRoute('payment.demo.success', now()->addMinutes(30), [$order, $payment]))->assertRedirect();

        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('processing', $order->status);
    }

    public function test_checkout_fails_when_stock_ran_out_after_adding_to_cart(): void
    {
        $product = $this->product(['stock' => 2]);
        $this->post(route('cart.store', $product), ['quantity' => 2]);

        $product->variants()->firstOrFail()->forceFill(['stock' => 1])->save();

        $this->post(route('checkout.store'), [
            'customer_name' => 'Sabbir',
            'customer_email' => 'sabbir@example.com',
            'customer_phone' => '01700000002',
            'shipping_address' => 'Mirpur, Dhaka',
            'payment_method' => 'cod',
        ])->assertSessionHas('error');

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(1, $product->variants()->firstOrFail()->stock);
    }

    public function test_guest_cart_is_merged_into_the_account_on_login(): void
    {
        $product = $this->product();
        $user = User::create(['name' => 'Nadia', 'email' => 'nadia@example.com', 'password' => 'password123']);

        $this->post(route('cart.store', $product), ['quantity' => 2]);
        $this->post(route('login'), ['email' => $user->email, 'password' => 'password123']);

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('carts', ['user_id' => $user->id]);
        $this->assertSame(2, $user->cart->items()->sum('quantity'));
    }

    public function test_only_a_buyer_can_review_a_product(): void
    {
        $product = $this->product();
        $user = User::create(['name' => 'Tania', 'email' => 'tania@example.com', 'password' => 'password123']);

        $this->actingAs($user)
            ->post(route('reviews.store', $product), ['rating' => 5, 'comment' => 'Great'])
            ->assertSessionHas('error');

        $this->assertDatabaseCount('reviews', 0);

        $order = Order::create([
            'user_id' => $user->id,
            'customer_name' => 'Tania',
            'customer_email' => $user->email,
            'customer_phone' => '01700000003',
            'shipping_address' => 'Bashundhara, Dhaka',
            'status' => 'delivered',
            'subtotal' => 1000,
            'total' => 1000,
        ]);
        $order->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'price' => 1000,
            'quantity' => 1,
            'subtotal' => 1000,
        ]);

        $this->actingAs($user)
            ->post(route('reviews.store', $product), ['rating' => 5, 'comment' => 'Great'])
            ->assertSessionHas('success');

        $this->assertDatabaseCount('reviews', 1);
    }

    public function test_cancelling_an_order_returns_stock(): void
    {
        $product = $this->product(['stock' => 5]);
        $user = User::create(['name' => 'Imran', 'email' => 'imran@example.com', 'password' => 'password123']);

        $this->actingAs($user)->post(route('cart.store', $product), ['quantity' => 2]);
        $this->actingAs($user)->post(route('checkout.store'), [
            'customer_name' => 'Imran',
            'customer_email' => $user->email,
            'customer_phone' => '01700000004',
            'shipping_address' => 'Banani, Dhaka',
            'payment_method' => 'cod',
        ]);

        $this->assertSame(3, $product->fresh()->stock);

        $order = Order::firstOrFail();
        $this->actingAs($user)->post(route('orders.cancel', $order))->assertSessionHas('success');

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame(5, $product->fresh()->stock);
    }

    public function test_admin_area_is_closed_to_customers(): void
    {
        $customer = User::create(['name' => 'Guest', 'email' => 'g@example.com', 'password' => 'password123']);
        $admin = $this->staff();

        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
        $this->actingAs($customer)->get(route('admin.dashboard'))->assertForbidden();
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();
    }

    public function test_admin_can_manage_products_and_orders(): void
    {
        $admin = $this->staff();
        $category = Category::create(['name' => 'Books', 'is_active' => true]);

        $this->actingAs($admin)->post(route('admin.products.store'), [
            'category_id' => $category->id,
            'name' => 'Laravel in Action',
            'price' => 1200,
            'variants' => [['opening_stock' => 7]],
            'is_active' => 1,
        ])->assertRedirect(route('admin.products.index'));

        $product = Product::firstOrFail();
        $this->assertNotEmpty($product->slug);
        $this->assertNotEmpty($product->sku);

        $order = Order::create([
            'customer_name' => 'Walk-in',
            'customer_email' => 'walkin@example.com',
            'customer_phone' => '01700000005',
            'shipping_address' => 'Gulshan, Dhaka',
            'status' => 'pending',
            'subtotal' => 1200,
            'total' => 1200,
        ]);
        $order->items()->create([
            'product_id' => $product->id,
            'variant_id' => $product->variants()->value('id'),
            'product_name' => $product->name,
            'price' => 1200,
            'quantity' => 2,
            'subtotal' => 2400,
        ]);

        $this->actingAs($admin)->patch(route('admin.orders.status', $order), [
            'status' => 'cancelled',
        ])->assertSessionHas('success');

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame(9, $product->fresh()->stock);
    }
}
