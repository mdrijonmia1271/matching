<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\CartService;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class SecurityAndPermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->withCookie(CartService::TOKEN_COOKIE, str_repeat('t', 40));
    }

    protected function product(array $attributes = []): Product
    {
        $category = Category::firstOrCreate(['name' => 'Kurti'], ['is_active' => true]);

        return Product::create(array_merge([
            'category_id' => $category->id,
            'name' => 'Black Kurti',
            'price' => 1000,
            'stock' => 10,
            'is_active' => true,
        ], $attributes));
    }

    protected function placeOnlineOrder(): Order
    {
        $this->post(route('cart.store', $this->product()));
        $this->post(route('checkout.store'), [
            'customer_name' => 'Karim',
            'customer_email' => 'karim@example.com',
            'customer_phone' => '01700000001',
            'shipping_address' => 'Uttara, Dhaka',
            'payment_method' => 'online',
        ]);

        return Order::firstOrFail();
    }

    protected function signedPayment(string $route, Order $order): string
    {
        return URL::temporarySignedRoute($route, now()->addMinutes(30), [$order, $order->payments()->firstOrFail()]);
    }

    public function test_payment_callbacks_reject_unsigned_requests(): void
    {
        $order = $this->placeOnlineOrder();
        $payment = $order->payments()->firstOrFail();

        $this->post(route('payment.demo.success', [$order, $payment]))->assertForbidden();

        $this->assertSame('unpaid', $order->fresh()->payment_status);
        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_a_payment_cannot_revive_a_cancelled_order_or_be_replayed(): void
    {
        $order = $this->placeOnlineOrder();
        $order->update(['status' => 'cancelled']);

        $this->post($this->signedPayment('payment.demo.success', $order))->assertRedirect();
        $this->post($this->signedPayment('payment.demo.success', $order))->assertRedirect();

        $order->refresh();
        $this->assertSame('cancelled', $order->status);
        $this->assertSame('unpaid', $order->payment_status);
        $this->assertSame('failed', $order->payments()->first()->status);
    }

    public function test_guests_only_see_confirmations_from_their_own_session_or_a_signed_link(): void
    {
        $order = Order::create([
            'customer_name' => 'Someone Else',
            'customer_email' => 'else@example.com',
            'customer_phone' => '01711111111',
            'shipping_address' => 'Mirpur, Dhaka',
            'subtotal' => 1000,
            'total' => 1000,
        ]);

        $this->get(route('checkout.success', $order))->assertForbidden();
        $this->get(URL::temporarySignedRoute('checkout.success', now()->addDay(), $order))->assertOk();
    }

    public function test_login_is_throttled_after_repeated_failures(): void
    {
        $user = User::create(['name' => 'Nadia', 'email' => 'nadia@example.com', 'password' => 'password123']);

        foreach (range(1, 5) as $attempt) {
            $this->post(route('login'), ['email' => $user->email, 'password' => 'wrong-password']);
        }

        $this->post(route('login'), ['email' => $user->email, 'password' => 'password123'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_registration_cannot_grant_admin_access(): void
    {
        $this->post('/register', [
            'name' => 'Sneaky',
            'email' => 'sneaky@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'is_admin' => 1,
            'role_id' => Role::where('slug', Role::SUPER_ADMIN)->value('id'),
        ]);

        $user = User::where('email', 'sneaky@example.com')->firstOrFail();
        $this->assertFalse((bool) $user->is_admin);
        $this->assertNull($user->role_id);
        $this->get(route('admin.dashboard'))->assertForbidden();
    }

    public function test_deactivated_staff_cannot_log_in_or_use_the_admin_panel(): void
    {
        $staff = $this->staff('sales_staff', ['email' => 'sales@example.com']);
        $staff->forceFill(['is_active' => false])->save();

        $this->post(route('login'), ['email' => 'sales@example.com', 'password' => 'password123'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->actingAs($staff)->get(route('admin.dashboard'))->assertForbidden();
    }

    public function test_permissions_are_enforced_on_the_server(): void
    {
        $product = $this->product();
        $stockEntry = ['variant_id' => $product->variants()->value('id'), 'type' => 'in', 'quantity' => 1, 'reason' => 'purchase'];

        $sales = $this->staff('sales_staff');
        $this->actingAs($sales)->get(route('admin.orders.index'))->assertOk();
        $this->actingAs($sales)->get(route('admin.products.index'))->assertOk();
        $this->actingAs($sales)->get(route('admin.products.create'))->assertForbidden();
        $this->actingAs($sales)->delete(route('admin.products.destroy', $product))->assertForbidden();
        $this->actingAs($sales)->post(route('admin.stock.store'), $stockEntry)->assertForbidden();
        $this->actingAs($sales)->get(route('admin.settings.edit'))->assertForbidden();
        $this->actingAs($sales)->get(route('admin.staff.index'))->assertForbidden();
        $this->actingAs($sales)->get(route('admin.activity.index'))->assertForbidden();

        $warehouse = $this->staff('warehouse_staff');
        $this->actingAs($warehouse)->post(route('admin.stock.store'), $stockEntry)->assertRedirect(route('admin.stock.index'));
        $this->actingAs($warehouse)->get(route('admin.coupons.index'))->assertForbidden();

        $this->assertSame(11, $product->fresh()->stock);
        $this->assertFalse($product->fresh()->trashed());
    }

    public function test_warehouse_staff_can_update_but_not_cancel_orders(): void
    {
        $order = Order::create([
            'customer_name' => 'Rina', 'customer_email' => 'rina@example.com', 'customer_phone' => '01722222222',
            'shipping_address' => 'Banani, Dhaka', 'subtotal' => 500, 'total' => 500,
        ]);

        $warehouse = $this->staff('warehouse_staff');

        $this->actingAs($warehouse)->patch(route('admin.orders.status', $order), ['status' => 'processing'])
            ->assertSessionHas('success');
        $this->actingAs($warehouse)->patch(route('admin.orders.status', $order), ['status' => 'cancelled'])
            ->assertForbidden();

        $this->assertSame('processing', $order->fresh()->status);
    }

    public function test_staff_cannot_grant_access_they_do_not_have(): void
    {
        $supervisor = Role::create(['name' => 'Supervisor', 'slug' => 'supervisor']);
        $supervisor->syncPermissions(['staff.view', 'staff.create', 'staff.edit', 'orders.view']);
        $actor = $this->staff('supervisor');

        $this->actingAs($actor)->post(route('admin.roles.store'), ['name' => 'Escalated', 'permissions' => ['settings.manage']])
            ->assertSessionHas('error');
        $this->assertDatabaseMissing('roles', ['name' => 'Escalated']);

        $this->actingAs($actor)->put(route('admin.roles.update', $supervisor), ['name' => 'Supervisor', 'permissions' => ['staff.view']])
            ->assertSessionHas('error');

        $this->actingAs($actor)->post(route('admin.staff.store'), [
            'name' => 'New Boss', 'email' => 'newboss@example.com',
            'role_id' => Role::where('slug', Role::SUPER_ADMIN)->value('id'),
            'password' => 'password123', 'password_confirmation' => 'password123',
        ])->assertForbidden();

        $this->actingAs($actor)->post(route('admin.staff.store'), [
            'name' => 'New Manager', 'email' => 'newmanager@example.com',
            'role_id' => Role::where('slug', 'manager')->value('id'),
            'password' => 'password123', 'password_confirmation' => 'password123',
        ])->assertSessionHas('error');

        $this->assertDatabaseMissing('users', ['email' => 'newboss@example.com']);
        $this->assertDatabaseMissing('users', ['email' => 'newmanager@example.com']);
    }

    public function test_super_admin_manages_staff_but_cannot_lock_themselves_out(): void
    {
        $admin = $this->staff();

        $this->actingAs($admin)->post(route('admin.staff.store'), [
            'name' => 'Shop Assistant', 'email' => 'assistant@example.com', 'phone' => '01733333333',
            'role_id' => Role::where('slug', 'sales_staff')->value('id'),
            'password' => 'password123', 'password_confirmation' => 'password123',
        ])->assertRedirect(route('admin.staff.index'));

        $assistant = User::where('email', 'assistant@example.com')->firstOrFail();
        $this->assertTrue($assistant->isStaff());
        $this->assertTrue($assistant->hasPermission('pos.sell'));
        $this->assertFalse($assistant->hasPermission('settings.manage'));

        $this->actingAs($admin)->delete(route('admin.staff.destroy', $admin))->assertSessionHas('error');
        $this->assertTrue($admin->fresh()->is_active);

        $this->actingAs($admin)->put(route('admin.staff.update', $admin), [
            'name' => $admin->name, 'email' => $admin->email,
            'role_id' => Role::where('slug', 'manager')->value('id'),
        ])->assertSessionHas('error');
        $this->assertTrue($admin->fresh()->isSuperAdmin());

        $this->actingAs($admin)->delete(route('admin.staff.destroy', $assistant))->assertSessionHas('success');
        $this->assertFalse($assistant->fresh()->isStaff());
        $this->assertDatabaseHas('activity_logs', ['action' => 'deactivated', 'subject_id' => $assistant->id]);
    }

    public function test_staff_and_role_screens_render(): void
    {
        $admin = $this->staff();
        $manager = $this->staff('manager');
        $managerRole = Role::where('slug', 'manager')->firstOrFail();

        $this->actingAs($admin)->get(route('admin.staff.index'))->assertOk()->assertSee($manager->email);
        $this->actingAs($admin)->get(route('admin.staff.create'))->assertOk();
        $this->actingAs($admin)->get(route('admin.staff.edit', $manager))->assertOk()->assertSee('Account is active');
        $this->actingAs($admin)->get(route('admin.staff.edit', $admin))->assertOk()->assertSee('You cannot change your own role');
        $this->actingAs($admin)->get(route('admin.roles.index'))->assertOk()->assertSee('Warehouse Staff');
        $this->actingAs($admin)->get(route('admin.roles.create'))->assertOk();
        $this->actingAs($admin)->get(route('admin.roles.edit', $managerRole))->assertOk()->assertSee('reports.export');
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->assertSee('Settings')->assertDontSee('Restricted');

        // A manager sees the staff list but gets no add/edit buttons, and no settings link.
        $this->actingAs($manager)->get(route('admin.staff.index'))->assertOk()->assertDontSee('Add staff');
        $this->actingAs($manager)->get(route('admin.dashboard'))->assertOk()->assertDontSee(route('admin.settings.edit'));

        // Sales staff do not see revenue.
        $this->actingAs($this->staff('sales_staff'))->get(route('admin.dashboard'))->assertOk()->assertSee('Restricted');
    }

    public function test_important_actions_are_written_to_the_activity_log(): void
    {
        $admin = $this->staff(Role::SUPER_ADMIN, ['email' => 'owner@example.com']);

        $this->post(route('login'), ['email' => 'owner@example.com', 'password' => 'password123']);
        $this->assertDatabaseHas('activity_logs', ['module' => 'auth', 'action' => 'login', 'user_id' => $admin->id]);

        $product = $this->product(['price' => 1000]);

        $this->actingAs($admin)->put(route('admin.products.update', $product), [
            'category_id' => $product->category_id, 'name' => $product->name,
            'price' => 1200, 'is_active' => 1,
            'variants' => [['id' => $product->variants()->value('id')]],
        ])->assertRedirect();

        $log = ActivityLog::where('action', 'price_changed')->firstOrFail();
        $this->assertSame($admin->id, $log->user_id);
        $this->assertEquals(1000, (float) $log->old_values['price']);
        $this->assertEquals(1200, (float) $log->new_values['price']);

        $this->actingAs($admin)->get(route('admin.activity.index'))->assertOk()->assertSee('price changed');
    }

    public function test_settings_drive_delivery_charges_and_coupon_rules(): void
    {
        $admin = $this->staff();

        $this->actingAs($admin)->put(route('admin.settings.update'), [
            'store_name' => 'Matching Fashion',
            'currency_symbol' => 'Tk',
            'order_prefix' => 'mf',
            'delivery_charge' => 100,
            'free_delivery_threshold' => 0,
            'low_stock_threshold' => 3,
            'payment_methods' => ['cash', 'cod'],
        ])->assertSessionHas('success');

        $this->assertSame(100.0, Settings::get('delivery_charge'));
        $this->assertSame('MF', Settings::get('order_prefix'));

        $this->actingAs($admin)->get(route('admin.settings.edit'))->assertOk()->assertSee('Matching Fashion');

        $this->actingAs($admin)->post(route('admin.coupons.store'), ['code' => 'HUGE', 'type' => 'percent', 'value' => 150])
            ->assertSessionHasErrors('value');

        // A customer checking out now pays the new delivery charge and gets the new order prefix.
        $customer = User::create(['name' => 'Mitu', 'email' => 'mitu@example.com', 'password' => 'password123']);
        $this->actingAs($customer)->post(route('cart.store', $this->product(['price' => 5000])));
        $this->actingAs($customer)->post(route('checkout.store'), [
            'customer_name' => 'Mitu', 'customer_email' => 'mitu@example.com', 'customer_phone' => '01744444444',
            'shipping_address' => 'Dhanmondi, Dhaka', 'payment_method' => 'cod',
        ]);

        $order = Order::firstOrFail();
        $this->assertEquals(100.0, (float) $order->shipping_cost);
        $this->assertStringStartsWith('MF-', $order->order_number);
    }
}
