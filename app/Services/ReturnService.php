<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Goods coming back from a customer.
 *
 * Requested → approved → received, or rejected at any point before the goods
 * arrive. Stock only moves on `receive`, and money never moves here at all:
 * a return records goods, a refund records money.
 */
class ReturnService
{
    public function __construct(
        protected StockService $stock,
        protected OrderStatusService $statuses,
    ) {}

    /**
     * Book a return against an order.
     *
     * @param  array<int, array{order_item_id: int, quantity: int, condition?: string}>  $items
     */
    public function request(Order $order, array $items, string $reason, ?string $note = null): OrderReturn
    {
        return DB::transaction(function () use ($order, $items, $reason, $note) {
            // Locking the order makes two people returning the same unit queue up, so the cap holds.
            $locked = Order::lockForUpdate()->findOrFail($order->id);

            if (in_array($locked->status, ['pending', 'cancelled'], true)) {
                throw new RuntimeException('Order ' . $locked->order_number . ' is ' . strtolower($locked->status_label)
                    . ', so nothing has gone out to come back. Cancel it instead.');
            }

            $lines = $this->checkLines($locked, $items);

            $return = OrderReturn::create([
                'order_id' => $locked->id,
                'customer_id' => $locked->customer_id,
                'status' => 'requested',
                'reason' => $reason,
                'note' => $note,
                'refund_total' => round(array_sum(array_column($lines, 'line_total')), 2),
                'requested_by' => Auth::id(),
            ]);

            foreach ($lines as $line) {
                $return->items()->create($line);
            }

            AuditLogger::log('orders', 'return_requested', $return,
                sprintf('Return %s raised on order %s — %d units, %s',
                    $return->number, $locked->order_number, array_sum(array_column($lines, 'quantity')),
                    Money::format((float) $return->refund_total)),
                new: [
                    'order' => $locked->order_number,
                    'reason' => $reason,
                    'units' => array_sum(array_column($lines, 'quantity')),
                    'value' => (float) $return->refund_total,
                ]);

            return $return->load('items.orderItem');
        });
    }

    public function approve(OrderReturn $return, ?string $note = null): OrderReturn
    {
        return DB::transaction(function () use ($return, $note) {
            $locked = OrderReturn::lockForUpdate()->findOrFail($return->id);

            if ($locked->status !== 'requested') {
                throw new RuntimeException('Return ' . $locked->number . ' is already ' . strtolower($locked->status_label) . '.');
            }

            $locked->update([
                'status' => 'approved',
                'approved_by' => Auth::id(),
                'approved_at' => now(),
                'note' => $note ?: $locked->note,
            ]);

            AuditLogger::log('orders', 'return_approved', $locked,
                'Return ' . $locked->number . ' approved, waiting for the goods');

            return $locked;
        });
    }

    public function reject(OrderReturn $return, ?string $reason = null): OrderReturn
    {
        return DB::transaction(function () use ($return, $reason) {
            $locked = OrderReturn::lockForUpdate()->findOrFail($return->id);

            if (! $locked->isOpen()) {
                throw new RuntimeException('Return ' . $locked->number . ' is ' . strtolower($locked->status_label)
                    . ' and cannot be rejected. The goods are already back in stock.');
            }

            $locked->update([
                'status' => 'rejected',
                'rejected_at' => now(),
                'note' => $reason ?: $locked->note,
            ]);

            // Rejecting releases the claim, so those units can be returned on a later request.
            AuditLogger::log('orders', 'return_rejected', $locked,
                'Return ' . $locked->number . ' rejected' . ($reason ? ': ' . $reason : ''));

            return $locked;
        });
    }

