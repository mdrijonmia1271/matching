<?php

namespace App\Services;

use App\Models\Account;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Supplier;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Purchases from suppliers.
 *
 * Writing a purchase moves nothing: it is a plan. Receiving it stocks every
 * item in at its landed cost, updates the average cost of each variant and
 * adds the total to what the supplier is owed — all in one transaction.
 */
class PurchaseService
{
    public function __construct(
        protected StockService $stock,
        protected SupplierService $suppliers,
    ) {}

    /**
     * Create or rewrite a purchase. Only drafts and ordered purchases can be
     * written; totals are always recalculated here, never taken from the form.
     *
     * @param  array<string, mixed>  $data
     */
    public function save(array $data, ?Purchase $purchase = null): Purchase
    {
        return DB::transaction(function () use ($data, $purchase) {
            if ($purchase) {
                $purchase = Purchase::lockForUpdate()->findOrFail($purchase->id);

                if (! $purchase->isOpen()) {
                    throw new RuntimeException('Purchase ' . $purchase->number . ' is ' . strtolower($purchase->status_label) . ' and can no longer be edited.');
                }
            }

            $supplier = Supplier::findOrFail($data['supplier_id']);
            $items = $this->priceItems($data['items'] ?? []);
            $subtotal = round(array_sum(array_column($items, 'line_total')), 2);
            $discount = round((float) ($data['discount'] ?? 0), 2);
            $additional = round((float) ($data['additional_cost'] ?? 0), 2);

            if ($discount > $subtotal) {
                throw new RuntimeException('The discount (' . Money::format($discount) . ') is more than the ' . Money::format($subtotal) . ' of goods.');
            }

            $attributes = [
                'supplier_id' => $supplier->id,
                'status' => in_array($data['status'] ?? 'draft', Purchase::OPEN_STATUSES, true) ? $data['status'] : 'draft',
                'purchase_date' => $data['purchase_date'],
                'invoice_number' => $data['invoice_number'] ?? null,
                'note' => $data['note'] ?? null,
                'subtotal' => $subtotal,
                'discount' => $discount,
                'additional_cost' => $additional,
                'total' => round($subtotal - $discount + $additional, 2),
            ];

            $creating = $purchase === null;

            if ($creating) {
                $purchase = Purchase::create($attributes + ['created_by' => Auth::id()]);
            } else {
                $purchase->update($attributes);
                // A purchase that has not been received is rewritten whole: its old lines go.
                $purchase->items()->delete();
            }

            foreach ($items as $item) {
                $purchase->items()->create($item);
            }

            AuditLogger::log('purchases', $creating ? 'purchase_created' : 'purchase_updated', $purchase,
                sprintf('Purchase %s for %s — %s', $purchase->number, $supplier->name, Money::format($purchase->total)),
                new: [
                    'supplier' => $supplier->name,
                    'status' => $purchase->status,
                    'items' => count($items),
                    'total' => (float) $purchase->total,
                ]);

            return $purchase->load('items.variant.product');
        });
    }

    /**
     * Stock the goods in and bill the supplier.
     *
     * @param  float|null  $payNow  Paid to the supplier straight away, out of $account.
     */
    public function receive(Purchase $purchase, ?float $payNow = null, ?Account $account = null, ?string $method = null): Purchase
    {
        return DB::transaction(function () use ($purchase, $payNow, $account, $method) {
            // Locking first means a double click, or two people clicking at once, cannot stock the goods in twice.
            $locked = Purchase::lockForUpdate()->findOrFail($purchase->id);

            if (! $locked->isOpen()) {
                throw new RuntimeException('Purchase ' . $locked->number . ' is already ' . strtolower($locked->status_label) . '.');
            }

            $locked->load(['items.variant', 'supplier']);

            if ($locked->items->isEmpty()) {
                throw new RuntimeException('Purchase ' . $locked->number . ' has no items to receive.');
            }

            foreach ($this->landedCosts($locked) as $item) {
                $movement = $this->stock->move(
                    $item->variant,
                    'in',
                    (int) $item->quantity,
                    'purchase_received',
                    'Purchase ' . $locked->number,
                    $locked,
                    (float) $item->landed_unit_cost,
                );

                // stock_before is the level the goods landed on top of, read under the same lock.
                $this->updateAverageCost($item->variant, (int) $movement->stock_before, (float) $item->landed_unit_cost, (int) $item->quantity);
            }

            $locked->update([
                'status' => 'received',
                'received_by' => Auth::id(),
                'received_at' => now(),
            ]);

            AuditLogger::log('purchases', 'purchase_received', $locked,
                sprintf('Purchase %s received: %d units, %s added to what %s is owed',
                    $locked->number, $locked->items->sum('quantity'), Money::format($locked->total), $locked->supplier->name),
                new: [
                    'supplier' => $locked->supplier->name,
                    'quantity' => (int) $locked->items->sum('quantity'),
                    'total' => (float) $locked->total,
                ]);

            if ($payNow !== null && round($payNow, 2) > 0) {
                if (! $account) {
                    throw new RuntimeException('Choose the account the supplier is paid from.');
                }

                $this->suppliers->pay($locked->supplier, $payNow, $account, $method ?: 'cash',
                    'Payment for purchase ' . $locked->number);
            }

            return $locked;
        });
    }

