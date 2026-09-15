# Progress — Matching admin panel upgrade

Last updated: 2026-09-16

Laravel 12 · Blade · Tailwind 4 · Alpine.js · MySQL 8.4 (WAMP). No rebuild: existing architecture is extended.

**Safety backups** (in `storage/app/backups/`):
- `matching-db-20260915-024856.sql`: full MySQL dump before any change
- `matching-code-20260915-024856.tar.gz`: code snapshot (excludes vendor, node_modules, storage)
- `matching-db-20260915-035317.sql`: before Step 4 and the timezone shift
- `matching-db-20260915-040722.sql`: before Step 6 (orders)
- `matching-db-20260915-042932.sql`: before Step 7 (payments and accounts)
- `matching-db-20260916-011232.sql`: before Step 8 (customers)

The project is not a git repository. Consider running `git init` and making a first commit before continuing.

**Test status:** 65 tests pass (621 assertions). `npm run build` succeeds. All 11 new migrations are applied to the live MySQL database. New admin pages were smoke-tested on MySQL (all return 200).

---

## 1. Completed

### Step 1: Data integrity foundation
- **Storage engine:** MyISAM → InnoDB for all tables; new tables are forced to InnoDB.
- **Foreign keys:** 16 created. Payments, stock movements and product → category are RESTRICT.
- **Status columns:** ENUMs replaced with strings.
- **Stock columns:** made signed.
- **Products are soft-deleted:** "Archive" plus "Restore".

### Step 2: Security, roles and permissions, settings, audit log
- **Payment callbacks:** signed, idempotent, can't revive a cancelled order.
- **Login and accounts:** login throttling; deactivated accounts blocked; `is_admin` not mass-assignable.
- **Other storefront security:** guest confirmation protected; throttles; security headers; coupon percent ≤ 100.
- **Roles and permissions:** 5 roles and 25 permissions, checked on the server; staff/role screens with escalation protection.
- **Settings** (DB-backed, cached), **activity log** with viewer.

### Step 3: Catalogue, variants, SKU and subcategories
- **Variants:** per-variant size, colour, SKU, barcode, prices, stock, alert level.
- **Products:** brand, subcategory, purchase price, tags, new arrival.
- **Existing data:** default variants and a reconciled stock ledger.
- **Product form:** variant builder, auto SKU, barcode validation; never changes existing stock.
- **Storefront:** colour/size picker; checkout re-checks prices; order items snapshot variant, SKU and unit cost.

### Step 4: Barcodes and timezone
- **Timezone:** Asia/Dhaka, with existing timestamps shifted +6h (owner approved).
- **Barcodes:** `picqer/php-barcode-generator` (EAN-13 / Code 128).
- **Barcode labels screen:** scan/search, quantities, 38×25 / 50×30 roll or A4 24-up, generate missing in-store EAN-13 codes.

### Step 5: Inventory screens
- **Stock overview:** status tabs, filters, sorting, value at cost, CSV export.
- **Stock count:** records only the counted − shown difference, so sales made during the count are kept.
- **Stock movement details page** and **stock history CSV.**

### Step 6: Order lifecycle
- **Statuses:** pending → confirmed → processing → packed → shipped → delivered, plus cancelled.
- **Allowed transitions** are enforced by `OrderStatusService`: locked, re-checked, history row plus audit entry, restock and coupon use restored on cancel.
- **Returned and refunded** are reserved for the returns/refunds workflow.
- **Customers** can cancel until packed.
- **Coupons:** use claimed atomically at checkout.
- **Admin order page:** allowed next statuses with a note, courier, tracking number, staff-only admin note, status timeline.
- **Order list:** status tabs and search by tracking number.

### Step 7: Payments and accounts
- **Accounts** (Admin → Finance → Accounts):
  - Cash, Bank, bKash and Nagad created by migration; more can be added (e.g. City Bank, Rocket) with type, account number and opening balance.
  - **Balance** = opening balance + money in − money out, always calculated from the ledger.
  - An account can be deactivated, except the one that receives online payments.
- **Ledger (`account_transactions`):** every amount moving in or out, with type, account, reference (order), linked payment, user, time and note.
  - **Append-only:** the model refuses edits and deletes; corrections are new entries.
- **Money in / money out** entries (a note is required) and **transfers between accounts** (for example cash deposited at the bank).
  - Recorded as a linked pair; can't take out more than the balance; both accounts are locked in id order so opposite transfers can't deadlock.
