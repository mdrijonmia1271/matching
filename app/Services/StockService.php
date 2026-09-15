<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * The single place stock levels change, so every unit in or out leaves a
 * StockMovement row behind. Stock is tracked per variant.
 */
class StockService
{
    /**
     * @param  Model|null  $reference  The document behind the movement (order, purchase, return...).
     * @param  bool|null  $allowNegative  Null follows Admin → Settings → "Allow negative stock".
     */
    public function move(
        ProductVariant $variant,
        string $type,
        int $quantity,
        string $reason,
        ?string $note = null,
        ?Model $reference = null,
        ?float $unitCost = null,
        ?bool $allowNegative = null,
    ): StockMovement {
        if (! in_array($type, ['in', 'out'], true) || $quantity < 1) {
            throw new InvalidArgumentException('A stock movement needs a type of in/out and a positive quantity.');
        }

        $allowNegative ??= (bool) Settings::get('allow_negative_stock', false);

        return DB::transaction(function () use ($variant, $type, $quantity, $reason, $note, $reference, $unitCost, $allowNegative) {
            // Lock the product first: every move on any of its variants queues behind
            // it, which keeps the cached product total exact under concurrent sales.
            $product = Product::withTrashed()->lockForUpdate()->findOrFail($variant->product_id);
            $locked = ProductVariant::withTrashed()->lockForUpdate()->findOrFail($variant->id);
            $locked->setRelation('product', $product);

            $before = (int) $locked->stock;

            if ($type === 'out' && ! $allowNegative && $quantity > $before) {
                throw new RuntimeException('Only ' . max(0, $before) . ' left in stock for ' . $locked->full_name . '.');
            }

            $after = $type === 'in' ? $before + $quantity : $before - $quantity;

            ProductVariant::withTrashed()->whereKey($locked->id)->update(['stock' => $after]);
            $variant->setAttribute('stock', $after)->syncOriginalAttribute('stock');
            $product->refreshStockCache();

            return StockMovement::create([
                'product_id' => $product->id,
                'variant_id' => $locked->id,
                'user_id' => Auth::id(),
                'order_id' => $reference instanceof Order ? $reference->id : null,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
                'type' => $type,
                'reason' => $reason,
                'quantity' => $quantity,
                'unit_cost' => $unitCost ?? $locked->effective_cost,
                'stock_before' => $before,
                'stock_after' => $after,
                'note' => $note,
            ]);
        });
    }

    /** Record whatever movement brings a variant to an exact counted quantity. */
    public function setTo(ProductVariant $variant, int $target, string $reason = 'adjustment', ?string $note = null): ?StockMovement
    {
        return DB::transaction(function () use ($variant, $target, $reason, $note) {
            Product::withTrashed()->lockForUpdate()->findOrFail($variant->product_id);
            $current = (int) ProductVariant::withTrashed()->lockForUpdate()->whereKey($variant->id)->value('stock');
            $difference = $target - $current;

            if ($difference === 0) {
                return null;
            }

            return $this->move($variant, $difference > 0 ? 'in' : 'out', abs($difference), $reason, $note, allowNegative: true);
        });
    }
}
