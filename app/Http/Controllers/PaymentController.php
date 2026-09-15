<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Payment;
use App\Services\OrderStatusService;
use App\Services\PaymentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * Callback endpoints for the demo gateway.
 *
 * Every route here sits behind the `signed` middleware: the URLs are minted by
 * the gateway when payment starts, so nobody can mark an order paid by guessing
 * an order number and payment id. A real provider replaces the signature with
 * its own callback verification, and must still go through the same guarded
 * state change below.
 */
class PaymentController extends Controller
{
    public function show(Order $order, Payment $payment)
    {
        abort_unless($payment->order_id === $order->id && $payment->status === 'pending', 404);

        $expires = now()->addMinutes(30);

        return view('payment.demo', [
            'order' => $order,
            'payment' => $payment,
            'successUrl' => URL::temporarySignedRoute('payment.demo.success', $expires, [$order, $payment]),
            'failUrl' => URL::temporarySignedRoute('payment.demo.fail', $expires, [$order, $payment]),
        ]);
    }

    public function success(Order $order, Payment $payment)
    {
        abort_unless($payment->order_id === $order->id, 404);

        $outcome = DB::transaction(function () use ($order, $payment) {
            $payment = Payment::lockForUpdate()->findOrFail($payment->id);
            $order = Order::lockForUpdate()->findOrFail($order->id);

            // Replays of an already-settled callback change nothing.
            if ($payment->status !== 'pending') {
                return 'already_processed';
            }

            // Never revive a cancelled order: its stock has already been released.
            if ($order->status === 'cancelled') {
                $payment->update(['status' => 'failed']);

                return 'order_cancelled';
            }

            // Marks the payment received, posts it to the online-payments account and recalculates the order.
            app(PaymentService::class)->settleGatewayPayment($payment, $order);

            if ($order->status === 'pending') {
                app(OrderStatusService::class)->transition($order, 'processing', 'Online payment received');
            }

            return 'paid';
        });

        return match ($outcome) {
            'paid' => redirect()->route('checkout.success', $order)->with('success', 'Payment received. Thank you!'),
            'order_cancelled' => redirect()->route('checkout.success', $order)
                ->with('error', 'This order was cancelled, so the payment was not accepted.'),
            default => redirect()->route('checkout.success', $order)->with('warning', 'This payment has already been processed.'),
        };
    }

    public function fail(Order $order, Payment $payment)
    {
        abort_unless($payment->order_id === $order->id, 404);

        DB::transaction(function () use ($order, $payment) {
            $payment = Payment::lockForUpdate()->findOrFail($payment->id);
            $order = Order::lockForUpdate()->findOrFail($order->id);

            if ($payment->status !== 'pending') {
                return;
            }

            $payment->update(['status' => 'failed']);

            if ((float) $order->paid_amount <= 0) {
                $order->update(['payment_status' => 'failed']);
            }
        });

        return redirect()->route('checkout.success', $order)
            ->with('error', 'Payment was cancelled. Your order is saved as unpaid - you can retry from your orders page.');
    }
}