- **Account page:** balance, opening balance, money in and out for the filter, transaction list (type, direction, dates, note search), CSV export.
- **Order payments:**
  - "Record a payment" on the admin order page: amount (defaults to the due amount), method (cash, bKash, Nagad, bank, card — as enabled in Settings), receiving account (pre-selected per method), transaction ID and note.
  - **Partial payments** are allowed; paying more than the amount due, zero or negative amounts, and payments on cancelled orders are rejected.
  - **Payments table** gains method, account, received by, paid at and note; each successful payment has a matching ledger entry.
  - **Orders** gain `paid_amount` and `refunded_amount`, plus a due amount shown on admin and customer pages.
  - **Payment status is derived, not typed:** unpaid, partially paid, paid, partially refunded, refunded, payment failed (`PaymentService::recalculate`).
  - **Online gateway:** success posts the payment into the account chosen in Settings → "Online gateway payments go into" (default Bank). Replays post nothing. The gateway only charges the amount still due.
  - **Permissions:** recording needs `accounting.create` or `pos.sell` (sales staff at the counter); accounts need `accounting.view`, `accounting.create` for money in/out and transfers, `accounting.edit` for account settings, and `reports.export` for CSV.
  - **Manual payment-status form and route removed.**
- **Backfill (owner approved):** the one existing paid order (MT-260903-D1UNV) got a Tk 41,100 cash payment dated when it was marked paid, posted to Cash. Verified: Cash balance Tk 41,100; other orders paid Tk 0.

### Step 8: Customers
- **`customers` table:**
  - Fields: name, phone, email, address, city, customer group (Retail / Wholesale / VIP), opening due, staff notes, optional online account (`user_id`, one customer per account).
  - Soft-deleted ("Archive" plus "Restore").
- **Phone rules:**
  - Phone is unique when present.
  - Always stored as 01XXXXXXXXX via `App\Support\Phone::normalise`: +880 / 880 / 00880 prefixes, spaces and dashes are removed; non-BD numbers keep their digits.
  - Normalised by a model mutator and before validation, so "+880 1712-345678" counts as a duplicate of 01712345678.
- **`orders.customer_id`** (RESTRICT). Orders keep their own typed name, phone and address.
- **Checkout** (`CustomerService::findOrCreateForCheckout`, inside the order transaction):
  - **Matching:** logged-in buyers are matched by account; guests by phone.
  - **Registration:** when a guest registers and buys with the same phone, their guest record becomes the account's record.
  - **Phones are never moved:** a phone that already belongs to another account's customer stays with that customer; the new record is saved without it.
  - **Existing details are never overwritten**; only blank fields are filled.
  - **Archived customers** who buy again are restored.
  - If two checkouts race to create the same new phone, the second retries once and links to the first one's record.
- **Admin → Sales → Customers:**
  - **List:** search by name, email or phone (any format); filter by group, "with due", archived; sort by newest, name, last order, total spent or highest due.
  - **List columns:** orders, spent, paid, due, last order.
  - **Stat cards:** customers, customers with due, total due.
- **Profile page:**
  - **Summary cards:** total orders; total spent (confirmed → delivered orders); total paid (net of refunds); current due (opening due + unpaid confirmed orders); last order.
  - **"What is owed":** opening due plus outstanding orders, oldest first.
  - **Lists:** paginated orders and payments (method, account, transaction ID, receiver).
  - Contact details, linked account and notes.
  - Returns will be added when returns exist.
- **Other screens:**
  - **Create / edit** with audit-log entries (`customers` module: created, updated, archived, restored).
  - **Admin order page:** "View profile →" link to the customer.
- **Permissions:** `customers.view` (list and profile), `customers.create`, `customers.edit` (edit, archive, restore).
- **Totals** come from SQL subqueries (`Customer::scopeWithTotals`, `scopeWithDue`), so sorting and filtering happen in the database.
- **Backfill (owner approved 2026-09-16):**
  - Registered accounts with orders become one customer each (Demo Customer: 3 orders, phone 01800000000).
  - Guest orders are matched by phone when the name also matches.
  - Guest order MT-260914-Q4ERD (Md Rijon Mia) used Demo Customer's phone, so it became a separate customer **without a phone**, with a note asking staff to confirm the real number.
  - Verified on MySQL: 2 customers, 0 orders without a customer; Demo Customer spent Tk 64,100, paid Tk 41,100, due Tk 23,000.

---

