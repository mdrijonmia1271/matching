<?php

namespace App\Models;

use App\Support\Settings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Order extends Model
{
    public const STATUS_LABELS = [
        'pending' => 'Pending',
        'confirmed' => 'Confirmed',
        'processing' => 'Processing',
        'packed' => 'Packed',
        'shipped' => 'Shipped',
        'delivered' => 'Delivered',
        'cancelled' => 'Cancelled',
        'returned' => 'Returned',
        'refunded' => 'Refunded',
    ];

    public const STATUSES = ['pending', 'confirmed', 'processing', 'packed', 'shipped', 'delivered', 'cancelled', 'returned', 'refunded'];

    /**
     * Status changes staff may make by hand. Returned and refunded are only set
     * by the returns and refunds workflows, which also move stock and money.
     */
    public const TRANSITIONS = [
        'pending' => ['confirmed', 'processing', 'cancelled'],
        'confirmed' => ['processing', 'packed', 'cancelled'],
        'processing' => ['packed', 'shipped', 'cancelled'],
        'packed' => ['shipped', 'cancelled'],
        'shipped' => ['delivered'],
        'delivered' => [],
        'cancelled' => [],
        'returned' => [],
        'refunded' => [],
    ];

    /** Customers may cancel from their account until the parcel is packed. */
    public const CUSTOMER_CANCELLABLE = ['pending', 'confirmed', 'processing'];

    /** Orders that count as a sale: accepted by the shop and not cancelled or reversed. */
    public const SALE_STATUSES = ['confirmed', 'processing', 'packed', 'shipped', 'delivered'];

    /** Derived from payment and refund records by PaymentService; never set by hand. */
    public const PAYMENT_STATUSES = [
        'unpaid' => 'Unpaid',
        'partially_paid' => 'Partially paid',
        'paid' => 'Paid',
        'partially_refunded' => 'Partially refunded',
        'refunded' => 'Refunded',
        'failed' => 'Payment failed',
    ];

    protected $fillable = [
        'order_number', 'user_id', 'customer_id', 'customer_name', 'customer_email', 'customer_phone',
        'shipping_address', 'shipping_city', 'note', 'admin_note', 'courier_name', 'tracking_number',
        'subtotal', 'discount', 'shipping_cost', 'total', 'paid_amount', 'refunded_amount', 'coupon_code', 'status', 'payment_method',
        'payment_status', 'paid_at', 'confirmed_at', 'shipped_at', 'delivered_at', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'shipping_cost' => 'decimal:2',
            'total' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'refunded_amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Order $order) {
            if (blank($order->order_number)) {
                $prefix = Settings::get('order_prefix', 'MT');

                do {
                    $number = $prefix . '-' . now()->format('ymd') . '-' . strtoupper(Str::random(6));
                } while (static::where('order_number', $number)->exists());

                $order->order_number = $number;
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'order_number';
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function statusHistories()
    {
        return $this->hasMany(OrderStatusHistory::class)->latest('id');
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? ucfirst((string) $this->status);
    }

    public function getPaymentStatusLabelAttribute(): string
    {
        return self::PAYMENT_STATUSES[$this->payment_status] ?? ucfirst(str_replace('_', ' ', (string) $this->payment_status));
    }

    /** What the customer still owes on this order. */
    public function getDueAmountAttribute(): float
    {
        return max(0.0, round((float) $this->total - (float) $this->paid_amount, 2));
    }

    public function paymentStatusColor(): string
    {
        return match ($this->payment_status) {
            'paid' => 'bg-emerald-100 text-emerald-800',
            'partially_paid' => 'bg-sky-100 text-sky-800',
            'unpaid' => 'bg-amber-100 text-amber-800',
            'failed' => 'bg-rose-100 text-rose-800',
            default => 'bg-slate-100 text-slate-700',
        };
    }

    /** @return list<string> */
    public function nextStatuses(): array
    {
        return self::TRANSITIONS[$this->status] ?? [];
    }

    public function canBeCancelledByCustomer(): bool
    {
        return in_array($this->status, self::CUSTOMER_CANCELLABLE, true);
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'pending' => 'bg-amber-100 text-amber-800',
            'confirmed' => 'bg-sky-100 text-sky-800',
            'processing' => 'bg-blue-100 text-blue-800',
            'packed' => 'bg-violet-100 text-violet-800',
            'shipped' => 'bg-indigo-100 text-indigo-800',
            'delivered' => 'bg-emerald-100 text-emerald-800',
            'cancelled' => 'bg-rose-100 text-rose-800',
            'returned' => 'bg-orange-100 text-orange-800',
            default => 'bg-slate-100 text-slate-800',
        };
    }
}
