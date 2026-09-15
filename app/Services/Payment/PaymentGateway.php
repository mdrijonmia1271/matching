<?php

namespace App\Services\Payment;

use App\Models\Order;

interface PaymentGateway
{
    /** Value stored in orders.payment_method. */
    public function key(): string;

    public function label(): string;

    public function description(): string;

    /**
     * Start payment for an order.
     *
     * Return a URL to send the customer to (hosted gateway pages), or null when
     * nothing more is needed and the order can go straight to confirmation.
     */
    public function initiate(Order $order): ?string;
}
