# PoolBuy

A B2B wholesale pool-buying marketplace, built as a self-contained OpenCart 4.1.0.4
extension (`upload/extension/poolbuy/`) plus a custom storefront theme.

Verified sellers list products with a minimum order quantity (MOQ) and a tiered price
ladder. Multiple buyers each commit part of the quantity. When the pool reaches its MOQ
the deal locks, **every** participant is repriced to the best tier the pool unlocked,
and the manufacturer ships to each buyer individually.

---

## Quick start

```powershell
docker compose up -d                                        # storefront :80, adminer :8080
powershell -File tools\poolbuy\seed_all.ps1                 # install, configure and seed
powershell -File tools\poolbuy\verify_all.ps1               # run every gate
```

| Surface | URL | Credentials |
|---|---|---|
| Storefront | `http://localhost/` | — |
| Marketplace | `/index.php?route=extension/poolbuy/pool` | — |
| Buyer dashboard | `/index.php?route=extension/poolbuy/account` | `buyer@poolbuy.test` / `PoolBuy123!` |
| Seller portal | `/index.php?route=extension/poolbuy/seller` | `sellera@poolbuy.test` / `PoolBuy123!` |
| Admin | `http://localhost/admin/` | `admin` / `admin` |
| Adminer | `http://localhost:8080` | `root` / `opencart`, db `opencart` |

