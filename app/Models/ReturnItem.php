<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One line of a return: how many units of an order item came back, and in what state. */
class ReturnItem extends Model
{
    protected $fillable = [
        'order_return_id', 'order_item_id', 'variant_id', 'quantity', 'condition', 'unit_price', 'line_total',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    public function return()
    {
        return $this->belongsTo(OrderReturn::class, 'order_return_id');
    }

    public function orderItem()
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id')->withTrashed();
    }

    public function getConditionLabelAttribute(): string
    {
        return OrderReturn::CONDITIONS[$this->condition] ?? ucfirst((string) $this->condition);
    }

    public function isDamaged(): bool
    {
        return $this->condition === 'damaged';
    }
}
