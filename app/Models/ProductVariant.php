<?php

namespace App\Models;

use App\Support\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A sellable unit of a product, e.g. "Premium Kurti — Black / M".
 *
 * Stock, SKU and barcode live here. Products without sizes or colours have a
 * single default variant. Prices left empty inherit from the product.
 */
class ProductVariant extends Model
{
    use SoftDeletes;

    /** `stock` is deliberately not fillable: only StockService changes it, through the ledger. */
    protected $fillable = [
        'product_id', 'sku', 'barcode', 'size', 'color', 'cost_price', 'price', 'sale_price',
        'low_stock_threshold', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'cost_price' => 'decimal:2',
            'price' => 'decimal:2',
            'sale_price' => 'decimal:2',
            'stock' => 'integer',
            'low_stock_threshold' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function product()
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function stockMovements()
    {
        return $this->hasMany(StockMovement::class, 'variant_id')->latest('id');
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class, 'variant_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('is_active'), true);
    }

    /** Variants whose product has not been archived. */
    public function scopeForLiveProducts(Builder $query): Builder
    {
        return $query->whereHas('product', fn (Builder $product) => $product->whereNull('products.deleted_at'));
    }

    public function scopeLowStock(Builder $query): Builder
    {
        return $query->where('stock', '>', 0)
            ->whereRaw('stock <= COALESCE(low_stock_threshold, ?)', [(int) Settings::get('low_stock_threshold', 5)]);
    }

    public function scopeOutOfStock(Builder $query): Builder
    {
        return $query->where('stock', '<=', 0);
    }

    /** SKU / barcode / product name search. */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $like = '%' . $term . '%';

        return $query->where(fn (Builder $q) => $q->where('sku', 'like', $like)
            ->orWhere('barcode', $term)
            ->orWhereHas('product', fn (Builder $product) => $product->where('name', 'like', $like)));
    }

    /** "Black / M", or "Default" for a product without options. */
    public function getLabelAttribute(): string
    {
        return implode(' / ', array_filter([$this->color, $this->size])) ?: 'Default';
    }

    public function getFullNameAttribute(): string
    {
        $name = $this->product?->name ?? 'Deleted product';

        return $this->color || $this->size ? $name . ' — ' . $this->label : $name;
    }

    public function getRegularPriceAttribute(): float
    {
        return $this->price !== null ? (float) $this->price : (float) $this->product?->price;
    }

    /** Selling price right now: the sale price when it is a real discount. */
    public function getCurrentPriceAttribute(): float
    {
        $regular = $this->regular_price;

        // A variant with its own price only uses its own sale price.
        $sale = $this->sale_price !== null
            ? (float) $this->sale_price
            : ($this->price === null && $this->product?->sale_price !== null ? (float) $this->product->sale_price : null);

        return ($sale !== null && $sale > 0 && $sale < $regular) ? $sale : $regular;
    }

    public function getOnSaleAttribute(): bool
    {
        return $this->current_price < $this->regular_price;
    }

    /** Purchase price used for stock value and profit; null when nobody has entered one. */
    public function getEffectiveCostAttribute(): ?float
    {
        if ($this->cost_price !== null) {
            return (float) $this->cost_price;
        }

        return $this->product?->cost_price !== null ? (float) $this->product->cost_price : null;
    }

    public function getLowStockLevelAttribute(): int
    {
        return $this->low_stock_threshold ?? (int) Settings::get('low_stock_threshold', 5);
    }

    /** in_stock | low | out */
    public function getStockStatusAttribute(): string
    {
        return match (true) {
            $this->stock <= 0 => 'out',
            $this->stock <= $this->low_stock_level => 'low',
            default => 'in_stock',
        };
    }

    /** Shape used by the admin variant search (stock entry, POS, barcode lookups). */
    public function toLookupArray(): array
    {
        $product = $this->product;

        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'name' => $product?->name,
            'label' => $this->label,
            'full_name' => $this->full_name,
            'stock' => (int) $this->stock,
            'price' => $this->current_price,
            'cost' => $this->effective_cost,
            'image' => $product?->image_url,
            'sellable' => $this->is_active && $product && ! $product->trashed() && $product->is_active,
        ];
    }
}