MySQL is published on host port **3307** (3306 is left to the host's own mysqld).

---

## End-to-end walkthrough

This is the full commercial cycle. Every step below is covered by an automated check.

### 1. A seller lists a pool

Sign in as `sellera@poolbuy.test` and open **Seller Portal** from the header. Create a
pool: pick one of your own products, set the MOQ, the window, per-buyer limits, and the
price ladder (for example 10–49 @ ₹1,550, 50–149 @ ₹1,380, 150+ @ ₹1,250).

The seller controls terms only. The platform issues the `PB-XXXXXX` reference, owns
`reserved_qty`, and decides every outcome status. A seller may set `draft`, `active` or
`cancelled` and nothing else, and cannot publish at all until an administrator has
approved their account.

Administrators can do the same from **Admin → Extensions → PoolBuy → Pools**, which also
exposes the reservation map, the audit trail, and close-early.

### 2. Buyers join

The pool appears on the marketplace and on the product page. A buyer opens **Join Pool**
and moves through quantity → price breakdown → destination address → confirmation.

Nothing the browser sends is trusted:

- quantity is re-clamped from the database against the pool's real remaining capacity
  and the per-buyer minimum and maximum;
- the unit price is recomputed server-side from the tier ladder — a posted `unit_price`
  is discarded;
- the commit runs inside `START TRANSACTION` + `SELECT ... FOR UPDATE`, recomputing the
  reserved total from the reservation rows rather than the cached column;
- a unique index on `(pool_id, active_customer_id)` makes one active commitment per
  buyer per pool a database guarantee, not a convention.

Six buyers racing for the last 20 units results in exactly two winners and a pool at
exactly 100/100.

### 3. MOQ is reached and everyone is repriced

The hourly cron (`extension/poolbuy/cron/poolbuy`) drives the lifecycle:

```
draft ──▶ active ──▶ reached ──▶ closed ──▶ fulfilled
             └────▶ expired
```

When reserved ≥ MOQ the pool moves to `reached` and **every** commitment is rewritten to
the best unlocked tier. Buyers who joined early at a worse price are refunded the
difference by construction: they are simply repriced.

Worked example, verified end to end: three buyers all locked ₹980; the pool reached its
400-unit MOQ, unlocking the ₹890 tier; all three were rewritten to ₹890 before invoicing.

### 4. Commitments become orders

Once closed, each confirmed commitment converts into a real OpenCart order:

```
150 units × ₹890            = ₹133,500   sub_total
platform fee 2%             =   ₹2,670   poolbuy_fee
GST 18%                     =  ₹24,030   poolbuy_gst
                              ─────────
order total                 = ₹160,200
```

Orders are created at a real order status so they appear in Admin → Sales. Each
conversion runs in its own transaction, and the update is guarded by
`WHERE status='confirmed' AND order_id=0`, so two concurrent cron runs cannot both
invoice the same commitment. A second run creates zero extra orders and does not
reprice again.

If the window closes below MOQ the pool expires, every commitment is released, and
`reserved_qty` is recomputed to zero. Nobody is charged.

### 5. Buyers track and sellers monitor

Buyers see active, completed and unfulfilled commitments on their dashboard, can
withdraw while a pool is still filling, and can export a CSV. Sellers see fill progress
and a commitment list — with buyer identities replaced by opaque `Buyer #NNNN` handles.

### 6. Sellers run their own shop

The portal has three tabs, all scoped to the signed-in seller:

**Pools** — create and edit pool campaigns, watch fill progress, read the reservation map.

**Products** — the seller's own catalogue. Add and edit products with a name,
description, product code, price, stock figure, status and a primary image. Ownership
lives in `oc_poolbuy_product_seller`, so the tenancy check is a join rather than a
convention: seller B opening seller A's product gets "could not be found", and a crafted
save updates zero rows. An unapproved seller may add products but cannot enable them,
mirroring the rule for publishing pools. New products immediately become attachable to a
pool.

Note OpenCart 4.1's `oc_product` has no separate `sku` column — `model` is the single
product code — so the form presents one "Model / SKU" field rather than inventing a
second identifier.

**Orders** — the orders the seller's pools generated. Advance an order through
Processing, Shipped or Complete and record a carrier tracking reference. Each change
writes an `oc_order_history` row, so it shows up in Admin → Sales → Orders and to the
buyer, plus a `order_status_changed` event on the pool's audit trail. Statuses outside
that working set (refunds, chargebacks) stay with the operator and are refused.

Buyer privacy holds on these screens too: the order list shows only `Buyer #NNNN`, and
the delivery address appears on the order detail **only once the pool is fulfilled** —
the model blanks those fields until then, rather than relying on the template to hide
them.

---

## Design decisions worth knowing

**Tiers are pool-level.** A tier is resolved against the pool's *total* reserved
quantity, not an individual buyer's line. Collective volume unlocks the price for
everyone. The source designs were ambiguous here; this is the only reading under which
retroactive repricing is coherent.

**Reserve now, pay on MOQ.** Joining a pool is a binding commitment, not a payment.
Nothing is charged while a pool is filling, so a pool that expires below MOQ costs its
participants nothing. Once the pool reaches MOQ and closes, each commitment becomes a
real order and payment is requested through the configured gateway
(`system/library/gateway_factory.php` selects between the Razorpay gateway and a mock
gateway for tests). Where no gateway is configured the order is raised unpaid against
the `poolbuy.invoice` method. Refunds for a pool that does not proceed after payment are
issued back through the original gateway.

**Sellers apply, administrators approve.** A shopper applies through
`extension/poolbuy/seller.register`, supplying the business name, GSTIN and location.
The profile is created unapproved: `status`, `gst_verified` and `verified_seller` are
forced to 0 server-side, so an applicant cannot self-approve or award themselves a
badge. An administrator promotes the account from Admin → PoolBuy → Sellers. Until then
the seller may prepare drafts but never publish.

**Join is a page, not a modal.** The design showed a JavaScript modal. A binding
purchase commitment should not depend on client-side state, so it is a server-rendered
multi-step page: it survives a refresh, works without JavaScript, and is linkable.

**Freight is quoted at close.** Real freight depends on final pooled volume, so the
join flow collects the destination address and states that freight is quoted when the
pool closes, rather than inventing a fixed number.

**No Tailwind CDN.** The design exports used the Tailwind CDN, which must not ship to
production. The theme is a hand-written token-driven stylesheet
(`upload/catalog/view/stylesheet/poolbuy.css`). Icons reuse the FontAwesome already
bundled with OpenCart; Inter is self-hosted. There are zero external CDN or font
requests in the rendered HTML.

**Multi-vendor is phased.** Sellers manage their own pools, catalogue and orders through
the portal. A seller is a customer account linked to a seller profile. Platform-wide
payment and settlement monitoring stays with the administrator.

---

## Architecture

```
upload/extension/poolbuy/
  admin/      controller|model|language|view   operator screens (sellers, pools)
  catalog/    controller|model|language|view   storefront (marketplace, join, account, seller portal, seller catalogue, seller orders, cron)
  system/library/                              framework-free domain logic
```

Three pure domain classes carry the business rules and are unit tested without booting
OpenCart:

| Class | Responsibility |
|---|---|
| `PoolCalculator` | tier validation and resolution, pricing, clamping, breakdowns, repricing |
| `PoolLifecycle` | the state machine and which transitions are legal |
| `PoolPresenter` | formatting and display decoration |

Eleven tables, all `InnoDB` / `utf8mb4`: `oc_poolbuy_seller`, `oc_poolbuy_product_seller`,
`oc_poolbuy_pool`, `oc_poolbuy_pool_tier`, `oc_poolbuy_reservation`,
`oc_poolbuy_pool_event`, `oc_poolbuy_payment`, `oc_poolbuy_settlement`,
`oc_poolbuy_promotion`, `oc_poolbuy_promotion_redemption`, `oc_poolbuy_review`.

The authoritative list is `$tables` in
`upload/extension/poolbuy/admin/model/module/poolbuy.php`; `verify_install.ps1`
asserts the installed set matches it exactly, so adding a table without updating
both is caught by the gate rather than silently under-tested.

Settings live under the `module_poolbuy` group: currency code and symbol, GST rate,
platform fee, default pool duration, retroactive pricing, and the cron token.
`module_poolbuy_status` must be `1` or the storefront features stay hidden.

### Currency

Two independent layers, deliberately:

- **PoolBuy pool prices are never converted.** Each pool carries its own
  `currency_code`, and converted orders are written with `currency_value = 1.0`. A pool
  priced at ₹1,550 is ₹1,550 whatever the shopper has selected in the switcher, because
  a wholesale commitment is denominated in the currency the deal was struck in.
- **Stock OpenCart product prices do convert**, from the base currency (USD, the row
  with `value = 1.0`) into whatever the shopper selects.

A stock OpenCart install ships Indian Rupee in `oc_currency` with `status = 0`, so it
never appears in the switcher — while PoolBuy is configured for INR and stamps orders
with `currencyId('INR')`. `tools\poolbuy\seed_currency.ps1` enables it (and is called by
`seed_all.ps1`), which also clears the cache, since OpenCart caches the currency list.

To make rupees the store default as well, set **System → Settings → edit store → Local →
Currency** to Indian Rupee. That converts stock product prices for display at the stored
rate; it does not touch pool pricing. Note `config_currency_auto` is `1`, so OpenCart
refreshes exchange rates on its own schedule.

### Core files touched

Every edit to stock OpenCart is guarded on `module_poolbuy_status`, so disabling the
extension restores stock behaviour:

- `catalog/controller/common/home.php` + `common/home.twig` — landing page
- `catalog/controller/product/product.php` + `product/product.twig` — pool widget
- `catalog/controller/common/header.php` + `common/header.twig` — nav and seller link
- `common/footer.twig`, `common/menu.twig` — theme shell and menu accessibility
- `catalog/language/en-gb/default.php` — accessible name for the icon-only home crumb

---

## Verification

`tools\poolbuy\verify_all.ps1` runs everything and prints a pass/fail summary.

| Gate | Covers |
|---|---|
| PHPUnit | 151 tests over the domain logic |
| PHPStan level 6 | zero errors from PoolBuy code |
| php-cs-fixer | zero PoolBuy style violations |
| `verify_install.ps1` | install / uninstall / reinstall (**destructive** — reseed after) |
| `verify_join.ps1` | the join flow as a real logged-in buyer |
| `verify_race.ps1` | six parallel buyers cannot oversubscribe a pool |
| `verify_account.ps1` | buyer dashboard, including cross-buyer isolation |
| `verify_lifecycle.ps1` | cron, repricing, order conversion, idempotency, expiry |
| `verify_freight.ps1` | pool freight quoting and the split across participants |
| `verify_promotions.ps1` | coupon eligibility, thresholds, and per-code refusal reasons |
| `verify_reviews.ps1` | review eligibility, moderation, and reviewer anonymity |
| `verify_currency.ps1` | multi-currency pricing and per-currency settlement totals |
| `verify_payment.ps1` | gateway checkout, webhooks, and refunds |
| `verify_progress.ps1` | live pool progress updates |
| `verify_discovery.ps1` | marketplace search, filters, trending and ending-soon rails |
| `verify_seller.ps1` | seller portal, onboarding, catalogue, order processing, and cross-tenant isolation |
| `verify_security.ps1` | authorisation, CSRF, XSS, SQL injection, price and quantity trust |
| `verify_accessibility.ps1` | landmarks, labels, ARIA, focus, no-JavaScript operation |

`verify_install.ps1` drops the PoolBuy tables, so `verify_all.ps1` runs it first and
reseeds before the behavioural suites. Order matters if you run suites by hand.

### Security properties under test

Authorisation on every admin and storefront route; forged `user_token` rejected;
private routes redirect to login while JSON endpoints refuse with JSON; draft and
cancelled pools never public; cron token-gated and **failing closed on a blank token**;
operator-supplied text escaped on every surface; SQL injection attempts leave all tables
intact and surface no errors; posted prices and quantities discarded in favour of
server-side values; `reserved_qty` not writable through any form; buyer identities never
exposed to sellers.

---

## Operations

Register and run the cron hourly. OpenCart's own dispatcher needs no secret:

```
upload/cron.php            # runs every oc_cron row, including poolbuy
```

For an HTTP trigger the token is mandatory:

```
/index.php?route=extension/poolbuy/cron/poolbuy.run&token=<module_poolbuy_cron_token>
```

A blank configured token denies all HTTP runs — it is never treated as "no auth
required".

Orders created by the lifecycle carry `user_agent = 'PoolBuy lifecycle'`, which makes
test data easy to identify and clear.