## 2. In progress

Nothing is half-finished. Steps 1–8 are complete, tested and migrated.

**Next up is Step 9, customer due payments.**

---

## 3. Remaining work (in priority order)

**P1**
- **Step 9, Customer due / ledger:** payments against due allocated oldest order first (reuse `PaymentService::record` per order), opening due, full history, due report.
- **Step 10, Suppliers and supplier ledger:** supplier profile, opening due, supplier payments as separate `account_transactions` (type `supplier_payment`), totals.
- **Step 11, Purchases:**
  - Number, supplier, items per variant, quantity, purchase price, discount, additional cost, total, paid, due, status.
  - Receiving increases stock with unit cost (weighted average updates `product_variants.cost_price`) and posts to the supplier balance, all in one transaction.
  - "Print labels for this purchase" link.
- **Step 12, POS / quick sale:**
  - Scan (reuse `admin.variants.search`), variant, quantity, discount.
  - Customer (or create a new one).
  - Split payment across accounts, change due, invoice.
  - Order `channel` = pos; uses `StockService`, `OrderStatusService` and `PaymentService`.
- **Step 13, Returns:**
  - Linked to the original order; per-item quantity (≤ purchased − already returned) and reason; approve → receive.
  - RESTOCK (return_restock) or DAMAGED (return in + damaged out).
  - Status `returned` through `OrderStatusService` (add a system-only transition path).
- **Step 14, Refunds:**
  - Cannot exceed paid − refunded.
  - Posts an out `refund` transaction.
  - Updates `refunded_amount` and derived payment status; `refunded` status when fully refunded.

**P2**
- **Expenses:** categories (Rent, Salary, Electricity, Internet, Packaging, Delivery, Facebook Ads, Marketing, Website, Office, Other), account (posts an `expense` transaction), attachment on the private disk.
- **Profit and loss:** revenue, COGS from `order_items.unit_cost`, gross profit, expenses, net profit; online / POS / combined.
- **Reports** (date range, search, filters, CSV via `App\Support\CsvExport`): sales, profit, expense, purchase, inventory, stock movement, product sales (per variant), category sales, customer, supplier, return, payment.
- **Dashboard rebuild:** today / 7 days / 30 days / this month / last month / custom range; charts; revenue based on real payments and sale statuses.

**P3**
- Invoice print page
- Bulk operations: activate/deactivate, category, price, stock, export
- Notifications (status-change SMS/email)
- Password reset
- Custom error pages
- Queued confirmation email
- Admin 2FA (optional)

---

## 4. Important files changed

**New**
- **Config:** `config/permissions.php`, `config/shop.php`
- **Support:** `app/Support/Permissions.php`, `Settings.php`, `SkuGenerator.php`, `Barcode.php`, `BarcodeRenderer.php`, `CsvExport.php`, `Phone.php`
- **Services:** `app/Services/SettingsRepository.php`, `AuditLogger.php`, `ProductService.php`, `OrderStatusService.php`, `AccountService.php`, `PaymentService.php`, `CustomerService.php`
- **Models:** `app/Models/Role.php`, `RolePermission.php`, `Setting.php`, `ActivityLog.php`, `ProductVariant.php`, `Brand.php`, `OrderStatusHistory.php`, `Account.php`, `AccountTransaction.php`, `Customer.php`
- **Middleware:** `app/Http/Middleware/SecurityHeaders.php`
- **Admin controllers** (`app/Http/Controllers/Admin/`): `StaffController`, `RoleController`, `SettingController`, `ActivityLogController`, `BrandController`, `VariantLookupController`, `BarcodeLabelController`, `InventoryController`, `AccountController`, `OrderPaymentController`, `CustomerController`
- **Views** (`resources/views/admin/`): `staff/{index,form}`, `roles/{index,form}`, `settings/edit`, `activity/index`, `brands/{index,edit}`, `barcodes/{index,print}`, `inventory/{index,count}`, `stock/show`, `accounts/{index,show,edit,_row}`, `customers/{index,show,form}`
- **Tests:** `tests/Feature/SecurityAndPermissionsTest.php`, `VariantCatalogueTest.php`, `BarcodeLabelTest.php`, `InventoryScreensTest.php`, `OrderLifecycleTest.php`, `FinanceTest.php`, `CustomerTest.php`

