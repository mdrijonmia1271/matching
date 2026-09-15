<?php

namespace App\Services\Payment;

use App\Models\Order;

class CashOnDelivery implements PaymentGateway
{
    public function key(): string
    {
        return 'cod';
    }

    public function label(): string
    {
        return 'Cash on Delivery';
    }

    public function description(): string
    {
        return 'Pay with cash when the courier hands over your parcel.';
    }

    public function initiate(Order $order): ?string
    {
        return null;
    }
}
