<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Advance orders: the customer books goods now and takes them later.
 *
 * A booking is an order like any other — same payments, same account ledger,
 * same customer due, same invoice — with `channel` set to `advance`. Two things
 * make it different:
 *
 * 1. **Stock does not move here.** The goods may not even be in the shop yet,
 *    so `stock_taken_at` stays null and the shelf is only touched when the order
 *    reaches `delivered` (OrderService::fulfil). Cancelling before then puts
 *    nothing back, because nothing went out.
 * 2. **The price is typed in, not read off the variant.** A booking is a deal
 *    struck with the customer, often before today's price is even set. The
 *    variant's current price is offered as the default; whatever is agreed is
 *    what the order records.
 *
 * It is created as `confirmed`: the shop has accepted the booking and taken
 * money for it, so it counts as a sale and whatever is unpaid is a real due.
 */
class AdvanceOrderService
{
    public function __construct(
        protected PaymentService $payments,
        protected CustomerService $customers,
    ) {}

    /**
     * Book an order, and record whatever was paid up front.
     *
     * @param  array<string, mixed>  $data
     */
    public function book(array $data): Order
    {
        return DB::transaction(function () use ($data) {
            $lines = $this->priceLines($data['items'] ?? []);
            $subtotal = round(array_sum(array_column($lines, 'subtotal')), 2);
            $discount = round((float) ($data['discount'] ?? 0), 2);

            if ($discount < 0) {
                throw new RuntimeException('A discount cannot be negative.');
            }

            if ($discount > $subtotal) {
                throw new RuntimeException('The discount (' . Money::format($discount) . ') is more than the '
                    . Money::format($subtotal) . ' being booked.');
            }

            $total = round($subtotal - $discount, 2);

            // The whole point of a booking is that the rest is owed, so there has to be
            // somebody on record to owe it. A walk-in cannot be chased for the balance.
            $customer = $this->resolveCustomer($data)
                ?? throw new RuntimeException('An advance order needs a customer: the rest of the money is owed until the goods are handed over.');

            $taken = $this->checkPayments($data['payments'] ?? [], $total);

            $order = Order::create([
                'user_id' => $customer->user_id,
                'customer_id' => $customer->id,
                'channel' => 'advance',
                'customer_name' => $customer->name,
                'customer_email' => $customer->email,
                'customer_phone' => $customer->phone ?? '',
                'shipping_address' => $data['shipping_address'] ?? null,
                'shipping_city' => $data['shipping_city'] ?? null,
                'note' => $data['note'] ?? null,
                'expected_at' => $data['expected_at'] ?? null,
                'subtotal' => $subtotal,
                'discount' => $discount,
                'shipping_cost' => 0,
                'total' => $total,
                'payment_method' => 'advance',
                'status' => 'confirmed',
                'payment_status' => 'unpaid',
                'confirmed_at' => now(),
                // Deliberately null: the goods stay on the shelf until delivery.
                'stock_taken_at' => null,
            ]);

            foreach ($lines as $line) {
                $variant = $line['variant'];

                $order->items()->create([
                    'product_id' => $variant->product_id,
                    'variant_id' => $variant->id,
                    'product_name' => $variant->product->name,
                    'sku' => $variant->sku,
                    'variant_label' => $variant->product->has_variants ? $variant->label : null,
                    'price' => $line['price'],
                    'unit_cost' => $variant->effective_cost,
                    'quantity' => $line['quantity'],
                    'subtotal' => $line['subtotal'],
                ]);
            }

            $order->statusHistories()->create([
                'from_status' => null,
                'to_status' => 'confirmed',
                'user_id' => Auth::id(),
                'note' => 'Advance order booked',
            ]);

            foreach ($taken as $payment) {
                $this->payments->record($order, $payment['amount'], $payment['account'], $payment['method'],
                    'Advance payment');
            }

            $order->refresh();

            AuditLogger::log('orders', 'advance_booked', $order,
                sprintf('Advance order %s — %s booked by %s, %s paid up front',
                    $order->order_number, Money::format($total), $order->customer_name, Money::format((float) $order->paid_amount)),
                new: [
                    'customer' => $order->customer_name,
                    'items' => count($lines),
                    'total' => $total,
                    'advance' => (float) $order->paid_amount,
                    'due' => $order->due_amount,
                    'expected' => $order->expected_at?->format('Y-m-d'),
                ]);

            return $order->load('items');
        });
    }

