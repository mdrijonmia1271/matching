<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Services\AccountService;
use App\Services\AuditLogger;
use App\Services\PaymentService;
use App\Services\SupplierService;
use App\Support\CsvExport;
use App\Support\Money;
use App\Support\Phone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

class SupplierController extends Controller implements HasMiddleware
{
    public const SORTS = [
        'recent' => 'Newest first',
        'name' => 'Name',
        'balance' => 'Highest balance',
        'last_payment' => 'Last payment',
    ];

    public static function middleware(): array
    {
        return [
            new Middleware('can:purchases.view', only: ['index', 'show', 'export']),
            new Middleware('can:reports.export', only: ['export']),
            new Middleware('can:purchases.create', only: ['create', 'store']),
            new Middleware('can:purchases.edit', only: ['edit', 'update', 'destroy', 'restore']),
        ];
    }

    public function index(Request $request)
    {
        return view('admin.suppliers.index', [
            'suppliers' => $this->query($request)->paginate(20)->withQueryString(),
            'sorts' => self::SORTS,
            'sort' => $this->sort($request),
            'stats' => [
                'suppliers' => Supplier::count(),
                'owed' => Supplier::owed()->count(),
                'payable' => round((float) Supplier::sum('opening_due')
                    + (float) Purchase::received()->whereIn('supplier_id', Supplier::select('id'))->sum('total')
                    - (float) SupplierPayment::whereIn('supplier_id', Supplier::select('id'))->sum('amount'), 2),
            ],
        ]);
    }

    public function export(Request $request)
    {
        $rows = (function () use ($request) {
            foreach ($this->query($request)->cursor() as $supplier) {
                yield [
                    $supplier->name,
                    (string) $supplier->company,
                    (string) $supplier->phone,
                    (string) $supplier->email,
                    (float) $supplier->opening_due,
                    (float) $supplier->purchases_total,
                    (float) $supplier->total_paid,
                    (float) $supplier->current_balance,
                    $supplier->last_payment_at?->format('Y-m-d') ?? '',
                ];
            }
        })();

        return CsvExport::download('suppliers-' . now()->format('Y-m-d-His') . '.csv',
            ['Supplier', 'Company', 'Phone', 'Email', 'Opening due', 'Purchased', 'Total paid', 'Balance owed', 'Last payment'], $rows);
    }

    public function create()
    {
        return view('admin.suppliers.form', ['supplier' => new Supplier]);
    }

    public function store(Request $request)
    {
        $supplier = Supplier::create($this->validated($request));

        AuditLogger::log('purchases', 'supplier_created', $supplier, 'Supplier ' . $supplier->name . ' created',
            new: array_filter($supplier->only(['name', 'company', 'phone', 'opening_due'])));

        return redirect()->route('admin.suppliers.show', $supplier)->with('success', 'Supplier ' . $supplier->name . ' added.');
    }

    public function show(Supplier $supplier, AccountService $accounts)
    {
        $supplier = Supplier::withTrashed()->withTotals()->findOrFail($supplier->id);
        $methods = SupplierService::methods();

        return view('admin.suppliers.show', [
            'supplier' => $supplier,
            'payments' => $supplier->payments()
                ->with(['account:id,name', 'payer:id,name'])
                ->orderByDesc('paid_at')
                ->orderByDesc('id')
                ->paginate(15)
                ->withQueryString(),
            'purchases' => $supplier->purchases()
                ->orderByDesc('purchase_date')
                ->orderByDesc('id')
                ->limit(10)
                ->get(),
            'methods' => $methods,
            'accounts' => Account::active()->orderBy('sort_order')->get(['id', 'name', 'code']),
            'balances' => $accounts->balances(),
            'methodAccounts' => collect($methods)->mapWithKeys(fn ($label, $method) => [$method => PaymentService::defaultAccountFor($method)?->id])->all(),
        ]);
    }

    public function edit(Supplier $supplier)
    {
        return view('admin.suppliers.form', ['supplier' => $supplier]);
    }

    public function update(Request $request, Supplier $supplier)
    {
        $data = $this->validated($request, $supplier);
        $paid = $supplier->totalPaid();

        if ($data['opening_due'] < $paid) {
            return back()->withInput()->withErrors([
                'opening_due' => 'The opening due cannot be less than the ' . Money::format($paid) . ' already paid to this supplier.',
            ]);
        }

        $supplier->fill($data);
        [$old, $new] = AuditLogger::changes($supplier);
        $supplier->save();

        if ($new) {
            AuditLogger::log('purchases', 'supplier_updated', $supplier, 'Supplier ' . $supplier->name . ' updated', $old, $new);
        }

        return redirect()->route('admin.suppliers.show', $supplier)->with('success', $new ? 'Supplier updated.' : 'Nothing changed.');
    }

    /** Suppliers are archived, never deleted: their payments keep pointing at them. */
    public function destroy(Supplier $supplier)
    {
        $supplier->delete();

        AuditLogger::log('purchases', 'supplier_archived', $supplier, 'Supplier ' . $supplier->name . ' archived');

        return redirect()->route('admin.suppliers.index')->with('success', $supplier->name . ' archived. Their payment history is kept.');
    }

    public function restore(Supplier $supplier)
    {
        $supplier->restore();

        AuditLogger::log('purchases', 'supplier_restored', $supplier, 'Supplier ' . $supplier->name . ' restored');

        return back()->with('success', $supplier->name . ' restored.');
    }

    protected function query(Request $request): Builder
    {
        $query = Supplier::withTotals()
            ->when($request->input('status') === 'archived', fn ($q) => $q->onlyTrashed())
            ->search($request->input('q'))
            ->when($request->input('balance') === 'owed', fn ($q) => $q->owed());

        match ($this->sort($request)) {
            'name' => $query->orderBy('suppliers.name'),
            'balance' => $query->orderByDesc('current_balance'),
            'last_payment' => $query->orderByDesc('last_payment_at'),
            default => null,
        };

        return $query->orderByDesc('suppliers.id');
    }

    protected function sort(Request $request): string
    {
        return array_key_exists((string) $request->input('sort'), self::SORTS) ? (string) $request->input('sort') : 'recent';
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request, ?Supplier $supplier = null): array
    {
        // Compare phones in the stored shape, so +880 1911-111111 clashes with 01911111111.
        $request->merge(['phone' => Phone::normalise($request->input('phone'))]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'company' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:20', Rule::unique('suppliers', 'phone')->ignore($supplier?->id)],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:500'],
            'opening_due' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'phone.unique' => 'Another supplier already has this phone number.',
        ]);

        $data['opening_due'] = round((float) ($data['opening_due'] ?? 0), 2);

        return $data;
    }
}
