<?php

namespace App\Models;

use App\Support\Phone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A business the shop buys stock from. What the shop owes them is calculated from records, never typed. */
class Supplier extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'company', 'phone', 'email', 'address', 'opening_due', 'notes'];

    protected function casts(): array
    {
        return [
            'opening_due' => 'decimal:2',
            'last_payment_at' => 'datetime',
        ];
    }

    protected function phone(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => Phone::normalise($value));
    }

    public function payments()
    {
        return $this->hasMany(SupplierPayment::class);
    }

    public function totalPaid(): float
    {
        return round((float) $this->payments()->sum('amount'), 2);
    }

    public function purchases()
    {
        return $this->hasMany(Purchase::class);
    }

    /** What the shop has bought from this supplier; only received purchases count. */
    public function totalPurchased(): float
    {
        return round((float) $this->purchases()->received()->sum('total'), 2);
    }

    /** What the shop owes this supplier: opening due + received purchases − payments. */
    public function balance(): float
    {
        return round((float) $this->opening_due + $this->totalPurchased() - $this->totalPaid(), 2);
    }

    /** Payment, purchase and balance figures as columns, so lists can sort and filter by them. */
    public function scopeWithTotals(Builder $query): void
    {
        $payments = fn () => SupplierPayment::query()->whereColumn('supplier_payments.supplier_id', 'suppliers.id');
        $purchases = fn () => Purchase::query()->whereColumn('purchases.supplier_id', 'suppliers.id')->received();

        $paid = $payments()->selectRaw('COALESCE(SUM(supplier_payments.amount), 0)');
        $bought = $purchases()->selectRaw('COALESCE(SUM(purchases.total), 0)');

        $query->addSelect([
            'total_paid' => $payments()->selectRaw('COALESCE(SUM(supplier_payments.amount), 0)'),
            'payments_count' => $payments()->selectRaw('COUNT(*)'),
            'last_payment_at' => $payments()->selectRaw('MAX(supplier_payments.paid_at)'),
            'purchases_total' => $purchases()->selectRaw('COALESCE(SUM(purchases.total), 0)'),
            'purchases_count' => $purchases()->selectRaw('COUNT(*)'),
        ])->selectRaw(
            'suppliers.opening_due + (' . $bought->toSql() . ') - (' . $paid->toSql() . ') AS current_balance',
            array_merge($bought->getBindings(), $paid->getBindings()),
        );
    }

    /** Suppliers the shop still owes money to. */
    public function scopeOwed(Builder $query): void
    {
        $paid = SupplierPayment::query()->whereColumn('supplier_payments.supplier_id', 'suppliers.id')
            ->selectRaw('COALESCE(SUM(supplier_payments.amount), 0)');
        $bought = Purchase::query()->whereColumn('purchases.supplier_id', 'suppliers.id')->received()
            ->selectRaw('COALESCE(SUM(purchases.total), 0)');

        $query->whereRaw(
            'suppliers.opening_due + (' . $bought->toSql() . ') > (' . $paid->toSql() . ')',
            array_merge($bought->getBindings(), $paid->getBindings()),
        );
    }

    /** Name, company, email or phone; a phone typed in any format still matches. */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $like = '%' . $term . '%';
        $phone = Phone::looksLikePhone($term) ? Phone::normalise($term) : null;

        $query->where(fn (Builder $q) => $q->where('suppliers.name', 'like', $like)
            ->orWhere('suppliers.company', 'like', $like)
            ->orWhere('suppliers.email', 'like', $like)
            ->orWhere('suppliers.phone', 'like', $like)
            ->when($phone, fn (Builder $q) => $q->orWhere('suppliers.phone', 'like', '%' . $phone . '%')));
    }
}
