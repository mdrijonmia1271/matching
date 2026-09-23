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

        // Home page hero; edited under Admin -> Settings -> Hero section.
        // Up to three pictures; the hero fades through them. Empty falls back
        // to the newest featured product's picture.
        'hero_images' => [],
        'hero_title' => 'Fashion are unique',
        'hero_subtitle' => 'Trending winter collection',
        'hero_offer_enabled' => true,
        'hero_offer_kicker' => 'Up to',
        'hero_offer_value' => '50',
        'hero_offer_suffix' => '%',
        'hero_offer_off' => 'Off',
        'hero_offer_label' => 'On new arrivals',
        'hero_offer_button' => 'Shop now',
        'hero_offer_link' => '',

        // The four counters under the categories, edited under
        // Admin -> Settings -> Stats strip. A tile reads its figure from the
        // catalogue unless its source is `manual`, and then `value` is printed.
        'home_stats_enabled' => true,
        'home_stats' => [
            ['label' => 'Product', 'icon' => 'box', 'source' => 'products', 'value' => ''],
            ['label' => 'Followers', 'icon' => 'users', 'source' => 'customers', 'value' => ''],
            ['label' => 'Monthly Sales', 'icon' => 'chart', 'source' => 'sales', 'value' => ''],
            ['label' => 'Happy Customers', 'icon' => 'user', 'source' => 'rating', 'value' => ''],
        ],
    ],

    /* Where a stats tile gets its figure from. */
    'home_stat_sources' => [
        'products' => 'Products in the catalogue',
        'customers' => 'Registered customers',
        'sales' => 'Completed orders',
        'rating' => 'Happy customers (from review scores)',
        'manual' => 'Fixed text I type myself',
    ],

    /* Marks drawn in home.blade.php for a stats tile. */
    'home_stat_icons' => [
        'box' => 'Box',
        'users' => 'People',
        'chart' => 'Chart',
        'user' => 'Person',
    ],

    /*
     * Logos for the "You can pay by" block in the footer, in the order shown.
     * `key` picks a mark drawn in partials/payment-mark.blade.php, so the block
     * looks right out of the box. Drop a real logo at public/images/payments/<image>
     * and that file is used instead — no code change needed.
     */
    'payment_badges' => [
        ['key' => 'visa', 'name' => 'Visa', 'image' => 'visa.svg'],
        ['key' => 'nagad', 'name' => 'Nagad', 'image' => 'nagad.svg'],
        ['key' => 'bkash', 'name' => 'bKash', 'image' => 'bkash.svg'],
        ['key' => 'amex', 'name' => 'American Express', 'image' => 'amex.png'],
        ['key' => 'mastercard', 'name' => 'Mastercard', 'image' => 'mastercard.svg'],
        ['key' => 'rocket', 'name' => 'Rocket', 'image' => 'rocket.svg'],
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
