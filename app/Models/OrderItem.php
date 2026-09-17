<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    /** Name, SKU, variant label, price and cost are snapshots taken when the order was placed. */
    protected $fillable = [
        'order_id', 'product_id', 'variant_id', 'product_name', 'sku', 'variant_label',
        'price', 'unit_cost', 'quantity', 'subtotal',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'subtotal' => 'decimal:2',
        ];
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function product()
    {
        // Archived products still belong to past orders.
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id')->withTrashed();
    }

    public function returnItems()
    {
        return $this->hasMany(ReturnItem::class);
    }

    /**
     * Units already claimed by a return. A rejected return releases its claim;
     * one that is only requested still holds it, so the same unit cannot be
     * booked onto two returns at once.
     */
    public function returnedQuantity(): int
    {
        return (int) $this->returnItems()
            ->whereHas('return', fn ($query) => $query->claiming())
            ->sum('quantity');
    }

    /** How many units of this line may still be sent back. */
    public function returnableQuantity(): int
    {
        return max(0, (int) $this->quantity - $this->returnedQuantity());
    }
}
