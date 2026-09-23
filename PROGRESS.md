# Progress — Matching admin panel upgrade

Last updated: 2026-09-19

Laravel 12 · Blade · Tailwind 4 · Alpine.js · MySQL 8.4 (WAMP). No rebuild: existing architecture is extended.

**Safety backups** (in `storage/app/backups/`):
- `matching-db-20260915-024856.sql`: full MySQL dump before any change
- `matching-code-20260915-024856.tar.gz`: code snapshot (excludes vendor, node_modules, storage)
- `matching-db-20260915-035317.sql`: before Step 4 and the timezone shift
- `matching-db-20260915-040722.sql`: before Step 6 (orders)
- `matching-db-20260915-042932.sql`: before Step 7 (payments and accounts)
- `matching-db-20260916-011232.sql`: before Step 8 (customers)
- `matching-db-20260916-031011.sql`: before Step 9 (customer due payments)
- `matching-db-20260916-032820.sql`: before Step 10 (suppliers)
- `matching-db-20260917-step11.sql`: before Step 11 (purchases)
- `matching-db-20260917-step12.sql`: before Step 12 (POS)
- `matching-db-20260917-step13.sql`: before Step 13 (returns)
- `matching-db-20260917-step14.sql`: before Step 14 (refunds)
- `matching-db-20260919-advance.sql`: before advance orders

The project is a git repository now (branch `main`), but Steps 8–14 are not committed yet.

**Test status:** 128 tests pass (1,307 assertions). `npm run build` succeeds. All new migrations (newsletter, advance orders) are applied to the live MySQL database. New admin pages were smoke-tested on MySQL (all return 200).

**Owner instruction (2026-09-16, standing):** after finishing each step, get the owner's confirmation before starting the next one.

---

## 1. Completed

### Step 1: Data integrity foundation
- **Storage engine:** MyISAM → InnoDB for all tables; new tables are forced to InnoDB.
- **Foreign keys:** 16 created. Payments, stock movements and product → category are RESTRICT.
- **Status columns:** ENUMs replaced with strings.
- **Stock columns:** made signed.
- **Products are soft-deleted:** "Archive" plus "Restore" (Products → status filter "Archived" → Restore).

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
- **Ledger (`account_transactions`):** every amount moving in or out, with type, account, reference (order or due receipt), linked payment, user, time and note.
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
  - **Summary cards:** total orders; total spent (confirmed → delivered orders); total paid (net of refunds); current due; last order.
  - **"What is owed":** opening due plus outstanding orders, oldest first.
  - **Lists:** paginated orders and payments (method, account, transaction ID, receiver).
  - Contact details, linked account and notes.
  - Returns will be added when returns exist.
- **Other screens:**
  - **Create / edit** with audit-log entries (`customers` module: created, updated, archived, restored).
  - **Admin order page:** "View profile →" link to the customer.
- **Permissions:** `customers.view` (list and profile), `customers.create`, `customers.edit` (edit, archive, restore).
- **Totals** come from SQL subqueries (`Customer::scopeWithTotals`, `scopeWithDue`, `scopeSearch`), so sorting and filtering happen in the database.
- **Backfill (owner approved 2026-09-16):**
  - Registered accounts with orders become one customer each (Demo Customer: 3 orders, phone 01800000000).
  - Guest orders are matched by phone when the name also matches.
  - Guest order MT-260914-Q4ERD (Md Rijon Mia) used Demo Customer's phone, so it became a separate customer **without a phone**, with a note asking staff to confirm the real number.
  - Verified on MySQL: 2 customers, 0 orders without a customer; Demo Customer spent Tk 64,100, paid Tk 41,100, due Tk 23,000.

### Step 9: Customer due payments
- **`customer_payments` table:** one receipt (shown as CP-00001) per "collect due" action.
  - Fields: customer, amount, amount applied to the opening due (`opening_due_paid`), method, receiving account, transaction ID, note, received by, paid at.
  - **`payments.customer_payment_id`** (nullable, RESTRICT) links the order payments a receipt created back to it.
- **Allocation** (`CustomerService::allocate`):
  - The opening due is paid first (it is the oldest debt), then unpaid accepted orders (`Order::SALE_STATUSES`), oldest first (`Customer::unpaidOrders`).
  - Pending and cancelled orders are never touched.
- **Collecting** (`CustomerService::collectDue`, one transaction):
  - The customer row is locked, so two cashiers can't apply the same due twice.
  - Rejected: zero or negative amounts, more than the total due, customers with no due, archived customers, inactive accounts.
  - **Opening-due part:** an `in` ledger entry of type `customer_payment` ("Customer due payment"), referencing the receipt.
  - **Each order part:** an ordinary order payment via `PaymentService::record` (so paid amount, payment status and a `sale_payment` ledger entry all update exactly as for a payment taken on the order page), linked to the receipt.
  - One audit entry (`customers` / `due_collected`) lists the split; each order payment also keeps its own audit entry.
- **Due formula:** current due = opening due − opening due collected + unpaid amounts on accepted orders. `customers.opening_due` keeps the original amount; the remaining opening due is calculated (`Customer::openingDueRemaining`, `opening_due_remaining` column in `scopeWithTotals`).
- **Opening due edits:** the opening due can't be lowered below what was already collected against it.
- **Customer profile:**
  - **"Collect payment" form:** amount (defaults to the total due), method, account (pre-selected per method), transaction ID, note, with a live preview of where the money will go.
  - **"What is owed":** the opening row shows original, collected and remaining.
  - **"Due collections":** each receipt with method, account, reference and what it was applied to.
