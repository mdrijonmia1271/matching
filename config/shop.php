<?php

/*
|--------------------------------------------------------------------------
| Store settings defaults
|--------------------------------------------------------------------------
|
| Admin → Settings overrides these values (stored in the `settings` table).
| The type of each default decides how the stored value is cast back:
| bool, int, float, array or string.
|
*/

return [
    'defaults' => [
        'store_name' => env('APP_NAME', 'Matching'),
        'store_phone' => '01812-345678',
        'store_email' => 'support@matching.test',
        'store_address' => '',
        'store_logo' => '',
        'currency_symbol' => 'Tk',

        'order_prefix' => 'MT',
        'delivery_charge' => 60.0,
        'free_delivery_threshold' => 3000.0,

        'low_stock_threshold' => 5,
        'allow_negative_stock' => false,

        'payment_methods' => ['cash', 'bkash', 'nagad', 'bank', 'cod', 'online'],
        // Account (by code) that receives online gateway payments.
        'online_payment_account' => 'bank',
        'invoice_note' => 'Thank you for shopping with us.',
    ],

    /* Payment methods the store can accept. `cod` and `online` are storefront checkout options. */
    'payment_methods' => [
        'cash' => 'Cash',
        'bkash' => 'bKash',
        'nagad' => 'Nagad',
        'bank' => 'Bank transfer',
        'card' => 'Card',
        'cod' => 'Cash on delivery (online orders)',
        'online' => 'Online payment gateway',
    ],
];
