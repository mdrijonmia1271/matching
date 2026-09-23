<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Order;
use App\Services\AccountService;
use App\Services\AdvanceOrderService;
use App\Services\PaymentService;
use App\Support\CsvExport;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Advance orders (bookings): goods paid for now and handed over later.
 *
 * They are ordinary orders with `channel = advance`, so everything after the
 * booking — payments, status changes, the invoice, returns — happens on the
 * usual order screens. This controller only lists them and takes new ones.
 */
class AdvanceOrderController extends Controller implements HasMiddleware
{
    public const FILTERS = [
        'open' => 'Waiting to be handed over',
        'due' => 'With money still owed',
        'overdue' => 'Past the expected date',
        'delivered' => 'Handed over',
        'cancelled' => 'Cancelled',
        'all' => 'All',
    ];

    public function __construct(protected AdvanceOrderService $advance) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:orders.view', only: ['index', 'export']),
            new Middleware('can:orders.advance', only: ['create', 'store']),
            new Middleware('can:reports.export', only: ['export']),
        ];
    }

    public function index(Request $request)
    {
        $open = Order::advance()->whereIn('status', Order::SALE_STATUSES)->whereNull('stock_taken_at');

        return view('admin.advance-orders.index', [
            'orders' => $this->query($request)->withCount('items')->paginate(20)->withQueryString(),
            'stats' => [
                'open' => (clone $open)->count(),
                'overdue' => (clone $open)->whereNotNull('expected_at')->where('expected_at', '<', now())->count(),
                'booked' => round((float) (clone $open)->sum('total'), 2),
                'advance' => round((float) (clone $open)->sum('paid_amount'), 2),
            ],
            'filters' => self::FILTERS,
            'filter' => $this->filter($request),
        ]);
    }

    public function create(AccountService $accounts)
    {
        $methods = PaymentService::manualMethods();

        return view('admin.advance-orders.create', [
            'methods' => $methods,
            'accounts' => Account::active()->orderBy('sort_order')->get(['id', 'name', 'code']),
            'balances' => $accounts->balances(),
            'methodAccounts' => collect($methods)->mapWithKeys(fn ($label, $method) => [$method => PaymentService::defaultAccountFor($method)?->id])->all(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.variant_id' => ['required', 'integer', Rule::exists('product_variants', 'id')->whereNull('deleted_at')],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'items.*.price' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'discount' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'expected_at' => ['nullable', 'date', 'after_or_equal:today'],
            'shipping_address' => ['nullable', 'string', 'max:500'],
            'shipping_city' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:500'],
            'customer_id' => ['nullable', Rule::exists('customers', 'id')->whereNull('deleted_at')],
            'customer_name' => ['nullable', 'string', 'max:120'],
            'customer_phone' => ['nullable', 'string', 'max:20'],
            'payments' => ['nullable', 'array', 'max:5'],
            'payments.*.amount' => ['required', 'numeric', 'min:0', 'max:100000000'],
            'payments.*.method' => ['required', Rule::in(array_keys(PaymentService::manualMethods()))],
            'payments.*.account_id' => ['required', Rule::exists('accounts', 'id')->where('is_active', true)],
        ], [
            'items.required' => 'Add at least one product to the booking.',
            'expected_at.after_or_equal' => 'The expected date cannot be in the past.',
            'customer_id.exists' => 'That customer no longer exists. Search for them again.',
            'payments.*.account_id.exists' => 'Choose an active account for each advance payment.',
        ]);

        try {
            $order = $this->advance->book($data);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.orders.show', $order)->with('success', sprintf(
            'Advance order %s booked — %s of %s taken, %s owed. The stock leaves when it is marked delivered.',
            $order->order_number,
            Money::format((float) $order->paid_amount),
            Money::format((float) $order->total),
            Money::format($order->due_amount),
        ));
    }

    public function export(Request $request)
    {
        $rows = (function () use ($request) {
            foreach ($this->query($request)->cursor() as $order) {
                yield [
                    $order->order_number,
                    $order->created_at->format('Y-m-d'),
                    $order->customer_name,
                    (string) $order->customer_phone,
                    $order->expected_at?->format('Y-m-d') ?? '',
                    $order->status_label,
                    $order->hasStockLeft() ? 'Handed over' : 'On the shelf',
                    (float) $order->total,
                    (float) $order->paid_amount,
                    $order->due_amount,
                ];
            }
        })();

        return CsvExport::download('advance-orders-' . now()->format('Y-m-d-His') . '.csv',
            ['Order', 'Booked', 'Customer', 'Phone', 'Expected', 'Status', 'Stock', 'Total', 'Advance paid', 'Due'], $rows);
    }

    protected function query(Request $request): Builder
    {
        $query = Order::advance()
            ->when($request->filled('q'), function (Builder $q) use ($request) {
                $term = trim((string) $request->input('q'));
                $like = '%' . $term . '%';

                $q->where(fn (Builder $inner) => $inner->where('order_number', 'like', $like)
                    ->orWhere('customer_name', 'like', $like)
                    ->orWhere('customer_phone', 'like', $like));
            })
            ->when($request->filled('from'), fn (Builder $q) => $q->whereDate('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn (Builder $q) => $q->whereDate('created_at', '<=', $request->date('to')));

        // "Open" is the one that matters day to day: booked, not cancelled, goods still here.
        match ($this->filter($request)) {
            'due' => $query->whereIn('status', Order::SALE_STATUSES)->whereColumn('paid_amount', '<', 'total'),
            'overdue' => $query->whereIn('status', Order::SALE_STATUSES)->whereNull('stock_taken_at')
                ->whereNotNull('expected_at')->where('expected_at', '<', now()),
            'delivered' => $query->where('status', 'delivered'),
            'cancelled' => $query->where('status', 'cancelled'),
            'all' => null,
            default => $query->whereIn('status', Order::SALE_STATUSES)->whereNull('stock_taken_at'),
        };

        return $query->latest('id');
    }

    protected function filter(Request $request): string
    {
        return array_key_exists((string) $request->input('filter'), self::FILTERS)
            ? (string) $request->input('filter')
            : 'open';
    }
}
