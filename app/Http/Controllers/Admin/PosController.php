<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Customer;
use App\Services\AccountService;
use App\Services\PaymentService;
use App\Services\PosService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use RuntimeException;

/** The shop counter: scan, take the money, hand over the goods. */
class PosController extends Controller implements HasMiddleware
{
    public function __construct(protected PosService $pos) {}

    public static function middleware(): array
    {
        return [new Middleware('can:pos.sell')];
    }

    public function index(AccountService $accounts)
    {
        $methods = PaymentService::manualMethods();

        return view('admin.pos.index', [
            'methods' => $methods,
            'accounts' => Account::active()->orderBy('sort_order')->get(['id', 'name', 'code']),
            'balances' => $accounts->balances(),
            'methodAccounts' => collect($methods)->mapWithKeys(fn ($label, $method) => [$method => PaymentService::defaultAccountFor($method)?->id])->all(),
        ]);
    }

    /** Customer lookup for the till: name, phone or email. */
    public function customers(Request $request): JsonResponse
    {
        $term = trim((string) $request->input('q'));

        if (mb_strlen($term) < 2) {
            return response()->json(['results' => []]);
        }

        // withTotals gives each match their current due, so the till can warn about an outstanding balance.
        $results = Customer::withTotals()->search($term)->orderBy('name')->limit(15)->get()
            ->map(fn (Customer $customer) => [
                'id' => $customer->id,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'group' => $customer->group_label,
                'due' => round((float) $customer->current_due, 2),
            ]);

        return response()->json(['results' => $results]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.variant_id' => ['required', 'integer', Rule::exists('product_variants', 'id')->whereNull('deleted_at')],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'discount' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'note' => ['nullable', 'string', 'max:500'],
            'customer_id' => ['nullable', Rule::exists('customers', 'id')->whereNull('deleted_at')],
            'customer_name' => ['nullable', 'string', 'max:120'],
            'customer_phone' => ['nullable', 'string', 'max:20'],
            'payments' => ['nullable', 'array', 'max:5'],
            'payments.*.amount' => ['required', 'numeric', 'min:0', 'max:100000000'],
            'payments.*.method' => ['required', Rule::in(array_keys(PaymentService::manualMethods()))],
            'payments.*.account_id' => ['required', Rule::exists('accounts', 'id')->where('is_active', true)],
        ], [
            'items.required' => 'Scan or search for at least one product to sell.',
            'customer_id.exists' => 'That customer no longer exists. Search for them again.',
            'payments.*.account_id.exists' => 'Choose an active account for each payment.',
        ]);

        try {
            $order = $this->pos->sell($data);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $due = $order->due_amount;

        return redirect()->route('admin.orders.invoice', $order)->with('success', sprintf('Sale %s recorded — %s%s.',
            $order->order_number,
            Money::format((float) $order->total),
            $due > 0 ? ', ' . Money::format($due) . ' left on account' : ' paid in full'));
    }
}
