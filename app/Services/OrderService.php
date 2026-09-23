<?php

namespace App\Services;

use App\Mail\OrderPlaced;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

class OrderService
{
    public function __construct(
        protected CartService $cart,
        protected StockService $stock,
        protected CustomerService $customers,
    ) {}

    /**
     * Turn the current cart into an order.
     *
     * Stock is re-checked and decremented inside the transaction (StockService
     * locks each product and variant) so two people racing for the last unit
     * cannot both win.
     *
     * @param  array<string, mixed>  $data  Validated checkout fields.
     */
    public function placeFromCart(array $data): Order
    {
        $cart = $this->cart->current();

        if ($cart->items->isEmpty()) {
            throw new RuntimeException('Your cart is empty.');
        }

        // Never charge a price the customer did not see: if anything changed since
        // it was added to the cart, update the cart and let them review it first.
        if ($this->cart->reprice($cart)) {
            throw new RuntimeException('Some prices in your cart have changed since you added them. Please review your cart and place the order again.');
        }

        $totals = $this->cart->totals();

        return DB::transaction(function () use ($cart, $totals, $data) {
            $customer = $this->customers->findOrCreateForCheckout(
                Auth::user(),
                $data['customer_name'],
                $data['customer_phone'],
                $data['customer_email'],
                $data['shipping_address'],
                $data['shipping_city'] ?? null,
            );

            $order = Order::create([
                'user_id' => Auth::id(),
                'customer_id' => $customer->id,
                'customer_name' => $data['customer_name'],
                'customer_email' => $data['customer_email'],
                'customer_phone' => $data['customer_phone'],
                'shipping_address' => $data['shipping_address'],
                'shipping_city' => $data['shipping_city'] ?? null,
                'note' => $data['note'] ?? null,
                'subtotal' => $totals['subtotal'],
                'discount' => $totals['discount'],
                'shipping_cost' => $totals['shipping'],
                'total' => $totals['total'],
                'coupon_code' => $totals['coupon']?->code,
                'payment_method' => $data['payment_method'],
                'status' => 'pending',
                'payment_status' => 'unpaid',
                // The stock leaves with the order, so returns and cancellations know there is something to put back.
                'stock_taken_at' => now(),
            ]);

            foreach ($cart->items as $item) {
                $variant = ProductVariant::with('product')->find($item->variant_id);
                $product = $variant?->product;

                if (! $variant || ! $variant->is_active || ! $product || $product->trashed() || ! $product->is_active) {
                    throw new RuntimeException('A product in your cart is no longer available.');
                }

                $order->items()->create([
                    'product_id' => $product->id,
                    'variant_id' => $variant->id,
                    'product_name' => $product->name,
                    'sku' => $variant->sku,
                    'variant_label' => $product->has_variants ? $variant->label : null,
                    'price' => $item->price,
                    'unit_cost' => $variant->effective_cost,
                    'quantity' => $item->quantity,
                    'subtotal' => round((float) $item->price * $item->quantity, 2),
                ]);

                // Online customers can never oversell, whatever the negative-stock setting says.
                $this->stock->move($variant, 'out', $item->quantity, 'sale', null, $order, allowNegative: false);
            }

            // Claim the coupon use atomically so two checkouts cannot both take the last one.
            if ($coupon = $totals['coupon']) {
                $claimed = Coupon::whereKey($coupon->id)
                    ->where(fn ($q) => $q->whereNull('usage_limit')->orWhereColumn('used_count', '<', 'usage_limit'))
                    ->increment('used_count');

                if (! $claimed) {
                    throw new RuntimeException('Coupon ' . $coupon->code . ' has just reached its usage limit. Remove it from your cart to continue.');
                }
            }

            $order->statusHistories()->create([
                'from_status' => null,
                'to_status' => 'pending',
                'user_id' => Auth::id(),
                'note' => 'Order placed online',
            ]);

            $this->cart->clear();

            return $order->load('items');
        });
    }

    /** Confirmation mail is best-effort: a mail outage must not lose the order. */
    public function sendConfirmation(Order $order): void
    {
        try {
            Mail::to($order->customer_email)->send(new OrderPlaced($order));
        } catch (\Throwable $e) {
            Log::warning('Order confirmation mail failed', [
                'order' => $order->order_number,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Put stock back when an order is cancelled.
     *
     * Only an order whose goods actually left has anything to give back: an
     * advance order cancelled before delivery never took stock, so restocking
     * it would invent units that were never sold.
     */
    public function restock(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $locked = Order::with('items')->lockForUpdate()->findOrFail($order->id);

            if (! $locked->hasStockLeft()) {
                return;
            }

            foreach ($locked->items as $item) {
                $variant = $item->variant_id ? ProductVariant::withTrashed()->find($item->variant_id) : null;

                if ($variant) {
                    $this->stock->move($variant, 'in', $item->quantity, 'order_cancelled', null, $locked,
                        $item->unit_cost !== null ? (float) $item->unit_cost : null, allowNegative: true);
                }
            }

            // Cleared so a second cancellation, however it arrives, puts nothing back twice.
            $locked->update(['stock_taken_at' => null]);
            $order->setAttribute('stock_taken_at', null)->syncOriginalAttribute('stock_taken_at');
        });
    }

    /**
     * Take the goods off the shelf for an order that has not had them yet.
     *
     * This is the delivery half of an advance order: the booking took the money
     * up front, and the stock only moves when the customer actually gets the
     * goods. Called from OrderStatusService when the order reaches `delivered`.
     */
    public function fulfil(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $locked = Order::with('items')->lockForUpdate()->findOrFail($order->id);

            // Already taken (an online or counter sale, or a repeated call): nothing to do.
            if ($locked->hasStockLeft()) {
                return;
            }

            foreach ($locked->items as $item) {
                $variant = $item->variant_id ? ProductVariant::withTrashed()->find($item->variant_id) : null;

                if (! $variant) {
                    throw new RuntimeException($item->product_name . ' no longer exists, so it cannot be handed over. '
                        . 'Remove the product from the order or restore it first.');
                }

                // allowNegative stays null: delivery follows Settings → "Allow negative stock",
                // so a shop that refuses to oversell cannot hand over goods it does not have.
                $this->stock->move($variant, 'out', $item->quantity, 'advance_delivery',
                    'Advance order ' . $locked->order_number, $locked,
                    $item->unit_cost !== null ? (float) $item->unit_cost : null);
            }

            $locked->update(['stock_taken_at' => now()]);
            $order->setAttribute('stock_taken_at', $locked->stock_taken_at)->syncOriginalAttribute('stock_taken_at');
        });
    }
}
