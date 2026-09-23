<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\CartService;
use App\Support\Money;
use App\Support\Phone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CustomerTest extends TestCase
{
    use RefreshDatabase;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->withCookie(CartService::TOKEN_COOKIE, str_repeat('c', 40));

        $category = Category::create(['name' => 'Sarees', 'is_active' => true]);
        $this->product = Product::create([
            'category_id' => $category->id, 'name' => 'Cotton Saree', 'price' => 1000, 'stock' => 50, 'is_active' => true,
        ]);
    }

    protected function order(array $attributes = []): Order
    {
        return Order::create(array_merge([
            'customer_name' => 'Lima', 'customer_email' => 'lima@example.com', 'customer_phone' => '01777777777',
            'shipping_address' => 'Mirpur, Dhaka', 'subtotal' => 1000, 'total' => 1000,
            'status' => 'confirmed', 'payment_method' => 'cod',
        ], $attributes));
    }

    protected function checkout(string $phone, string $name = 'Rahim', string $email = 'rahim@example.com'): Order
    {
        $this->post(route('cart.store', $this->product), ['quantity' => 1]);

        $this->post(route('checkout.store'), [
            'customer_name' => $name,
            'customer_email' => $email,
            'customer_phone' => $phone,
            'shipping_address' => 'Dhanmondi, Dhaka',
            'payment_method' => 'cod',
        ])->assertRedirect()->assertSessionHasNoErrors()->assertSessionMissing('error');

        return Order::latest('id')->firstOrFail();
    }

    public function test_phone_numbers_are_normalised(): void
    {
        foreach (['+880 1712-345678', '8801712345678', '008801712345678', '01712345678', '1712345678', '(017) 1234 5678'] as $typed) {
            $this->assertSame('01712345678', Phone::normalise($typed), $typed);
        }

        $this->assertNull(Phone::normalise(''));
        $this->assertNull(Phone::normalise(null));
        $this->assertSame('442071234567', Phone::normalise('+44 20 7123 4567'));
        $this->assertSame('01712345678', Customer::create(['name' => 'X', 'phone' => '+8801712345678'])->phone);
    }

    public function test_checkout_links_existing_customers_and_creates_new_ones(): void
    {
        $existing = Customer::create(['name' => 'Rahim Uddin', 'phone' => '01712345678', 'address' => 'Old address']);

        // A guest typing a known phone in another format is the same customer; details on file are kept, blanks filled.
        $order = $this->checkout('+880 1712-345678');
        $this->assertEquals($existing->id, $order->customer_id);
        $existing->refresh();
        $this->assertSame('Rahim Uddin', $existing->name);
        $this->assertSame('Old address', $existing->address);
        $this->assertSame('rahim@example.com', $existing->email);

        $guest = $this->checkout('01811111111', 'Karim', 'karim@example.com');
        $this->assertNotEquals($existing->id, $guest->customer_id);
        $this->assertDatabaseHas('customers', ['id' => $guest->customer_id, 'name' => 'Karim', 'phone' => '01811111111', 'user_id' => null]);

        // Registered buyers are matched by their account, whatever phone they type.
        $sadia = User::create(['name' => 'Sadia', 'email' => 'sadia@example.com', 'password' => 'password123']);
        $this->actingAs($sadia);
        $first = $this->checkout('01922222222', 'Sadia', 'sadia@example.com');
        $second = $this->checkout('01933333333', 'Sadia', 'sadia@example.com');
        $this->assertEquals($first->customer_id, $second->customer_id);
        $this->assertEquals($sadia->id, Customer::findOrFail($first->customer_id)->user_id);
        $this->assertSame('01922222222', Customer::findOrFail($first->customer_id)->phone);

        // A guest record becomes the account's record when that person registers and buys.
        $karim = User::create(['name' => 'Karim', 'email' => 'karim@example.com', 'password' => 'password123']);
        $this->actingAs($karim);
        $third = $this->checkout('01811111111', 'Karim', 'karim@example.com');
        $this->assertEquals($guest->customer_id, $third->customer_id);
        $this->assertEquals($karim->id, Customer::findOrFail($guest->customer_id)->user_id);

        // Someone else's account phone never moves: a new account using it gets its own record without the phone.
        $other = User::create(['name' => 'Other', 'email' => 'other@example.com', 'password' => 'password123']);
        $this->actingAs($other);
        $fourth = $this->checkout('01922222222', 'Other', 'other@example.com');
        $this->assertNotEquals($first->customer_id, $fourth->customer_id);
        $this->assertNull(Customer::findOrFail($fourth->customer_id)->phone);

        $this->assertSame(4, Customer::count());

        // An archived customer who buys again is restored.
        Customer::findOrFail($existing->id)->delete();
        auth()->logout();
        $this->assertEquals($existing->id, $this->checkout('01712345678')->customer_id);
        $this->assertNotSoftDeleted($existing);
    }

    public function test_backfill_creates_customers_from_existing_orders(): void
    {
        $demo = User::create(['name' => 'Demo Customer', 'email' => 'demo@example.com', 'password' => 'password123', 'phone' => '01800000000']);
        $a = $this->order(['user_id' => $demo->id, 'customer_name' => 'Demo Customer', 'customer_phone' => '01800000000']);
        $b = $this->order(['user_id' => $demo->id, 'customer_name' => 'Demo C', 'customer_phone' => '01899999999']);

        // Same phone as Demo Customer but a different person: kept apart, without the phone (owner's decision).
        $clash = $this->order(['customer_name' => 'Md Rijon Mia', 'customer_email' => 'rijon@example.com', 'customer_phone' => '01800000000']);
        $clashAgain = $this->order(['customer_name' => 'md  rijon mia', 'customer_email' => 'rijon@example.com', 'customer_phone' => '+8801800000000']);

        // Repeat guest with the number typed differently: one customer.
        $guest = $this->order(['customer_name' => 'Nusrat', 'customer_email' => 'nusrat@example.com', 'customer_phone' => '01711111111']);
        $guestAgain = $this->order(['customer_name' => 'Nusrat', 'customer_email' => 'nusrat@example.com', 'customer_phone' => '8801711111111']);

        $migration = require database_path('migrations/2026_09_16_000001_create_customers_table.php');
        $migration->backfill();
        $migration->backfill();

        $this->assertSame(3, Customer::count());

        $demoCustomer = Customer::where('user_id', $demo->id)->firstOrFail();
        $this->assertSame('01800000000', $demoCustomer->phone);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $demoCustomer->orders()->pluck('id')->all());

        $rijon = Customer::where('name', 'Md Rijon Mia')->firstOrFail();
        $this->assertNull($rijon->phone);
        $this->assertStringContainsString('Demo Customer', $rijon->notes);
        $this->assertEqualsCanonicalizing([$clash->id, $clashAgain->id], $rijon->orders()->pluck('id')->all());

        $nusrat = Customer::where('phone', '01711111111')->firstOrFail();
        $this->assertEqualsCanonicalizing([$guest->id, $guestAgain->id], $nusrat->orders()->pluck('id')->all());

        $this->assertSame(0, Order::whereNull('customer_id')->count());
    }

    public function test_customer_list_search_and_profile_totals(): void
    {
        $admin = $this->staff();
        $rahim = Customer::create(['name' => 'Rahim Uddin', 'phone' => '01712345678', 'email' => 'rahim@example.com', 'customer_group' => 'wholesale', 'opening_due' => 500]);
        $karim = Customer::create(['name' => 'Karim Sheikh', 'phone' => '01811111111']);

        $delivered = $this->order(['customer_id' => $rahim->id, 'total' => 3000, 'paid_amount' => 1000, 'status' => 'delivered', 'payment_status' => 'partially_paid']);
        $this->order(['customer_id' => $rahim->id, 'total' => 2000, 'paid_amount' => 2000, 'status' => 'shipped', 'payment_status' => 'paid']);
        $this->order(['customer_id' => $rahim->id, 'total' => 9999, 'status' => 'cancelled']);
        $this->order(['customer_id' => $karim->id, 'total' => 700, 'paid_amount' => 700, 'status' => 'delivered', 'payment_status' => 'paid']);
        $delivered->payments()->create(['gateway' => 'manual', 'method' => 'bkash', 'amount' => 1000, 'status' => 'success', 'transaction_id' => 'TRXCUST1', 'paid_at' => now()]);

        $this->actingAs($admin);

        // Rahim: 3 orders, spent 5,000 (cancelled excluded), paid 3,000, due 500 opening + 2,000.
        $this->get(route('admin.customers.index'))->assertOk()
            ->assertSee('Rahim Uddin')->assertSee('Karim Sheikh')->assertSee(Money::format(2500))->assertSee(Money::format(5000));

        $this->get(route('admin.customers.index', ['q' => 'rahim']))->assertOk()->assertSee('Rahim Uddin')->assertDontSee('Karim Sheikh');
        $this->get(route('admin.customers.index', ['q' => '+880 1712-345678']))->assertOk()->assertSee('Rahim Uddin')->assertDontSee('Karim Sheikh');
        $this->get(route('admin.customers.index', ['q' => '1811']))->assertOk()->assertSee('Karim Sheikh')->assertDontSee('Rahim Uddin');
        $this->get(route('admin.customers.index', ['group' => 'wholesale']))->assertOk()->assertSee('Rahim Uddin')->assertDontSee('Karim Sheikh');
        $this->get(route('admin.customers.index', ['due' => 'with', 'sort' => 'due']))->assertOk()->assertSee('Rahim Uddin')->assertDontSee('Karim Sheikh');

        foreach (array_keys(\App\Http\Controllers\Admin\CustomerController::SORTS) as $sort) {
            $this->get(route('admin.customers.index', ['sort' => $sort]))->assertOk();
        }

        $this->get(route('admin.customers.show', $rahim))->assertOk()
            ->assertSee(Money::format(5000))->assertSee(Money::format(3000))->assertSee(Money::format(2500))
            ->assertSee('Includes opening due ' . Money::format(500))
            ->assertSee($delivered->order_number)->assertSee('TRXCUST1')->assertSee('Wholesale');

        $this->get(route('admin.orders.show', $delivered))->assertOk()->assertSee(route('admin.customers.show', $rahim));
    }

    public function test_staff_create_edit_and_archive_customers_with_permissions(): void
    {
        $this->actingAs($this->staff('warehouse_staff'))->get(route('admin.customers.index'))->assertForbidden();

        $accountant = $this->staff('accountant');
        $this->actingAs($accountant)->get(route('admin.customers.index'))->assertOk()->assertDontSee('Add customer');
        $this->actingAs($accountant)->get(route('admin.customers.create'))->assertForbidden();
        $this->actingAs($accountant)->post(route('admin.customers.store'), ['name' => 'X', 'customer_group' => 'retail'])->assertForbidden();

        $sales = $this->staff('sales_staff');
        $this->actingAs($sales)->get(route('admin.customers.create'))->assertOk();
        $this->actingAs($sales)->post(route('admin.customers.store'), [
            'name' => 'Farhana', 'phone' => '+880 1655-555555', 'customer_group' => 'vip', 'opening_due' => 1200,
        ])->assertRedirect();

        $farhana = Customer::where('phone', '01655555555')->firstOrFail();
        $this->assertEquals(1200, (float) $farhana->opening_due);
        $this->assertDatabaseHas('activity_logs', ['module' => 'customers', 'action' => 'created', 'subject_id' => $farhana->id]);

        $this->actingAs($sales)->post(route('admin.customers.store'), ['name' => 'Copy', 'phone' => '01655555555', 'customer_group' => 'retail'])
            ->assertSessionHasErrors('phone');
        $this->actingAs($sales)->post(route('admin.customers.store'), ['name' => 'Bad', 'customer_group' => 'gold'])
            ->assertSessionHasErrors('customer_group');
        $this->actingAs($accountant)->get(route('admin.customers.edit', $farhana))->assertForbidden();

        $this->actingAs($sales)->get(route('admin.customers.edit', $farhana))->assertOk()->assertSee('01655555555');
        $this->actingAs($sales)->put(route('admin.customers.update', $farhana), [
            'name' => 'Farhana Akter', 'phone' => '01655555555', 'customer_group' => 'vip', 'opening_due' => 1000,
        ])->assertRedirect(route('admin.customers.show', $farhana));
        $this->assertDatabaseHas('customers', ['id' => $farhana->id, 'name' => 'Farhana Akter', 'opening_due' => 1000]);
        $this->assertDatabaseHas('activity_logs', ['module' => 'customers', 'action' => 'updated', 'subject_id' => $farhana->id]);

        $this->actingAs($sales)->patch(route('admin.customers.archive', $farhana))->assertSessionHas('success');
        $this->assertSoftDeleted($farhana);
        $profile = route('admin.customers.show', $farhana);
        $this->actingAs($sales)->get(route('admin.customers.index'))->assertDontSee($profile);
        $this->actingAs($sales)->get(route('admin.customers.index', ['status' => 'archived']))->assertSee($profile);
        $this->actingAs($sales)->get(route('admin.customers.show', $farhana))->assertOk()->assertSee('Restore');

        $this->actingAs($sales)->patch(route('admin.customers.restore', $farhana))->assertSessionHas('success');
        $this->assertNotSoftDeleted($farhana);
    }
}
