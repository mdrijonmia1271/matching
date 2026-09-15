<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Order;
use App\Models\Payment;
use App\Support\Money;
use App\Support\Settings;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Money received against orders. Every successful payment has a matching
 * account ledger entry, and an order's paid amount and payment status are
 * always recalculated from its payment rows, never typed in.
 */
class PaymentService
{
    /** Account (by code) that receives each payment method unless staff choose another. */
    public const METHOD_ACCOUNTS = [
        'cash' => 'cash',
        'cod' => 'cash',
        'bkash' => 'bkash',
        'nagad' => 'nagad',
        'bank' => 'bank',
        'card' => 'bank',
    ];

    public function __construct(protected AccountService $accounts) {}

    /** Methods staff can record by hand (enabled in settings; checkout-only methods excluded). */
    public static function manualMethods(): array
    {
        $enabled = array_diff((array) Settings::get('payment_methods', []), ['cod', 'online']);

        return collect(config('shop.payment_methods'))->only($enabled)->all();
    }

    public static function defaultAccountFor(string $method): ?Account
    {
        $code = $method === 'online'
            ? Settings::get('online_payment_account', 'bank')
            : (self::METHOD_ACCOUNTS[$method] ?? null);

        return $code ? Account::active()->where('code', $code)->first() : null;
    }

    public function record(Order $order, float $amount, Account $account, string $method, ?string $note = null, ?string $reference = null): Payment
    {
        $amount = round($amount, 2);

        if ($amount <= 0) {
            throw new RuntimeException('The payment amount must be more than zero.');
        }

        return DB::transaction(function () use ($order, $amount, $account, $method, $note, $reference) {
            $locked = Order::lockForUpdate()->findOrFail($order->id);

            if ($locked->status === 'cancelled') {
                throw new RuntimeException('Order ' . $locked->order_number . ' is cancelled, so it cannot take a payment.');
            }

            if (! $account->is_active) {
                throw new RuntimeException($account->name . ' is inactive. Choose another account.');
            }

            $due = $locked->due_amount;

            if ($due <= 0) {
                throw new RuntimeException('Order ' . $locked->order_number . ' is already fully paid.');
            }

            if ($amount > $due) {
                throw new RuntimeException('The payment (' . Money::format($amount) . ') is more than the amount due (' . Money::format($due) . ').');
            }

            $payment = $locked->payments()->create([
                'gateway' => 'manual',
                'method' => $method,
                'account_id' => $account->id,
                'amount' => $amount,
                'status' => 'success',
                'transaction_id' => $reference,
                'received_by' => Auth::id(),
                'paid_at' => now(),
                'note' => $note,
            ]);

            $this->accounts->post($account, 'in', $amount, 'sale_payment', $locked,
                'Payment for order ' . $locked->order_number . ($note ? ' — ' . $note : ''), $payment);

            $this->recalculate($locked);

            AuditLogger::log('orders', 'payment_recorded', $locked,
                sprintf('%s received for order %s into %s', Money::format($amount), $locked->order_number, $account->name),
                new: array_filter(['amount' => $amount, 'method' => $method, 'account' => $account->name, 'reference' => $reference, 'payment_status' => $locked->payment_status]));

            $order->setRawAttributes($locked->getAttributes(), true);

            return $payment;
        });
    }

    /**
     * Completes a pending gateway payment. Call inside the caller's transaction
     * with the payment and order rows already locked.
     */
    public function settleGatewayPayment(Payment $payment, Order $order): void
    {
        $account = self::defaultAccountFor('online')
            ?? throw new RuntimeException('No active account is set to receive online payments. Check Admin → Settings.');

        $payment->update(['status' => 'success', 'method' => 'online', 'account_id' => $account->id, 'paid_at' => now()]);

        $this->accounts->post($account, 'in', (float) $payment->amount, 'sale_payment', $order,
            'Online payment for order ' . $order->order_number, $payment);

        $this->recalculate($order);
    }

    /** Paid amount and payment status, derived from successful payments and refunds. */
    public function recalculate(Order $order): void
    {
        $paid = round((float) $order->payments()->where('status', 'success')->sum('amount'), 2);
        $refunded = round((float) $order->refunded_amount, 2);

        $status = match (true) {
            $paid <= 0 => $order->payment_status === 'failed' ? 'failed' : 'unpaid',
            $refunded > 0 && $refunded >= $paid => 'refunded',
            $refunded > 0 => 'partially_refunded',
            $paid >= round((float) $order->total, 2) => 'paid',
            default => 'partially_paid',
        };

        $changes = ['paid_amount' => $paid, 'payment_status' => $status];

        if ($status === 'paid' && ! $order->paid_at) {
            $changes['paid_at'] = now();
        }

        $order->update($changes);
    }
}
