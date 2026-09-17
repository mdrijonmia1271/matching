<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One variant on a purchase, with what it cost before and after its share of discount and extras. */
class PurchaseItem extends Model
{
    protected $fillable = [
        'purchase_id', 'product_id', 'variant_id', 'quantity', 'unit_cost', 'line_total', 'landed_unit_cost',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_cost' => 'decimal:2',
            'line_total' => 'decimal:2',
            'landed_unit_cost' => 'decimal:2',
        ];
    }

    public function purchase()
    {
        return $this->belongsTo(Purchase::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id')->withTrashed();
    }

    /** What the goods really cost per unit; falls back to the typed cost until the purchase is received. */
    public function getEffectiveLandedCostAttribute(): float
    {
        return (float) ($this->landed_unit_cost ?? $this->unit_cost);
    }
}
