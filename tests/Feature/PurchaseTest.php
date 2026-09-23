<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: ProductVariant, 1: ProductVariant} */
    protected function variants(): array
    {
        $category = Category::create(['name' => 'Kurti', 'is_active' => true]);

        $product = new Product([
            'category_id' => $category->id, 'name' => 'Premium Kurti', 'price' => 2000,
            'cost_price' => null, 'is_active' => true, 'has_variants' => true,
        ]);
        $product->withoutDefaultVariant = true;
        $product->save();

        $make = function (string $size, int $stock, ?float $cost) use ($product) {
            $variant = new ProductVariant(['sku' => 'KUR-' . $size, 'size' => $size, 'cost_price' => $cost, 'is_active' => true]);
            $variant->product_id = $product->id;
            $variant->stock = $stock;
            $variant->save();

            return $variant;
        };

        return [$make('M', 10, 100.0), $make('L', 0, null)];
    }

    protected function supplier(float $openingDue = 0): Supplier
    {
        return Supplier::create(['name' => 'Abdur Rahman', 'company' => 'Rahman & Sons', 'opening_due' => $openingDue]);
    }

    /** @return array<string, mixed> */
    protected function payload(Supplier $supplier, ProductVariant $a, ProductVariant $b, array $overrides = []): array
    {
        return array_merge([
            'supplier_id' => $supplier->id,
            'status' => 'ordered',
            'purchase_date' => now()->toDateString(),
            'invoice_number' => 'INV-9001',
            'discount' => 0,
            'additional_cost' => 0,
            'items' => [
                ['variant_id' => $a->id, 'quantity' => 10, 'unit_cost' => 200],
                ['variant_id' => $b->id, 'quantity' => 5, 'unit_cost' => 100],
            ],
        ], $overrides);
    }

    public function test_totals_are_recalculated_on_the_server_and_the_form_is_permission_gated(): void
    {
        [$m, $l] = $this->variants();
        $supplier = $this->supplier();

        $this->actingAs($this->staff('sales_staff'))->get(route('admin.purchases.index'))->assertForbidden();
        $this->actingAs($this->staff('accountant'))->get(route('admin.purchases.create'))->assertForbidden();

        $warehouse = $this->staff('warehouse_staff');
        $this->actingAs($warehouse)->get(route('admin.purchases.create'))->assertOk()->assertSee('Abdur Rahman');

        // Goods 2,500 − 300 discount + 500 transport = 2,700, whatever the form might claim.
        $this->actingAs($warehouse)
            ->post(route('admin.purchases.store'), $this->payload($supplier, $m, $l, [
                'discount' => 300, 'additional_cost' => 500, 'total' => 999999,
            ]))
            ->assertRedirect();

        $purchase = Purchase::firstOrFail();
        $this->assertSame(2500.0, (float) $purchase->subtotal);
        $this->assertSame(2700.0, (float) $purchase->total);
        $this->assertSame('ordered', $purchase->status);
        $this->assertSame(2, $purchase->items()->count());
        $this->assertStringStartsWith('PU-', $purchase->number);
        $this->assertDatabaseHas('activity_logs', ['module' => 'purchases', 'action' => 'purchase_created', 'subject_id' => $purchase->id]);

        // Nothing has moved yet: it is only a plan.
        $this->assertSame(10, (int) $m->fresh()->stock);
        $this->assertSame(0.0, $supplier->fresh()->balance());
        $this->assertSame(0, StockMovement::count());

        // A purchase needs items, a real supplier, a sane date, and a discount no larger than the goods.
        $this->actingAs($warehouse)->post(route('admin.purchases.store'), $this->payload($supplier, $m, $l, ['items' => []]))
            ->assertSessionHasErrors('items');
        $this->actingAs($warehouse)->post(route('admin.purchases.store'), $this->payload($supplier, $m, $l, ['supplier_id' => 9999]))
            ->assertSessionHasErrors('supplier_id');
        $this->actingAs($warehouse)->post(route('admin.purchases.store'), $this->payload($supplier, $m, $l, ['purchase_date' => now()->addWeek()->toDateString()]))
            ->assertSessionHasErrors('purchase_date');
        $this->actingAs($warehouse)->post(route('admin.purchases.store'), $this->payload($supplier, $m, $l, ['discount' => 9000]))
            ->assertSessionHas('error');
        $this->actingAs($warehouse)->post(route('admin.purchases.store'), $this->payload($supplier, $m, $l, [
            'items' => [['variant_id' => $m->id, 'quantity' => 0, 'unit_cost' => 10]],
        ]))->assertSessionHasErrors('items.0.quantity');
        $this->assertSame(1, Purchase::count());

        // The same variant typed twice becomes one line.
        $this->actingAs($warehouse)->post(route('admin.purchases.store'), $this->payload($supplier, $m, $l, [
            'items' => [
                ['variant_id' => $m->id, 'quantity' => 3, 'unit_cost' => 200],
                ['variant_id' => $m->id, 'quantity' => 2, 'unit_cost' => 200],
            ],
        ]))->assertRedirect();

        $merged = Purchase::latest('id')->firstOrFail();
        $this->assertSame(1, $merged->items()->count());
        $this->assertSame(5, (int) $merged->items()->value('quantity'));
        $this->assertSame(1000.0, (float) $merged->total);
    }

    public function test_receiving_adds_stock_once_at_landed_cost_and_bills_the_supplier(): void
    {
        [$m, $l] = $this->variants();
        $supplier = $this->supplier(1000);
        $manager = $this->staff('manager');

        $this->actingAs($manager)->post(route('admin.purchases.store'),
            $this->payload($supplier, $m, $l, ['discount' => 300, 'additional_cost' => 500]))->assertRedirect();

        $purchase = Purchase::firstOrFail();
        $this->actingAs($manager)->get(route('admin.purchases.show', $purchase))->assertOk()
            ->assertSee('Receive into stock')->assertSee(Money::format(2700));

        $this->actingAs($manager)->post(route('admin.purchases.receive', $purchase))->assertSessionHas('success');

        // 200 spread over 2,500 of goods is 8%: 200 → 216, 100 → 108.
        $purchase->refresh()->load('items');
        $this->assertSame('received', $purchase->status);
        $this->assertSame($manager->id, $purchase->received_by);
        $this->assertEqualsWithDelta(216.0, (float) $purchase->items->firstWhere('variant_id', $m->id)->landed_unit_cost, 0.01);
        $this->assertEqualsWithDelta(108.0, (float) $purchase->items->firstWhere('variant_id', $l->id)->landed_unit_cost, 0.01);

        $this->assertSame(20, (int) $m->fresh()->stock);
        $this->assertSame(5, (int) $l->fresh()->stock);
        $this->assertSame(2, StockMovement::where('reason', 'purchase_received')->count());
        $this->assertDatabaseHas('stock_movements', [
            'variant_id' => $m->id, 'type' => 'in', 'quantity' => 10,
            'reference_type' => Purchase::class, 'reference_id' => $purchase->id,
        ]);

        // Weighted average: 10 units at 100 plus 10 at 216 averages 158. An empty variant just takes the landed cost.
        $this->assertEqualsWithDelta(158.0, (float) $m->fresh()->cost_price, 0.01);
        $this->assertEqualsWithDelta(108.0, (float) $l->fresh()->cost_price, 0.01);

        // The supplier is now owed the opening due plus this purchase.
        $this->assertSame(3700.0, $supplier->fresh()->balance());
        $this->actingAs($manager)->get(route('admin.suppliers.show', $supplier))->assertOk()
            ->assertSee($purchase->number)->assertSee(Money::format(3700));

        // Receiving twice, editing or cancelling a received purchase are all refused.
        $this->actingAs($manager)->post(route('admin.purchases.receive', $purchase))->assertSessionHas('error');
        $this->actingAs($manager)->post(route('admin.purchases.cancel', $purchase))->assertSessionHas('error');
        $this->actingAs($manager)->get(route('admin.purchases.edit', $purchase))->assertRedirect(route('admin.purchases.show', $purchase));
        $this->actingAs($manager)->put(route('admin.purchases.update', $purchase), $this->payload($supplier, $m, $l))
            ->assertSessionHas('error');

        $this->assertSame(20, (int) $m->fresh()->stock);
        $this->assertSame(2, StockMovement::count());
        $this->assertSame(3700.0, $supplier->fresh()->balance());
    }

    public function test_paying_while_receiving_needs_the_accounting_permission_and_respects_the_account_balance(): void
    {
        [$m, $l] = $this->variants();
        $supplier = $this->supplier();
        $cash = Account::where('code', 'cash')->firstOrFail();
        $cash->update(['opening_balance' => 2000]);

        $warehouse = $this->staff('warehouse_staff');
        $this->actingAs($warehouse)->post(route('admin.purchases.store'), $this->payload($supplier, $m, $l))->assertRedirect();
        $purchase = Purchase::firstOrFail();

        // Warehouse staff receive goods but cannot move money.
        $this->actingAs($warehouse)->get(route('admin.purchases.show', $purchase))->assertOk()->assertDontSee('Pay now');
        $this->actingAs($warehouse)->post(route('admin.purchases.receive', $purchase), ['pay_now' => 500, 'method' => 'cash', 'account_id' => $cash->id])
            ->assertSessionHas('error');
        $this->assertTrue($purchase->fresh()->isOpen());
        $this->assertSame(0, SupplierPayment::count());

        $manager = $this->staff('manager');

        // The cash drawer only holds 2,000, so the whole purchase cannot be paid at once.
        $this->actingAs($manager)->post(route('admin.purchases.receive', $purchase), ['pay_now' => 2500, 'method' => 'cash', 'account_id' => $cash->id])
            ->assertSessionHas('error');
        $this->assertTrue($purchase->fresh()->isOpen());
        $this->assertSame(10, (int) $m->fresh()->stock);
        $this->assertSame(0, StockMovement::count());

        $this->actingAs($manager)->post(route('admin.purchases.receive', $purchase), ['pay_now' => 1500, 'method' => 'cash', 'account_id' => $cash->id])
            ->assertSessionHas('success');

        $payment = SupplierPayment::firstOrFail();
        $this->assertSame(1500.0, (float) $payment->amount);
        $this->assertSame(500.0, $cash->fresh()->balance());
        $this->assertSame(1000.0, $supplier->fresh()->balance());
        $this->assertDatabaseHas('account_transactions', [
            'account_id' => $cash->id, 'direction' => 'out', 'type' => 'supplier_payment',
            'reference_type' => SupplierPayment::class, 'reference_id' => $payment->id,
        ]);
        $this->assertSame(1, AccountTransaction::count());
        $this->assertStringContainsString($purchase->number, (string) $payment->note);
        $this->assertSame(20, (int) $m->fresh()->stock);
    }

    public function test_an_open_purchase_can_be_edited_and_cancelled_without_touching_stock(): void
    {
        [$m, $l] = $this->variants();
        $supplier = $this->supplier();
        $manager = $this->staff('manager');

        $this->actingAs($manager)->post(route('admin.purchases.store'), $this->payload($supplier, $m, $l))->assertRedirect();
        $purchase = Purchase::firstOrFail();

        $this->actingAs($manager)->get(route('admin.purchases.edit', $purchase))->assertOk()->assertSee('INV-9001');
        $this->actingAs($manager)->put(route('admin.purchases.update', $purchase), $this->payload($supplier, $m, $l, [
            'invoice_number' => 'INV-9002',
            'items' => [['variant_id' => $m->id, 'quantity' => 4, 'unit_cost' => 250]],
        ]))->assertRedirect(route('admin.purchases.show', $purchase));

        $purchase->refresh();
        $this->assertSame('INV-9002', $purchase->invoice_number);
        $this->assertSame(1, $purchase->items()->count());
        $this->assertSame(1000.0, (float) $purchase->total);
        $this->assertDatabaseHas('activity_logs', ['action' => 'purchase_updated', 'subject_id' => $purchase->id]);

        $this->actingAs($manager)->post(route('admin.purchases.cancel', $purchase), ['reason' => 'Supplier out of stock'])
            ->assertSessionHas('success');

        $this->assertSame('cancelled', $purchase->fresh()->status);
        $this->assertSame(10, (int) $m->fresh()->stock);
        $this->assertSame(0.0, $supplier->fresh()->balance());
        $this->assertSame(0, StockMovement::count());
        $this->assertDatabaseHas('activity_logs', ['action' => 'purchase_cancelled', 'subject_id' => $purchase->id]);

        // A cancelled purchase is read-only.
        $this->actingAs($manager)->post(route('admin.purchases.receive', $purchase))->assertSessionHas('error');
        $this->actingAs($manager)->post(route('admin.purchases.cancel', $purchase))->assertSessionHas('error');
    }

    public function test_the_list_filters_sorts_exports_and_links_labels_for_a_received_purchase(): void
    {
        [$m, $l] = $this->variants();
        $supplier = $this->supplier();
        $other = Supplier::create(['name' => 'Karim Fabrics']);
        $manager = $this->staff('manager');

        $this->actingAs($manager)->post(route('admin.purchases.store'), $this->payload($supplier, $m, $l))->assertRedirect();
        $this->actingAs($manager)->post(route('admin.purchases.store'), $this->payload($other, $m, $l, ['invoice_number' => 'INV-7777']))->assertRedirect();

        $first = Purchase::orderBy('id')->firstOrFail();
        $this->actingAs($manager)->post(route('admin.purchases.receive', $first))->assertSessionHas('success');

        $this->actingAs($manager)->get(route('admin.purchases.index'))->assertOk()
            ->assertSee($first->number)->assertSee('Abdur Rahman')->assertSee('Karim Fabrics');
        $this->actingAs($manager)->get(route('admin.purchases.index', ['status' => 'received']))->assertOk()
            ->assertSee($first->number)->assertDontSee('INV-7777');
        $this->actingAs($manager)->get(route('admin.purchases.index', ['supplier' => $other->id]))->assertOk()
            ->assertSee('INV-7777')->assertDontSee($first->number);
        $this->actingAs($manager)->get(route('admin.purchases.index', ['q' => 'INV-7777']))->assertOk()->assertSee('Karim Fabrics');
        $this->actingAs($manager)->get(route('admin.purchases.index', ['from' => now()->addDay()->toDateString()]))->assertOk()
            ->assertDontSee($first->number);

        foreach (array_keys(\App\Http\Controllers\Admin\PurchaseController::SORTS) as $sort) {
            $this->actingAs($manager)->get(route('admin.purchases.index', ['sort' => $sort]))->assertOk();
        }

        $csv = $this->actingAs($manager)->get(route('admin.purchases.export', ['status' => 'received']))->assertOk()->streamedContent();
        $this->assertStringContainsString($first->number, $csv);
        $this->assertStringNotContainsString('INV-7777', $csv);
        $this->actingAs($this->staff('warehouse_staff'))->get(route('admin.purchases.export'))->assertForbidden();

        $this->actingAs($manager)->get(route('admin.purchases.show', $first))->assertOk()
            ->assertDontSee('Print labels for this purchase')->assertSee('Stock added');

        // The barcode screen still accepts a purchase's variants and quantities.

        $this->actingAs($manager)->get(route('admin.barcodes.index', [
            'variants' => [$m->id], 'qty' => [$m->id => 10],
        ]))->assertOk()->assertViewHas('preselected', fn ($rows) => $rows->firstWhere('id', $m->id)['quantity'] === 10);
    }

    public function test_a_purchase_prints_as_an_a4_invoice(): void
    {
        [$m, $l] = $this->variants();
        $manager = $this->staff('manager');

        $this->actingAs($manager)->post(route('admin.purchases.store'), $this->payload($this->supplier(), $m, $l))->assertRedirect();
        $purchase = Purchase::firstOrFail();

        $this->actingAs($manager)->get(route('admin.purchases.show', $purchase))
            ->assertSee(route('admin.purchases.invoice', $purchase));

        $this->actingAs($manager)->get(route('admin.purchases.invoice', $purchase))
            ->assertOk()
            ->assertSee('Purchase invoice')
            ->assertSee($purchase->number)
            ->assertSee('Rahman &amp; Sons', false)
            ->assertSee('INV-9001')
            ->assertSee('KUR-M')
            ->assertSee(\App\Support\Money::format(2500));

        $this->actingAs($this->staff('sales_staff'))->get(route('admin.purchases.invoice', $purchase))->assertForbidden();
    }

    public function test_the_purchase_form_starts_from_the_saved_or_last_paid_price_and_receiving_keeps_it(): void
    {
        $category = Category::create(['name' => 'Tops', 'is_active' => true]);
        $shirt = Product::create(['category_id' => $category->id, 'name' => 'Polo Shirt', 'price' => 1500, 'cost_price' => null, 'is_active' => true]);
        $variant = $shirt->variants()->firstOrFail();
        $manager = $this->staff('manager');

        // Nothing saved and never bought: the form has nothing to start from.
        $result = $this->actingAs($manager)->getJson(route('admin.variants.search', ['q' => $variant->sku]))->json('results.0');
        $this->assertNull($result['cost']);
        $this->assertNull($result['last_cost']);

        $this->actingAs($manager)->post(route('admin.purchases.store'), [
            'supplier_id' => $this->supplier()->id, 'status' => 'ordered', 'purchase_date' => now()->toDateString(),
            'discount' => 0, 'additional_cost' => 0,
            'items' => [['variant_id' => $variant->id, 'quantity' => 2, 'unit_cost' => 800]],
        ])->assertRedirect();
        $this->actingAs($manager)->post(route('admin.purchases.receive', Purchase::firstOrFail()))->assertSessionHas('success');

        // A product without options gets the cost on the product itself, so editing it later keeps the cost.
        $this->assertSame(800.0, (float) $shirt->fresh()->cost_price);

        $result = $this->actingAs($manager)->getJson(route('admin.variants.search', ['q' => $variant->sku]))->json('results.0');
        $this->assertEquals(800, $result['cost']);
        $this->assertEquals(800, $result['last_cost']);
    }

    public function test_a_purchase_can_be_made_without_a_supplier_and_paid_straight_from_an_account(): void
    {
        [$m, $l] = $this->variants();
        $manager = $this->staff('manager');
        $cash = Account::where('code', 'cash')->firstOrFail();
        app(\App\Services\AccountService::class)->entry($cash, 'in', 5000, 'Float');

        $this->actingAs($manager)->get(route('admin.purchases.create'))->assertOk()->assertSee('No supplier');

        $this->actingAs($manager)->post(route('admin.purchases.store'), $this->payload($this->supplier(), $m, $l, ['supplier_id' => '']))
            ->assertSessionHasNoErrors()->assertRedirect();
        $purchase = Purchase::firstOrFail();
        $this->assertNull($purchase->supplier_id);
        $this->assertSame(2500.0, (float) $purchase->total);

        $this->actingAs($manager)->get(route('admin.purchases.show', $purchase))->assertOk()->assertSee('No supplier');

        // More than the purchase is refused; the full total comes out of cash as a purchase payment.
        $this->actingAs($manager)->post(route('admin.purchases.receive', $purchase), [
            'pay_now' => 3000, 'method' => 'cash', 'account_id' => $cash->id,
        ])->assertSessionHas('error');
        $this->assertSame('ordered', $purchase->fresh()->status);

        $this->actingAs($manager)->post(route('admin.purchases.receive', $purchase), [
            'pay_now' => 2500, 'method' => 'cash', 'account_id' => $cash->id,
        ])->assertSessionHas('success');

        $this->assertSame('received', $purchase->fresh()->status);
        $this->assertSame(20, (int) $m->fresh()->stock);
        $this->assertSame(2500.0, $cash->fresh()->balance());
        $this->assertDatabaseHas('account_transactions', [
            'account_id' => $cash->id, 'direction' => 'out', 'amount' => 2500, 'type' => 'purchase_payment',
            'reference_type' => Purchase::class, 'reference_id' => $purchase->id,
        ]);
        $this->assertSame(0, SupplierPayment::count());

        $this->actingAs($manager)->get(route('admin.purchases.invoice', $purchase))->assertOk()->assertSee('No supplier');
        $this->actingAs($manager)->get(route('admin.purchases.index'))->assertOk()->assertSee('No supplier');
        $this->actingAs($manager)->get(route('admin.reports.cost'))->assertOk()->assertSee('Purchase paid (no supplier)');
    }
}
