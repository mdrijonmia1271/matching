<?php

namespace App\Services;

use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseItem;
use App\Models\ReturnItem;
use App\Models\StockMovement;
use App\Support\Barcode;
use App\Support\SkuGenerator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Creates and updates products together with their variants, in one transaction.
 * Stock is never set here: it only changes from the Stock screen. SKUs and
 * barcodes are always generated, never typed in.
 */
class ProductService
{
    /**
     * @param  array<string, mixed>  $data  Validated product attributes.
     * @param  list<array<string, mixed>>  $rows  Validated variant rows.
     */
    public function create(array $data, array $rows): Product
    {
        return DB::transaction(function () use ($data, $rows) {
            $product = new Product($data);
            $product->withoutDefaultVariant = true;
            $product->save();

            $this->syncVariants($product, $rows);

            return $product->refresh();
        });
    }

    /**
     * @return array{changes: array{0: array, 1: array}, variants: array{created: list<string>, updated: list<string>, removed: list<string>}}
     */
    public function update(Product $product, array $data, array $rows): array
    {
        return DB::transaction(function () use ($product, $data, $rows) {
            $product->fill($data);
            $changes = AuditLogger::changes($product);
            $product->save();

            return [
                'changes' => $changes,
                'variants' => $this->syncVariants($product, $rows),
            ];
        });
    }

    /** @return array{created: list<string>, updated: list<string>, removed: list<string>} */
    protected function syncVariants(Product $product, array $rows): array
    {
        $summary = ['created' => [], 'updated' => [], 'removed' => []];
        $keep = [];
        $product->loadMissing('category');

        foreach (array_values($rows) as $index => $row) {
            $variant = filled($row['id'] ?? null)
                ? $product->variants()->findOrFail($row['id'])
                : new ProductVariant;

            $withOptions = $product->has_variants;
            $color = $withOptions ? $this->text($row['color'] ?? null) : null;
            $size = $withOptions ? $this->text($row['size'] ?? null) : null;

            $variant->fill([
                'color' => $color,
                'size' => $size,
                'barcode' => $variant->barcode ?: Barcode::generateUnique(),
                // A product without options keeps its prices on the product itself.
                'cost_price' => $withOptions ? $this->money($row['cost_price'] ?? null) : null,
                'price' => $withOptions ? $this->money($row['price'] ?? null) : null,
                'sale_price' => $withOptions ? $this->money($row['sale_price'] ?? null) : null,
                'low_stock_threshold' => filled($row['low_stock_threshold'] ?? null) ? (int) $row['low_stock_threshold'] : null,
                'is_active' => $withOptions ? filter_var($row['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN) : true,
                'sort_order' => $index,
            ]);

            $variant->product_id = $product->id;
            $variant->setRelation('product', $product);
            $variant->sku = $withOptions
                ? ($variant->sku ?: SkuGenerator::forVariant($product, $color, $size, $variant->id))
                : $product->sku;

            $isNew = ! $variant->exists;
            $changed = $variant->isDirty();
            $variant->save();

            if ($isNew) {
                $summary['created'][] = $variant->label;
            } elseif ($changed) {
                $summary['updated'][] = $variant->label;
            }

            $keep[] = $variant->id;
        }

        foreach ($product->variants()->whereNotIn('id', $keep)->get() as $removed) {
            if ((int) $removed->stock !== 0) {
                throw new RuntimeException($removed->label . ' still has ' . $removed->stock . ' in stock. Bring its stock to 0 or untick "Active" instead of removing it.');
            }

            $hasHistory = $removed->stockMovements()->exists() || OrderItem::where('variant_id', $removed->id)->exists();

            // Variants with sales or stock history are archived so reports keep their names.
            $hasHistory ? $removed->delete() : $removed->forceDelete();
            $summary['removed'][] = $removed->label;
        }

        $product->refreshStockCache();

        return $summary;
    }

    protected function text(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * Removes a product from the database for good: variants, prices, stock,
     * stock history and images. A product that was ever bought from a supplier
     * or returned is refused, because those documents would stop adding up.
     * Order lines keep their own copy of name, SKU and price, so sales survive.
     *
     * @return list<string> Image paths the caller should remove from disk.
     */
    public function delete(Product $product): array
    {
        return DB::transaction(function () use ($product) {
            $variantIds = ProductVariant::withTrashed()->where('product_id', $product->id)->pluck('id');

            $blockers = array_filter([
                PurchaseItem::where('product_id', $product->id)->orWhereIn('variant_id', $variantIds)->exists() ? 'purchases' : null,
                ReturnItem::whereIn('variant_id', $variantIds)->exists() ? 'returns' : null,
            ]);

            if ($blockers) {
                throw new RuntimeException($product->name . ' cannot be deleted: it appears on ' . implode(' and ', $blockers)
                    . '. Deleting it would break those records.');
            }

            $images = $product->images()->pluck('path')->push($product->image)->filter()->values()->all();

            StockMovement::where('product_id', $product->id)->delete();
            ProductVariant::withTrashed()->where('product_id', $product->id)->forceDelete();
            // Images, cart items, reviews and wishlists cascade; order lines keep their snapshot.
            $product->forceDelete();

            return $images;
        });
    }

    protected function money(mixed $value): ?string
    {
        return filled($value) ? number_format((float) $value, 2, '.', '') : null;
    }
}