    /**
     * The goods are physically back. Good units go on the shelf; damaged ones
     * come in and go straight back out, so the write-off is visible in the
     * stock ledger instead of never appearing at all.
     */
    public function receive(OrderReturn $return): OrderReturn
    {
        return DB::transaction(function () use ($return) {
            $locked = OrderReturn::lockForUpdate()->findOrFail($return->id);

            if ($locked->status !== 'approved') {
                throw new RuntimeException($locked->status === 'received'
                    ? 'Return ' . $locked->number . ' has already been received.'
                    : 'Return ' . $locked->number . ' is ' . strtolower($locked->status_label) . '. Approve it before the goods come back.');
            }

            $locked->load(['items.variant', 'items.orderItem', 'order']);

            foreach ($locked->items as $item) {
                if (! $item->variant) {
                    // The variant was deleted outright: the money side still stands, but there is no shelf to put it on.
                    continue;
                }

                $cost = $item->orderItem?->unit_cost !== null ? (float) $item->orderItem->unit_cost : null;

                // Both conditions come in first, so the ledger always shows the goods arriving.
                $this->stock->move($item->variant, 'in', (int) $item->quantity, 'return_restock',
                    'Return ' . $locked->number, $locked, $cost);

                if ($item->isDamaged()) {
                    $this->stock->move($item->variant, 'out', (int) $item->quantity, 'damaged',
                        'Damaged on return ' . $locked->number, $locked, $cost, allowNegative: true);
                }
            }

            $locked->update([
                'status' => 'received',
                'received_by' => Auth::id(),
                'received_at' => now(),
            ]);

            AuditLogger::log('orders', 'return_received', $locked,
                sprintf('Return %s received: %d units back on order %s',
                    $locked->number, $locked->items->sum('quantity'), $locked->order->order_number),
                new: [
                    'order' => $locked->order->order_number,
                    'restocked' => (int) $locked->items->where('condition', 'restock')->sum('quantity'),
                    'damaged' => (int) $locked->items->where('condition', 'damaged')->sum('quantity'),
                ]);

            $this->closeOrderIfFullyReturned($locked->order);

            return $locked;
        });
    }

    /**
     * Once every unit on the order is back, the order itself is `returned`.
     * A partial return leaves the order as it was: part of it still stands.
     */
    protected function closeOrderIfFullyReturned(Order $order): void
    {
        $outstanding = $order->items()->get()->sum(fn (OrderItem $item) => $item->returnableQuantity());

        if ($outstanding > 0 || ! in_array('returned', $order->systemNextStatuses(), true)) {
            return;
        }

        $this->statuses->transition($order, 'returned', 'Every item came back', system: true);
    }

    /**
     * Check each line against what is left to return, priced from the order.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    protected function checkLines(Order $order, array $items): array
    {
        $items = array_values(array_filter($items, fn ($row) => (int) ($row['order_item_id'] ?? 0) > 0 && (int) ($row['quantity'] ?? 0) > 0));

        if (! $items) {
            throw new RuntimeException('Choose at least one item to return.');
        }

        $orderItems = $order->items()->get()->keyBy('id');
        $lines = [];
        $wanted = [];

        // Good and damaged units of one item are separate lines, but one shared cap.
        foreach ($items as $row) {
            $orderItem = $orderItems->get((int) $row['order_item_id']);

            if (! $orderItem) {
                throw new RuntimeException('One of those items is not on order ' . $order->order_number . '.');
            }

            $quantity = (int) $row['quantity'];
            $condition = ($row['condition'] ?? 'restock') === 'damaged' ? 'damaged' : 'restock';
            $key = $orderItem->id . '-' . $condition;
            $price = round((float) $orderItem->price, 2);

            $lines[$key] ??= [
                'order_item_id' => $orderItem->id,
                'variant_id' => $orderItem->variant_id,
                'quantity' => 0,
                'condition' => $condition,
                'unit_price' => $price,
                'line_total' => 0.0,
            ];

            $lines[$key]['quantity'] += $quantity;
            $lines[$key]['line_total'] = round($lines[$key]['quantity'] * $price, 2);
            $wanted[$orderItem->id] = ($wanted[$orderItem->id] ?? 0) + $quantity;
        }

        foreach ($wanted as $itemId => $quantity) {
            $orderItem = $orderItems->get($itemId);
            $available = $orderItem->returnableQuantity();

            if ($quantity > $available) {
                throw new RuntimeException(sprintf('%s: only %d of %d can still come back%s.',
                    $orderItem->product_name, $available, $orderItem->quantity,
                    $available < $orderItem->quantity ? ' — the rest is already on another return' : ''));
            }
        }

        return array_values($lines);
    }
}
