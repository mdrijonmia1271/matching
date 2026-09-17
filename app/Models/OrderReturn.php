<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Goods a customer sent back.
 *
 * Requested → approved → received, or rejected. Stock only moves when the
 * goods are actually received; the money is a separate refund (Step 14).
 */
class OrderReturn extends Model
{
    public const STATUS_LABELS = [
        'requested' => 'Requested',
        'approved' => 'Approved',
        'received' => 'Received',
        'rejected' => 'Rejected',
    ];

    /** Returns that still lay claim to the goods, so they count against the quantity cap. */
    public const CLAIMING_STATUSES = ['requested', 'approved', 'received'];

    /** Why the goods came back. */
    public const REASONS = [
        'wrong_size' => 'Wrong size or fit',
        'wrong_item' => 'Wrong item sent',
        'damaged' => 'Damaged or faulty',
        'not_as_described' => 'Not as described',
        'changed_mind' => 'Customer changed their mind',
        'courier_returned' => 'Returned by the courier',
        'other' => 'Other',
    ];

    /** What happens to each unit that comes back. */
    public const CONDITIONS = [
        'restock' => 'Good — back on the shelf',
        'damaged' => 'Damaged — written off',
    ];

    protected $fillable = [
        'number', 'order_id', 'customer_id', 'status', 'reason', 'note', 'refund_total',
        'requested_by', 'approved_by', 'received_by', 'approved_at', 'received_at', 'rejected_at',
    ];

    protected function casts(): array
    {
        return [
            'refund_total' => 'decimal:2',
            'approved_at' => 'datetime',
            'received_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (OrderReturn $return) {
            if (blank($return->number)) {
                do {
                    $number = 'RT-' . now()->format('ymd') . '-' . strtoupper(Str::random(4));
                } while (static::where('number', $number)->exists());

                $return->number = $number;
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'number';
    }

    public function items()
    {
        return $this->hasMany(ReturnItem::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function refunds()
    {
        return $this->hasMany(Refund::class, 'order_return_id')->latest('id');
    }

    public function stockMovements()
    {
        return $this->morphMany(StockMovement::class, 'reference');
    }

    /** Returns that still hold a claim on the goods (everything but rejected). */
    public function scopeClaiming(Builder $query): void
    {
        $query->whereIn('status', self::CLAIMING_STATUSES);
    }

    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $like = '%' . $term . '%';

        $query->where(fn (Builder $q) => $q->where('order_returns.number', 'like', $like)
            ->orWhereHas('order', fn (Builder $order) => $order->where('order_number', 'like', $like)
                ->orWhere('customer_name', 'like', $like)
                ->orWhere('customer_phone', 'like', $like)));
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? ucfirst((string) $this->status);
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            'received' => 'bg-emerald-100 text-emerald-700',
            'approved' => 'bg-sky-100 text-sky-700',
            'rejected' => 'bg-rose-100 text-rose-700',
            default => 'bg-amber-100 text-amber-700',
        };
    }

    public function getReasonLabelAttribute(): string
    {
        return self::REASONS[$this->reason] ?? ucfirst(str_replace('_', ' ', (string) $this->reason));
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['requested', 'approved'], true);
    }

    public function isReceived(): bool
    {
        return $this->status === 'received';
    }

    public function getQuantityAttribute(): int
    {
        return (int) $this->items->sum('quantity');
    }
}
