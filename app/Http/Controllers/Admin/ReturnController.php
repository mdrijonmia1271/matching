<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Services\ReturnService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use RuntimeException;

/** Goods coming back from customers: raised on an order, approved, then received into stock. */
class ReturnController extends Controller implements HasMiddleware
{
    public function __construct(protected ReturnService $returns) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:orders.view', only: ['index', 'show']),
            new Middleware('can:orders.update', only: ['store', 'approve', 'reject']),
            // Putting goods back on the shelf is a stock change, so it needs the stock permission.
            new Middleware('can:inventory.adjust', only: ['receive']),
        ];
    }

    public function index(Request $request)
    {
        return view('admin.returns.index', [
            'returns' => $this->query($request)
                ->with(['order:id,order_number,customer_name', 'items'])
                ->paginate(20)
                ->withQueryString(),
            'statuses' => OrderReturn::STATUS_LABELS,
            'reasons' => OrderReturn::REASONS,
            'stats' => [
                'open' => OrderReturn::whereIn('status', ['requested', 'approved'])->count(),
                'waiting' => OrderReturn::where('status', 'approved')->count(),
                'value' => round((float) OrderReturn::where('status', 'received')->sum('refund_total'), 2),
            ],
        ]);
    }

    public function show(OrderReturn $return)
    {
        return view('admin.returns.show', [
            'return' => $return->load([
                'items.orderItem', 'items.variant.product', 'order.customer',
                'requester:id,name', 'approver:id,name', 'receiver:id,name', 'refunds',
            ]),
            'movements' => $return->isReceived()
                ? $return->stockMovements()->with('variant.product')->get()
                : collect(),
        ]);
    }

    /** Raised from the order page, where staff can see what is still returnable. */
    public function store(Request $request, Order $order)
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.order_item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:0', 'max:100000'],
            'items.*.condition' => ['required', Rule::in(array_keys(OrderReturn::CONDITIONS))],
            'reason' => ['required', Rule::in(array_keys(OrderReturn::REASONS))],
            'note' => ['nullable', 'string', 'max:500'],
        ], [
            'items.required' => 'Choose at least one item to return.',
            'reason.required' => 'Choose why the goods are coming back.',
        ]);

        try {
            $return = $this->returns->request($order, $data['items'], $data['reason'], $data['note'] ?? null);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.returns.show', $return)
            ->with('success', 'Return ' . $return->number . ' raised. Approve it, then receive the goods when they arrive.');
    }

    public function approve(Request $request, OrderReturn $return)
    {
        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;

        try {
            $this->returns->approve($return, $note);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Return ' . $return->number . ' approved. Receive it when the goods arrive.');
    }

    public function reject(Request $request, OrderReturn $return)
    {
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? null;

        try {
            $this->returns->reject($return, $reason);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Return ' . $return->number . ' rejected. Those units can be returned again later.');
    }

    public function receive(OrderReturn $return)
    {
        try {
            $received = $this->returns->receive($return);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $damaged = (int) $received->items->where('condition', 'damaged')->sum('quantity');

        return back()->with('success', sprintf('Return %s received: %d back on the shelf%s.',
            $received->number,
            (int) $received->items->where('condition', 'restock')->sum('quantity'),
            $damaged > 0 ? ', ' . $damaged . ' written off as damaged' : ''));
    }

    protected function query(Request $request): Builder
    {
        return OrderReturn::query()
            ->search($request->input('q'))
            ->when($request->filled('status'), fn ($q) => $q->where('order_returns.status', $request->input('status')))
            ->when($request->filled('reason'), fn ($q) => $q->where('order_returns.reason', $request->input('reason')))
            ->orderByDesc('order_returns.id');
    }
}
