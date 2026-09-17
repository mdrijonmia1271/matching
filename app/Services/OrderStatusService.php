<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\Order;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * The only place an order's status changes.
 *
 * Every change locks the order row and re-checks the current status inside a
 * transaction, so a double-click or two staff members acting at once cannot
 * apply the same change twice (for example restocking a cancelled order twice).
 */
class OrderStatusService
{
    /** Column stamped when an order reaches each milestone. */
    protected const MILESTONES = [
        'confirmed' => 'confirmed_at',
        'shipped' => 'shipped_at',
        'delivered' => 'delivered_at',
        'cancelled' => 'cancelled_at',
    ];

    public function __construct(protected OrderService $orders) {}

    /**
     * @param  bool  $system  Set by a workflow that has already done the work
     *                        (returns, refunds), not by staff picking a status.
     *
     * @throws RuntimeException when the change is not allowed from the current status
     */
    public function transition(Order $order, string $to, ?string $note = null, bool $byCustomer = false, bool $system = false): Order
    {
        if (! array_key_exists($to, Order::STATUS_LABELS)) {
            throw new InvalidArgumentException("Unknown order status [{$to}].");
        }

        $updated = DB::transaction(function () use ($order, $to, $note, $byCustomer, $system) {
            $locked = Order::with('items')->lockForUpdate()->findOrFail($order->id);
            $from = $locked->status;

            $allowed = match (true) {
                $byCustomer => $to === 'cancelled' && $locked->canBeCancelledByCustomer(),
                $system => in_array($to, $locked->systemNextStatuses(), true),
                default => in_array($to, $locked->nextStatuses(), true),
            };

            if (! $allowed) {
                throw new RuntimeException($byCustomer
                    ? 'This order can no longer be cancelled online. Please contact us.'
                    : sprintf('Order %s is %s, so it cannot be changed to %s.',
                        $locked->order_number, strtolower($locked->status_label), strtolower(Order::STATUS_LABELS[$to])));
            }

            if ($to === 'cancelled') {
                $this->orders->restock($locked);

                // The coupon use goes back so the customer (or someone else) can use it again.
                if ($locked->coupon_code) {
                    Coupon::where('code', $locked->coupon_code)->where('used_count', '>', 0)->decrement('used_count');
                }
            }

            $changes = ['status' => $to];

            if (isset(self::MILESTONES[$to])) {
                $changes[self::MILESTONES[$to]] = now();
            }

            $locked->update($changes);

            $locked->statusHistories()->create([
                'from_status' => $from,
                'to_status' => $to,
                'user_id' => Auth::id(),
                'note' => $note,
            ]);

            AuditLogger::log('orders', 'status_changed', $locked,
                sprintf('Order %s: %s → %s', $locked->order_number, Order::STATUS_LABELS[$from] ?? $from, Order::STATUS_LABELS[$to]),
                ['status' => $from], array_filter(['status' => $to, 'note' => $note]));

            return $locked;
        });

        $order->setRawAttributes($updated->getAttributes(), true);
        $order->unsetRelation('statusHistories');

        return $order;
    }
}
