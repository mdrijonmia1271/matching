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
}
