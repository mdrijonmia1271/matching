<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Purchase;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_offer_edit_and_delete_to_staff_who_can_edit(): void
    {
        $customer = Customer::create(['name' => 'Lima', 'phone' => '01777777777', 'customer_group' => 'retail']);
        $supplier = Supplier::create(['name' => 'Karim Fabrics']);
        $admin = $this->staff();

        $this->actingAs($admin)->get(route('admin.customers.index'))->assertOk()
            ->assertSee(route('admin.customers.edit', $customer))
            ->assertSee(route('admin.customers.destroy', $customer));

        $this->actingAs($admin)->get(route('admin.suppliers.index'))->assertOk()
            ->assertSee(route('admin.suppliers.edit', $supplier))
            ->assertSee(route('admin.suppliers.destroy', $supplier));

        // Viewers without edit rights see neither.
        $this->actingAs($this->staff('accountant'))->get(route('admin.customers.index'))->assertOk()
            ->assertDontSee(route('admin.customers.edit', $customer));
    }

    public function test_a_customer_without_history_is_deleted_for_good(): void
    {
        $customer = Customer::create(['name' => 'Lima', 'phone' => '01777777777', 'customer_group' => 'retail']);

        $this->actingAs($this->staff())->delete(route('admin.customers.destroy', $customer))->assertSessionHas('success');

        $this->assertNull(Customer::withTrashed()->find($customer->id));
    }

    public function test_a_customer_with_orders_cannot_be_deleted(): void
    {
        $customer = Customer::create(['name' => 'Lima', 'phone' => '01777777777', 'customer_group' => 'retail']);
        Order::create([
            'customer_id' => $customer->id, 'customer_name' => 'Lima', 'customer_email' => 'lima@example.com',
            'customer_phone' => '01777777777', 'shipping_address' => 'Mirpur, Dhaka',
            'subtotal' => 1000, 'total' => 1000, 'status' => 'confirmed', 'payment_method' => 'cod',
        ]);

        $this->actingAs($this->staff())->delete(route('admin.customers.destroy', $customer))->assertSessionHas('error');

        $this->assertNotNull(Customer::find($customer->id));
    }

    public function test_a_supplier_without_history_is_deleted_but_one_with_purchases_is_kept(): void
    {
        $admin = $this->staff();
        $clean = Supplier::create(['name' => 'Karim Fabrics']);
        $busy = Supplier::create(['name' => 'Rahman & Sons']);
        Purchase::create(['supplier_id' => $busy->id, 'purchase_date' => today(), 'subtotal' => 0, 'total' => 0]);

        $this->actingAs($admin)->delete(route('admin.suppliers.destroy', $clean))->assertSessionHas('success');
        $this->assertNull(Supplier::withTrashed()->find($clean->id));

        $this->actingAs($admin)->delete(route('admin.suppliers.destroy', $busy))->assertSessionHas('error');
        $this->assertNotNull(Supplier::find($busy->id));
    }
}
