<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Where money is held: cash drawer, bank account, bKash, Nagad... */
class Account extends Model
{
    public const TYPES = [
        'cash' => 'Cash',
        'bank' => 'Bank account',
        'mobile_wallet' => 'Mobile wallet',
        'other' => 'Other',
    ];

    protected $fillable = ['name', 'code', 'type', 'account_number', 'opening_balance', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return [
            'opening_balance' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function transactions()
    {
        return $this->hasMany(AccountTransaction::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** Opening balance plus every ledger entry in, minus every entry out. */
    public function balance(): float
    {
        $net = AccountTransaction::where('account_id', $this->id)
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END), 0) AS net")
            ->value('net');

        return round((float) $this->opening_balance + (float) $net, 2);
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type] ?? ucfirst((string) $this->type);
    }
}
