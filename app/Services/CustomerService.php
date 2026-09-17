<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Order;
use App\Models\User;
use App\Support\Money;
use App\Support\Phone;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Finds the customer behind a sale and collects what they owe. */
class CustomerService
{
    public function __construct(protected PaymentService $payments, protected AccountService $accounts) {}

    /**
     * How a payment would be split: the opening due first (the oldest debt),
     * then unpaid orders, oldest first.
     *
     * @return array{opening: float, orders: list<array{order: Order, amount: float}>, total_due: float}
     */
    public function allocate(Customer $customer, float $amount): array
    {
        $left = round($amount, 2);
        $opening = max(0.0, $customer->openingDueRemaining());
        $orders = $customer->unpaidOrders()->get();

        $fromOpening = min($opening, $left);
        $left = round($left - $fromOpening, 2);
        $lines = [];

        foreach ($orders as $order) {
            if ($left <= 0) {
                break;
            }

            $part = min($order->due_amount, $left);
            $lines[] = ['order' => $order, 'amount' => $part];
            $left = round($left - $part, 2);
        }

        return [
            'opening' => round($fromOpening, 2),
            'orders' => $lines,
            'total_due' => round($opening + $orders->sum(fn (Order $order) => $order->due_amount), 2),
        ];
    }

    /**
     * Records one receipt and applies it with allocate(). The opening-due part
     * posts a customer_payment ledger entry; each order part is a normal order
     * payment (PaymentService::record), linked back to the receipt.
     */
    public function collectDue(Customer $customer, float $amount, Account $account, string $method, ?string $note = null, ?string $reference = null): CustomerPayment
    {
        $amount = round($amount, 2);

        if ($amount <= 0) {
            throw new RuntimeException('The payment amount must be more than zero.');
        }

        return DB::transaction(function () use ($customer, $amount, $account, $method, $note, $reference) {
            // Locking the customer makes collections for them run one at a time, so a due is never applied twice.
            $locked = Customer::withTrashed()->lockForUpdate()->findOrFail($customer->id);

            if ($locked->trashed()) {
                throw new RuntimeException($locked->name . ' is archived. Restore the customer before collecting a payment.');
            }

            if (! $account->is_active) {
                throw new RuntimeException($account->name . ' is inactive. Choose another account.');
            }

            $plan = $this->allocate($locked, $amount);

            if ($plan['total_due'] <= 0) {
                throw new RuntimeException($locked->name . ' has no due to collect.');
            }

            if ($amount > $plan['total_due']) {
                throw new RuntimeException('The payment (' . Money::format($amount) . ') is more than ' . $locked->name . ' owes (' . Money::format($plan['total_due']) . ').');
            }

            $receipt = CustomerPayment::create([
                'customer_id' => $locked->id,
                'amount' => $amount,
                'opening_due_paid' => $plan['opening'],
                'method' => $method,
                'account_id' => $account->id,
                'reference' => $reference,
                'note' => $note,
                'received_by' => Auth::id(),
                'paid_at' => now(),
            ]);

            $label = 'Due collection ' . $receipt->receipt_number . ($note ? ' — ' . $note : '');

            if ($plan['opening'] > 0) {
                $this->accounts->post($account, 'in', $plan['opening'], 'customer_payment', $receipt,
                    'Opening due from ' . $locked->name . ' (' . $label . ')');
            }

            foreach ($plan['orders'] as ['order' => $order, 'amount' => $part]) {
                $this->payments->record($order, $part, $account, $method, $label, $reference)
                    ->forceFill(['customer_payment_id' => $receipt->id])
                    ->save();
            }

            AuditLogger::log('customers', 'due_collected', $locked,
                sprintf('%s collected from %s into %s (%s)', Money::format($amount), $locked->name, $account->name, $receipt->receipt_number),
                new: array_filter([
                    'amount' => $amount,
                    'account' => $account->name,
                    'method' => $method,
                    'reference' => $reference,
                    'opening_due' => $plan['opening'] ?: null,
                    'orders' => collect($plan['orders'])->mapWithKeys(fn (array $line) => [$line['order']->order_number => $line['amount']])->all() ?: null,
                ]));

            return $receipt->load('payments.order');
        });
    }

    /**
     * Registered buyers are matched by their account, everyone else by phone
     * number. Details already on file are never overwritten from checkout, only
     * blanks are filled, and an archived customer who buys again is restored.
     * Call inside the order transaction.
     */
    public function findOrCreateForCheckout(
        ?User $user,
        string $name,
        ?string $phone,
        ?string $email = null,
        ?string $address = null,
        ?string $city = null,
        bool $retried = false,
    ): Customer {
        $phone = Phone::normalise($phone);
        $details = ['name' => trim($name), 'email' => $email, 'address' => $address, 'city' => $city];

        if ($user && ($customer = Customer::withTrashed()->where('user_id', $user->id)->lockForUpdate()->first())) {
            return $this->fillBlanks($customer, $details + ['phone' => $phone]);
        }

        $byPhone = $phone ? Customer::withTrashed()->where('phone', $phone)->lockForUpdate()->first() : null;

        // A guest record with this phone becomes the account's record when they register and buy.
        if ($byPhone && (! $user || $byPhone->user_id === null)) {
            if ($user) {
                $byPhone->forceFill(['user_id' => $user->id]);
            }

            return $this->fillBlanks($byPhone, $details);
        }

        // New customer. A phone that belongs to another account's customer stays on that record only.
        $customer = new Customer($details + ['phone' => $byPhone ? null : $phone, 'customer_group' => 'retail', 'opening_due' => 0]);
        $customer->forceFill(['user_id' => $user?->id]);

        try {
            $customer->save();
        } catch (UniqueConstraintViolationException $e) {
            // Another checkout with the same new phone (or account) saved first: use that record.
            if ($retried) {
                throw $e;
            }

            return $this->findOrCreateForCheckout($user, $name, $phone, $email, $address, $city, retried: true);
        }

        return $customer;
    }

    /** @param  array<string, string|null>  $details */
    protected function fillBlanks(Customer $customer, array $details): Customer
    {
        foreach ($details as $key => $value) {
            if (blank($value) || filled($customer->{$key})) {
                continue;
            }

            if ($key === 'phone' && Customer::withTrashed()->where('phone', $value)->whereKeyNot($customer->id)->exists()) {
                continue;
            }

            $customer->{$key} = $value;
        }

        if ($customer->trashed()) {
            $customer->deleted_at = null;
        }

        $customer->save();

        return $customer;
    }
}
