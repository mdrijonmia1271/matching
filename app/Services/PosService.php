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
 * Selling over the counter.
 *
 * A counter sale is an order like any other — the same stock ledger, the same
 * payment records, the same account ledger — with `channel` set to `pos`. It is
 * created as delivered, because the customer walks out with the goods.
 *
 * Prices always come from the variant, never from the form.
 */
class PosService
{
    /** A sale with no customer record behind it. */
    public const WALK_IN = 'Walk-in customer';

    public function __construct(
        protected StockService $stock,
        protected PaymentService $payments,
        protected CustomerService $customers,
    ) {}

    /**
     * Ring up a sale: stock out, order, payments, all or nothing.
     *
     * @param  array<string, mixed>  $data
     */
    public function sell(array $data): Order
    {
        return DB::transaction(function () use ($data) {
            $lines = $this->priceLines($data['items'] ?? []);
            $subtotal = round(array_sum(array_column($lines, 'subtotal')), 2);
            $discount = round((float) ($data['discount'] ?? 0), 2);

            if ($discount < 0) {
                throw new RuntimeException('A discount cannot be negative.');
            }

            if ($discount > $subtotal) {
                throw new RuntimeException('The discount (' . Money::format($discount) . ') is more than the ' . Money::format($subtotal) . ' being sold.');
            }

            $total = round($subtotal - $discount, 2);
            $customer = $this->resolveCustomer($data);
            $payments = $this->checkPayments($data['payments'] ?? [], $total);
            $taken = round(array_sum(array_column($payments, 'amount')), 2);

            // A walk-in has no record to collect from, so the money cannot be left owing.
            if (! $customer && $taken < $total) {
                throw new RuntimeException('A walk-in sale must be paid in full. Choose a customer if '
                    . Money::format(round($total - $taken, 2)) . ' is to be left owing.');
            }

            $order = Order::create([
                'user_id' => $customer?->user_id,
                'customer_id' => $customer?->id,
                'channel' => 'pos',
                'customer_name' => $customer?->name ?? self::WALK_IN,
                'customer_email' => $customer?->email,
                'customer_phone' => $customer?->phone ?? '',
                'shipping_address' => null,
                'note' => $data['note'] ?? null,
                'subtotal' => $subtotal,
                'discount' => $discount,
                'shipping_cost' => 0,
                'total' => $total,
                'payment_method' => 'pos',
                // The customer has the goods in hand, so the sale is finished the moment it is rung up.
                'status' => 'delivered',
                'payment_status' => 'unpaid',
                'confirmed_at' => now(),
                'delivered_at' => now(),
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

                // allowNegative stays null on purpose: the counter follows Settings → "Allow negative stock".
                $this->stock->move($variant, 'out', $line['quantity'], 'pos_sale', null, $order);
            }

            $order->statusHistories()->create([
                'from_status' => null,
                'to_status' => 'delivered',
                'user_id' => Auth::id(),
                'note' => 'Sold at the counter',
            ]);

            foreach ($payments as $payment) {
                $this->payments->record($order, $payment['amount'], $payment['account'], $payment['method'],
                    'Counter payment');
            }

            $order->refresh();

            AuditLogger::log('orders', 'pos_sale', $order,
                sprintf('Counter sale %s — %s from %s', $order->order_number, Money::format($total), $order->customer_name),
                new: [
                    'customer' => $order->customer_name,
                    'items' => count($lines),
                    'total' => $total,
                    'paid' => (float) $order->paid_amount,
                    'due' => $order->due_amount,
                ]);

            return $order->load('items');
        });
    }

    /**
     * The customer behind the sale: an existing record, a new one from the name
     * and phone typed at the till, or nobody at all for a walk-in.
     *
     * @param  array<string, mixed>  $data
     */
    protected function resolveCustomer(array $data): ?Customer
    {
        if (! empty($data['customer_id'])) {
            $customer = Customer::find($data['customer_id'])
                ?? throw new RuntimeException('That customer no longer exists. Search for them again.');

            return $customer;
        }

        if (blank($data['customer_name'] ?? null)) {
            return null;
        }

        // The same matching the website uses, so a phone never ends up on two records.
        return $this->customers->findOrCreateForCheckout(
            null,
            (string) $data['customer_name'],
            $data['customer_phone'] ?? null,
        );
    }

    /**
     * Price each line from the live variant and check it can be sold.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function priceLines(array $rows): array
    {
        $rows = array_values(array_filter($rows, fn ($row) => (int) ($row['variant_id'] ?? 0) > 0 && (int) ($row['quantity'] ?? 0) > 0));

        if (! $rows) {
            throw new RuntimeException('Scan or search for at least one product to sell.');
        }

        $variants = ProductVariant::with('product')->whereIn('id', array_column($rows, 'variant_id'))->get()->keyBy('id');
        $lines = [];

        foreach ($rows as $row) {
            $variant = $variants->get((int) $row['variant_id']);
            $product = $variant?->product;

            if (! $variant || ! $product) {
                throw new RuntimeException('One of the scanned products no longer exists.');
            }

            if (! $variant->is_active || $product->trashed() || ! $product->is_active) {
                throw new RuntimeException($variant->full_name . ' is not on sale any more. Remove it from the sale.');
            }

            $quantity = (int) $row['quantity'];

            // The same barcode scanned twice is one line, so stock leaves once per unit.
            if (isset($lines[$variant->id])) {
                $lines[$variant->id]['quantity'] += $quantity;
                $lines[$variant->id]['subtotal'] = round($lines[$variant->id]['quantity'] * $lines[$variant->id]['price'], 2);

                continue;
            }

            $price = round((float) $variant->current_price, 2);

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
     * Check the split before anything is recorded: every part must be a real
     * active account, and together they cannot come to more than the sale.
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
                ?? throw new RuntimeException('Choose the account each payment goes into.');

            if (! $account->is_active) {
                throw new RuntimeException($account->name . ' is inactive. Choose another account.');
            }

            $taken = round($taken + $amount, 2);
            $payments[] = ['amount' => $amount, 'account' => $account, 'method' => (string) ($row['method'] ?? 'cash')];
        }

        if ($taken > $total) {
            throw new RuntimeException('The payment (' . Money::format($taken) . ') is more than the '
                . Money::format($total) . ' total. Give the difference back as change instead of recording it.');
        }

        return $payments;
    }
}
