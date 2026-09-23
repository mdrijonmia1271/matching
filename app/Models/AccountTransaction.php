<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One amount moving into or out of an account. The ledger is append-only:
 * mistakes are fixed with a new correcting entry, never by editing history.
 */
class AccountTransaction extends Model
{
    public const UPDATED_AT = null;

    public const TYPES = [
        'sale_payment' => 'Order payment',
        'customer_payment' => 'Customer due payment',
        'refund' => 'Refund',
        'supplier_payment' => 'Supplier payment',
        'purchase_payment' => 'Purchase paid (no supplier)',
        'expense' => 'Expense',
        'deposit' => 'Money in',
        'withdrawal' => 'Money out',
        'transfer_in' => 'Transfer in',
        'transfer_out' => 'Transfer out',
    ];

    protected $fillable = [
        'account_id', 'direction', 'amount', 'type', 'reference_type', 'reference_id',
        'payment_id', 'transfer_group', 'user_id', 'note', 'transacted_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'transacted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Account transactions cannot be edited. Record a correcting entry instead.'));
        static::deleting(fn () => throw new LogicException('Account transactions cannot be deleted. Record a correcting entry instead.'));
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function reference()
    {
        return $this->morphTo();
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type] ?? ucfirst(str_replace('_', ' ', (string) $this->type));
    }

    public function getSignedAmountAttribute(): float
    {
        return $this->direction === 'in' ? (float) $this->amount : -(float) $this->amount;
    }
}
