<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReturnTest extends TestCase
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
        $variant->stock = 20;
        $variant->save();

        $this->variant = $variant;
    }

    /** A delivered order for 5 units, made through the counter so the money and stock are real. */
    protected function deliveredOrder(int $quantity = 5, ?Customer $customer = null): Order
    {
        $this->actingAs($this->staff())->post(route('admin.pos.store'), [
            'items' => [['variant_id' => $this->variant->id, 'quantity' => $quantity]],
            'customer_id' => $customer?->id,
            'payments' => [['method' => 'cash', 'account_id' => Account::where('code', 'cash')->value('id'), 'amount' => $quantity * 1000]],
        ])->assertSessionHas('success');

        return Order::latest('id')->firstOrFail();
    }

    /** @return array<string, mixed> */
    protected function payload(Order $order, int $quantity, string $condition = 'restock', array $overrides = []): array
    {
        return array_merge([
            'items' => [[
                'order_item_id' => $order->items()->value('id'),
                'quantity' => $quantity,
                'condition' => $condition,
            ]],
            'reason' => 'wrong_size',
        ], $overrides);
    }

    public function test_a_return_moves_nothing_until_the_goods_are_received(): void
    {
        $order = $this->deliveredOrder();
        $manager = $this->staff('manager');
        $stockAfterSale = (int) $this->variant->fresh()->stock;

        $this->actingAs($manager)->get(route('admin.orders.show', $order))->assertOk()->assertSee('Start a return');

        $this->actingAs($manager)->post(route('admin.orders.returns.store', $order), $this->payload($order, 2))
            ->assertSessionHas('success');

        $return = OrderReturn::firstOrFail();
        $this->assertSame('requested', $return->status);
        $this->assertStringStartsWith('RT-', $return->number);
        $this->assertSame(2, $return->quantity);
        $this->assertSame(2000.0, (float) $return->refund_total);
        $this->assertSame($order->customer_id, $return->customer_id);
        $this->assertDatabaseHas('activity_logs', ['module' => 'orders', 'action' => 'return_requested', 'subject_id' => $return->id]);

        // Requested and approved move nothing: no stock, no money, and the order is untouched.
        $this->assertSame($stockAfterSale, (int) $this->variant->fresh()->stock);
        $this->assertSame('delivered', $order->fresh()->status);

        $this->actingAs($manager)->post(route('admin.returns.approve', $return))->assertSessionHas('success');
        $this->assertSame('approved', $return->fresh()->status);
        $this->assertSame($stockAfterSale, (int) $this->variant->fresh()->stock);
        $this->assertSame(1, AccountTransaction::count());

        $this->actingAs($manager)->post(route('admin.returns.receive', $return))->assertSessionHas('success');

        $return->refresh();
        $this->assertSame('received', $return->status);
        $this->assertSame($manager->id, $return->received_by);
        $this->assertSame($stockAfterSale + 2, (int) $this->variant->fresh()->stock);
        $this->assertDatabaseHas('stock_movements', [
            'variant_id' => $this->variant->id, 'type' => 'in', 'reason' => 'return_restock', 'quantity' => 2,
            'reference_type' => OrderReturn::class, 'reference_id' => $return->id,
        ]);

        // A return records goods, never money: the account ledger is untouched.
        $this->assertSame(1, AccountTransaction::count());
        $this->assertSame(5000.0, (float) $order->fresh()->paid_amount);

        // Part of the order still stands, so the order is not "returned" yet.
        $this->assertSame('delivered', $order->fresh()->status);

        // Receiving twice is refused, and so is rejecting goods already back on the shelf.
        $this->actingAs($manager)->post(route('admin.returns.receive', $return))->assertSessionHas('error');
        $this->actingAs($manager)->post(route('admin.returns.reject', $return))->assertSessionHas('error');
        $this->assertSame($stockAfterSale + 2, (int) $this->variant->fresh()->stock);
        $this->assertSame(1, StockMovement::where('reason', 'return_restock')->count());
    }

    public function test_damaged_units_come_in_and_are_written_off_so_the_loss_is_visible(): void
    {
        $order = $this->deliveredOrder(3);
        $manager = $this->staff('manager');
        $stockAfterSale = (int) $this->variant->fresh()->stock;

        $this->actingAs($manager)->post(route('admin.orders.returns.store', $order),
            $this->payload($order, 3, 'damaged', ['reason' => 'damaged']))->assertSessionHas('success');

        $return = OrderReturn::firstOrFail();
        $this->actingAs($manager)->post(route('admin.returns.approve', $return))->assertSessionHas('success');
        $this->actingAs($manager)->post(route('admin.returns.receive', $return))->assertSessionHas('success');

        // In then straight out: the stock level is unchanged, but both legs are in the ledger.
        $this->assertSame($stockAfterSale, (int) $this->variant->fresh()->stock);
        $this->assertSame(1, StockMovement::where('reason', 'return_restock')->count());
        $this->assertSame(1, StockMovement::where('reason', 'damaged')->count());
        $this->assertDatabaseHas('stock_movements', [
            'reason' => 'damaged', 'type' => 'out', 'quantity' => 3,
            'reference_type' => OrderReturn::class, 'reference_id' => $return->id,
        ]);

        // Everything on the order came back, so the order itself is returned.
        $order->refresh();
        $this->assertSame('returned', $order->status);
        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id, 'from_status' => 'delivered', 'to_status' => 'returned',
        ]);

        $this->actingAs($manager)->get(route('admin.returns.show', $return))->assertOk()
            ->assertSee('Damaged')->assertSee('Stock moved')->assertSee(Money::format(3000));
    }

    public function test_the_quantity_cap_holds_across_several_returns(): void
    {
        $order = $this->deliveredOrder(5);
        $manager = $this->staff('manager');
        $itemId = $order->items()->value('id');

        // More than was bought is refused outright.
        $this->actingAs($manager)->post(route('admin.orders.returns.store', $order), $this->payload($order, 6))
            ->assertSessionHas('error');
        $this->assertSame(0, OrderReturn::count());

        $this->actingAs($manager)->post(route('admin.orders.returns.store', $order), $this->payload($order, 3))
            ->assertSessionHas('success');

        // A second return can only claim what is left, even while the first is merely requested.
        $this->actingAs($manager)->post(route('admin.orders.returns.store', $order), $this->payload($order, 3))
            ->assertSessionHas('error');
        $this->actingAs($manager)->post(route('admin.orders.returns.store', $order), $this->payload($order, 2))
            ->assertSessionHas('success');
        $this->assertSame(2, OrderReturn::count());

        // Nothing is left to return now.
        $this->actingAs($manager)->post(route('admin.orders.returns.store', $order), $this->payload($order, 1))
            ->assertSessionHas('error');

        // Rejecting the first release its claim, so those 3 can come back on a new return.
        $first = OrderReturn::orderBy('id')->firstOrFail();
        $this->actingAs($manager)->post(route('admin.returns.reject', $first), ['reason' => 'Customer kept them'])
            ->assertSessionHas('success');
        $this->assertSame('rejected', $first->fresh()->status);
        $this->assertSame(3, $order->items()->first()->returnableQuantity());

        $this->actingAs($manager)->post(route('admin.orders.returns.store', $order), $this->payload($order, 3))
            ->assertSessionHas('success');
        $this->assertSame(0, $order->items()->first()->returnableQuantity());

        // Good and damaged units of one line share the same cap.
        $other = $this->deliveredOrder(4);
        $this->actingAs($manager)->post(route('admin.orders.returns.store', $other), [
            'items' => [
                ['order_item_id' => $other->items()->value('id'), 'quantity' => 3, 'condition' => 'restock'],
                ['order_item_id' => $other->items()->value('id'), 'quantity' => 2, 'condition' => 'damaged'],
            ],
            'reason' => 'wrong_item',
        ])->assertSessionHas('error');

        $this->actingAs($manager)->post(route('admin.orders.returns.store', $other), [
            'items' => [
                ['order_item_id' => $other->items()->value('id'), 'quantity' => 3, 'condition' => 'restock'],
                ['order_item_id' => $other->items()->value('id'), 'quantity' => 1, 'condition' => 'damaged'],
            ],
            'reason' => 'wrong_item',
        ])->assertSessionHas('success');

        $split = OrderReturn::latest('id')->firstOrFail();
        $this->assertSame(2, $split->items()->count());
        $this->assertSame(4, $split->quantity);
        $this->assertSame(0, $other->items()->first()->returnableQuantity());
        $this->assertSame($itemId, $itemId);
    }

    public function test_returns_need_the_right_permissions_and_a_sensible_order(): void
    {
        $order = $this->deliveredOrder(2);
        $payload = $this->payload($order, 1);

        // Raising a return needs orders.update. The accountant can see orders and refund them, but not update them.
        $this->actingAs($this->staff('accountant'))->post(route('admin.orders.returns.store', $order), $payload)
            ->assertForbidden();
        $this->assertSame(0, OrderReturn::count());

        // Sales staff are the ones at the counter when goods come back, so they can raise one.
        $this->actingAs($this->staff('sales_staff'))->post(route('admin.orders.returns.store', $order), $payload)
            ->assertRedirect();
        $return = OrderReturn::firstOrFail();

        // But putting goods back on the shelf needs inventory.adjust, which sales staff lack.
        $warehouse = $this->staff('warehouse_staff');
        $this->actingAs($this->staff('sales_staff'))->post(route('admin.returns.approve', $return))->assertSessionHas('success');
        $this->actingAs($this->staff('sales_staff'))->post(route('admin.returns.receive', $return))->assertForbidden();

        $this->actingAs($this->staff('accountant'))->post(route('admin.returns.receive', $return))->assertForbidden();
        $this->actingAs($warehouse)->post(route('admin.returns.receive', $return))->assertSessionHas('success');

        // Receiving out of order is refused: approve first.
        $this->actingAs($warehouse)->post(route('admin.orders.returns.store', $order), $payload)->assertRedirect();
        $second = OrderReturn::latest('id')->firstOrFail();
        $this->actingAs($warehouse)->post(route('admin.returns.receive', $second))->assertSessionHas('error');
        $this->assertSame('requested', $second->fresh()->status);

        // A pending order has sent nothing out, so nothing can come back.
        $pending = Order::create([
            'customer_name' => 'Nobody', 'customer_phone' => '01700000000', 'channel' => 'online',
            'subtotal' => 100, 'discount' => 0, 'shipping_cost' => 0, 'total' => 100,
            'payment_method' => 'cod', 'status' => 'pending', 'payment_status' => 'unpaid',
        ]);
        $pending->items()->create([
            'product_id' => $this->variant->product_id, 'variant_id' => $this->variant->id,
            'product_name' => 'Premium Kurti', 'price' => 100, 'quantity' => 1, 'subtotal' => 100,
        ]);

        $this->actingAs($warehouse)->post(route('admin.orders.returns.store', $pending), [
            'items' => [['order_item_id' => $pending->items()->value('id'), 'quantity' => 1, 'condition' => 'restock']],
            'reason' => 'other',
        ])->assertSessionHas('error');

        // An item from another order cannot be smuggled onto this one.
        $this->actingAs($warehouse)->post(route('admin.orders.returns.store', $order), [
            'items' => [['order_item_id' => $pending->items()->value('id'), 'quantity' => 1, 'condition' => 'restock']],
            'reason' => 'other',
        ])->assertSessionHas('error');
    }

    public function test_returns_are_listed_and_show_on_the_customer_profile(): void
    {
        $customer = Customer::create(['name' => 'Rahima Begum', 'phone' => '01712345678', 'customer_group' => 'retail']);
        $order = $this->deliveredOrder(2, $customer);
        $manager = $this->staff('manager');

        $this->actingAs($manager)->post(route('admin.orders.returns.store', $order),
            $this->payload($order, 1, 'restock', ['note' => 'Zip is broken']))->assertSessionHas('success');

        $return = OrderReturn::firstOrFail();

        $this->actingAs($manager)->get(route('admin.returns.index'))->assertOk()
            ->assertSee($return->number)->assertSee($order->order_number)->assertSee('Wrong size or fit');
        $this->actingAs($manager)->get(route('admin.returns.index', ['status' => 'requested']))->assertOk()->assertSee($return->number);
        $this->actingAs($manager)->get(route('admin.returns.index', ['status' => 'received']))->assertOk()->assertDontSee($return->number);
        $this->actingAs($manager)->get(route('admin.returns.index', ['q' => $order->order_number]))->assertOk()->assertSee($return->number);
        $this->actingAs($manager)->get(route('admin.returns.index', ['reason' => 'damaged']))->assertOk()->assertDontSee($return->number);

        $this->actingAs($manager)->get(route('admin.returns.show', $return))->assertOk()
            ->assertSee('Zip is broken')->assertSee('Rahima Begum')->assertSee('Nothing has moved yet');

        // The order page and the customer profile both show it.
        $this->actingAs($manager)->get(route('admin.orders.show', $order))->assertOk()->assertSee($return->number);
        $this->actingAs($manager)->get(route('admin.customers.show', $customer))->assertOk()
            ->assertSee($return->number)->assertSee('Returns');
    }
}
