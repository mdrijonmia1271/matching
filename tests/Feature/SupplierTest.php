<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\SupplierController;
use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierTest extends TestCase
{
    use RefreshDatabase;

    protected function account(string $code): Account
    {
        return Account::where('code', $code)->firstOrFail();
    }

    public function test_staff_create_edit_and_archive_suppliers_with_permissions(): void
    {
        $this->actingAs($this->staff('sales_staff'))->get(route('admin.suppliers.index'))->assertForbidden();

        $accountant = $this->staff('accountant');
        $this->actingAs($accountant)->get(route('admin.suppliers.index'))->assertOk()->assertDontSee('Add supplier');
        $this->actingAs($accountant)->get(route('admin.suppliers.create'))->assertForbidden();
        $this->actingAs($accountant)->post(route('admin.suppliers.store'), ['name' => 'X'])->assertForbidden();

        $warehouse = $this->staff('warehouse_staff');
        $this->actingAs($warehouse)->get(route('admin.suppliers.create'))->assertOk();
        $this->actingAs($warehouse)->post(route('admin.suppliers.store'), [
            'name' => 'Abdur Rahman', 'company' => 'Rahman & Sons Textiles', 'phone' => '+880 1911-111111', 'opening_due' => 5000,
        ])->assertRedirect();

        $supplier = Supplier::where('phone', '01911111111')->firstOrFail();
        $this->assertEquals(5000, (float) $supplier->opening_due);
        $this->assertDatabaseHas('activity_logs', ['module' => 'purchases', 'action' => 'supplier_created', 'subject_id' => $supplier->id]);

        $this->actingAs($warehouse)->post(route('admin.suppliers.store'), ['name' => 'Copy', 'phone' => '01911111111'])
            ->assertSessionHasErrors('phone');
        $this->actingAs($accountant)->get(route('admin.suppliers.edit', $supplier))->assertForbidden();

        $this->actingAs($warehouse)->get(route('admin.suppliers.edit', $supplier))->assertOk()->assertSee('01911111111');
        $this->actingAs($warehouse)->put(route('admin.suppliers.update', $supplier), [
            'name' => 'Abdur Rahman', 'company' => 'Rahman & Sons Textiles', 'phone' => '01911111111', 'opening_due' => 4500,
        ])->assertRedirect(route('admin.suppliers.show', $supplier));
        $this->assertDatabaseHas('suppliers', ['id' => $supplier->id, 'opening_due' => 4500]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'supplier_updated', 'subject_id' => $supplier->id]);

        // Warehouse staff manage suppliers but cannot move money.
        $this->actingAs($warehouse)->get(route('admin.suppliers.show', $supplier))->assertOk()
            ->assertSee('Rahman & Sons Textiles')->assertSee(Money::format(4500))->assertDontSee('Pay supplier');

        $profile = route('admin.suppliers.show', $supplier);
        $this->actingAs($warehouse)->patch(route('admin.suppliers.archive', $supplier))->assertSessionHas('success');
        $this->assertSoftDeleted($supplier);
        $this->actingAs($warehouse)->get(route('admin.suppliers.index'))->assertDontSee($profile);
        $this->actingAs($warehouse)->get(route('admin.suppliers.index', ['status' => 'archived']))->assertSee($profile);
        $this->actingAs($warehouse)->get($profile)->assertOk()->assertSee('Restore');

        $this->actingAs($warehouse)->patch(route('admin.suppliers.restore', $supplier))->assertSessionHas('success');
        $this->assertNotSoftDeleted($supplier);
    }

    public function test_paying_a_supplier_takes_money_out_of_the_account_and_lowers_the_balance(): void
    {
        $supplier = Supplier::create(['name' => 'Abdur Rahman', 'opening_due' => 5000]);
        $cash = $this->account('cash');
        $cash->update(['opening_balance' => 3000]);
        $manager = $this->staff('manager');
        $url = route('admin.suppliers.payments.store', $supplier);
        $payload = ['method' => 'cash', 'account_id' => $cash->id];

        // Paying needs both purchases.edit and accounting.create.
        $this->actingAs($this->staff('accountant'))->post($url, ['amount' => 100] + $payload)->assertForbidden();
        $this->actingAs($this->staff('warehouse_staff'))->post($url, ['amount' => 100] + $payload)->assertForbidden();

        $this->actingAs($manager)->get(route('admin.suppliers.show', $supplier))->assertOk()->assertSee('Pay supplier');

        // More than is owed, or more than the account holds, is refused and nothing is written.
        $this->actingAs($manager)->post($url, ['amount' => 6000] + $payload)->assertSessionHas('error');
        $this->actingAs($manager)->post($url, ['amount' => 4000] + $payload)->assertSessionHas('error');
        $this->actingAs($manager)->post($url, ['amount' => 0] + $payload)->assertSessionHasErrors('amount');
        $this->actingAs($manager)->post($url, ['amount' => 10, 'method' => 'cod', 'account_id' => $cash->id])->assertSessionHasErrors('method');
        $this->assertSame(0, SupplierPayment::count());
        $this->assertSame(0, AccountTransaction::count());

        $this->actingAs($manager)->post($url, ['amount' => 2000, 'reference' => 'CHQ-778', 'note' => 'September stock'] + $payload)
            ->assertSessionHas('success');

        $payment = SupplierPayment::firstOrFail();
        $this->assertSame(3000.0, $supplier->fresh()->balance());
        $this->assertSame(1000.0, $cash->fresh()->balance());
        $this->assertDatabaseHas('account_transactions', [
            'account_id' => $cash->id, 'direction' => 'out', 'type' => 'supplier_payment',
            'reference_type' => SupplierPayment::class, 'reference_id' => $payment->id, 'amount' => 2000,
        ]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'supplier_paid', 'subject_id' => $supplier->id]);

        $this->actingAs($manager)->get(route('admin.suppliers.show', $supplier))->assertOk()
            ->assertSee($payment->receipt_number)->assertSee('CHQ-778')->assertSee('September stock')->assertSee(Money::format(3000));
        $this->actingAs($manager)->get(route('admin.accounts.show', $cash))->assertOk()
            ->assertSee('Supplier payment')->assertSee($payment->receipt_number);

        // The opening due cannot drop below what was already paid.
        $this->actingAs($manager)->put(route('admin.suppliers.update', $supplier), ['name' => 'Abdur Rahman', 'opening_due' => 1500])
            ->assertSessionHasErrors('opening_due');

        // Inactive accounts and archived suppliers cannot be used.
        $bank = $this->account('bank');
        $bank->update(['opening_balance' => 10000, 'is_active' => false]);
        $this->actingAs($manager)->post($url, ['amount' => 100, 'method' => 'bank', 'account_id' => $bank->id])->assertSessionHasErrors('account_id');

        $supplier->delete();
        $this->actingAs($manager)->post($url, ['amount' => 100] + $payload)->assertNotFound();
        $this->assertSame(1, SupplierPayment::count());
    }

    public function test_supplier_list_search_balance_filter_and_export(): void
    {
        $owed = Supplier::create(['name' => 'Abdur Rahman', 'company' => 'Rahman & Sons', 'phone' => '01911111111', 'opening_due' => 5000]);
        Supplier::create(['name' => 'Karim Fabrics', 'phone' => '01822222222']);

        $this->actingAs($this->staff());

        $this->get(route('admin.suppliers.index'))->assertOk()
            ->assertSee('Abdur Rahman')->assertSee('Karim Fabrics')->assertSee(Money::format(5000));
        $this->get(route('admin.suppliers.index', ['q' => 'sons']))->assertOk()->assertSee('Abdur Rahman')->assertDontSee('Karim Fabrics');
        $this->get(route('admin.suppliers.index', ['q' => '+880 1822-222222']))->assertOk()->assertSee('Karim Fabrics')->assertDontSee('Abdur Rahman');
        $this->get(route('admin.suppliers.index', ['balance' => 'owed']))->assertOk()->assertSee('Abdur Rahman')->assertDontSee('Karim Fabrics');

        foreach (array_keys(SupplierController::SORTS) as $sort) {
            $this->get(route('admin.suppliers.index', ['sort' => $sort]))->assertOk();
        }

        $csv = $this->get(route('admin.suppliers.export', ['balance' => 'owed']))->assertOk()->streamedContent();
        $this->assertStringContainsString('Abdur Rahman', $csv);
        $this->assertStringContainsString('5000', $csv);
        $this->assertStringNotContainsString('Karim Fabrics', $csv);

        $this->actingAs($this->staff('warehouse_staff'))->get(route('admin.suppliers.export'))->assertForbidden();
        $this->actingAs($this->staff('warehouse_staff'))->get(route('admin.dashboard'))->assertOk()->assertSee(route('admin.suppliers.index'));
        $this->assertSame($owed->id, Supplier::owed()->value('id'));
    }
}
