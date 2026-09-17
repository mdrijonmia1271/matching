<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Order;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CustomerDueTest extends TestCase
{
    use RefreshDatabase;

    protected function account(string $code): Account
    {
        return Account::where('code', $code)->firstOrFail();
    }

    protected function order(Customer $customer, float $total, string $placedAt, string $status = 'delivered', float $paid = 0): Order
    {
        $order = Order::create([
            'customer_id' => $customer->id,
            'customer_name' => $customer->name, 'customer_email' => 'buyer@example.com', 'customer_phone' => $customer->phone ?? '01700000000',
            'shipping_address' => 'Mirpur, Dhaka', 'subtotal' => $total, 'total' => $total, 'paid_amount' => $paid,
            'status' => $status, 'payment_method' => 'cod',
        ]);

        $order->forceFill(['created_at' => Carbon::parse($placedAt)])->save();

        return $order;
    }

    public function test_a_due_payment_clears_opening_due_first_then_orders_oldest_first(): void
    {
        $customer = Customer::create(['name' => 'Rahim', 'phone' => '01712345678', 'opening_due' => 500]);
        $new = $this->order($customer, 2000, '2026-08-01 10:00:00');
        $old = $this->order($customer, 1000, '2026-06-01 10:00:00');
        $pending = $this->order($customer, 700, '2026-05-01 10:00:00', 'pending');
        $this->order($customer, 900, '2026-05-02 10:00:00', 'cancelled');

        $admin = $this->staff();
        $cash = $this->account('cash');
        $bkash = $this->account('bkash');
        $url = route('admin.customers.payments.store', $customer);

        // 1,000: the 500 opening due first, then 500 into the oldest order.
        $this->actingAs($admin)->post($url, ['amount' => 1000, 'method' => 'cash', 'account_id' => $cash->id, 'reference' => 'RCPT-1'])
            ->assertSessionHas('success');

        $receipt = CustomerPayment::firstOrFail();
        $this->assertEquals(500, (float) $receipt->opening_due_paid);
        $this->assertSame(0.0, $customer->fresh()->openingDueRemaining());
        $this->assertEquals(500, (float) $old->fresh()->paid_amount);
        $this->assertSame('partially_paid', $old->fresh()->payment_status);
        $this->assertEquals(0, (float) $new->fresh()->paid_amount);
        $this->assertEquals([$old->id], $receipt->payments()->pluck('order_id')->all());

        $this->assertDatabaseHas('account_transactions', ['type' => 'customer_payment', 'reference_type' => CustomerPayment::class, 'reference_id' => $receipt->id, 'amount' => 500]);
        $this->assertDatabaseHas('account_transactions', ['type' => 'sale_payment', 'reference_id' => $old->id, 'amount' => 500]);
        $this->assertSame(1000.0, $cash->fresh()->balance());
        $this->assertDatabaseHas('activity_logs', ['action' => 'due_collected', 'subject_id' => $customer->id]);

        // 2,500 is still owed (500 + 2,000). More than that is refused and nothing is written.
        $this->actingAs($admin)->post($url, ['amount' => 2600, 'method' => 'cash', 'account_id' => $cash->id])->assertSessionHas('error');
        $this->assertSame(1, CustomerPayment::count());
        $this->assertSame(1000.0, $cash->fresh()->balance());

        $this->actingAs($admin)->post($url, ['amount' => 2500, 'method' => 'bkash', 'account_id' => $bkash->id])->assertSessionHas('success');

        $this->assertSame('paid', $old->fresh()->payment_status);
        $this->assertSame('paid', $new->fresh()->payment_status);
        $this->assertEquals(0, (float) $pending->fresh()->paid_amount);
        $this->assertSame(2500.0, $bkash->fresh()->balance());
        $this->assertEquals(0, (float) Customer::withTotals()->findOrFail($customer->id)->current_due);

        $this->actingAs($admin)->post($url, ['amount' => 1, 'method' => 'cash', 'account_id' => $cash->id])->assertSessionHas('error');

        $this->actingAs($admin)->get(route('admin.customers.show', $customer))->assertOk()
            ->assertSee($receipt->receipt_number)->assertSee('RCPT-1')->assertSee('Due collections')->assertDontSee('Collect payment');
        $this->actingAs($admin)->get(route('admin.accounts.show', $cash))->assertOk()->assertSee('Customer due payment');
    }

    public function test_collection_permissions_validation_and_opening_due_limits(): void
    {
        $customer = Customer::create(['name' => 'Karim', 'opening_due' => 1000]);
        $cash = $this->account('cash');
        $payload = ['amount' => 400, 'method' => 'cash', 'account_id' => $cash->id];
        $url = route('admin.customers.payments.store', $customer);

        $this->actingAs($this->staff('accountant'))->post($url, $payload)->assertForbidden();
        $this->actingAs($this->staff('warehouse_staff'))->post($url, $payload)->assertForbidden();

        $sales = $this->staff('sales_staff');
        $this->actingAs($sales)->get(route('admin.customers.show', $customer))->assertOk()->assertSee('Collect payment');
        $this->actingAs($sales)->post($url, ['amount' => 0] + $payload)->assertSessionHasErrors('amount');
        $this->actingAs($sales)->post($url, ['method' => 'online'] + $payload)->assertSessionHasErrors('method');
        $this->actingAs($sales)->post($url, $payload)->assertSessionHas('success');
        $this->assertSame(600.0, $customer->fresh()->openingDueRemaining());

        // The opening due cannot be lowered below what was already collected against it.
        $edit = ['name' => 'Karim', 'customer_group' => 'retail'];
        $this->actingAs($sales)->put(route('admin.customers.update', $customer), $edit + ['opening_due' => 300])->assertSessionHasErrors('opening_due');
        $this->actingAs($sales)->put(route('admin.customers.update', $customer), $edit + ['opening_due' => 400])->assertRedirect();
        $this->assertSame(0.0, $customer->fresh()->openingDueRemaining());

        // Archived customers cannot pay until restored.
        $customer->fresh()->forceFill(['opening_due' => 1000])->save();
        $customer->delete();
        $this->actingAs($sales)->post($url, $payload)->assertNotFound();
        $this->assertSame(1, CustomerPayment::count());
    }

    public function test_due_report_shows_who_owes_how_much_and_for_how_long(): void
    {
        $this->travelTo(Carbon::parse('2026-09-16 12:00:00'));

        $old = Customer::create(['name' => 'Old Debtor', 'phone' => '01711111111']);
        $this->order($old, 3000, '2026-05-01 10:00:00');
        $recent = Customer::create(['name' => 'Recent Debtor', 'phone' => '01722222222', 'customer_group' => 'wholesale']);
        $this->order($recent, 1500, '2026-09-10 10:00:00');
        Customer::create(['name' => 'Opening Only', 'opening_due' => 800]);
        $paidUp = Customer::create(['name' => 'Paid Up']);
        $this->order($paidUp, 1000, '2026-04-01 10:00:00', 'delivered', 1000);

        $admin = $this->staff();
        $this->actingAs($admin);

        $this->get(route('admin.customer-dues.index'))->assertOk()
            ->assertSee('Old Debtor')->assertSee('Recent Debtor')->assertSee('Opening Only')->assertDontSee('Paid Up')
            ->assertSee(Money::format(5300))->assertSee('138 days')->assertSee('Opening due only');

        $this->get(route('admin.customer-dues.index', ['age' => 90]))->assertOk()
            ->assertSee('Old Debtor')->assertDontSee('Recent Debtor')->assertDontSee('Opening Only');
        $this->get(route('admin.customer-dues.index', ['group' => 'wholesale']))->assertOk()
            ->assertSee('Recent Debtor')->assertDontSee('Old Debtor');
        $this->get(route('admin.customer-dues.index', ['q' => '0172']))->assertOk()
            ->assertSee('Recent Debtor')->assertDontSee('Old Debtor');
        $this->get(route('admin.customer-dues.index', ['sort' => 'oldest']))->assertOk();
        $this->get(route('admin.customer-dues.index', ['sort' => 'name']))->assertOk();

        $csv = $this->actingAs($this->staff('accountant'))->get(route('admin.customer-dues.export'))->assertOk()->streamedContent();
        $this->assertStringContainsString('Old Debtor', $csv);
        $this->assertStringContainsString('2026-05-01', $csv);
        $this->assertStringNotContainsString('Paid Up', $csv);

        $this->actingAs($this->staff('sales_staff'))->get(route('admin.customer-dues.index'))->assertOk();
        $this->actingAs($this->staff('sales_staff'))->get(route('admin.customer-dues.export'))->assertForbidden();
        $this->actingAs($this->staff('warehouse_staff'))->get(route('admin.customer-dues.index'))->assertForbidden();
    }
}