    /**
     * The customer behind the booking: an existing record, or a new one from the
     * name and phone typed on the form.
     *
     * @param  array<string, mixed>  $data
     */
    protected function resolveCustomer(array $data): ?Customer
    {
        if (! empty($data['customer_id'])) {
            return Customer::find($data['customer_id'])
                ?? throw new RuntimeException('That customer no longer exists. Search for them again.');
        }

        if (blank($data['customer_name'] ?? null)) {
            return null;
        }

        // The same matching the website and the till use, so one phone means one record.
        return $this->customers->findOrCreateForCheckout(
            null,
            (string) $data['customer_name'],
            $data['customer_phone'] ?? null,
        );
    }

    /**
     * Check each line and work out what it comes to.
     *
     * Stock is not checked at all: booking goods the shop has not got yet is
     * exactly what an advance order is for.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function priceLines(array $rows): array
    {
        $rows = array_values(array_filter($rows, fn ($row) => (int) ($row['variant_id'] ?? 0) > 0 && (int) ($row['quantity'] ?? 0) > 0));

        if (! $rows) {
            throw new RuntimeException('Add at least one product to the booking.');
        }

        $variants = ProductVariant::with('product')->whereIn('id', array_column($rows, 'variant_id'))->get()->keyBy('id');
        $lines = [];

        foreach ($rows as $row) {
            $variant = $variants->get((int) $row['variant_id']);
            $product = $variant?->product;

            if (! $variant || ! $product) {
                throw new RuntimeException('One of the products on the booking no longer exists.');
            }

            if (! $variant->is_active || $product->trashed() || ! $product->is_active) {
                throw new RuntimeException($variant->full_name . ' is not on sale any more. Remove it from the booking.');
            }

            $quantity = (int) $row['quantity'];

            // A blank price falls back to today's price; zero is allowed, for an item thrown in.
            $price = ($row['price'] ?? '') === ''
                ? round((float) $variant->current_price, 2)
                : round((float) $row['price'], 2);

            if ($price < 0) {
                throw new RuntimeException('A price cannot be negative.');
            }

            // The same product added twice is one line, so the quantity adds up
            // instead of the second row quietly replacing the first.
            if (isset($lines[$variant->id])) {
                $lines[$variant->id]['quantity'] += $quantity;
                $lines[$variant->id]['subtotal'] = round($lines[$variant->id]['quantity'] * $lines[$variant->id]['price'], 2);

                continue;
            }

            $lines[$variant->id] = [
                'variant' => $variant,
                'quantity' => $quantity,
                'price' => $price,
                'subtotal' => round($quantity * $price, 2),
            ];
        }

        return array_values($lines);
    }

    /**
     * Check the advance before anything is recorded: real active accounts, and
     * never more than the booking comes to.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array{amount: float, account: Account, method: string}>
     */
    protected function checkPayments(array $rows, float $total): array
    {
        $accounts = Account::whereIn('id', array_column($rows, 'account_id'))->get()->keyBy('id');
        $payments = [];
        $taken = 0.0;

        foreach ($rows as $row) {
            $amount = round((float) ($row['amount'] ?? 0), 2);

            if ($amount <= 0) {
                continue;
            }

            $account = $accounts->get((int) ($row['account_id'] ?? 0))
                ?? throw new RuntimeException('Choose the account each advance payment goes into.');

            if (! $account->is_active) {
                throw new RuntimeException($account->name . ' is inactive. Choose another account.');
            }

            $taken = round($taken + $amount, 2);
            $payments[] = ['amount' => $amount, 'account' => $account, 'method' => (string) ($row['method'] ?? 'cash')];
        }

        if ($taken > $total) {
            throw new RuntimeException('The advance (' . Money::format($taken) . ') is more than the '
                . Money::format($total) . ' the booking comes to.');
        }

        return $payments;
    }
}
