<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Services\CartService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use LogicException;
use Tests\TestCase;

class FinanceTest extends TestCase
{
    use RefreshDatabase;

    protected function account(string $code): Account
    {
        return Account::where('code', $code)->firstOrFail();
    }

    protected function order(float $total = 5000, string $status = 'confirmed'): Order
    {
        return Order::create([
            'customer_name' => 'Lima', 'customer_email' => 'lima@example.com', 'customer_phone' => '01777777777',
            'shipping_address' => 'Mirpur, Dhaka', 'subtotal' => $total, 'total' => $total,
            'status' => $status, 'payment_method' => 'cod',
        ]);
    }

    public function test_default_accounts_exist_and_balances_follow_the_ledger(): void
    {
        $this->assertSame(['cash', 'bank', 'bkash', 'nagad'], Account::orderBy('sort_order')->pluck('code')->all());

        $admin = $this->staff();
        $cash = $this->account('cash');
        $bank = $this->account('bank');
        $cash->update(['opening_balance' => 1000]);

        $this->actingAs($admin)->post(route('admin.accounts.entries.store'), ['account_id' => $cash->id, 'direction' => 'in', 'amount' => 500, 'note' => 'Owner added cash'])
            ->assertSessionHas('success');
        $this->actingAs($admin)->post(route('admin.accounts.entries.store'), ['account_id' => $cash->id, 'direction' => 'out', 'amount' => 5000, 'note' => 'More than we have'])
            ->assertSessionHas('error');
        $this->actingAs($admin)->post(route('admin.accounts.entries.store'), ['account_id' => $cash->id, 'direction' => 'out', 'amount' => 10])
            ->assertSessionHasErrors('note');
        $this->actingAs($admin)->post(route('admin.accounts.transfers.store'), ['from_account_id' => $cash->id, 'to_account_id' => $bank->id, 'amount' => 1200, 'note' => 'Deposited at bank'])
            ->assertSessionHas('success');
        $this->actingAs($admin)->post(route('admin.accounts.transfers.store'), ['from_account_id' => $cash->id, 'to_account_id' => $cash->id, 'amount' => 10])
            ->assertSessionHasErrors('to_account_id');

        $this->assertSame(300.0, $cash->fresh()->balance());
        $this->assertSame(1200.0, $bank->fresh()->balance());
        $this->assertSame(2, AccountTransaction::whereNotNull('transfer_group')->count());

        $this->actingAs($admin)->get(route('admin.accounts.index'))->assertOk()->assertSee(Money::format(1500));
        $this->actingAs($admin)->get(route('admin.accounts.show', $cash))->assertOk()->assertSee('Owner added cash')->assertSee('Transfer out');

        $csv = $this->actingAs($admin)->get(route('admin.accounts.export', $cash))->assertOk()->streamedContent();
        $this->assertStringContainsString('Owner added cash', $csv);

        $this->expectException(LogicException::class);
        AccountTransaction::firstOrFail()->update(['amount' => 1]);
    }

    public function test_payments_can_be_partial_never_exceed_the_due_and_post_to_the_account(): void
    {
        $order = $this->order(5000);
        $accountant = $this->staff('accountant');
        $bkash = $this->account('bkash');
        $cash = $this->account('cash');
        $url = route('admin.orders.payments.store', $order);

        $this->actingAs($accountant)->post($url, ['amount' => 2000, 'method' => 'bkash', 'account_id' => $bkash->id, 'reference' => 'TRX123ABC'])
            ->assertSessionHas('success');

        $order->refresh();
        $this->assertEquals(2000, (float) $order->paid_amount);
        $this->assertSame('partially_paid', $order->payment_status);
        $this->assertSame(3000.0, $order->due_amount);

        $this->actingAs($accountant)->post($url, ['amount' => 3500, 'method' => 'cash', 'account_id' => $cash->id])->assertSessionHas('error');
        $this->actingAs($accountant)->post($url, ['amount' => 0, 'method' => 'cash', 'account_id' => $cash->id])->assertSessionHasErrors('amount');
        $this->actingAs($accountant)->post($url, ['amount' => -50, 'method' => 'cash', 'account_id' => $cash->id])->assertSessionHasErrors('amount');
        $this->actingAs($accountant)->post($url, ['amount' => 100, 'method' => 'online', 'account_id' => $cash->id])->assertSessionHasErrors('method');

        $this->actingAs($accountant)->post($url, ['amount' => 3000, 'method' => 'cash', 'account_id' => $cash->id, 'note' => 'Courier remittance'])
            ->assertSessionHas('success');

        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertNotNull($order->paid_at);
        $this->assertSame(0.0, $order->due_amount);
        $this->assertSame(2000.0, $bkash->balance());
        $this->assertSame(3000.0, $cash->balance());
        $this->assertDatabaseHas('account_transactions', ['type' => 'sale_payment', 'reference_id' => $order->id, 'amount' => 2000]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'payment_recorded', 'subject_id' => $order->id]);

