<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Stock bought from a supplier.
 *
 * Nothing moves until the purchase is received: receiving stocks every item in
 * and adds the total to what the supplier is owed. Totals are recalculated on
 * the server, never trusted from the form.
 */
class Purchase extends Model
{
    public const STATUS_LABELS = [
        'draft' => 'Draft',
        'ordered' => 'Ordered',
        'received' => 'Received',
        'cancelled' => 'Cancelled',
    ];

    /** Purchases that count towards the supplier balance and stock value. */
    public const COUNTED_STATUSES = ['received'];

    /** While a purchase is in one of these it can still be edited or cancelled. */
    public const OPEN_STATUSES = ['draft', 'ordered'];

    protected $fillable = [
        'number', 'supplier_id', 'status', 'purchase_date', 'invoice_number',
        'subtotal', 'discount', 'additional_cost', 'total', 'note',
        'created_by', 'received_by', 'received_at', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'purchase_date' => 'date',
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'additional_cost' => 'decimal:2',
            'total' => 'decimal:2',
            'received_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Purchase $purchase) {
            if (blank($purchase->number)) {
                do {
                    $number = 'PU-' . now()->format('ymd') . '-' . strtoupper(Str::random(4));
                } while (static::where('number', $number)->exists());

                $purchase->number = $number;
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'number';
    }

    public function items()
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function stockMovements()
    {
        return $this->morphMany(StockMovement::class, 'reference');
    }

    public function scopeReceived(Builder $query): void
    {
        $query->whereIn('status', self::COUNTED_STATUSES);
    }

    /** Number or supplier invoice number. */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $like = '%' . $term . '%';

        $query->where(fn (Builder $q) => $q->where('purchases.number', 'like', $like)
            ->orWhere('purchases.invoice_number', 'like', $like)
            ->orWhereHas('supplier', fn (Builder $supplier) => $supplier->where('name', 'like', $like)->orWhere('company', 'like', $like)));
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? ucfirst((string) $this->status);
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            'received' => 'bg-emerald-100 text-emerald-700',
            'ordered' => 'bg-amber-100 text-amber-700',
            'cancelled' => 'bg-rose-100 text-rose-700',
            default => 'bg-slate-200 text-slate-600',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
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
