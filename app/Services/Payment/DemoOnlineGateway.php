<?php

namespace App\Services\Payment;

use App\Models\Order;
use Illuminate\Support\Facades\URL;

/**
 * Stand-in for a hosted gateway (SSLCommerz, bKash, Stripe Checkout).
 *
 * It records a pending payment and sends the customer to a local page that
 * mimics the gateway's confirm/cancel screen. Swapping in a real gateway means
 * replacing initiate() with the provider's session-create call and pointing the
 * customer at the URL it returns; the callback routes already handle the rest.
 */
class DemoOnlineGateway implements PaymentGateway
{
    public function key(): string
    {
        return 'online';
    }

    public function label(): string
    {
        return 'Online Payment (demo)';
    }

    public function description(): string
    {
        return 'Card / mobile banking sandbox. No real money moves until a live gateway key is configured.';
    }

    public function initiate(Order $order): ?string
    {
        $payment = $order->payments()->create([
            'gateway' => $this->key(),
            // Only what is still owed, in case part was already paid another way.
            'amount' => $order->due_amount,
            'method' => 'online',
            'status' => 'pending',
            'transaction_id' => 'DEMO-' . strtoupper(bin2hex(random_bytes(5))),
        ]);

        // Signed and short-lived: the callback routes reject anything else.
        return URL::temporarySignedRoute(
            'payment.demo.show',
            now()->addMinutes(30),
            ['order' => $order->order_number, 'payment' => $payment->id],
        );
    }
}
