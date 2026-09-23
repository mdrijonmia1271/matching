<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One row per stock change. Rows are never edited; corrections are new movements. */
class StockMovement extends Model
{
    /** Reasons an admin can pick when adding stock by hand. */
    public const IN_REASONS = [
        'manual_in' => 'Manual stock in',
        'purchase' => 'Purchase from supplier',
        'return' => 'Customer return',
        'adjustment' => 'Stock count correction',
        'other' => 'Other',
    ];

    /** Reasons an admin can pick when removing stock by hand. */
    public const OUT_REASONS = [
        'manual_out' => 'Manual stock out',
        'offline_sale' => 'Offline / showroom sale',
        'damaged' => 'Damaged',
        'lost' => 'Lost or stolen',
        'adjustment' => 'Stock count correction',
        'other' => 'Other',
    ];

    /** Reasons recorded automatically by the system. */
    public const SYSTEM_REASONS = [
        'opening' => 'Opening stock',
        'sale' => 'Online sale',
        'pos_sale' => 'POS sale',
        'advance_delivery' => 'Advance order delivered',
        'order_cancelled' => 'Order cancelled',
        'purchase_received' => 'Purchase received',
        'return_restock' => 'Return restocked',
        'return_damaged' => 'Return written off (damaged)',
    ];

    protected $fillable = [
        'product_id', 'variant_id', 'user_id', 'order_id', 'reference_type', 'reference_id',
        'type', 'reason', 'quantity', 'unit_cost', 'stock_before', 'stock_after', 'note',
    ];

    protected function casts(): array
    {
        return ['unit_cost' => 'decimal:2'];
    }

    public static function reasonLabels(): array
    {
        return self::IN_REASONS + self::OUT_REASONS + self::SYSTEM_REASONS;
    }

    public function product()
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id')->withTrashed();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    /** The document that caused the movement (order, purchase, return...). */
    public function reference()
    {
        return $this->morphTo();
    }

    public function getReasonLabelAttribute(): string
    {
        return self::reasonLabels()[$this->reason] ?? ucfirst(str_replace('_', ' ', $this->reason));
    }
}
