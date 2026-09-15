<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\OrderStatusService;
use App\Services\Payment\PaymentManager;
use RuntimeException;

class OrderController extends Controller
{
    public function __construct(
        protected OrderStatusService $statuses,
        protected PaymentManager $payments,
    ) {}

    public function index()
    {
        return view('orders.index', [
            'orders' => auth()->user()->orders()->withCount('items')->paginate(10),
        ]);
    }

    public function show(Order $order)
    {
        $this->authorizeOrder($order);

        return view('orders.show', ['order' => $order->load('items.product', 'payments', 'statusHistories')]);
    }

    /** Retry an online payment that was cancelled or failed. */
    public function pay(Order $order)
    {
        $this->authorizeOrder($order);

        if ($order->payment_status === 'paid') {
            return back()->with('error', 'This order is already paid.');
        }

        if ($order->status === 'cancelled') {
            return back()->with('error', 'Cancelled orders cannot be paid.');
        }

        $url = $this->payments->get($order->payment_method)->initiate($order);

        return $url
            ? redirect()->away($url)
            : back()->with('error', 'This order is set to cash on delivery.');
    }

    public function cancel(Order $order)
    {
        $this->authorizeOrder($order);

        try {
            $this->statuses->transition($order, 'cancelled', 'Cancelled by the customer', byCustomer: true);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Order ' . $order->order_number . ' cancelled.');
    }

    protected function authorizeOrder(Order $order): void
    {
        abort_unless($order->user_id === auth()->id(), 403);
    }
}
