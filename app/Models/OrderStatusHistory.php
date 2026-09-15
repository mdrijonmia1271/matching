<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One row per order status change. Never edited. */
class OrderStatusHistory extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['order_id', 'from_status', 'to_status', 'user_id', 'note'];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function getFromLabelAttribute(): ?string
    {
        return $this->from_status ? (Order::STATUS_LABELS[$this->from_status] ?? ucfirst($this->from_status)) : null;
    }

    public function getToLabelAttribute(): string
    {
        return Order::STATUS_LABELS[$this->to_status] ?? ucfirst($this->to_status);
    }
}
