<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Purchase;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Services\AccountService;
use App\Services\PaymentService;
use App\Services\PurchaseService;
use App\Services\SupplierService;
use App\Support\CsvExport;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use RuntimeException;

/** Stock bought from suppliers: written as a draft, then received into stock. */
class PurchaseController extends Controller implements HasMiddleware
{
    public const SORTS = [
        'recent' => 'Newest first',
        'date' => 'Purchase date',
        'total' => 'Highest total',
        'supplier' => 'Supplier',
    ];

    public function __construct(protected PurchaseService $purchases) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:purchases.view', only: ['index', 'show', 'export']),
            new Middleware('can:reports.export', only: ['export']),
            new Middleware('can:purchases.create', only: ['create', 'store']),
            new Middleware('can:purchases.edit', only: ['edit', 'update', 'receive', 'cancel']),
        ];
    }

    public function index(Request $request)
    {
        $totals = (clone $this->query($request))->reorder()
            ->selectRaw('COUNT(*) AS purchases_count, COALESCE(SUM(purchases.total), 0) AS total')
            ->first();

        return view('admin.purchases.index', [
            'purchases' => $this->query($request)->with('supplier:id,name,company')->paginate(20)->withQueryString(),
            'suppliers' => Supplier::orderBy('name')->get(['id', 'name']),
            'statuses' => Purchase::STATUS_LABELS,
            'sorts' => self::SORTS,
            'sort' => $this->sort($request),
            'stats' => [
                'purchases' => (int) $totals->purchases_count,
                'total' => (float) $totals->total,
                'open' => Purchase::whereIn('status', Purchase::OPEN_STATUSES)->count(),
            ],
        ]);
    }

    public function export(Request $request)
    {
        $rows = (function () use ($request) {
            foreach ($this->query($request)->with('supplier:id,name')->cursor() as $purchase) {
                yield [
                    $purchase->number,
                    $purchase->purchase_date?->format('Y-m-d'),
                    $purchase->supplier?->name ?? '',
                    $purchase->status_label,
                    (string) $purchase->invoice_number,
                    (float) $purchase->subtotal,
                    (float) $purchase->discount,
                    (float) $purchase->additional_cost,
                    (float) $purchase->total,
                    $purchase->received_at?->format('Y-m-d') ?? '',
                ];
            }
        })();

        return CsvExport::download('purchases-' . now()->format('Y-m-d-His') . '.csv',
            ['Purchase', 'Date', 'Supplier', 'Status', 'Supplier invoice', 'Subtotal', 'Discount', 'Additional cost', 'Total', 'Received'],
            $rows);
    }

    public function create(Request $request)
    {
        return view('admin.purchases.form', $this->formData(new Purchase([
            'purchase_date' => now()->toDateString(),
            'supplier_id' => $request->integer('supplier') ?: null,
            'status' => 'draft',
        ])));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        try {
            $purchase = $this->purchases->save($data);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.purchases.show', $purchase)
            ->with('success', 'Purchase ' . $purchase->number . ' saved. Receive it when the goods arrive.');
    }

    public function show(Purchase $purchase, AccountService $accounts)
    {
        $purchase->load(['items.variant.product', 'supplier', 'creator:id,name', 'receiver:id,name']);
        $methods = SupplierService::methods();
        $balance = $purchase->supplier->balance();

        return view('admin.purchases.show', [
            'purchase' => $purchase,
            'balance' => $balance,
            'methods' => $methods,
            'accounts' => Account::active()->orderBy('sort_order')->get(['id', 'name', 'code']),
            'balances' => $accounts->balances(),
            'methodAccounts' => collect($methods)->mapWithKeys(fn ($label, $method) => [$method => PaymentService::defaultAccountFor($method)?->id])->all(),
            'movements' => $purchase->isReceived()
                ? $purchase->stockMovements()->with('variant.product')->get()
                : collect(),
        ]);
    }

    public function edit(Purchase $purchase)
    {
        if (! $purchase->isOpen()) {
            return redirect()->route('admin.purchases.show', $purchase)
                ->with('error', 'Purchase ' . $purchase->number . ' is ' . strtolower($purchase->status_label) . ' and can no longer be edited.');
        }

        return view('admin.purchases.form', $this->formData($purchase->load('items.variant.product')));
    }

    public function update(Request $request, Purchase $purchase)
    {
        $data = $this->validated($request);

        try {
            $this->purchases->save($data, $purchase);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.purchases.show', $purchase)->with('success', 'Purchase ' . $purchase->number . ' updated.');
    }

    /** Stock the goods in, bill the supplier, and optionally pay them straight away. */
    public function receive(Request $request, Purchase $purchase)
    {
        $data = $request->validate([
            'pay_now' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'method' => ['nullable', Rule::in(array_keys(SupplierService::methods()))],
            'account_id' => ['nullable', Rule::exists('accounts', 'id')->where('is_active', true)],
        ], [
            'account_id.exists' => 'Choose an active account to pay from.',
        ]);

        $payNow = round((float) ($data['pay_now'] ?? 0), 2);

        // Paying while receiving is money leaving an account, so it needs the accounting permission too.
        if ($payNow > 0 && ! $request->user()->can('accounting.create')) {
            return back()->with('error', 'You can receive goods, but not pay the supplier. Leave "pay now" empty.');
        }

        try {
            $received = $this->purchases->receive(
                $purchase,
                $payNow ?: null,
                $payNow > 0 && ! empty($data['account_id']) ? Account::find($data['account_id']) : null,
                $data['method'] ?? null,
            );
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', sprintf('Purchase %s received. %s added to stock and %s billed to %s.',
            $received->number, number_format($received->items->sum('quantity')) . ' units',
            Money::format($received->total), $received->supplier->name));
    }

    public function cancel(Request $request, Purchase $purchase)
    {
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:200']])['reason'] ?? null;

        try {
            $this->purchases->cancel($purchase, $reason);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Purchase ' . $purchase->number . ' cancelled.');
    }

    protected function query(Request $request): Builder
    {
        $query = Purchase::query()
            ->search($request->input('q'))
            ->when($request->filled('status'), fn ($q) => $q->where('purchases.status', $request->input('status')))
            ->when($request->filled('supplier'), fn ($q) => $q->where('purchases.supplier_id', $request->integer('supplier')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('purchases.purchase_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('purchases.purchase_date', '<=', $request->date('to')));

        match ($this->sort($request)) {
            'date' => $query->orderByDesc('purchases.purchase_date'),
            'total' => $query->orderByDesc('purchases.total'),
            'supplier' => $query->orderBy(Supplier::select('name')->whereColumn('suppliers.id', 'purchases.supplier_id')),
            default => null,
        };

        return $query->orderByDesc('purchases.id');
    }

    protected function sort(Request $request): string
    {
        return array_key_exists((string) $request->input('sort'), self::SORTS) ? (string) $request->input('sort') : 'recent';
    }

    /** @return array<string, mixed> */
    protected function formData(Purchase $purchase): array
    {
        // After a failed save the typed lines come back from old input; otherwise the saved ones are shown.
        $lines = collect(old('items'))
            ->map(fn ($row) => [
                'variant_id' => (int) ($row['variant_id'] ?? 0),
                'quantity' => max(1, (int) ($row['quantity'] ?? 1)),
                'unit_cost' => (float) ($row['unit_cost'] ?? 0),
            ])
            ->filter(fn ($row) => $row['variant_id'] > 0);

        $variants = $lines->isNotEmpty()
            ? ProductVariant::with('product')->whereIn('id', $lines->pluck('variant_id'))->get()->keyBy('id')
            : $purchase->items->pluck('variant')->keyBy('id');

        $rows = ($lines->isNotEmpty() ? $lines : $purchase->items->map(fn ($item) => [
            'variant_id' => $item->variant_id,
            'quantity' => (int) $item->quantity,
            'unit_cost' => (float) $item->unit_cost,
        ]))
            // A variant archived since the purchase was written simply drops out of the form.
            ->filter(fn ($row) => isset($variants[$row['variant_id']]))
            ->map(fn ($row) => $variants[$row['variant_id']]->toLookupArray() + [
                'quantity' => $row['quantity'],
                'unit_cost' => $row['unit_cost'],
            ])
            ->values();

        return [
            'purchase' => $purchase,
            'suppliers' => Supplier::orderBy('name')->get(['id', 'name', 'company']),
            'lines' => $rows,
        ];
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request): array
    {
        return $request->validate([
            'supplier_id' => ['required', Rule::exists('suppliers', 'id')->whereNull('deleted_at')],
            'status' => ['required', Rule::in(Purchase::OPEN_STATUSES)],
            'purchase_date' => ['required', 'date', 'before_or_equal:today'],
            'invoice_number' => ['nullable', 'string', 'max:100'],
            'discount' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'additional_cost' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'note' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.variant_id' => ['required', 'integer', Rule::exists('product_variants', 'id')->whereNull('deleted_at')],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0', 'max:10000000'],
        ], [
            'supplier_id.required' => 'Choose the supplier these goods came from.',
            'supplier_id.exists' => 'Choose an active supplier.',
            'items.required' => 'Add at least one product to the purchase.',
            'purchase_date.before_or_equal' => 'A purchase cannot be dated in the future.',
        ]);
    }
}