**Modified**
- **Config and bootstrap:** `composer.json` / `composer.lock` (picqer), `config/app.php` (Asia/Dhaka), `config/database.php` (InnoDB), `bootstrap/app.php`, `app/Providers/AppServiceProvider.php`, `routes/web.php`
- **Models:** `User` (customer relation), `Product`, `Category`, `Order` (statuses, transitions, tracking, paid/refunded/due, payment status labels and colours, `customer_id` and customer relation), `OrderItem`, `CartItem`, `StockMovement`, `Payment` (method, account, receiver, paid_at, note)
- **Services:** `StockService`, `CartService`, `OrderService` (links the customer at checkout), `Payment/PaymentManager`, `Payment/DemoOnlineGateway` (charges the due amount)
- **Controllers:**
  - Storefront and auth: `PaymentController` (settles via `PaymentService`), `CheckoutController`, `CartController`, `ShopController`, `HomeController`, `OrderController`, `ReviewController`, `Auth/AuthenticatedSessionController`
  - Admin: all existing admin controllers; `Admin/OrderController` (status and details; payment form removed; loads customer); `Admin/SettingController` (online payment account); `Admin/StockController`; `Admin/DashboardController`
- **Other:** `Mail/OrderPlaced`, `Http/Middleware/EnsureUserIsAdmin`, `app/Support/Money.php`
- **Views:** `layouts/admin` (Catalogue, Inventory and Finance sections; Customers under Sales), `layouts/app`, `home`, `shop/show`, `components/product-card`, `cart/index`, `checkout/index`, `checkout/success`, `orders/{index,show}`, `payment/demo`, `emails/order-placed`, `admin/dashboard`, `admin/products/{index,form}`, `admin/categories/{index,form}`, `admin/stock/{index,create}`, `admin/orders/{index,show}` (show: customer profile link), `admin/settings/edit`
- **Seeder and tests:** `database/seeders/DatabaseSeeder.php`, `tests/TestCase.php`, `tests/Feature/ShopFlowTest.php`, `tests/Feature/StockManagementTest.php`

---

## 5. Database changes (all applied to MySQL)

| Migration | Change |
|---|---|
| `2026_09_15_000001_convert_tables_to_innodb` | MyISAM → InnoDB for all tables (irreversible by design) |
| `2026_09_15_000002_harden_foreign_keys` | Adds the 16 FKs; RESTRICT on payments, stock_movements.product_id, products.category_id |
| `2026_09_15_000003_relax_enums_and_add_product_soft_deletes` | ENUM → string on status/type columns; signed stock columns; `products.deleted_at` |
| `2026_09_15_000004_create_roles_and_permissions` | `roles`, `role_permissions`; `users.role_id`, `is_active`, `last_login_at`; seeds 5 roles |
| `2026_09_15_000005_create_settings_table` | `settings` (key/value) |
| `2026_09_15_000006_create_activity_logs_table` | `activity_logs` |
| `2026_09_15_000007_add_variants_brands_and_subcategories` | `brands`, `product_variants`; `categories.parent_id`; product brand/subcategory/cost/tags/flags; `variant_id` on cart/order items and stock movements; default-variant backfill and opening-balance reconciliation |
| `2026_09_15_000008_shift_stored_times_to_dhaka` | +6h on every `timestamp`/`datetime` column (MySQL only; reversible) |
| `2026_09_15_000009_add_order_status_history_and_tracking` | `order_status_histories`; orders admin note, courier, tracking number, milestone timestamps; history backfilled |
| `2026_09_15_000010_create_accounts_and_payment_ledger` | `accounts` (seeded Cash, Bank, bKash, Nagad); `account_transactions`; payments `method`, `account_id`, `received_by`, `paid_at`, `note`; orders `paid_amount`, `refunded_amount`; paid-order backfill (1 payment + 1 ledger entry) |
| `2026_09_16_000001_create_customers_table` | `customers` (unique phone and user_id, soft deletes); `orders.customer_id` FK (RESTRICT); customer backfill from orders (reversible: `down` drops both) |

**Live data after the latest migration (2026-09-16):**
- 2 users, 4 orders, **2 customers** (every order linked).
- **Owner activity on 2026-09-15/16, after Step 7:**
  - MT-260914-Q4ERD confirmed → packed → shipped → delivered, with a Tk 1,560 cash payment.
  - Tk 500 transferred from Cash to Bank.
  - Product edits, a stock-in of 10, two stock counts, product "Polo Shirt" archived, brand "Polo" created.
