<?php

namespace App\Services;

use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\Barcode;
use App\Support\SkuGenerator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Creates and updates products together with their variants, in one transaction.
 * Stock is never set here except as opening stock for brand-new variants.
 */
class ProductService
{
    public function __construct(protected StockService $stock) {}

    /**
     * @param  array<string, mixed>  $data  Validated product attributes.
     * @param  list<array<string, mixed>>  $rows  Validated variant rows.
     */
    public function create(array $data, array $rows, bool $generateBarcodes = false): Product
    {
        return DB::transaction(function () use ($data, $rows, $generateBarcodes) {
            $product = new Product($data);
            $product->withoutDefaultVariant = true;
            $product->save();

            $this->syncVariants($product, $rows, $generateBarcodes);

            return $product->refresh();
        });
    }

    /**
     * @return array{changes: array{0: array, 1: array}, variants: array{created: list<string>, updated: list<string>, removed: list<string>}}
     */
    public function update(Product $product, array $data, array $rows, bool $generateBarcodes = false): array
    {
        return DB::transaction(function () use ($product, $data, $rows, $generateBarcodes) {
            $product->fill($data);
            $changes = AuditLogger::changes($product);
            $product->save();

            return [
                'changes' => $changes,
                'variants' => $this->syncVariants($product, $rows, $generateBarcodes),
            ];
        });
    }

    /** @return array{created: list<string>, updated: list<string>, removed: list<string>} */
    protected function syncVariants(Product $product, array $rows, bool $generateBarcodes): array
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
            $barcode = $this->text($row['barcode'] ?? null);

            $variant->fill([
                'color' => $color,
                'size' => $size,
                'barcode' => $barcode ?? ($generateBarcodes ? ($variant->barcode ?: Barcode::generateUnique()) : null),
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
                ? ($this->text($row['sku'] ?? null) ?? ($variant->sku ?: SkuGenerator::forVariant($product, $color, $size, $variant->id)))
                : $product->sku;

            $isNew = ! $variant->exists;
            $changed = $variant->isDirty();
            $variant->save();

            if ($isNew) {
                $summary['created'][] = $variant->label;

                if (($opening = (int) ($row['opening_stock'] ?? 0)) > 0) {
                    $this->stock->move($variant, 'in', $opening, 'opening', 'Opening stock entered when the variant was created');
                }
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

    protected function money(mixed $value): ?string
    {
        return filled($value) ? number_format((float) $value, 2, '.', '') : null;
    }
}
