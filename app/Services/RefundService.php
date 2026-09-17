<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\Refund;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Money given back to a customer.
 *
 * The counterpart to a return: a return records goods, a refund records money.
 * Only money that was actually taken can go back, and it always leaves a real
 * account, so the ledger and the shop's cash stay in step.
 */
class RefundService
{
    public function __construct(
        protected AccountService $accounts,
        protected PaymentService $payments,
        protected OrderStatusService $statuses,
    ) {}

    /** Ways money can be handed back (checkout-only options excluded). */
    public static function methods(): array
    {
        return collect(config('shop.payment_methods'))->except(['cod', 'online'])->all();
    }

    public function refund(
        Order $order,
        float $amount,
        Account $account,
        string $method,
        ?OrderReturn $return = null,
        ?string $note = null,
        ?string $reference = null,
    ): Refund {
        $amount = round($amount, 2);

        if ($amount <= 0) {
            throw new RuntimeException('The refund amount must be more than zero.');
        }

        return DB::transaction(function () use ($order, $amount, $account, $method, $return, $note, $reference) {
            // Order first, then the account. Nothing locks them the other way round, so this cannot deadlock.
            $locked = Order::lockForUpdate()->findOrFail($order->id);
            $source = Account::lockForUpdate()->findOrFail($account->id);

            if (! $source->is_active) {
                throw new RuntimeException($source->name . ' is inactive. Choose another account.');
            }

            // Re-read under the lock: two people refunding at once cannot both pass the cap.
            $refundable = $locked->refundable_amount;

            if ($refundable <= 0) {
                throw new RuntimeException((float) $locked->paid_amount <= 0
                    ? 'Nothing has been paid on order ' . $locked->order_number . ', so there is nothing to refund.'
                    : 'Order ' . $locked->order_number . ' has already been refunded in full.');
            }

            if ($amount > $refundable) {
                throw new RuntimeException('The refund (' . Money::format($amount) . ') is more than the '
                    . Money::format($refundable) . ' that can still go back on order ' . $locked->order_number . '.');
            }

            $available = $source->balance();

            if ($amount > $available) {
                throw new RuntimeException('Only ' . Money::format($available) . ' is available in ' . $source->name . '.');
            }

            if ($return && $return->order_id !== $locked->id) {
                throw new RuntimeException('Return ' . $return->number . ' is not on order ' . $locked->order_number . '.');
            }

            $refund = Refund::create([
                'order_id' => $locked->id,
                'order_return_id' => $return?->id,
                'amount' => $amount,
                'method' => $method,
                'account_id' => $source->id,
                'reference' => $reference,
                'note' => $note,
                'refunded_by' => Auth::id(),
                'refunded_at' => now(),
            ]);

            $this->accounts->post($source, 'out', $amount, 'refund', $refund,
                'Refund for order ' . $locked->order_number . ' (' . $refund->number . ')' . ($note ? ' — ' . $note : ''));

            // Set the refunded total first: the payment status is derived from it.
            $locked->update(['refunded_amount' => round((float) $locked->refunded_amount + $amount, 2)]);
            $this->payments->recalculate($locked);

            AuditLogger::log('orders', 'refund_issued', $locked,
                sprintf('%s refunded on order %s from %s (%s)',
                    Money::format($amount), $locked->order_number, $source->name, $refund->number),
                new: array_filter([
                    'amount' => $amount,
                    'account' => $source->name,
                    'method' => $method,
                    'return' => $return?->number,
                    'reference' => $reference,
                    'refunded_total' => (float) $locked->refunded_amount,
                    'payment_status' => $locked->payment_status,
                ], fn ($value) => $value !== null));

            $this->closeOrderIfSettled($locked);

            $order->setRawAttributes($locked->getAttributes(), true);

            return $refund;
        });
    }

    /**
     * An order is `refunded` only when the money is all back *and* the goods
     * are too — which is exactly what the `returned` status means. A refund on
     * a cancelled order leaves it cancelled: the status tracks goods, the
     * payment status tracks money.
     */
    protected function closeOrderIfSettled(Order $order): void
    {
        if ($order->refundable_amount > 0 || ! in_array('refunded', $order->systemNextStatuses(), true)) {
            return;
        }

        $this->statuses->transition($order, 'refunded', 'Everything came back and the money was returned', system: true);
    }
}
