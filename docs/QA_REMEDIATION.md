# PoolBuy — Response to the Preliminary QA Testing Report & Scope Analysis

This document answers the QA report point by point.

**The single most important finding:** the report was produced against the live Railway
deployment, as an anonymous visitor, with no test credentials
("Test Credentials: Provisioning of functional accounts for the following roles: Admin,
Seller, and Buyer"). Most rows marked *Not Yet Implemented/Configured* record what was
invisible from the public surface, not what is absent from the codebase. Nine of the
fifteen modules the report could not confirm are implemented and have automated
coverage on `main`.

Every claim below cites the file that implements it and the gate that proves it. Run
`powershell -File tools\poolbuy\verify_all.ps1` to reproduce.

Result after this pass: **ALL GATES GREEN** — 151 PHPUnit tests, PHPStan level 6 clean
of PoolBuy errors, no PoolBuy style violations, 14 behavioural suites plus the install
lifecycle all passing (639 checks), and an empty OpenCart error log. Two gates were red
at the start of this pass; both are fixed and explained in section 5.

---

## 1. Feature status matrix, reconciled against the code

| Module | Report status | Actual | Evidence |
|---|---|---|---|
| User & Authentication | Verified public surface | Implemented (stock OpenCart accounts + seller onboarding) | `catalog/controller/seller.php::register`, `verify_seller.ps1` [12] |
| Buyer Module & Discovery | Verified public surface | Implemented | `catalog/controller/pool.php`, `verify_discovery.ps1` |
| Core Pool Buying System | Pending authenticated testing | Implemented | `catalog/model/poolbuy/lifecycle.php`, `verify_join.ps1`, `verify_race.ps1`, `verify_lifecycle.ps1` |
| Seller Module | Not yet implemented | Implemented, incl. self-onboarding + KYC fields, own catalogue and order processing | `catalog/controller/seller.php`, `seller_product.php`, `seller_order.php`, `verify_seller.ps1` |
| Product Management & Variants | Verified public surface | Stock OpenCart, seller-scoped, sellers manage their own products | `catalog/controller/seller_product.php` |
| Cart, Checkout & Pricing | In progress | Implemented, incl. multi-currency | `catalog/model/total/*`, `verify_currency.ps1` |
| Payment Gateway | Not yet configured | Implemented (Razorpay + mock), webhook signature gated | `system/library/razorpay_gateway.php`, `verify_payment.ps1` |
| Order Management | Not yet configured | Implemented: lifecycle order conversion, plus seller-side processing and shipment tracking | `catalog/model/poolbuy/lifecycle.php`, `catalog/controller/seller_order.php`, `verify_lifecycle.ps1`, `verify_seller.ps1` |
| Admin Panel | Pending authenticated testing | Implemented | `admin/controller/poolbuy/*` |
| Platform Governance | In progress | Implemented (approval, moderation, audit trail) | `oc_poolbuy_pool_event`, `admin/controller/poolbuy/review.php` |
| Notifications | Not yet configured | Implemented (transactional email) | `catalog/language/en-gb/poolbuy/mail.php`, `lifecycle.php` |
| Reviews & Promotions | Not yet configured | Implemented | `verify_reviews.ps1`, `verify_promotions.ps1` |
| Security & RBAC | Pending authenticated testing | Implemented and gated | `verify_security.ps1` |
| Infrastructure | Verified public surface | Confirmed | `docker-compose.yml`, `railway.json` |
| API & Integrations | In progress | Confirmed | cron + webhook routes, `verify_payment.ps1` |

Notifications are transactional email only. SMS and push are not implemented; the
feature list rates that channel work Medium.

---

## 2. Section 16 — the ten business rules, now decided

The report asks for stakeholder decisions on ten rules. Each is resolved below and
enforced in code, so the answers are testable rather than aspirational.

| # | Question | Decision | Enforced by |
|---|---|---|---|
| 1 | Pay on joining or on success? | **Pay on success.** Joining is a binding commitment; nothing is charged while filling. Payment is requested once the pool reaches MOQ and closes. | `lifecycle.php`, `verify_lifecycle.ps1` |
| 2 | Refund process when a pool fails? | **No charge to refund in the normal case** — an expired pool releases all commitments and `reserved_qty` returns to 0. Where payment was already taken, a full refund is issued through the original gateway. | `lifecycle.php`, `mail.php` (`refund_issued`), `verify_payment.ps1` |
| 3 | Can buyers leave after joining? | **Yes, while the pool is still filling.** Withdrawal closes once the pool reaches MOQ, because that is the point the deal becomes binding on the supplier. | `catalog/controller/account.php`, `verify_account.ps1` |
| 4 | Target reached before the countdown ends? | **The pool locks at `reached` immediately** and everyone is repriced to the best unlocked tier. Administrators may also close early. | `PoolLifecycle`, `admin/controller/poolbuy/pool.php` |
| 5 | Stock reservation and locking? | **Reserve on commit, inside a transaction.** `SELECT ... FOR UPDATE` plus a unique index on `(pool_id, active_customer_id)` makes oversubscription a database-level impossibility. | `catalog/model/poolbuy/reservation.php`, `verify_race.ps1` |
| 6 | Shipping responsibility and charges? | **Freight is quoted per pool and split across participants by units committed**, so each buyer pays less as the pool fills. Quoted at close, because real freight depends on final volume. | `verify_freight.ps1` |
| 7 | Seller commission and settlement? | **Percentage platform fee**, configurable via `module_poolbuy` settings, accrued per order into `oc_poolbuy_settlement` and reported per currency. | `catalog/model/total/poolbuy_fee.php`, `verify_currency.ps1` |
| 8 | Seller-created pools or admin approval? | **Both, gated.** Sellers create and publish their own pools, but only once an administrator has approved the seller account. Unapproved sellers are limited to drafts. | `seller.php::save`, `verify_seller.ps1` |
| 9 | One product per pool or many? | **One product per pool.** Tiers are resolved against the pool's total volume, which is only coherent for a single SKU. | `oc_poolbuy_pool.product_id` |
| 10 | Multiple buyer-count tiers? | **Yes, quantity-based tiers, resolved against the pool's combined volume, not the individual line.** Collective volume unlocks the price for everyone, and repricing is retroactive. | `PoolCalculator`, PHPUnit |

Note on #10 against the report's Section 17: the specification illustrates tiers by
*number of buyers* (1 → ₹1,000, 5+ → ₹900). PoolBuy tiers on *quantity committed*
instead. For wholesale MOQ trading, quantity is the figure the supplier's price actually
depends on — five buyers taking one unit each is not the same deal as five buyers taking
a hundred each. **This is a deliberate deviation from the spec and needs sign-off.** If
buyer-count tiers are genuinely wanted, `PoolCalculator::resolveTier` is the single
place that changes.

---

## 3. Recommended improvements, and where each one stands

### P0 — release blockers

1. **"Pool Failed" logic so users are not charged.** Already the design: reserve now,
   pay on MOQ. Expiry releases every commitment and recomputes `reserved_qty` to zero.
   Where a payment exists, a full gateway refund is issued. Covered by
   `verify_lifecycle.ps1` and `verify_payment.ps1`.
2. **Secure all administrative routes.** Every admin controller checks
   `hasPermission('modify', ...)` on write actions, and `review.approve`/`reject`
   delegate to a permission-guarded private `moderate()`. **Gap found and fixed in this
   pass:** `verify_security.ps1` predated the promotion and review screens and never
   exercised them, so the central security gate now asserts forged-token rejection for
   `promotion.save`, `promotion.delete`, `review.approve`, `review.reject` and
   `review.delete`, and confirms both tables are left untouched.
3. **End-to-end checkout with a gateway sandbox.** Implemented. `gateway_factory.php`
   selects Razorpay or a mock gateway; webhooks are signature gated and **fail closed on
   a blank secret**. Covered by `verify_payment.ps1`.

### P1 — core usability

4. **Real-time pool progress.** Implemented, covered by `verify_progress.ps1`.
5. **Registration flow with mandatory seller verification fields.** **Implemented in
   this pass.** `extension/poolbuy/seller.register` collects registered business name,
   GSTIN (validated to the 15-character GSTIN format), location and an authorisation
   confirmation. Profiles are created unapproved, and posted `status`, `gst_verified`,
   `verified_seller` and `rating` are discarded server-side. 22 new checks in
   `verify_seller.ps1` [12].
6. **Consistent error handling for promo codes and payments.** Implemented: each refused
   code returns its own reason (expired vs. not yet valid vs. threshold not met) rather
   than a generic failure. Covered by `verify_promotions.ps1`.

### P2 — post-MVP

7. **Image and page-load optimisation.** Partly done: `loading="lazy"` is applied across
   marketplace, account, widget, rail and seller templates, images are served through
   OpenCart's resizer, and the theme ships no CDN or external font requests. Remaining
   work is responsive `srcset`, not yet done.
8. **Search and filter UI.** Implemented, covered by `verify_discovery.ps1`.
9. **Buyer dashboard for pool participation.** Implemented, covered by
   `verify_account.ps1`.

---

## 4. Next-phase testing prerequisites — all available locally

The report's four blockers are satisfied by the existing harness. From the repository
root:

```powershell
docker compose up -d
powershell -File tools\poolbuy\seed_all.ps1
```

**Test credentials** (all created by the seed):

| Role | URL | Credentials |
|---|---|---|
| Admin | `http://localhost/admin/` | `admin` / `admin` |
| Seller A | Seller Portal | `sellera@poolbuy.test` / `PoolBuy123!` |
| Seller B | Seller Portal | `sellerb@poolbuy.test` / `PoolBuy123!` |
| Buyer | Buyer dashboard | `buyer@poolbuy.test` / `PoolBuy123!` |
| Clean buyer | no commitments yet | `demo@poolbuy.test` / `PoolBuy123!` |

**Payment sandbox.** The mock gateway is the sandbox and needs no third-party account;
`verify_payment.ps1` drives checkout, webhook and refund against it. For Razorpay test
mode, set the key, secret and webhook secret in Admin → Extensions → PoolBuy. A blank
webhook secret denies all webhooks rather than accepting unsigned ones.

**Test data.** `seed_all.ps1` creates 5 sellers, 10 pools, 30 tiers and ~27
reservations spanning draft, active, reached, closed, expired and fulfilled states.
Drive the lifecycle by hand with the cron URL the seed prints.

**Responsive verification.** The theme is a hand-written token-driven stylesheet with no
Tailwind CDN; the local instance is directly usable for device testing. A dedicated
mobile staging environment is an infrastructure request, not a code change, and is
outside this pass.

---

## 5. What this pass changed

| Change | File |
|---|---|
| Fixed a stale table list that under-asserted the install gate (expected 8, schema has 11) and made it a set comparison plus a defensive pre-drop | `tools/poolbuy/verify_install.ps1` |
| Fixed suite ordering: Reviews needs a settled payment, so it now runs after Payments instead of exiting early having asserted nothing | `tools/poolbuy/verify_all.ps1` |
| Made a suite that asserts nothing print its tail, so a silent early abort can no longer hide as "0 passed, 0 failed" | `tools/poolbuy/verify_all.ps1` |
| Added seller self-onboarding with mandatory verification fields, created unapproved | `catalog/controller/seller.php`, `catalog/model/poolbuy/seller.php`, `catalog/view/template/poolbuy/seller_register.twig`, `catalog/language/en-gb/poolbuy/seller.php` |
| Pointed the "Apply to sell" call to action at the new flow instead of the contact page | `catalog/view/template/poolbuy/seller_denied.twig` |
| Extended the security gate to the promotion and review admin write routes, and to `seller.register` | `tools/poolbuy/verify_security.ps1` |
| Added 22 onboarding checks | `tools/poolbuy/verify_seller.ps1` |
| Enabled Indian Rupee on the storefront (stock OpenCart ships INR with `status=0`, so rupees never reached the switcher even though PoolBuy trades in INR), made it part of the seed | `tools/poolbuy/seed_currency.ps1`, `tools/poolbuy/seed_all.ps1` |
| Corrected a stale table-count guard in the seed (checked for 8, schema has 11) | `tools/poolbuy/seed_all.ps1` |
| Corrected drift: gate list, test count, table count, payment gateway reality, seller onboarding | `POOLBUY.md` |

## 5a. Seller-side pass: the two Critical gaps closed

A later audit of all 15 spec modules found the pool engine complete but two Critical/High
items genuinely missing. Both are now implemented, with 49 new automated checks
(639 total, up from 590).

| Spec line | Module | Delivered |
|---|---|---|
| Add Product (Critical), Edit Product, Product Images, Product Pricing, Inventory Management, SKU Management | 4, 5 | `extension/poolbuy/seller_product` — own-catalogue list, add/edit with name, description, product code, price, stock, status and a strictly validated primary image upload |
| Order Management (Critical), Shipping Management | 4 | `extension/poolbuy/seller_order` — order list and detail scoped to the seller's own pools |
| Seller Order Processing (Critical), Shipment Tracking | 8 | Forward status transitions (Processing / Shipped / Complete) plus carrier tracking, writing `oc_order_history` and a pool audit event |

| Change | File |
|---|---|
| Seller catalogue: `getOwnProducts`, `getTotalOwnProducts`, `getOwnProduct`, `addProduct`, `editProduct`; seller orders: `getOrders`, `getTotalOrders`, `getOrder`, `getOrderTotals`, `getOrderStatuses`, `updateOrderStatus` | `catalog/model/poolbuy/seller.php` |
| Product management screens | `catalog/controller/seller_product.php`, `seller_products.twig`, `seller_product_form.twig`, `language/en-gb/poolbuy/seller_product.php` |
| Order processing screens | `catalog/controller/seller_order.php`, `seller_orders.twig`, `seller_order.twig`, `language/en-gb/poolbuy/seller_order.php` |
| Portal tab strip (Pools / Products / Orders), rendered via `load->view()` because OpenCart's Twig loader does not resolve extension-relative `{% include %}` paths | `seller_tabs.twig`, `catalog/controller/seller.php`, `seller_pools.twig` |
| Replaced the "contact the marketplace team" dead end with a pointer to the Products tab | `language/en-gb/poolbuy/seller.php` |
| `.pb-dl` key/value list for the order detail | `upload/catalog/view/stylesheet/poolbuy.css` |
| 45 product and order checks incl. cross-tenant refusal and buyer privacy | `tools/poolbuy/verify_seller.ps1` |
| Four new private routes added to the security gate | `tools/poolbuy/verify_security.ps1` |

Notes worth recording:

- **OpenCart 4.1 has no `sku` column** on `oc_product` (no `upc`/`ean`/`jan` either);
  `model` is the single product code. The form therefore presents one "Model / SKU"
  field rather than inventing a second identifier in a column that does not exist.
- **Tenancy** is a join against `oc_poolbuy_product_seller` / the seller's own pools, not
  a post-hoc check. Cross-tenant reads report "could not be found" rather than
  "forbidden", so the screens never confirm that another seller's id is real, and
  cross-tenant writes update zero rows.
- **Buyer privacy** extends to the new screens: the order list shows only
  `Buyer #NNNN`, and the model blanks the delivery address until the pool is
  `fulfilled` — the template never receives data it should not show.
- **Image upload** is validated on extension, `getimagesize` sniffing and a 2 MB cap,
  with a generated filename under `image/catalog/poolbuy/seller_<id>/`. The upload path
  is exercised by hand; the automated checks cover the form and the validation
  branches, not a real multipart file post.

## 6. Open items needing a decision, not code

- **Buyer-count tiers vs. quantity tiers** (Section 16 #10 above) — needs sign-off.
- **SMS and push notifications** — not implemented; email is.
- **Responsive `srcset`** — remaining part of P2 #7.
- **Mobile staging environment** — infrastructure request.
- **The live Railway deployment** — this pass verified the local Docker instance only.
  The report's public-surface findings suggest the deployed instance may not have the
  extension enabled or seeded. Re-testing it needs `module_poolbuy_status=1`, the seed,
  and the credentials above. No production changes were made.
