<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Money received against an order: one row per payment or gateway attempt. */
class Payment extends Model
{
    protected $fillable = [
        'order_id', 'gateway', 'method', 'account_id', 'transaction_id', 'amount', 'status',
        'received_by', 'paid_at', 'note', 'payload',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function getMethodLabelAttribute(): string
    {
        if ($this->method === 'cod') {
            return 'Cash on delivery';
        }

        return config('shop.payment_methods')[$this->method] ?? ucfirst((string) ($this->method ?? $this->gateway));
    }
}