        // Fully paid: nothing more can be taken.
        $this->actingAs($accountant)->post($url, ['amount' => 1, 'method' => 'cash', 'account_id' => $cash->id])->assertSessionHas('error');
        $this->assertSame(2, AccountTransaction::count());

        $this->actingAs($accountant)->get(route('admin.orders.show', $order))->assertOk()
            ->assertSee('TRX123ABC')->assertSee('Courier remittance')->assertDontSee('Record a payment');
    }

    public function test_payment_permissions_and_cancelled_orders(): void
    {
        $order = $this->order(1000);
        $payload = ['amount' => 500, 'method' => 'cash', 'account_id' => $this->account('cash')->id];

        $this->actingAs($this->staff('warehouse_staff'))->post(route('admin.orders.payments.store', $order), $payload)->assertForbidden();
        $this->actingAs($this->staff('sales_staff'))->post(route('admin.orders.payments.store', $order), $payload)->assertSessionHas('success');

        $cancelled = $this->order(1000, 'cancelled');
        $this->actingAs($this->staff())->post(route('admin.orders.payments.store', $cancelled), $payload)->assertSessionHas('error');
        $this->assertEquals(0, (float) $cancelled->fresh()->paid_amount);

        $this->actingAs($this->staff('sales_staff'))->get(route('admin.accounts.index'))->assertForbidden();

        $accountant = $this->staff('accountant');
        $this->actingAs($accountant)->get(route('admin.accounts.index'))->assertOk();
        $this->actingAs($accountant)->post(route('admin.accounts.store'), ['name' => 'City Bank', 'type' => 'bank', 'opening_balance' => 2500])
            ->assertSessionHas('success');
        $this->assertDatabaseHas('accounts', ['name' => 'City Bank', 'code' => 'city_bank']);
        $this->assertSame(2500.0, Account::where('code', 'city_bank')->firstOrFail()->balance());

        // The account that receives online payments cannot be switched off.
        $bank = $this->account('bank');
        $this->actingAs($accountant)->put(route('admin.accounts.update', $bank), ['name' => 'Bank', 'type' => 'bank', 'opening_balance' => 0])
            ->assertSessionHas('error');
        $this->assertTrue($bank->fresh()->is_active);
    }

    public function test_online_gateway_payments_are_posted_once_to_the_online_account(): void
    {
        Mail::fake();
        $this->withCookie(CartService::TOKEN_COOKIE, str_repeat('f', 40));

        $category = Category::create(['name' => 'Abaya', 'is_active' => true]);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Black Abaya', 'price' => 4000, 'stock' => 5, 'is_active' => true]);

        $this->post(route('cart.store', $product));
        $this->post(route('checkout.store'), [
            'customer_name' => 'Nabila', 'customer_email' => 'nabila@example.com', 'customer_phone' => '01788888888',
            'shipping_address' => 'Gulshan, Dhaka', 'payment_method' => 'online',
        ]);

        $order = Order::firstOrFail();
        $payment = $order->payments()->firstOrFail();
        $url = URL::temporarySignedRoute('payment.demo.success', now()->addMinutes(30), [$order, $payment]);

        $this->post($url)->assertRedirect();
        $this->post($url)->assertRedirect();

        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('processing', $order->status);
        $this->assertEquals((float) $order->total, (float) $order->paid_amount);
        $this->assertSame(1, AccountTransaction::count());
        $this->assertSame((float) $order->total, $this->account('bank')->balance());
        $this->assertSame('online', $payment->fresh()->method);
    }
}
