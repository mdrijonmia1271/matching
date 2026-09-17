<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Money paid to a supplier out of one account. Its ledger entry is a "supplier payment" out of that account. */
class SupplierPayment extends Model
{
    protected $fillable = [
        'supplier_id', 'amount', 'method', 'account_id', 'reference', 'note', 'paid_by', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function payer()
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function getReceiptNumberAttribute(): string
    {
        return 'SP-' . str_pad((string) $this->id, 5, '0', STR_PAD_LEFT);
    }

    public function getMethodLabelAttribute(): string
    {
        return config('shop.payment_methods')[$this->method] ?? ucfirst((string) $this->method);
    }
}
