<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Order;
use App\Services\AccountService;
use App\Services\AuditLogger;
use App\Services\OrderStatusService;
use App\Services\PaymentService;
use App\Services\RefundService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use RuntimeException;

class OrderController extends Controller implements HasMiddleware
{
    public function __construct(protected OrderStatusService $statuses) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:orders.view', only: ['index', 'show', 'invoice']),
            new Middleware('can:orders.update', only: ['updateStatus', 'updateDetails']),
        ];
    }

    public function index(Request $request)
    {
        $orders = Order::withCount('items')
            ->when(in_array($request->input('status'), Order::STATUSES, true), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('payment_status'), fn ($q) => $q->where('payment_status', $request->string('payment_status')))
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = trim((string) $request->input('q'));
                $like = '%' . $term . '%';
                $query->where(fn ($q) => $q->where('order_number', 'like', $like)
                    ->orWhere('customer_name', 'like', $like)
                    ->orWhere('customer_email', 'like', $like)
                    ->orWhere('customer_phone', 'like', $like)
                    ->orWhere('tracking_number', $term));
            })
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date('to')))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('admin.orders.index', [
            'orders' => $orders,
            'statusCounts' => Order::selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status'),
        ]);
    }

    public function show(Order $order, AccountService $accounts)
    {
        $methods = PaymentService::manualMethods();
        $refundMethods = RefundService::methods();

        return view('admin.orders.show', [
            'order' => $order->load('items.product', 'payments.account:id,name', 'payments.receiver:id,name',
                'user', 'customer', 'statusHistories.user:id,name', 'returns.items',
                'refunds.account:id,name', 'refunds.issuer:id,name', 'refunds.return:id,number'),
            'methods' => $methods,
            'refundMethods' => $refundMethods,
            'accounts' => Account::active()->orderBy('sort_order')->get(['id', 'name', 'code']),
            'balances' => $accounts->balances(),
            'methodAccounts' => collect($methods + $refundMethods)
                ->mapWithKeys(fn ($label, $method) => [$method => PaymentService::defaultAccountFor($method)?->id])->all(),
        ]);
    }

    /** A printable invoice: the counter receipt, and a copy for any online order. */
    public function invoice(Order $order)
    {
        return view('admin.orders.invoice', [
            'order' => $order->load('items', 'payments.account:id,name', 'customer'),
        ]);
    }

    public function updateStatus(Request $request, Order $order)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(Order::STATUSES)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        abort_if($data['status'] === 'cancelled' && ! $request->user()->can('orders.cancel'), 403, 'You do not have permission to cancel orders.');

        try {
            $this->statuses->transition($order, $data['status'], $data['note'] ?? null);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Order ' . $order->order_number . ' is now ' . strtolower($order->status_label) . '.');
    }

    public function updateDetails(Request $request, Order $order)
    {
        $data = $request->validate([
            'courier_name' => ['nullable', 'string', 'max:80'],
            'tracking_number' => ['nullable', 'string', 'max:100'],
            'admin_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $order->fill(array_map(fn ($value) => filled($value) ? trim($value) : null, $data));
        [$old, $new] = AuditLogger::changes($order);
        $order->save();

        if ($new) {
            AuditLogger::log('orders', 'details_updated', $order, 'Order ' . $order->order_number . ' delivery details updated', $old, $new);
        }

        return back()->with('success', $new ? 'Delivery details saved.' : 'Nothing changed.');
    }
}