- **Money:** 2 payments and 4 account transactions; Cash Tk 42,160, Bank Tk 500 (41,100 + 1,560 − 500).

---

## 6. Known bugs / risks still open

1. **Dashboard revenue** still sums order totals for sale statuses (including unpaid cash-on-delivery orders) and ignores refunds. It is not profit. *P2 dashboard rebuild.*
2. **No real online gateway.** `DemoOnlineGateway` is a sandbox; a live gateway (SSLCommerz, bKash) must verify provider callbacks and use `PaymentService::settleGatewayPayment`.
3. **Existing products have no purchase price** (`cost_price` null), so stock value excludes them and past order items have `unit_cost` null. Profit for historic orders will be incomplete until purchase prices are entered.
4. **No returns or refunds from the admin yet.** `returned` / `refunded` statuses and `refunded_amount` exist but are only set by the future returns/refunds steps. A parcel returned by the courier currently has no correct path.
5. **Account opening balances are all 0.** Enter the real cash-in-hand and bank/bKash/Nagad balances from Accounts → Edit, otherwise balances show only what was recorded in the system.
6. **Leftover `paid_at` values:** orders MT-260901-MLRRQ (cancelled) and MT-260901-6GVGH (processing) have `paid_at` set although unpaid, from the old manual toggle. Harmless (the paid amount and status are correct), but reports must not use `paid_at` alone. Left untouched to avoid changing data without approval.
7. **Test database differs from production.** Tests run on SQLite; MySQL-only migrations (InnoDB, FK hardening, timezone shift) are skipped there. New pages were smoke-tested on MySQL by hand.
8. **`.env` is set for local development:** `APP_DEBUG=true`, `SESSION_ENCRYPT=false`, `MAIL_MAILER=log`. Must change for production (also `SESSION_SECURE_COOKIE=true` under HTTPS).
9. **Order confirmation email** is sent synchronously; no customer notifications on status changes yet.
10. **Label printing:** physical alignment depends on the printer driver's paper size and 100% scale.
11. **IDE warnings** (Intelephense "undefined method auth()->…", stale "undefined type/constant" right after edits) are false positives; PHP runs and tests pass.
12. **Customer Md Rijon Mia has no phone.** Their order used 01800000000, which belongs to Demo Customer and looks like a test number. Staff should enter the real number from Customers → Edit.
13. **Customer due has no payment path of its own yet.** Opening due can be entered, but it can't be collected until Step 9; order dues are still paid from each order's page.
14. **Guest phone matching is strict at checkout:** a guest typing an existing customer's phone is linked to that customer, even if the name differs. This is the standard shop rule; only the one-off backfill kept different names apart.

---

## 7. Exact next steps

1. **Step 9, Customer due payments.** Back up the DB first.
   - **Migration:** a `customer_payments` table (customer, amount, method, account, reference, note, received_by, paid_at). It is the receipt for one "collect due" action; the individual order payments link back to it through a nullable `payments.customer_payment_id`.
   - **Opening due:** needs its own ledger because `PaymentService::record` requires an order.
     - Record the amount applied in `customer_payments.opening_due_paid` (or a `customer_due_entries` table).
     - Post an `in` account transaction of type `customer_due_payment` with the Customer as reference.
     - Keep `customers.opening_due` as the original amount; current due = opening − opening paid + order dues. Update `Customer::scopeWithTotals` / `scopeWithDue` to match.
   - **`CustomerService::collectDue(customer, amount, account, method, note, reference)`:**
     - Runs in one transaction with the customer row locked.
     - Allocates the payment to the opening due first (the oldest debt), then to outstanding orders oldest first (same query as the profile's "What is owed"), calling `PaymentService::record` for each order.
     - Rejects an amount above the total due.
     - Writes an audit entry.
   - **Admin screens:**
     - A "Collect payment" form on the customer profile (permission `customers.edit`, plus `accounting.create` or `pos.sell` like order payments) that shows the planned allocation.
     - A due history tab (opening due, order dues, collections).
     - A due report page listing customers with due and ageing (oldest unpaid order date), with CSV (`reports.export`).
   - **Tests:** allocation order, partial allocation, over-payment rejected, ledger and balances, opening due, permissions.
2. Then suppliers → purchases → POS → returns → refunds (Steps 10–14 above).

**For each step:** back up the DB, write migrations that only add data or are reversible (explain any data backfill to the owner first), run tests, run `php artisan migrate`, smoke-test pages on MySQL, verify counts and balances, then update this file.
