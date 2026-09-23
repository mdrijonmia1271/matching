<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\Payment;
use App\Services\AuditLogger;
use App\Services\PaymentService;
use App\Support\Money;
use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CustomerController extends Controller implements HasMiddleware
{
    public const SORTS = [
        'recent' => 'Newest first',
        'name' => 'Name',
        'last_order' => 'Last order',
        'spent' => 'Total spent',
        'due' => 'Highest due',
    ];

    public static function middleware(): array
    {
        return [
            new Middleware('can:customers.view', only: ['index', 'show']),
            new Middleware('can:customers.create', only: ['create', 'store']),
            new Middleware('can:customers.edit', only: ['edit', 'update', 'destroy', 'archive', 'restore']),
        ];
    }

    public function index(Request $request)
    {
        $term = trim((string) $request->input('q'));
        $sort = array_key_exists((string) $request->input('sort'), self::SORTS) ? $request->input('sort') : 'recent';

        $query = Customer::withTotals()
            ->when($request->input('status') === 'archived', fn ($q) => $q->onlyTrashed())
            ->search($term)
            ->when(array_key_exists((string) $request->input('group'), Customer::GROUPS), fn ($q) => $q->where('customers.customer_group', $request->input('group')))
            ->when($request->input('due') === 'with', fn ($q) => $q->withDue());

        match ($sort) {
            'name' => $query->orderBy('customers.name'),
            'last_order' => $query->orderByDesc('last_order_at'),
            'spent' => $query->orderByDesc('total_spent'),
            'due' => $query->orderByDesc('current_due'),
            default => null,
        };

        $orderDue = Order::whereIn('status', Order::SALE_STATUSES)
            ->whereColumn('total', '>', 'paid_amount')
            ->whereHas('customer')
            ->sum(DB::raw('total - paid_amount'));

        return view('admin.customers.index', [
            'customers' => $query->orderByDesc('customers.id')->paginate(20)->withQueryString(),
            'sorts' => self::SORTS,
            'sort' => $sort,
            'stats' => [
                'customers' => Customer::count(),
                'with_due' => Customer::withDue()->count(),
                'total_due' => round((float) Customer::sum('opening_due')
                    - (float) CustomerPayment::whereIn('customer_id', Customer::select('id'))->sum('opening_due_paid')
                    + (float) $orderDue, 2),
            ],
        ]);
    }

    public function create()
    {
        return view('admin.customers.form', ['customer' => new Customer(['customer_group' => 'retail'])]);
    }

    public function store(Request $request)
    {
        $customer = Customer::create($this->validated($request));

        AuditLogger::log('customers', 'created', $customer, 'Customer ' . $customer->name . ' created',
            new: array_filter($customer->only(['name', 'phone', 'email', 'customer_group', 'opening_due'])));

        return redirect()->route('admin.customers.show', $customer)->with('success', 'Customer ' . $customer->name . ' added.');
    }

    public function show(Customer $customer)
    {
        $customer = Customer::withTrashed()->withTotals()->with('user:id,name,email')->findOrFail($customer->id);
        $methods = PaymentService::manualMethods();

        return view('admin.customers.show', [
            'customer' => $customer,
            'outstanding' => $customer->unpaidOrders()->get(),
            'collections' => $customer->customerPayments()
                ->with(['payments.order:id,order_number', 'account:id,name', 'receiver:id,name'])
                ->orderByDesc('paid_at')
                ->orderByDesc('id')
                ->paginate(10, pageName: 'collections_page')
                ->withQueryString(),
            'methods' => $methods,
            'accounts' => Account::active()->orderBy('sort_order')->get(['id', 'name', 'code']),
            'methodAccounts' => collect($methods)->mapWithKeys(fn ($label, $method) => [$method => PaymentService::defaultAccountFor($method)?->id])->all(),
            'orders' => $customer->orders()->withCount('items')->latest()->paginate(15, pageName: 'orders_page')->withQueryString(),
            'returns' => OrderReturn::where('customer_id', $customer->id)
                ->with(['order:id,order_number', 'items'])
                ->orderByDesc('id')
                ->limit(10)
                ->get(),
            'payments' => Payment::with(['order:id,order_number', 'account:id,name', 'receiver:id,name'])
                ->whereHas('order', fn ($q) => $q->where('customer_id', $customer->id))
                ->orderByDesc('id')
                ->paginate(15, pageName: 'payments_page')
                ->withQueryString(),
        ]);
    }

    public function edit(Customer $customer)
    {
        return view('admin.customers.form', ['customer' => $customer]);
    }

    public function update(Request $request, Customer $customer)
    {
        $data = $this->validated($request, $customer);
        $collected = round((float) $customer->customerPayments()->sum('opening_due_paid'), 2);

        if ($data['opening_due'] < $collected) {
            return back()->withInput()->withErrors([
                'opening_due' => 'The opening due cannot be less than the ' . Money::format($collected) . ' already collected against it.',
            ]);
        }

        $customer->fill($data);
        [$old, $new] = AuditLogger::changes($customer);
        $customer->save();

        if ($new) {
            AuditLogger::log('customers', 'updated', $customer, 'Customer ' . $customer->name . ' updated', $old, $new);
        }

        return redirect()->route('admin.customers.show', $customer)->with('success', $new ? 'Customer updated.' : 'Nothing changed.');
    }

    /**
     * Deletes the customer for good. Refused while any order, payment or return
     * points at them, because those records would lose who they belong to.
     */
    public function destroy(Customer $customer)
    {
        $blockers = array_filter([
            $customer->orders()->exists() ? 'orders' : null,
            $customer->customerPayments()->exists() ? 'payments' : null,
            OrderReturn::where('customer_id', $customer->id)->exists() ? 'returns' : null,
        ]);

        if ($blockers) {
            return back()->with('error', $customer->name . ' cannot be deleted: they have ' . implode(', ', $blockers) . ' on record.');
        }

        $customer->forceDelete();

        AuditLogger::log('customers', 'deleted', null, 'Customer ' . $customer->name . ($customer->phone ? ' (' . $customer->phone . ')' : '') . ' deleted permanently');

        return redirect()->route('admin.customers.index')->with('success', $customer->name . ' deleted permanently.');
    }

    /** Hides a customer who has history and so cannot be deleted; their orders and payments are kept. */
    public function archive(Customer $customer)
    {
        $customer->delete();

        AuditLogger::log('customers', 'archived', $customer, 'Customer ' . $customer->name . ' archived');

        return redirect()->route('admin.customers.index')->with('success', $customer->name . ' archived. Their orders and payments are kept.');
    }

    public function restore(Customer $customer)
    {
        $customer->restore();

        AuditLogger::log('customers', 'restored', $customer, 'Customer ' . $customer->name . ' restored');

        return back()->with('success', $customer->name . ' restored.');
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request, ?Customer $customer = null): array
    {
        // Compare phones in the stored shape, so +880 1712-345678 clashes with 01712345678.
        $request->merge(['phone' => Phone::normalise($request->input('phone'))]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:20', Rule::unique('customers', 'phone')->ignore($customer?->id)],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:100'],
            'customer_group' => ['required', Rule::in(array_keys(Customer::GROUPS))],
            'opening_due' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'phone.unique' => 'Another customer already has this phone number. Search for them instead of adding a duplicate.',
        ]);

        $data['opening_due'] = round((float) ($data['opening_due'] ?? 0), 2);

        return $data;
    }
}
