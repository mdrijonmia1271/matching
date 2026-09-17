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
            'oldest_unpaid_at' => 'datetime',
            'last_payment_at' => 'datetime',
            'last_collection_at' => 'datetime',
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

    public function customerPayments()
    {
        return $this->hasMany(CustomerPayment::class);
    }

    /** Accepted orders not yet fully paid, oldest first: the order a due payment is applied in. */
    public function unpaidOrders()
    {
        return $this->orders()
            ->whereIn('status', Order::SALE_STATUSES)
            ->whereColumn('total', '>', 'paid_amount')
            ->orderBy('created_at')
            ->orderBy('id');
    }

    /** Opening due still owed after due collections. */
    public function openingDueRemaining(): float
    {
        return round((float) $this->opening_due - (float) $this->customerPayments()->sum('opening_due_paid'), 2);
    }

    /** Name, email or phone; a phone typed in any format (+880 1712-345678) still matches. */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $like = '%' . $term . '%';
        $phone = Phone::looksLikePhone($term) ? Phone::normalise($term) : null;

        $query->where(fn (Builder $q) => $q->where('customers.name', 'like', $like)
            ->orWhere('customers.email', 'like', $like)
            ->orWhere('customers.phone', 'like', $like)
            ->when($phone, fn (Builder $q) => $q->orWhere('customers.phone', 'like', '%' . $phone . '%')));
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

        $openingPaid = CustomerPayment::query()
            ->whereColumn('customer_payments.customer_id', 'customers.id')
            ->selectRaw('COALESCE(SUM(customer_payments.opening_due_paid), 0)');

        $openingRemaining = 'customers.opening_due - (' . $openingPaid->toSql() . ')';

        $query->addSelect([
            'orders_count' => $orders()->selectRaw('COUNT(*)'),
            'total_spent' => $orders()->whereIn('orders.status', Order::SALE_STATUSES)->selectRaw('COALESCE(SUM(orders.total), 0)'),
            'total_paid' => $orders()->selectRaw('COALESCE(SUM(orders.paid_amount - orders.refunded_amount), 0)'),
            'last_order_at' => $orders()->select('orders.created_at')->orderByDesc('orders.created_at')->limit(1),
        ])
            ->selectRaw($openingRemaining . ' AS opening_due_remaining', $openingPaid->getBindings())
            ->selectRaw($openingRemaining . ' + (' . $due->toSql() . ') AS current_due', array_merge($openingPaid->getBindings(), $due->getBindings()));
    }

    /** Customers who owe money: an opening due, or an accepted order not fully paid. */
    public function scopeWithDue(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q
            ->whereRaw('customers.opening_due > (SELECT COALESCE(SUM(customer_payments.opening_due_paid), 0) FROM customer_payments WHERE customer_payments.customer_id = customers.id)')
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
