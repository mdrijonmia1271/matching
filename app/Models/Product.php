<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Stock lives on variants. `products.stock` is a cached total of the active
     * variants' stock, kept current by StockService, for fast shop listings.
     */
    protected $fillable = [
        'category_id', 'subcategory_id', 'brand_id', 'name', 'slug', 'sku', 'short_description', 'description',
        'cost_price', 'price', 'sale_price', 'stock', 'image', 'tags',
        'is_active', 'is_featured', 'is_new_arrival', 'has_variants',
    ];

    /**
     * Set by code that creates its own variants (ProductService); otherwise a
     * default variant carrying the product's SKU and stock is created.
     */
    public bool $withoutDefaultVariant = false;

    protected function casts(): array
    {
        return [
            'cost_price' => 'decimal:2',
            'price' => 'decimal:2',
            'sale_price' => 'decimal:2',
            'stock' => 'integer',
            'tags' => 'array',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'is_new_arrival' => 'boolean',
            'has_variants' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Product $product) {
            if (blank($product->slug)) {
                $product->slug = static::uniqueSlug($product->name, $product->id);
            }

            if (blank($product->sku)) {
                $product->sku = 'SKU-'.strtoupper(Str::random(8));
            }
        });

        // Every product has at least one variant, so cart, orders and stock always have one to point at.
        static::created(function (Product $product) {
            if ($product->withoutDefaultVariant) {
                return;
            }

            $variant = new ProductVariant(['sku' => $product->sku, 'is_active' => true]);
            $variant->product_id = $product->id;
            $variant->stock = (int) $product->stock;
            $variant->save();
        });
    }

    public static function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'product';
        $slug = $base;
        $i = 2;

        while (static::withTrashed()->where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function subcategory()
    {
        return $this->belongsTo(Category::class, 'subcategory_id');
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    public function variants()
    {
        return $this->hasMany(ProductVariant::class)->orderBy('sort_order')->orderBy('id');
    }

    public function images()
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    public function reviews()
    {
        return $this->hasMany(Review::class)->where('is_approved', true);
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function stockMovements()
    {
        return $this->hasMany(StockMovement::class)->latest('id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** Recompute the cached total from the variants (sellable units only). */
    public function refreshStockCache(): void
    {
        $total = (int) ProductVariant::where('product_id', $this->id)
            ->where('is_active', true)
            ->where('stock', '>', 0)
            ->sum('stock');

        static::withTrashed()->whereKey($this->id)->update(['stock' => $total]);
        $this->setAttribute('stock', $total)->syncOriginalAttribute('stock');
    }

    /** Effective selling price (sale price when it is a real discount). */
    public function getCurrentPriceAttribute(): float
    {
        $sale = $this->sale_price !== null ? (float) $this->sale_price : null;

        return ($sale !== null && $sale > 0 && $sale < (float) $this->price)
            ? $sale
            : (float) $this->price;
    }

    public function getOnSaleAttribute(): bool
    {
        return $this->current_price < (float) $this->price;
    }

    public function getDiscountPercentAttribute(): int
    {
        if (! $this->on_sale || (float) $this->price <= 0) {
            return 0;
        }

        return (int) round((((float) $this->price - $this->current_price) / (float) $this->price) * 100);
    }

    public function getInStockAttribute(): bool
    {
        return $this->stock > 0;
    }

    public function getAverageRatingAttribute(): float
    {
        return round((float) $this->reviews()->avg('rating'), 1);
    }

    public function getImageUrlAttribute(): string
    {
        return $this->image
            ? asset('storage/'.$this->image)
            : 'https://placehold.co/600x800/e2e8f0/64748b?text='.urlencode($this->name);
    }
}
