<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Services\PosService;
use App\Services\SettingsRepository;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: ProductVariant, 1: ProductVariant} */
    protected function variants(): array
    {
        $category = Category::create(['name' => 'Kurti', 'is_active' => true]);

        $product = new Product([
            'category_id' => $category->id, 'name' => 'Premium Kurti', 'price' => 1000,
            'cost_price' => 600, 'is_active' => true, 'has_variants' => true,
        ]);
        $product->withoutDefaultVariant = true;
        $product->save();

        $make = function (string $size, int $stock, ?float $sale = null) use ($product) {
            $variant = new ProductVariant(['sku' => 'KUR-' . $size, 'size' => $size, 'sale_price' => $sale, 'is_active' => true]);
            $variant->product_id = $product->id;
            $variant->stock = $stock;
            $variant->save();

            return $variant;
        };

        return [$make('M', 10), $make('L', 4, 800.0)];
    }

    protected function account(string $code): Account
    {
        return Account::where('code', $code)->firstOrFail();
    }

    /** @return array<string, mixed> */
    protected function sale(ProductVariant $variant, int $quantity = 1, array $overrides = []): array
    {
        return array_merge([
            'items' => [['variant_id' => $variant->id, 'quantity' => $quantity]],
        ], $overrides);
    }

    public function test_a_counter_sale_prices_itself_takes_stock_and_posts_the_money(): void
    {
        [$m, $l] = $this->variants();
        $cash = $this->account('cash');
        $seller = $this->staff('sales_staff');

        $this->actingAs($seller)->get(route('admin.pos.index'))->assertOk()->assertSee('Scan or search');

        // Prices come from the variant: the 5,000 typed here is ignored, M is 1,000 and L is on sale at 800.
        $this->actingAs($seller)->post(route('admin.pos.store'), [
            'items' => [
                ['variant_id' => $m->id, 'quantity' => 2, 'price' => 5000],
                ['variant_id' => $l->id, 'quantity' => 1],
            ],
            'discount' => 300,
            'payments' => [['method' => 'cash', 'account_id' => $cash->id, 'amount' => 2500]],
        ])->assertSessionHas('success');

        $order = Order::firstOrFail();
        $this->assertSame('pos', $order->channel);
        $this->assertTrue($order->isPosSale());
        $this->assertSame('delivered', $order->status);
        $this->assertNotNull($order->delivered_at);
        $this->assertSame(PosService::WALK_IN, $order->customer_name);
        $this->assertNull($order->customer_id);
        $this->assertSame(2800.0, (float) $order->subtotal);
        $this->assertSame(2500.0, (float) $order->total);
        $this->assertSame(2500.0, (float) $order->paid_amount);
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame(0.0, $order->due_amount);

        // Cost is snapshotted for profit, and the sale is on the order's own history.
        $this->assertSame(600.0, (float) $order->items()->where('variant_id', $m->id)->value('unit_cost'));
        $this->assertDatabaseHas('order_status_histories', ['order_id' => $order->id, 'to_status' => 'delivered', 'note' => 'Sold at the counter']);
        $this->assertDatabaseHas('activity_logs', ['module' => 'orders', 'action' => 'pos_sale', 'subject_id' => $order->id]);

        $this->assertSame(8, (int) $m->fresh()->stock);
        $this->assertSame(3, (int) $l->fresh()->stock);
        $this->assertSame(2, StockMovement::where('reason', 'pos_sale')->count());

        $this->assertSame(2500.0, $cash->fresh()->balance());
        $this->assertDatabaseHas('account_transactions', [
            'account_id' => $cash->id, 'direction' => 'in', 'type' => 'sale_payment', 'amount' => 2500,
        ]);

        // The same barcode scanned twice is one line, so stock leaves once per unit.
        $this->actingAs($seller)->post(route('admin.pos.store'), [
            'items' => [
                ['variant_id' => $m->id, 'quantity' => 1],
                ['variant_id' => $m->id, 'quantity' => 2],
            ],
            'payments' => [['method' => 'cash', 'account_id' => $cash->id, 'amount' => 3000]],
        ])->assertSessionHas('success');

        $merged = Order::latest('id')->firstOrFail();
        $this->assertSame(1, $merged->items()->count());
        $this->assertSame(3, (int) $merged->items()->value('quantity'));
        $this->assertSame(5, (int) $m->fresh()->stock);
    }

    public function test_payments_can_be_split_across_accounts_and_never_exceed_the_sale(): void
    {
        [$m] = $this->variants();
        $cash = $this->account('cash');
        $bkash = $this->account('bkash');
        $manager = $this->staff('manager');

        // More than the total is refused outright, and nothing is written.
        $this->actingAs($manager)->post(route('admin.pos.store'), $this->sale($m, 1, [
            'payments' => [['method' => 'cash', 'account_id' => $cash->id, 'amount' => 1500]],
        ]))->assertSessionHas('error');
        $this->assertSame(0, Order::count());
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(10, (int) $m->fresh()->stock);

        $this->actingAs($manager)->post(route('admin.pos.store'), $this->sale($m, 2, [
            'payments' => [
                ['method' => 'cash', 'account_id' => $cash->id, 'amount' => 1200],
                ['method' => 'bkash', 'account_id' => $bkash->id, 'amount' => 800],
            ],
        ]))->assertSessionHas('success');

        $order = Order::firstOrFail();
        $this->assertSame(2000.0, (float) $order->total);
        $this->assertSame(2000.0, (float) $order->paid_amount);
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame(2, Payment::where('order_id', $order->id)->count());
        $this->assertSame(1200.0, $cash->fresh()->balance());
        $this->assertSame(800.0, $bkash->fresh()->balance());
        $this->assertSame(2, AccountTransaction::count());

        // An inactive account cannot take money.
        $bkash->update(['is_active' => false]);
        $this->actingAs($manager)->post(route('admin.pos.store'), $this->sale($m, 1, [
            'payments' => [['method' => 'bkash', 'account_id' => $bkash->id, 'amount' => 1000]],
        ]))->assertSessionHasErrors('payments.0.account_id');
        $this->assertSame(1, Order::count());
    }

    public function test_an_unpaid_counter_sale_leaves_a_due_on_the_customer_but_never_on_a_walk_in(): void
    {
        [$m] = $this->variants();
        $cash = $this->account('cash');
        $seller = $this->staff('sales_staff');
        $customer = Customer::create(['name' => 'Rahima Begum', 'phone' => '01712345678', 'customer_group' => 'retail']);

        // Nobody to collect from: a walk-in must pay in full.
        $this->actingAs($seller)->post(route('admin.pos.store'), $this->sale($m, 1, [
            'payments' => [['method' => 'cash', 'account_id' => $cash->id, 'amount' => 400]],
        ]))->assertSessionHas('error');
        $this->assertSame(0, Order::count());

        $this->actingAs($seller)->post(route('admin.pos.store'), $this->sale($m, 1, [
            'customer_id' => $customer->id,
            'payments' => [['method' => 'cash', 'account_id' => $cash->id, 'amount' => 400]],
        ]))->assertSessionHas('success');

        $order = Order::firstOrFail();
        $this->assertSame($customer->id, $order->customer_id);
        $this->assertSame('Rahima Begum', $order->customer_name);
        $this->assertSame(600.0, $order->due_amount);
        $this->assertSame('partially_paid', $order->payment_status);

        // Delivered counts as a sale, so the due shows up wherever customer dues are listed.
        $this->assertSame(600.0, round((float) Customer::withTotals()->find($customer->id)->current_due, 2));
        $this->actingAs($this->staff())->get(route('admin.customer-dues.index'))->assertOk()
            ->assertSee('Rahima Begum')->assertSee(Money::format(600));

        // And it can be collected exactly like any other due.
        $this->actingAs($this->staff())->post(route('admin.customers.payments.store', $customer), [
            'amount' => 600, 'method' => 'cash', 'account_id' => $cash->id,
        ])->assertSessionHas('success');
        $this->assertSame(0.0, $order->fresh()->due_amount);

        // A new customer can be created straight from the till.
        $this->actingAs($seller)->post(route('admin.pos.store'), $this->sale($m, 1, [
            'customer_name' => 'Karim Mia', 'customer_phone' => '+880 1811-222333',
            'payments' => [['method' => 'cash', 'account_id' => $cash->id, 'amount' => 1000]],
        ]))->assertSessionHas('success');

        $created = Customer::where('phone', '01811222333')->firstOrFail();
        $this->assertSame('Karim Mia', $created->name);
        $this->assertSame($created->id, Order::latest('id')->value('customer_id'));
    }

    public function test_the_counter_follows_the_negative_stock_setting_and_only_sells_live_products(): void
    {
        [$m, $l] = $this->variants();
        $cash = $this->account('cash');
        $seller = $this->staff('sales_staff');

        // L has 4 in stock; with the setting off the counter refuses to oversell.
        $this->actingAs($seller)->post(route('admin.pos.store'), $this->sale($l, 5, [
            'payments' => [['method' => 'cash', 'account_id' => $cash->id, 'amount' => 4000]],
        ]))->assertSessionHas('error');
        $this->assertSame(0, Order::count());
        $this->assertSame(4, (int) $l->fresh()->stock);

        app(SettingsRepository::class)->set(['allow_negative_stock' => true]);

        $this->actingAs($seller)->post(route('admin.pos.store'), $this->sale($l, 5, [
            'payments' => [['method' => 'cash', 'account_id' => $cash->id, 'amount' => 4000]],
        ]))->assertSessionHas('success');
        $this->assertSame(-1, (int) $l->fresh()->stock);

        app(SettingsRepository::class)->set(['allow_negative_stock' => false]);

        // An archived product cannot be sold, whatever the stock says.
        $m->product->delete();
        $this->actingAs($seller)->post(route('admin.pos.store'), $this->sale($m, 1, [
            'payments' => [['method' => 'cash', 'account_id' => $cash->id, 'amount' => 1000]],
        ]))->assertSessionHas('error');
        $this->assertSame(1, Order::count());
    }

    public function test_the_counter_and_the_invoice_are_permission_gated(): void
    {
        [$m] = $this->variants();
        $cash = $this->account('cash');

        // Selling needs pos.sell: the accountant and warehouse staff do not have it.
        foreach (['accountant', 'warehouse_staff'] as $role) {
            $this->actingAs($this->staff($role))->get(route('admin.pos.index'))->assertForbidden();
            $this->actingAs($this->staff($role))->post(route('admin.pos.store'), $this->sale($m))->assertForbidden();
        }

        $this->assertSame(0, Order::count());

        $seller = $this->staff('sales_staff');
        $this->actingAs($seller)->get(route('admin.pos.customers', ['q' => 'ra']))->assertOk()->assertJsonStructure(['results']);
        $this->actingAs($seller)->post(route('admin.pos.store'), $this->sale($m, 1, [
            'payments' => [['method' => 'cash', 'account_id' => $cash->id, 'amount' => 1000]],
        ]))->assertSessionHas('success');

        $order = Order::firstOrFail();

        // The sale lands on the invoice, which anyone who can see orders can print.
        $this->actingAs($seller)->get(route('admin.orders.invoice', $order))->assertOk()
            ->assertSee('Sales receipt')->assertSee($order->order_number)->assertSee(Money::format(1000));
        $this->actingAs($this->staff('accountant'))->get(route('admin.orders.invoice', $order))->assertOk();

        // A counter sale is never offered to the customer as an online payment.
        $this->assertFalse($order->canPayOnline());
        $this->actingAs($seller)->get(route('admin.orders.show', $order))->assertOk()->assertSee('Counter sale');
        $this->actingAs($seller)->get(route('admin.orders.index'))->assertOk()->assertSee('Counter');
    }
}