    public function cancel(Purchase $purchase, ?string $reason = null): Purchase
    {
        return DB::transaction(function () use ($purchase, $reason) {
            $locked = Purchase::lockForUpdate()->findOrFail($purchase->id);

            if (! $locked->isOpen()) {
                throw new RuntimeException('Purchase ' . $locked->number . ' is ' . strtolower($locked->status_label)
                    . ' and cannot be cancelled. Send received goods back with a supplier return instead.');
            }

            $locked->update(['status' => 'cancelled', 'cancelled_at' => now()]);

            AuditLogger::log('purchases', 'purchase_cancelled', $locked,
                'Purchase ' . $locked->number . ' cancelled' . ($reason ? ': ' . $reason : ''));

            return $locked;
        });
    }

    /**
     * Each line's real cost per unit: its own cost, plus its share of the
     * additional cost, minus its share of the discount, split by line value.
     *
     * @return Collection<int, PurchaseItem>
     */
    public function landedCosts(Purchase $purchase): Collection
    {
        $subtotal = (float) $purchase->subtotal;
        $spread = round((float) $purchase->additional_cost - (float) $purchase->discount, 2);

        return $purchase->items->each(function (PurchaseItem $item) use ($subtotal, $spread) {
            $quantity = max(1, (int) $item->quantity);
            // Nothing to spread, or a purchase of free goods: the typed cost is already the landed cost.
            $share = $subtotal > 0 ? $spread * ((float) $item->line_total / $subtotal) : 0.0;
            $landed = max(0, round((float) $item->unit_cost + $share / $quantity, 2));

            $item->forceFill(['landed_unit_cost' => $landed])->save();
        });
    }

    /**
     * Weighted average cost: what is already on the shelf keeps its cost, the
     * new units bring theirs. Stock at or below zero has no cost to average.
     */
    protected function updateAverageCost(ProductVariant $variant, int $stockBefore, float $landedCost, int $quantity): void
    {
        $oldCost = $variant->effective_cost;

        $average = round(($stockBefore > 0 && $oldCost !== null)
            ? ($stockBefore * $oldCost + $quantity * $landedCost) / ($stockBefore + $quantity)
            : $landedCost, 2);

        ProductVariant::withTrashed()->whereKey($variant->id)->update(['cost_price' => $average]);
        $variant->setAttribute('cost_price', $average)->syncOriginalAttribute('cost_price');
    }

    /**
     * Check the lines against live variants and price them.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function priceItems(array $rows): array
    {
        $rows = array_values(array_filter($rows, fn ($row) => (int) ($row['variant_id'] ?? 0) > 0 && (int) ($row['quantity'] ?? 0) > 0));

        if (! $rows) {
            throw new RuntimeException('Add at least one product to the purchase.');
        }

        $variants = ProductVariant::whereIn('id', array_column($rows, 'variant_id'))->get()->keyBy('id');
        $items = [];

        foreach ($rows as $row) {
            $variant = $variants->get((int) $row['variant_id']);

            if (! $variant) {
                throw new RuntimeException('One of the products on this purchase no longer exists.');
            }

            $quantity = (int) $row['quantity'];
            $unitCost = round((float) ($row['unit_cost'] ?? 0), 2);

            if ($unitCost < 0) {
                throw new RuntimeException('A purchase price cannot be negative.');
            }

            // The same variant typed twice is one line, so stock and costs are counted once.
            if (isset($items[$variant->id])) {
                $items[$variant->id]['quantity'] += $quantity;
                $items[$variant->id]['line_total'] = round($items[$variant->id]['quantity'] * (float) $items[$variant->id]['unit_cost'], 2);

                continue;
            }

            $items[$variant->id] = [
                'product_id' => $variant->product_id,
                'variant_id' => $variant->id,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'line_total' => round($quantity * $unitCost, 2),
            ];
        }

        return array_values($items);
    }
}
