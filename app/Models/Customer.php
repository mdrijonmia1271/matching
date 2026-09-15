<?php

namespace App\Models;

use App\Support\Phone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A person the shop sells to, online or at the counter. Orders keep their own
 * copy of the name, phone and address typed at checkout; the customer holds the
 * current details and ties the history together.
 */
class Customer extends Model
{
    use SoftDeletes;

    public const GROUPS = [
        'retail' => 'Retail',
        'wholesale' => 'Wholesale',
        'vip' => 'VIP',
    ];

    /** user_id is deliberately absent: accounts are linked only by CustomerService. */
    protected $fillable = [
        'name', 'phone', 'email', 'address', 'city', 'customer_group', 'opening_due', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'opening_due' => 'decimal:2',
            'last_order_at' => 'datetime',
        ];
    }

    protected function phone(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => Phone::normalise($value));
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function payments()
    {
        return $this->hasManyThrough(Payment::class, Order::class);
    }

    /**
     * Order count and money totals as columns (orders_count, total_spent,
     * total_paid, current_due, last_order_at), so lists can sort by them.
     *
     * Spent and due count only orders the shop accepted (Order::SALE_STATUSES);
     * paid is net of refunds; due includes the opening due.
     */
    public function scopeWithTotals(Builder $query): void
    {
        $orders = fn () => Order::query()->whereColumn('orders.customer_id', 'customers.id');

        $due = $orders()->whereIn('orders.status', Order::SALE_STATUSES)
            ->selectRaw('COALESCE(SUM(CASE WHEN orders.total > orders.paid_amount THEN orders.total - orders.paid_amount ELSE 0 END), 0)');

        $query->addSelect([
            'orders_count' => $orders()->selectRaw('COUNT(*)'),
            'total_spent' => $orders()->whereIn('orders.status', Order::SALE_STATUSES)->selectRaw('COALESCE(SUM(orders.total), 0)'),
            'total_paid' => $orders()->selectRaw('COALESCE(SUM(orders.paid_amount - orders.refunded_amount), 0)'),
            'last_order_at' => $orders()->select('orders.created_at')->orderByDesc('orders.created_at')->limit(1),
        ])->selectRaw('customers.opening_due + (' . $due->toSql() . ') AS current_due', $due->getBindings());
    }

    /** Customers who owe money: an opening due, or an accepted order not fully paid. */
    public function scopeWithDue(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q->where('customers.opening_due', '>', 0)
            ->orWhereHas('orders', fn (Builder $orders) => $orders
                ->whereIn('status', Order::SALE_STATUSES)
                ->whereColumn('total', '>', 'paid_amount')));
    }

    public function getGroupLabelAttribute(): string
    {
        return self::GROUPS[$this->customer_group] ?? ucfirst((string) $this->customer_group);
    }

    public function groupColor(): string
    {
        return match ($this->customer_group) {
            'wholesale' => 'bg-sky-100 text-sky-800',
            'vip' => 'bg-amber-100 text-amber-800',
            default => 'bg-slate-100 text-slate-700',
        };
    }
}
