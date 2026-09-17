<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A receipt for money collected against a customer's due. The part applied to
 * the opening due is kept here; the parts applied to orders are the linked
 * order payments.
 */
class CustomerPayment extends Model
{
    protected $fillable = [
        'customer_id', 'amount', 'opening_due_paid', 'method', 'account_id',
        'reference', 'note', 'received_by', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'opening_due_paid' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function getReceiptNumberAttribute(): string
    {
        return 'CP-' . str_pad((string) $this->id, 5, '0', STR_PAD_LEFT);
    }

    public function getMethodLabelAttribute(): string
    {
        return config('shop.payment_methods')[$this->method] ?? ucfirst((string) $this->method);
    }
}
