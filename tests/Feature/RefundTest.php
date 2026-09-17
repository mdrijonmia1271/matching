<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Refund;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RefundTest extends TestCase
{
    use RefreshDatabase;

    protected ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $category = Category::create(['name' => 'Kurti', 'is_active' => true]);

        $product = new Product([
            'category_id' => $category->id, 'name' => 'Premium Kurti', 'price' => 1000,
            'cost_price' => 600, 'is_active' => true, 'has_variants' => true,
        ]);
        $product->withoutDefaultVariant = true;
        $product->save();

        $variant = new ProductVariant(['sku' => 'KUR-M', 'size' => 'M', 'is_active' => true]);
        $variant->product_id = $product->id;
        $variant->stock = 50;
        $variant->save();

        $this->variant = $variant;
    }

    protected function account(string $code): Account
    {
        return Account::where('code', $code)->firstOrFail();
    }

    /** A paid counter sale, so there is real money in the drawer to give back. */
    protected function paidOrder(int $quantity = 2, ?Customer $customer = null): Order
    {
        $this->actingAs($this->staff())->post(route('admin.pos.store'), [
            'items' => [['variant_id' => $this->variant->id, 'quantity' => $quantity]],
            'customer_id' => $customer?->id,
            'payments' => [['method' => 'cash', 'account_id' => $this->account('cash')->id, 'amount' => $quantity * 1000]],
        ])->assertSessionHas('success');

        return Order::latest('id')->firstOrFail();
    }

    /** Send every unit back and receive it, so the order reaches `returned`. */
    protected function returnEverything(Order $order): OrderReturn
    {
        $manager = $this->staff('manager');

        $this->actingAs($manager)->post(route('admin.orders.returns.store', $order), [
            'items' => [[
                'order_item_id' => $order->items()->value('id'),
                'quantity' => $order->items()->value('quantity'),
                'condition' => 'restock',
            ]],
            'reason' => 'wrong_size',
        ])->assertSessionHas('success');

        $return = OrderReturn::latest('id')->firstOrFail();
        $this->actingAs($manager)->post(route('admin.returns.approve', $return))->assertSessionHas('success');
        $this->actingAs($manager)->post(route('admin.returns.receive', $return))->assertSessionHas('success');

        return $return->fresh();
    }

    public function test_a_refund_takes_money_out_of_an_account_and_derives_the_payment_status(): void
    {
        $order = $this->paidOrder(2);
        $cash = $this->account('cash');
        $manager = $this->staff('manager');

        $this->assertSame(2000.0, $cash->fresh()->balance());
        $this->assertSame(2000.0, $order->refundable_amount);

        $this->actingAs($manager)->get(route('admin.orders.show', $order))->assertOk()->assertSee('Give money back');

        $this->actingAs($manager)->post(route('admin.orders.refunds.store', $order), [
            'amount' => 500, 'method' => 'cash', 'account_id' => $cash->id, 'note' => 'Goodwill on one item',
        ])->assertSessionHas('success');

        $refund = Refund::firstOrFail();
        $this->assertStringStartsWith('RF-', $refund->number);
        $this->assertSame($manager->id, $refund->refunded_by);
        $this->assertNull($refund->order_return_id);

        $order->refresh();
        $this->assertSame(500.0, (float) $order->refunded_amount);
        $this->assertSame('partially_refunded', $order->payment_status);
        $this->assertSame(1500.0, $order->refundable_amount);
        $this->assertSame(1500.0, $cash->fresh()->balance());

        $this->assertDatabaseHas('account_transactions', [
            'account_id' => $cash->id, 'direction' => 'out', 'type' => 'refund',
            'reference_type' => Refund::class, 'reference_id' => $refund->id, 'amount' => 500,
        ]);
        $this->assertDatabaseHas('activity_logs', ['module' => 'orders', 'action' => 'refund_issued', 'subject_id' => $order->id]);

        // The rest goes back too, which makes the payment status "refunded".
        $this->actingAs($manager)->post(route('admin.orders.refunds.store', $order), [
            'amount' => 1500, 'method' => 'cash', 'account_id' => $cash->id,
        ])->assertSessionHas('success');

        $order->refresh();
        $this->assertSame(2000.0, (float) $order->refunded_amount);
        $this->assertSame('refunded', $order->payment_status);
        $this->assertSame(0.0, $order->refundable_amount);
        $this->assertSame(0.0, $cash->fresh()->balance());
        $this->assertSame(2, Refund::count());

        // The order status still says delivered: the goods never came back.
        $this->assertSame('delivered', $order->status);

        $this->actingAs($manager)->get(route('admin.orders.show', $order))->assertOk()
            ->assertSee($refund->number)->assertSee('Goodwill on one item')->assertDontSee('Give money back');
        $this->actingAs($manager)->get(route('admin.accounts.show', $cash))->assertOk()
            ->assertSee('Refund')->assertSee($refund->number);
    }

    public function test_a_refund_can_never_exceed_what_was_paid_or_what_the_account_holds(): void
    {
        $order = $this->paidOrder(2);
        $cash = $this->account('cash');
        $bank = $this->account('bank');
        $manager = $this->staff('manager');

        // More than was paid is refused, and nothing is written.
        $this->actingAs($manager)->post(route('admin.orders.refunds.store', $order), [
            'amount' => 2500, 'method' => 'cash', 'account_id' => $cash->id,
        ])->assertSessionHas('error');
        $this->assertSame(0, Refund::count());
        $this->assertSame(2000.0, $cash->fresh()->balance());
        $this->assertSame(0.0, (float) $order->fresh()->refunded_amount);

        // The bank holds nothing, so the money cannot come from there.
        $this->actingAs($manager)->post(route('admin.orders.refunds.store', $order), [
            'amount' => 100, 'method' => 'bank', 'account_id' => $bank->id,
        ])->assertSessionHas('error');
        $this->assertSame(0, Refund::count());

        $this->actingAs($manager)->post(route('admin.orders.refunds.store', $order), [
            'amount' => 0, 'method' => 'cash', 'account_id' => $cash->id,
        ])->assertSessionHasErrors('amount');

        // Two refunds cannot together exceed what was paid.
        $this->actingAs($manager)->post(route('admin.orders.refunds.store', $order), [
            'amount' => 1200, 'method' => 'cash', 'account_id' => $cash->id,
        ])->assertSessionHas('success');
        $this->actingAs($manager)->post(route('admin.orders.refunds.store', $order), [
            'amount' => 900, 'method' => 'cash', 'account_id' => $cash->id,
        ])->assertSessionHas('error');
        $this->assertSame(1, Refund::count());
        $this->assertSame(800.0, $cash->fresh()->balance());

        // An unpaid order has nothing to give back.
        $unpaid = Order::create([
            'customer_name' => 'Nobody', 'customer_phone' => '01700000000', 'channel' => 'online',
            'subtotal' => 500, 'discount' => 0, 'shipping_cost' => 0, 'total' => 500,
            'payment_method' => 'cod', 'status' => 'delivered', 'payment_status' => 'unpaid',
        ]);
        $this->actingAs($manager)->post(route('admin.orders.refunds.store', $unpaid), [
            'amount' => 100, 'method' => 'cash', 'account_id' => $cash->id,
        ])->assertSessionHas('error');

        // An inactive account cannot pay out.
        $bank->update(['opening_balance' => 5000, 'is_active' => false]);
        $this->actingAs($manager)->post(route('admin.orders.refunds.store', $order), [
            'amount' => 100, 'method' => 'bank', 'account_id' => $bank->id,
        ])->assertSessionHasErrors('account_id');
        $this->assertSame(1, Refund::count());
    }

    public function test_an_order_is_refunded_only_when_the_goods_are_back_and_the_money_has_gone(): void
    {
        $customer = Customer::create(['name' => 'Rahima Begum', 'phone' => '01712345678', 'customer_group' => 'retail']);
        $order = $this->paidOrder(2, $customer);
        $cash = $this->account('cash');
        $manager = $this->staff('manager');

        $return = $this->returnEverything($order);
        $this->assertSame('returned', $order->fresh()->status);

        // Goods back, money not yet: still "returned", and the money is flagged as refundable.
        $this->assertSame(2000.0, $order->fresh()->refundable_amount);
        $this->actingAs($manager)->get(route('admin.returns.show', $return))->assertOk()
            ->assertSee('Refund on this order')->assertSee(Money::format(2000));

        // A partial refund leaves the order as it is.
        $this->actingAs($manager)->post(route('admin.orders.refunds.store', $order), [
            'amount' => 800, 'method' => 'cash', 'account_id' => $cash->id, 'order_return_id' => $return->id,
        ])->assertSessionHas('success');

        $order->refresh();
        $this->assertSame('returned', $order->status);
        $this->assertSame('partially_refunded', $order->payment_status);

        $this->actingAs($manager)->post(route('admin.orders.refunds.store', $order), [
            'amount' => 1200, 'method' => 'cash', 'account_id' => $cash->id, 'order_return_id' => $return->id,
        ])->assertSessionHas('success');

        // Goods back and money returned: now the order itself is refunded.
        $order->refresh();
        $this->assertSame('refunded', $order->status);
        $this->assertSame('refunded', $order->payment_status);
        $this->assertSame(0.0, $cash->fresh()->balance());
        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id, 'from_status' => 'returned', 'to_status' => 'refunded',
        ]);

        // Both refunds are tied to the return that caused them.
        $this->assertSame(2, $return->refunds()->count());
        $this->actingAs($manager)->get(route('admin.returns.show', $return))->assertOk()
            ->assertSee('Refunded against this return')->assertDontSee('Refund on this order');

        // A refunded order takes no more money back.
        $this->actingAs($manager)->post(route('admin.orders.refunds.store', $order), [
            'amount' => 1, 'method' => 'cash', 'account_id' => $cash->id,
        ])->assertSessionHas('error');
        $this->assertSame(2, Refund::count());
    }

    public function test_a_refund_cannot_be_attached_to_another_orders_return_and_needs_both_permissions(): void
    {
        $order = $this->paidOrder(2);
        $other = $this->paidOrder(1);
        $cash = $this->account('cash');
        $otherReturn = $this->returnEverything($other);

        // Refunding needs orders.refund and accounting.create.
        foreach (['sales_staff', 'warehouse_staff'] as $role) {
            $this->actingAs($this->staff($role))->post(route('admin.orders.refunds.store', $order), [
                'amount' => 100, 'method' => 'cash', 'account_id' => $cash->id,
            ])->assertForbidden();
        }

        $this->assertSame(0, Refund::count());

        // The accountant has both, so they can refund.
        $this->actingAs($this->staff('accountant'))->post(route('admin.orders.refunds.store', $order), [
            'amount' => 100, 'method' => 'cash', 'account_id' => $cash->id,
        ])->assertSessionHas('success');
        $this->assertSame(1, Refund::count());

        // A return belonging to a different order is rejected by validation.
        $this->actingAs($this->staff('manager'))->post(route('admin.orders.refunds.store', $order), [
            'amount' => 100, 'method' => 'cash', 'account_id' => $cash->id, 'order_return_id' => $otherReturn->id,
        ])->assertSessionHasErrors('order_return_id');
        $this->assertSame(1, Refund::count());

        // Sales staff never see the refund form.
        $this->actingAs($this->staff('sales_staff'))->get(route('admin.orders.show', $order))->assertOk()
            ->assertDontSee('Give money back');
    }
}
