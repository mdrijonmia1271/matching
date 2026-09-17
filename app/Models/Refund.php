<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Money given back to a customer, out of one of the shop's accounts. */
class Refund extends Model
{
    protected $fillable = [
        'number', 'order_id', 'order_return_id', 'amount', 'method', 'account_id',
        'reference', 'note', 'refunded_by', 'refunded_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'refunded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Refund $refund) {
            if (blank($refund->number)) {
                do {
                    $number = 'RF-' . now()->format('ymd') . '-' . strtoupper(Str::random(4));
                } while (static::where('number', $number)->exists());

                $refund->number = $number;
            }
        });
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    /** The return that caused it, when there was one. */
    public function return()
    {
        return $this->belongsTo(OrderReturn::class, 'order_return_id');
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function issuer()
    {
        return $this->belongsTo(User::class, 'refunded_by');
    }

    public function getMethodLabelAttribute(): string
    {
        return config('shop.payment_methods')[$this->method] ?? ucfirst((string) $this->method);
    }
}
