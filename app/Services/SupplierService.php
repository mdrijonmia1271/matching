<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Money paid to suppliers. Every payment has a matching "supplier payment" ledger entry out of its account. */
class SupplierService
{
    public function __construct(protected AccountService $accounts) {}

    /** Ways the shop can pay a supplier (checkout-only options excluded). */
    public static function methods(): array
    {
        return collect(config('shop.payment_methods'))->except(['cod', 'online'])->all();
    }

    public function pay(Supplier $supplier, float $amount, Account $account, string $method, ?string $note = null, ?string $reference = null): SupplierPayment
    {
        $amount = round($amount, 2);

        if ($amount <= 0) {
            throw new RuntimeException('The payment amount must be more than zero.');
        }

        return DB::transaction(function () use ($supplier, $amount, $account, $method, $note, $reference) {
            // Supplier first, then the account. Nothing locks them in the opposite order, so this cannot deadlock.
            $locked = Supplier::withTrashed()->lockForUpdate()->findOrFail($supplier->id);
            $source = Account::lockForUpdate()->findOrFail($account->id);

            if ($locked->trashed()) {
                throw new RuntimeException($locked->name . ' is archived. Restore the supplier before paying them.');
            }

            if (! $source->is_active) {
                throw new RuntimeException($source->name . ' is inactive. Choose another account.');
            }

            $owed = $locked->balance();

            if ($owed <= 0) {
                throw new RuntimeException('Nothing is owed to ' . $locked->name . '.');
            }

            if ($amount > $owed) {
                throw new RuntimeException('The payment (' . Money::format($amount) . ') is more than the ' . Money::format($owed) . ' owed to ' . $locked->name . '.');
            }

            $available = $source->balance();

            if ($amount > $available) {
                throw new RuntimeException('Only ' . Money::format($available) . ' is available in ' . $source->name . '.');
            }

            $payment = SupplierPayment::create([
                'supplier_id' => $locked->id,
                'amount' => $amount,
                'method' => $method,
                'account_id' => $source->id,
                'reference' => $reference,
                'note' => $note,
                'paid_by' => Auth::id(),
                'paid_at' => now(),
            ]);

            $this->accounts->post($source, 'out', $amount, 'supplier_payment', $payment,
                'Payment to ' . $locked->name . ' (' . $payment->receipt_number . ')' . ($note ? ' — ' . $note : ''));

            AuditLogger::log('purchases', 'supplier_paid', $locked,
                sprintf('%s paid to %s from %s (%s)', Money::format($amount), $locked->name, $source->name, $payment->receipt_number),
                new: array_filter([
                    'amount' => $amount,
                    'account' => $source->name,
                    'method' => $method,
                    'reference' => $reference,
                    'balance_after' => round($owed - $amount, 2),
                ], fn ($value) => $value !== null));

            return $payment;
        });
    }
}