- **Admin → Sales → Customer dues** (report):
  - **Cards:** total due and number of customers; ageing buckets — opening due, 0–30, 31–60, 61–90 and over 90 days (by each unpaid order's date).
  - **Table:** customer, group, phone, opening due, order due, total due, oldest unpaid order with days unpaid, last payment.
  - **Filters and sorting:** search, group, "unpaid over 30/60/90 days"; sort by highest due, oldest unpaid or name.
  - **CSV export.**
- **Accounts:** ledger rows for due receipts link to the customer ("CP-00001 · name").
- **Permissions:**
  - Collecting needs `customers.edit` and also `accounting.create` or `pos.sell` (Super Admin, Manager and Sales Staff by default; the Accountant role lacks `customers.edit`).
  - The report needs `customers.view`; its CSV needs `reports.export`.
- No existing data changed (the migration only adds a table and an empty column).

### Step 10: Suppliers and supplier payments
- **`suppliers` table:**
  - Fields: contact name, company, phone, email, address, opening due (what the shop owed before this system), staff notes.
  - Soft-deleted ("Archive" plus "Restore"); there is no separate active flag.
  - Phone normalised with `Phone` and unique when present.
- **`supplier_payments` table:** one row per payment (shown as SP-00001).
  - Fields: supplier, amount, method, account paid from, transaction/cheque number, note, paid by, paid at.
- **Balance** (what the shop owes) is calculated, never typed: opening due − payments.
  - Calculated by `Supplier::balance()`, and as the `current_balance` column in `scopeWithTotals`; `scopeOwed` filters suppliers with a balance.
  - Received purchases will be added to this formula in Step 11.
  - The opening due can't be lowered below what was already paid.
- **Paying** (`SupplierService::pay`, one transaction):
  - Locks the supplier, then the account.
  - Rejected: zero or negative amounts, more than the supplier balance (so no advance payments yet), more than the account holds ("Only Tk X is available in Cash"), inactive accounts, archived suppliers.
  - **Ledger:** posts an `out` entry of type `supplier_payment` referencing the payment.
  - **Audit:** writes a `purchases` / `supplier_paid` entry with the balance after payment.
  - **Methods:** cash, bKash, Nagad, bank transfer, card (`SupplierService::methods`); the account is pre-selected per method.
- **Admin → Purchases → Suppliers** (new Purchases section in the nav):
  - **List:** search by name, company, email or phone (any format); filter "We owe" and archived; sort by newest, name, highest balance or last payment.
  - **List columns:** opening due, paid, balance, last payment.
  - **Stat cards:** suppliers, suppliers we owe, total payable.
  - **CSV export.**
- **Profile page:**
  - **Summary cards:** opening due, total paid (with count), balance, last payment.
  - **Payments list** with receipt, method → account, reference, note and payer.
  - **"Pay supplier" form:** defaults to the full balance; the account dropdown shows each account's current balance.
  - Contact details and notes.
- **Other screens:**
  - **Create / edit / archive / restore**, with audit entries (`supplier_created`, `supplier_updated`, `supplier_archived`, `supplier_restored`).
  - **Accounts:** ledger rows for supplier payments link to the supplier ("SP-00001 · name").
- **Permissions:**
  - **Suppliers:** `purchases.view` (list and profile), `purchases.create` (add), `purchases.edit` (edit, archive, restore). CSV needs `reports.export` as well.
  - **Paying:** needs `purchases.edit` and `accounting.create`, so only Super Admin and Manager can pay by default. Warehouse Staff can manage suppliers but not pay them; the Accountant can view suppliers but not pay them.
- No existing data changed (the migration only adds two tables). Live database: 0 suppliers at the time; the owner has added 1 since.

### Step 11: Purchases
- **`purchases` table:** number (PU-260917-XXXX), supplier (RESTRICT), status, purchase date, supplier invoice no., subtotal, discount, additional cost, total, note, created by, received by, received at, cancelled at.
- **`purchase_items` table:** purchase (cascade, so editing replaces the lines), product and variant (RESTRICT), quantity, unit cost, line total, landed unit cost.
- **Statuses:** draft → ordered → received, or cancelled. Draft and ordered are "open": only those can be edited or cancelled. Received is final.
- **Nothing moves until the goods are received.** A purchase is a plan; stock and the supplier balance are untouched while it is open.
- **Landed cost** (`PurchaseService::landedCosts`): unit cost plus the line's share of the additional cost, minus its share of the discount, split by line value. This is what goes into stock and into the average cost — never the raw typed price.
- **Receiving** (`PurchaseService::receive`, one transaction):
  - The purchase row is locked first, so a double click or two people clicking at once cannot stock the goods in twice.
  - Each item is stocked in through `StockService::move` (type `in`, reason `purchase_received`, landed unit cost, the purchase as reference), so every unit leaves a stock movement behind.
  - **`product_variants.cost_price` is updated by weighted average:** (old stock × old cost + qty × landed cost) ÷ (old stock + qty). Stock at or below zero, or no cost yet, simply takes the landed cost. Old cost falls back to the product's cost price.
  - Status → received with the receiver and time, plus an audit entry.
  - **Optional "pay now"** calls `SupplierService::pay` inside the same transaction, so an account that cannot cover the payment rolls the whole receive back (nothing is stocked in).
- **Editing and cancelling:** `save` and `cancel` both refuse anything that is not open. Editing rewrites the lines whole; the server always recalculates subtotal, discount and total, and refuses a discount larger than the goods. The same variant typed twice becomes one line.
- **Supplier balance is now opening due + received purchases − payments** (`Supplier::balance`, `scopeWithTotals` with `purchases_total` / `purchases_count`, `scopeOwed`, and the "total payable" stat). Only received purchases count.
- **Admin → Purchases → Purchases:**
  - **List:** search by number, supplier invoice or supplier; filter by status, supplier and date range; sort by newest, date, highest total or supplier.
  - **Stat cards:** purchases and value for the current filters, plus how many are not received yet.
  - **CSV export.**
- **Create / edit form:** supplier, date, supplier invoice, stage, note; variant search through `admin.variants.search` (barcode scan, SKU or name) with quantity and purchase price per line, and live totals.
- **Purchase page:** items with unit and landed cost, totals, the stock movements the receive created, a receive form with the optional pay-now field, and **"Print labels for this purchase"** (barcode labels pre-filled with one label per unit received; `BarcodeLabelController` now accepts `qty[variant id]`).
- **Supplier profile:** a "Total purchased" card, the last 10 purchases, and a "New purchase" button. The supplier list and CSV gained a "Purchased" column.
- **Permissions:** `purchases.view` (list and page), `purchases.create` (new), `purchases.edit` (edit, receive, cancel); CSV also needs `reports.export`, and **pay-now also needs `accounting.create`** — Warehouse Staff can receive goods but the pay-now field is hidden and refused for them.
- No existing data changed (the migration only adds two tables). Live database: 0 purchases.

---

### Step 12: POS / quick sale
- **Owner decisions (2026-09-17):** a counter sale is created as **delivered** (the customer walks out with the goods), and the till **follows Settings → "Allow negative stock"** (currently off, so it refuses to oversell).
- **`orders.channel`** (`online` / `pos`, default online, indexed). All 4 existing orders became `online`. `customer_email` and `shipping_address` are now nullable, because a walk-in has neither.
- **A counter sale is an ordinary order:** the same stock ledger, payment rows, account ledger and customer due. Only `channel`, the status it starts in, and `payment_method = pos` differ.
- **`App\Services\PosService::sell`,** one transaction:
  - **Prices always come from the variant** (`current_price`, so a sale price applies), never from the form. A whole-sale discount is allowed and cannot exceed the goods.
  - Refuses anything not on sale: inactive variants, archived or inactive products.
  - The same barcode scanned twice becomes one line, so stock leaves once per unit.
  - Stock out through `StockService::move` (reason `pos_sale`), `allowNegative` left null so the setting decides. Online checkout still blocks overselling whatever the setting says.
  - Order created as **delivered** with `confirmed_at` and `delivered_at` stamped, plus one status-history row ("Sold at the counter") and a `pos_sale` audit entry.
  - `unit_cost` is snapshotted per item, so POS sales carry their cost into future profit reports.
- **Customer:** an existing record, a new one created through `CustomerService::findOrCreateForCheckout` (so a phone never lands on two records), or nobody at all for a walk-in.
- **Split payment:** up to 5 parts, each with its own method and receiving account, recorded through `PaymentService::record` so every part gets its own ledger entry.
  - **The split is checked before anything is written**, so a bad account never leaves a half-recorded sale.
  - **More than the total is refused** — the difference is change, not money to record.
  - **Change is worked out in the browser only.** The "cash handed over" box posts nothing.
- **Unpaid and part-paid counter sales** leave a due on the customer. `delivered` is a sale status, so the due appears in Customer dues and is collected exactly like any other (Step 9).
  - **A walk-in cannot leave money owing** — there is no record to collect it from. Enforced in the service, not just the screen.
- **The counter screen** (Admin → Sales → Counter (POS)): barcode/SKU/name scan with enter-to-add, live cart with stock warnings, customer search showing any existing due, discount, split payment, change, and a note.
- **Invoice / receipt** (`admin.orders.invoice`, printable A4): store details, customer, items, totals, payments, due and the invoice note from Settings. Works for **every** order, not only POS; linked from the admin order page and opened straight after a sale.
- **Counter sales are never offered online payment.** `Order::canPayOnline()` guards the customer's "Pay now" buttons, and `payment_method_label` replaced the old "cod or else Online" guesses across admin and storefront views.
- **Permissions:** the counter needs `pos.sell` (Super Admin, Manager and Sales Staff by default — the Accountant and Warehouse Staff cannot sell). The invoice needs `orders.view`.
- No existing data changed beyond `channel` being filled in as `online`.

---

### Step 13: Returns
- **`order_returns` table** (named that because `RETURNS` is a reserved word in MySQL): number (RT-260917-XXXX), order (RESTRICT), customer, status, reason, note, `refund_total`, who requested / approved / received it, and the time of each.
- **`return_items` table:** return (cascade), order item (RESTRICT), variant, quantity, condition, unit price, line total.
- **Requested → approved → received, or rejected.** Rejection is only possible while the goods are still out.
- **A return records goods, never money.** `refund_total` is only what the returned goods sold for; nothing touches an account. Refunds are Step 14.
- **Quantity cap:** per order line, quantity ≤ bought − already returned. The order row is locked and the cap re-checked inside the transaction, so two people cannot book the same unit twice.
  - **A requested return already holds its claim,** so a second return can only take what is left.
  - **Rejecting releases the claim,** and those units can come back on a later return.
  - **Good and damaged units of one line are separate lines but share one cap.**
- **Receiving** (`ReturnService::receive`, one transaction):
  - **Good units:** stock in, reason `return_restock`, at the cost snapshotted on the order item.
  - **Damaged units:** stock in with `return_restock`, then straight out with `damaged`. The level ends up unchanged, but **both legs are in the ledger, so the write-off is visible** instead of never appearing.
  - Receiving twice is refused; so is receiving something not yet approved.
- **The order becomes `returned` only when every unit is back.** A partial return leaves the order as it was, because part of it still stands.
  - This needed a **system-only transition path** (`Order::SYSTEM_TRANSITIONS`, `OrderStatusService::transition(..., system: true)`): staff still cannot pick `returned` from a menu, but the workflow that has actually moved the goods can set it. `returned → refunded` is registered there too, ready for Step 14.
- **Counter sales can be returned** like any other order: a POS sale is `delivered`, which is one of the statuses a return may start from. An order still `pending` or `cancelled` is refused — nothing went out to come back.
- **Screens:**
  - **Admin → Sales → Returns:** list with search (return, order, customer) and filters by status and reason; cards for open returns, returns waiting for goods, and the value received back.
  - **Return page:** items with condition, approve / reject / receive, the stock movements the receive created, and a progress trail of who did what and when.
  - **Admin order page:** a "Return items" form showing exactly how many of each line can still come back, plus a list of that order's returns.
  - **Customer profile:** the returns list Step 8 left a place for.
- **Permissions:** `orders.view` to see, `orders.update` to raise, approve and reject (Super Admin, Manager, Sales Staff and Warehouse Staff — the Accountant cannot), and **`inventory.adjust` to receive**, because that is a stock change. Sales Staff can raise a return but cannot put the goods back on the shelf.
- No existing data changed (the migration only adds two tables).

---

### Step 14: Refunds
- **`refunds` table:** number (RF-260917-XXXX), order (RESTRICT), return (nullable, RESTRICT), amount, method, account paid from, reference, note, who refunded it and when. `orders.refunded_amount` already existed from Step 7; this adds the rows behind it.
- **A refund records money, a return records goods.** A refund may be linked to the return that caused it, but does not have to be — money can also go back on an order where no goods ever moved.
- **`App\Services\RefundService::refund`,** one transaction:
  - Locks the order, then the account (nothing locks them the other way, so it cannot deadlock).
  - **Cap: amount ≤ paid − already refunded,** re-read under the lock, so two people refunding at once cannot both slip past it.
  - Refuses more than the account holds, inactive accounts, zero or negative amounts, an order that was never paid, and a return belonging to a different order.
  - Posts an **`out` ledger entry of type `refund`** referencing the refund, so the money really leaves a named account.
  - Updates `orders.refunded_amount`, then `PaymentService::recalculate` derives **partially refunded** / **refunded**. The payment status is still never typed by hand.
  - Audit entry under `orders` / `refund_issued`.
- **The order status becomes `refunded` only when the money is all back *and* the goods are too** — which is exactly what `returned` means, so the system transition runs from `returned → refunded` (registered in Step 13).
  - **A refund on an order that was never returned leaves the status alone.** A fully refunded but undelivered-back order stays `delivered` with payment status `refunded`: **the order status tracks goods, the payment status tracks money.** They are deliberately not the same thing.
- **Screens:**
  - **Admin order page:** a "Refunds" card listing every refund (amount, method, account, who, reference, linked return) and a "Give money back" form defaulting to the full refundable amount, with account balances shown and an optional link to a received return.
  - **Return page:** refunds made against that return, and — once the goods are received and money is still refundable — a link through to the order's refund form.
  - **Accounts ledger:** refund rows link back to their order ("RF-00001 · MT-…"), like due receipts and supplier payments.
- **Permissions:** `orders.refund` **plus** `accounting.create`, because money leaves an account. That means Super Admin, Manager and Accountant; Sales Staff and Warehouse Staff cannot refund and never see the form.
- No existing data changed (the migration only adds a table).

### Newsletter subscriptions (2026-09-19, outside the numbered plan)
Asked for on top of the P1 plan, so the P2 step numbers below are unchanged.
- **`newsletter_subscribers` table:** unique `email`, `source` (default `home`), `ip_address`, `subscribed_at`, `unsubscribed_at`. Nothing else was touched.
- **Storefront:** the home page newsletter form was a decoration — a GET to the shop page. It is now `POST /newsletter` (`throttle:10,1`), validated, with the email lowercased and trimmed before saving.
  - **A repeat sign-up is not an error and not a duplicate:** an address already on the list is told so, an address that had left is switched back on.
  - The result renders **beside the form** (named error bag `newsletter`, session key `newsletter_status`) and the redirect carries the `#newsletter` fragment, so the visitor stays where they were instead of being thrown to the page-top flash strip.
- **Admin → Sales → Newsletter:** counts (on the list, new this month, unsubscribed), search by email, status and sort filters, per-row Unsubscribe / Resubscribe and Delete, and a CSV export that follows the filters.
  - **Unsubscribe keeps the row** (`unsubscribed_at`), so the history survives; Delete is the only thing that really removes an address.
  - Toggles and deletes write to the activity log under module `newsletter`.
- **Permissions:** `marketing.manage` for the screen (Super Admin and Manager, same as coupons), `reports.export` on top for the CSV. No new permission key, so no role changes were needed.
- **Not built:** no email is actually sent yet — the list is collected, nothing mails it. No public unsubscribe link, so a request to leave is handled by staff from the admin screen.

### Product gallery picker (2026-09-19, outside the numbered plan)
- **Drag and drop, or click to choose.** The plain file input is now a dashed drop box (the input itself is `sr-only`, so keyboard and screen readers still get a real labelled field). Dropping images on it works the same as picking them.
- **Up to six gallery images in one go, with previews under the field.** Chosen files draw a thumbnail each (with its name) directly below the drop box, every one with its own **×**. Removing one rebuilds the input's `FileList` (`DataTransfer`), so what is previewed is exactly what is posted.
- **Files are added to the selection, not swapped for it.** A second drop or pick appends, so six images can arrive in one go or a few at a time. Non-images, files already chosen and anything past the limit are skipped, and the line under the box says which and why.
- **The cap is on the gallery, not on one upload.** `gallery|max:6` only limited a single submit, so a product with six images could take six more. The rule now counts what the product already has and says how much room is left; the counter under the field says the same thing before the form is sent.
- **`Product::MAX_GALLERY_IMAGES`** holds the number, used by the rule, the label and the picker.
- **New images are ordered after the existing ones.** `sort_order` used to restart at 0 on every upload, so an edit gave duplicate positions; the whole gallery is now renumbered from 0 on every save, with the new uploads last.
- **The main image is previewed too, on both forms.** Choosing a file shows it straight away (brand-coloured ring, "New — replaces the current one when you save"); on edit the current image shows until then, and a **×** drops the new choice and brings the saved one back. Nothing is uploaded until the product is saved.
- **Saved images and new files share one grid** under the drop box, so the gallery reads as a single run of tiles rather than two blocks. The new ones are marked by a brand-coloured number badge and their file name; the saved ones are plain. The Images card gives the gallery the wide half of the row (`sm:grid-cols-[200px_1fr]`), so all six tiles fit side by side.
- **Thumbnails are dragged into the order the shop shows them in.** Saved images and new files are two separate lists — one is rows in the database, the other files not uploaded yet — and each reorders on its own; the new ones always follow the saved ones. Every tile carries its position number, plus ← → buttons, because dragging is not reachable from the keyboard.
- **The saved order travels as hidden `image_order[]` fields** on the product form, so reordering is part of saving the product, not a separate request. `ProductController::syncGallery` writes `sort_order` from that list, puts anything the form did not mention after it, then the new uploads. The ids are looked up **through the product's own images**, so an id belonging to another product is ignored rather than stolen.
- `shop/show` reads `$product->images`, which is ordered by `sort_order`, so the order set here is what the shop gallery shows.

### Advance orders (2026-09-19, outside the numbered plan)
Bookings: the customer pays something now and takes the goods later. Backup before the change: `matching-db-20260919-advance.sql`.

- **An advance order is an ordinary order with `channel = advance`,** not a separate table. That is what keeps payments, the account ledger, customer dues, the invoice, status history, returns, refunds and every report working on it without being rebuilt. It is created as **`confirmed`**, so it counts as a sale and whatever is unpaid is a real due.
- **The one real difference is when stock moves.** Online and counter sales take stock as they are created; an advance order takes it **when it is delivered**, because the goods may not be in the shop yet. Booking an out-of-stock item is allowed and is the whole point.
- **`orders.stock_taken_at`** records that difference for every order instead of it being guessed from the channel and status. Everything that touches stock reads it:
  - `OrderService::fulfil` — takes the goods off the shelf (`advance_delivery` movement), called from `OrderStatusService` when the order reaches `delivered`. It follows Settings → "Allow negative stock", so a shop that refuses to oversell cannot hand over goods it has not got.
  - `OrderService::restock` — **only restocks an order whose goods actually left**, and clears the flag afterwards. This was a real bug waiting to happen: cancelling a booking would otherwise have invented units that were never sold. It now also locks the order, so two cancellations cannot both put stock back.
  - `ReturnService::request` — refuses a return on an order that has not been handed over yet ("cancel it instead"): nothing has gone out to come back.
  - Existing rows were backfilled `stock_taken_at = created_at` for everything except cancelled orders, whose stock has already gone back — so no order can be restocked twice. Checked on the live database: 6 of 8 orders stamped, both cancelled ones left null.
- **The price is typed in, not read off the variant.** A booking is a deal struck with the customer, often before today's price is set. The variant's current price is the default; a blank falls back to it, zero is allowed, negative is not. (This is the deliberate difference from the till, where the price always comes from the variant.)
- **A customer is required.** The rest of the money is owed until the goods are handed over, and a walk-in cannot be chased for it.
- **Admin → Sales → Advance orders:** counts (waiting, past the expected date, booked value, advance held), filters (waiting / with money owed / overdue / handed over / cancelled / all), search, date range, CSV export, and a booking form with the same product and customer lookup as the till, per-line agreed price, expected date, and a split advance payment.
- The order page itself does the rest: an **Advance order** badge, a banner saying the goods are still on the shelf, and the usual status buttons — `delivered` is what moves the stock.
- **Permissions:** `orders.view` to see the list, new **`orders.advance`** to take one (money is involved), `reports.export` for the CSV. The migration grants it to Manager and Sales Staff, which matches the config presets; Accountant and Warehouse Staff cannot book.
- **Not built:** a booking cannot be edited after it is taken (change it by cancelling and re-booking), and there is no reminder when the expected date passes beyond the count on the screen.

---
## 2. In progress

Nothing is half-finished. **Steps 1–14 are complete, tested and migrated — the whole P1 plan is done.**

**Waiting for the owner's confirmation before starting the P2 work (expenses, profit and loss, reports, dashboard).**

---

## 3. Remaining work (in priority order)

**P1**
**P2**
- **Expenses:** categories (Rent, Salary, Electricity, Internet, Packaging, Delivery, Facebook Ads, Marketing, Website, Office, Other), account (posts an `expense` transaction), attachment on the private disk.
- **Profit and loss:** revenue, COGS from `order_items.unit_cost`, gross profit, expenses, net profit; online / POS / combined.
- **Reports** (date range, search, filters, CSV via `App\Support\CsvExport`): sales, profit, expense, purchase, inventory, stock movement, product sales (per variant), category sales, customer, supplier, return, payment.
- **Dashboard rebuild:** today / 7 days / 30 days / this month / last month / custom range; charts; revenue based on real payments and sale statuses.

**P3**
- Printable due receipt (the order invoice / receipt page landed with Step 12)
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
- **Services:** `app/Services/SettingsRepository.php`, `AuditLogger.php`, `ProductService.php`, `OrderStatusService.php`, `AccountService.php`, `PaymentService.php`, `CustomerService.php` (checkout matching, due allocation and collection), `SupplierService.php`, `PurchaseService.php`, `PosService.php`, `ReturnService.php`, `RefundService.php`
- **Models:** `app/Models/Role.php`, `RolePermission.php`, `Setting.php`, `ActivityLog.php`, `ProductVariant.php`, `Brand.php`, `OrderStatusHistory.php`, `Account.php`, `AccountTransaction.php`, `Customer.php`, `CustomerPayment.php`, `Supplier.php`, `SupplierPayment.php`, `Purchase.php`, `PurchaseItem.php`, `OrderReturn.php`, `ReturnItem.php`, `Refund.php`
- **Middleware:** `app/Http/Middleware/SecurityHeaders.php`
- **Admin controllers** (`app/Http/Controllers/Admin/`): `StaffController`, `RoleController`, `SettingController`, `ActivityLogController`, `BrandController`, `VariantLookupController`, `BarcodeLabelController`, `InventoryController`, `AccountController`, `OrderPaymentController`, `CustomerController`, `CustomerPaymentController`, `CustomerDueController`, `SupplierController`, `SupplierPaymentController`, `PurchaseController`, `PosController`, `ReturnController`, `RefundController`
- **Views** (`resources/views/admin/`): `staff/{index,form}`, `roles/{index,form}`, `settings/edit`, `activity/index`, `brands/{index,edit}`, `barcodes/{index,print}`, `inventory/{index,count}`, `stock/show`, `accounts/{index,show,edit,_row}`, `customers/{index,show,form,dues}`, `suppliers/{index,show,form}`, `purchases/{index,show,form}`, `pos/index`, `orders/invoice`, `returns/{index,show}`
- **Tests:** `tests/Feature/SecurityAndPermissionsTest.php`, `VariantCatalogueTest.php`, `BarcodeLabelTest.php`, `InventoryScreensTest.php`, `OrderLifecycleTest.php`, `FinanceTest.php`, `CustomerTest.php`, `CustomerDueTest.php`, `SupplierTest.php`, `PurchaseTest.php`, `PosTest.php`, `ReturnTest.php`, `RefundTest.php`

**Modified**
- **Config and bootstrap:** `composer.json` / `composer.lock` (picqer), `config/app.php` (Asia/Dhaka), `config/database.php` (InnoDB), `bootstrap/app.php`, `app/Providers/AppServiceProvider.php`, `routes/web.php`
- **Models:** `User` (customer relation), `Product`, `Category`, `Order` (statuses, transitions, tracking, paid/refunded/due, payment status labels and colours, `customer_id` and customer relation), `OrderItem`, `CartItem`, `StockMovement`, `Payment` (method, account, receiver, paid_at, note)
- **Services:** `StockService`, `CartService`, `OrderService` (links the customer at checkout), `Payment/PaymentManager`, `Payment/DemoOnlineGateway` (charges the due amount)
- **Controllers:**
  - Storefront and auth: `PaymentController` (settles via `PaymentService`), `CheckoutController`, `CartController`, `ShopController`, `HomeController`, `OrderController`, `ReviewController`, `Auth/AuthenticatedSessionController`
  - Admin: all existing admin controllers; `Admin/OrderController` (status and details; payment form removed; loads customer); `Admin/SettingController` (online payment account); `Admin/StockController`; `Admin/DashboardController`
- **Other:** `Mail/OrderPlaced`, `Http/Middleware/EnsureUserIsAdmin`, `app/Support/Money.php`
- **Views:** `layouts/admin` (Catalogue, Inventory and Finance sections; Customers and Customer dues under Sales; Purchases section with Suppliers), `layouts/app`, `home`, `shop/show`, `components/product-card`, `cart/index`, `checkout/index`, `checkout/success`, `orders/{index,show}`, `payment/demo`, `emails/order-placed`, `admin/dashboard`, `admin/products/{index,form}`, `admin/categories/{index,form}`, `admin/stock/{index,create}`, `admin/orders/{index,show}` (show: customer profile link), `admin/accounts/_row` (due receipt and supplier payment links), `admin/settings/edit`
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
| `2026_09_16_000002_create_customer_payments_table` | `customer_payments` (due receipts); `payments.customer_payment_id` FK (RESTRICT); no data changes (reversible) |
| `2026_09_16_000003_create_suppliers_tables` | `suppliers` (unique phone, soft deletes); `supplier_payments` (FKs to suppliers and accounts RESTRICT, users null on delete); no data changes (reversible) |
| `2026_09_17_000001_create_purchases_tables` | `purchases` (unique number, supplier RESTRICT); `purchase_items` (purchase cascade, product and variant RESTRICT); no data changes (reversible) |
| `2026_09_17_000002_add_pos_channel_to_orders` | `orders.channel` (online / pos, existing rows online); `customer_email` and `shipping_address` made nullable (reversible: `down` refills blanks first) |
| `2026_09_17_000003_create_order_returns_tables` | `order_returns` (unique number, order and customer RESTRICT); `return_items` (return cascade, order item and variant RESTRICT); no data changes (reversible) |
| `2026_09_17_000004_create_refunds_table` | `refunds` (unique number; order, return and account RESTRICT, user null on delete); no data changes (reversible) |

**Live data after the latest migration (2026-09-17):**
- 2 users, **5 orders** (4 online, 1 counter sale), 2 customers, 0 due receipts, 1 supplier, 1 supplier payment, 1 purchase, 0 returns.
- **Owner activity on 2026-09-15/16, after Step 7:**
  - MT-260914-Q4ERD confirmed → packed → shipped → delivered, with a Tk 1,560 cash payment.
  - Tk 500 transferred from Cash to Bank.
  - Product edits, a stock-in of 10, two stock counts, brand "Polo" created; product "Polo Shirt" archived, then restored and archived again, and is currently **restored**.
  - 2026-09-16 01:39–01:44: category "testtt" created, and a Tk 100 money out of Cash with the note "sdd" (both look like tests).
- **Owner activity on 2026-09-17, using Step 11:** supplier "Rijon Islam" (opening due Tk 800); purchase **PU-260917-CRP9** — 10 × Polo Shirt at Tk 500, less Tk 10 discount, plus Tk 90 transport = **Tk 5,080**, received, with Tk 900 paid from Cash.
  - Verified correct: landed cost Tk 508/unit, Polo Shirt stock 7 → 17, `cost_price` set to Tk 508 (the variant had no cost before, so the landed cost applies), supplier balance 800 + 5,080 − 900 = **Tk 4,980**.
- **Owner activity on 2026-09-17, using Step 12:** counter sale **MT-260917-0IKWC5** — 3 × Polo Shirt at Tk 1,500, less Tk 200 discount = **Tk 4,300**, paid in full in cash, walk-in, delivered.
  - Verified correct: `channel` = pos, payment status paid, one cash ledger entry, and `unit_cost` Tk 508 carried through from the Step 11 purchase — so purchase → landed cost → sale → profit basis works end to end on live data.
- **Money:** 3 order payments, 1 supplier payment, 7 account transactions; **Cash Tk 45,460** (41,100 + 1,560 − 500 − 100 − 900 + 4,300), Bank Tk 500, bKash Tk 0, Nagad Tk 0 (verified after the Step 13 migration; every change is the owner's own trading, not a migration).
- **Customer dues:** Demo Customer owes Tk 23,000 (order MT-260901-6GVGH, processing, unpaid).

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
13. **Guest phone matching is strict at checkout:** a guest typing an existing customer's phone is linked to that customer, even if the name differs. This is the standard shop rule; only the one-off backfill kept different names apart.
14. **Due collections can't be reversed yet.** A mistaken receipt needs a correcting entry; there is no "void receipt" action (the ledger is append-only by design). Add one with refunds (Step 14) if needed.
15. **No printable due receipt yet** (planned with the invoice print page, P3).
16. **The accountant role can't collect customer dues by default** (it lacks `customers.edit`). Give it that permission from Roles if the accountant should take due payments.
17. **Possible test entries in live data:** a Tk 100 "Money out" from Cash with the note "sdd" (2026-09-16 01:44) and a category named "testtt". If they were tests, delete the category, and cancel the Tk 100 with a matching "Money in" to Cash (the ledger is append-only, so entries are corrected, never deleted).
18. **Suppliers still can't be paid in advance.** A payment can never exceed the supplier balance, and the balance only rises when a purchase is *received*. So goods paid for before they arrive have to be received first, or the payment recorded afterwards. Add an "advance" option if the shop really pays up front.
19. **Only Super Admin and Manager can pay suppliers by default.** Paying needs `purchases.edit` + `accounting.create`; the Accountant lacks `purchases.edit` and Warehouse Staff lack `accounting.create`. Adjust in Roles if needed.
20. **Supplier payments can't be voided.** Like due receipts, a mistaken payment needs a correcting entry (money in to the account) and a note; there is no reverse action yet.
21. **A received purchase cannot be undone.** Receiving is final by design: it stocks goods in and bills the supplier. A wrong receive has to be corrected by hand (a stock-out with a note, plus a correcting ledger entry). A proper "supplier return" belongs with the returns work in Step 13.
22. **Purchases do not carry their own paid / due figures.** Money is tracked against the supplier, not the individual purchase: pay-now records an ordinary supplier payment. A per-purchase due column would need purchase-level allocation, like customer dues.
23. **Weighted average cost changes the value of stock already on the shelf.** Receiving at a higher price raises `cost_price` for existing units too, so stock value and past-order profit shift slightly. This is normal weighted-average costing, but it means profit reports are not exact for goods bought before the system.
24. **Landed cost is split by line value, not by weight or volume.** Transport on one heavy cheap item is spread as if it cost the same to move as a light expensive one. Split the purchase in two if that matters.
25. **A counter sale cannot be cancelled by staff.** It is created as `delivered`, which is a final status, so a mis-rung sale has no undo. Correct it by hand for now (stock in with a note, plus a correcting ledger entry); the proper path is returns and refunds, Steps 13–14.
26. **No cash-drawer session or X/Z report.** There is no "open the till with Tk 5,000, close it and count" workflow. Cash taken at the counter lands straight in the Cash account; reconcile it with Accounts → money in / money out for now.
27. **The counter has no on-screen product grid.** Everything goes through the scan/search box, which suits a barcode scanner but is slower for a shop selling a few unbarcoded items by hand. A "favourites" grid would help if that is how the shop works.
28. **Change is worked out in the browser only** and is never recorded, which is correct — but it also means the cash figure recorded is what staff type, not what was physically taken. A wrong split still balances against the sale total.
29. **A walk-in counter sale is not linked to any customer,** so it never appears in customer history or dues. That is deliberate (no junk records), but it means walk-in sales cannot be traced to a person later.
30. **A return still does not refund by itself.** Receiving goods and giving money back are two deliberate actions: after receiving a return, someone must also make the refund on the order. The return page links straight to it, but nothing happens automatically, so a received return with no refund leaves the customer out of pocket.
31. **A received return cannot be undone.** Like receiving a purchase, it is final: the goods are on the shelf. A mistake needs a correcting stock movement by hand.
32. **A return does not put a coupon use back,** unlike a cancellation. If a coupon order is fully returned, the coupon stays used.
33. **Returning goods does not reduce what the customer owes.** If an *unpaid* order is returned, the due stays: a refund only gives back money that was actually taken, so it cannot clear a debt. An unpaid returned order needs the due writing off by hand (Accounts → money out, or an opening-due adjustment). This matters most for counter sales left on account.
34. **A fully returned order stays `returned` (or `refunded`) for good.** There is no path back if the customer changes their mind again; raise a new sale instead.
35. **A refund cannot be voided.** Like every other money record, a mistake needs a correcting entry (money in to the account) and a note. The ledger stays append-only.
36. **A refund does not put stock back.** It is money only. If goods are coming back too, raise a return — refunding alone leaves the stock sold.
37. **Refunds ignore the delivery charge.** The cap is simply what was paid, so staff decide by hand whether to hand back the delivery charge on a returned order. There is no rule enforcing either way.
38. **The refund account is not checked against how the customer paid.** Money taken by bKash can be refunded from Cash. That is often what a shop actually does, but it means the account a refund leaves is a staff decision, not a system one.

---

## 7. Exact next steps

**The P1 plan (Steps 1–14) is finished.** What follows is the P2 work from section 3, in the order that gives the shop the most straight away.

1. **Get the owner's confirmation before starting the P2 work.**
2. **Two things worth doing first, both small:**
   - **Commit the code.** Steps 8–14 are written and migrated but not in git. One commit per step, or one for the lot, before more work lands on top.
   - **Enter the real opening balances** (Accounts → Edit) and the **purchase prices on existing products**. Risks 3 and 5: until both are in, stock value and every profit figure are incomplete — which is exactly what the P2 reports are built on.
3. **Step 15, Expenses.** Read `AccountService::post` and `SupplierService::pay` (the same shape: money out of an account, with an audit entry).
   - **Migration:** `expense_categories` (seeded with Rent, Salary, Electricity, Internet, Packaging, Delivery, Facebook Ads, Marketing, Website, Office, Other) and `expenses` (category RESTRICT, account RESTRICT, amount, date, note, attachment path, created by).
   - **`ExpenseService::record`,** one transaction: lock the account, refuse more than it holds, post an `out` entry of type `expense`, write an audit entry. Attachments go on the **private** disk, never public.
   - **Screens:** expense list with date range, category and account filters, plus totals and CSV; a create/edit form; category management.
   - **Permissions:** `accounting.view` to see, `accounting.create` to record, `accounting.edit` to change; CSV needs `reports.export`.
4. **Step 16, Profit and loss.** Revenue from real payments on sale statuses, COGS from `order_items.unit_cost`, gross profit, minus expenses, to net profit — split online / POS / combined, over a date range.
   - **Watch out:** refunds must come off revenue, returned goods off COGS, and orders with a null `unit_cost` must be counted and shown as "cost unknown" rather than silently treated as free. That honesty is the whole point of the report.
5. **Step 17, Reports** (date range, search, filters, CSV via `App\Support\CsvExport`): sales, profit, expense, purchase, inventory, stock movement, product sales per variant, category sales, customer, supplier, return, payment.
6. **Step 18, Dashboard rebuild:** today / 7 days / 30 days / this month / last month / custom; charts; **revenue based on real payments and sale statuses** — which finally closes risk 1, the oldest open bug in this file.

**For each step:** back up the DB, write migrations that only add data or are reversible (explain any data backfill to the owner first), run tests, run `php artisan migrate`, smoke-test pages on MySQL, verify counts and balances, then update this file.
