<?php

namespace App\Services\Payment;

use App\Support\Settings;
use InvalidArgumentException;

class PaymentManager
{
    /** @var array<string, PaymentGateway> */
    protected array $gateways = [];

    public function __construct()
    {
        foreach ([new CashOnDelivery, new DemoOnlineGateway] as $gateway) {
            $this->gateways[$gateway->key()] = $gateway;
        }
    }

    /**
     * Gateways offered at checkout: registered and enabled in Admin → Settings.
     *
     * @return array<string, PaymentGateway>
     */
    public function all(): array
    {
        $enabled = (array) Settings::get('payment_methods', []);

        return array_filter($this->gateways, fn (string $key) => in_array($key, $enabled, true), ARRAY_FILTER_USE_KEY);
    }

    public function keys(): array
    {
        return array_keys($this->all());
    }

    /** Any registered gateway, enabled or not, so existing orders can still retry payment. */
    public function get(string $key): PaymentGateway
    {
        return $this->gateways[$key] ?? throw new InvalidArgumentException("Unknown payment gateway [{$key}].");
    }
}
