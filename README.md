# Matching

A women's clothing store and admin panel built on Laravel 12, Blade, Tailwind CSS 4 and Alpine.js.

The demo catalogue carries 24 styles across six categories — **Saree, Salwar Kameez, Kurti & Tops,
Lehenga & Gown, Abaya & Borka, Hijab & Shawl** — priced in BDT for a Bangladeshi shop.

## Features

**Storefront**
- Home page with categories, featured styles, deals and new arrivals
- Catalogue with search, category filter, price range, in-stock / on-sale filters and six sort orders
- Product page with image gallery, stock state, quantity picker, related products and reviews
- Guest cart that survives login (cookie-token based) and merges into the account cart
- Coupon codes (percent or fixed, minimum order, cap, usage limit, date window)
- Checkout with Cash on Delivery or an online gateway, free delivery over Tk 3,000
- Order confirmation email, order history, order cancellation (returns stock), wishlist, reviews
- Account settings: profile, default delivery address, password change

**Admin panel** (`/admin`)
- Dashboard: revenue (all time and this month), order counts, low stock, best sellers
- Products: CRUD, main image + gallery upload, sale price, stock, featured/visible flags
- Categories: CRUD with image and sort order
- Orders: search and filter, detail view, fulfilment and payment status updates (cancelling restocks)
- Coupons: CRUD

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate

# point DB_DATABASE at a database named `matching` (or edit .env), then:
php artisan migrate --seed
php artisan storage:link

npm install
npm run build      # or: npm run dev
```

Serve it through WAMP (document root `public/`) or with `php artisan serve`.

### Demo accounts

| Role     | Email                    | Password |
|----------|--------------------------|----------|
| Admin    | admin@matching.test      | password |
| Customer | customer@matching.test   | password |

Seeded coupons: `WELCOME10` (10% off, min Tk 1,000, max Tk 500) and `EID500` (Tk 500 off, min Tk 4,000).

### Product photos

Demo photos live in `database/seeders/images/` and are copied onto the public disk by the seeder, so
`migrate:fresh --seed` always rebuilds the same catalogue. They come from [Unsplash](https://unsplash.com)
(free for commercial use, no attribution required) and are placeholders — replace them with your own
studio shots from **Admin → Products → Edit**, or drop new files into that folder using the same
filenames and re-seed.

## Payments

Payment gateways implement `App\Services\Payment\PaymentGateway` and are registered in
`App\Services\Payment\PaymentManager`:

- `CashOnDelivery` — no redirect, order starts unpaid.
- `DemoOnlineGateway` — records a pending payment and sends the customer to a local sandbox
  confirm/cancel screen.

To go live with SSLCommerz, bKash or Stripe, add a class implementing the same interface, return the
provider's hosted-payment URL from `initiate()`, and register it in `PaymentManager`. The existing
`payment.demo.success` / `payment.demo.fail` routes show the callback shape the order expects.

## Email

`MAIL_MAILER=log` by default, so order confirmations land in `storage/logs/laravel.log`. Point the
mail config at SMTP to send for real. Mail failures are logged and never block an order.

## Tests

```bash
php artisan test
```

`tests/Feature/ShopFlowTest.php` covers browsing, cart quantity capping, sale pricing, coupons,
guest and online checkout, stock races, cart merging on login, review permissions, order
cancellation and admin access control.
