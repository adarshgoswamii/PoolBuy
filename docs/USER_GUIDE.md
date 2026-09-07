# PoolBuy — User Guide

A practical, role-by-role guide to using the PoolBuy wholesale pool-buying
marketplace. It covers the three people who touch the system: the **buyer** who
joins pools, the **seller** who lists them, and the **administrator** who runs the
platform.

For the architecture and design rationale, see [`POOLBUY.md`](../POOLBUY.md). This
document is about *using* the running store.

---

## What PoolBuy is, in one paragraph

Sellers list a product with a minimum order quantity (MOQ) and a tiered price
ladder — the more the pool buys collectively, the lower the unit price for
everyone. Buyers each commit part of the quantity. When the pool reaches its MOQ
it locks, **every** participant is repriced to the best tier the pool unlocked,
and each buyer's commitment becomes a real order. If the window closes before the
MOQ is met, the pool expires and nobody is charged.

---

## Getting in

| Surface | URL | Credentials |
|---|---|---|
| Storefront home | `http://localhost/` | — |
| Marketplace | `http://localhost/index.php?route=extension/poolbuy/pool` | — |
| Buyer dashboard | `http://localhost/index.php?route=extension/poolbuy/account` | see below |
| Seller portal | `http://localhost/index.php?route=extension/poolbuy/seller` | see below |
| Admin | `http://localhost/admin/` | `admin` / `admin` |

**Demo logins** (all password `PoolBuy123!`):

- Buyer with history: `buyer@poolbuy.test`
- **Fresh demo buyer** (clean, no commitments — best for a first walkthrough): `demo@poolbuy.test`
- Seller A (Titan Pumps): `sellera@poolbuy.test`
- Seller B (ChemBulk): `sellerb@poolbuy.test`

> Tip: the buyer dashboard and seller portal are protected. Visiting them while
> logged out sends you to the login page — that is expected.

---

## For buyers

### 1. Find a pool

Open the **Marketplace** (link in the site header, or the URL above). Each card
shows the product, the seller, the current fill against the MOQ, the price the
pool has unlocked so far, and how long is left. The landing page and individual
product pages also surface any pool attached to a product.

### 2. Join a pool

Click **Join Pool** and move through the steps:

1. **Quantity** — how many units you want to commit. There is a per-buyer minimum
   and maximum; the store re-checks your number against the pool's real remaining
   capacity, so you cannot over-commit even if two people click at once.
2. **Price breakdown** — the unit price is computed from the tier ladder on the
   server. You see the subtotal, the 2% platform fee, and 18% GST.
3. **Delivery address** — pick where the goods ship. Freight itself is quoted when
   the pool closes, because real freight depends on the final pooled volume.
4. **Confirm** — your commitment is binding, but it is *reserve now, pay on MOQ*:
   no card is charged at this point.

The join flow is a normal page, not a pop-up. You can refresh, bookmark, or use
it without JavaScript and it still works.

### 3. Track your commitments

The **Buyer dashboard** shows three groups:

- **Active** — pools you are in that are still filling. You can **withdraw** while
  a pool is still open.
- **Completed** — pools that reached MOQ and became orders. You were automatically
  repriced to the best tier the pool unlocked, even if you joined early at a worse
  price.
- **Unfulfilled** — pools that expired below MOQ. These are released; you were not
  charged.

You can **export a CSV** of your commitments from the dashboard.

### What happens when a pool reaches its MOQ

You do not have to do anything. The platform locks the pool, rewrites every
participant to the best unlocked tier, and turns each confirmed commitment into a
real order (visible to the store as a normal sale). If you joined at a higher tier
price, the lower pooled price is what you actually get.

---

## For sellers

> A seller account is a normal customer login that an administrator has linked to
> a seller profile and approved. You cannot publish pools until that approval is
> in place.

### 1. Open the Seller Portal

Log in, then use the **Seller** link in the header (or the portal URL). You see
only **your own** products and pools. You never see buyer names or emails — buyers
appear as opaque handles like `Buyer #1017`. You also cannot see other sellers'
pools.

### 2. List a pool

Create a pool against one of your products and set:

- the **MOQ** (the volume the pool must reach to lock);
- the **window** (start and end dates);
- **per-buyer minimum and maximum** quantities;
- the **price ladder** — e.g. 10–49 @ ₹1,550, 50–149 @ ₹1,380, 150+ @ ₹1,250.

You control the commercial terms only. The platform issues the `PB-XXXXXX`
reference, owns the reserved quantity, and decides every outcome. As a seller you
may set a pool to `draft`, `active`, or `cancelled` — nothing else.

### 3. Monitor progress

The portal shows fill progress and the commitment list (with anonymised buyers).
Watch the fill bar climb toward the MOQ. When it locks and converts, the resulting
orders flow into the store's normal sales pipeline.

---

## For administrators

Log in to **Admin** (`http://localhost/admin/`, `admin` / `admin`).

### Manage the marketplace

Go to **Extensions → PoolBuy**. From here you reach:

- **Settings** — currency, GST rate, platform fee, default pool duration,
  retroactive pricing toggle, and the cron token (see automation below).
- **Sellers** — approve seller accounts and link them to customer logins.
- **Pools** — full pool management: create/edit pools, the reservation map, the
  audit trail, and **Close Pool Early**. Closing early lands on *reached* if the
  MOQ was met or *expired* if it was not — the volume decides, not the operator.

### Run the lifecycle on demand (no token needed)

Pools normally advance on an hourly schedule. To process everything **right now**
— activate due pools, lock any that hit MOQ, reprice participants, raise orders,
and expire pools past their window — you do **not** need to touch a URL or a token:

1. Open **Extensions → PoolBuy → Settings**.
2. Make sure **Status** is *Enabled* and save once (this also generates the cron
   token if it is blank).
3. In the **Automation** section, click **Run Lifecycle Now**.

You get a short summary such as *"Lifecycle processed. 0 activated, 1 reached MOQ,
0 expired, 0 closed, 2 orders created, 5 repriced."* The operation is idempotent —
clicking it again when there is nothing new to do is harmless.

This button is available only to admins with modify permission on the PoolBuy
settings, and it reads the cron token for you behind the scenes, so you never have
to handle the secret yourself.

### Automation (scheduled runs)

For unattended operation, register PoolBuy with OpenCart's cron dispatcher so it
runs hourly:

```
upload/cron.php
```

An HTTP trigger is also available for external schedulers, and this one **does**
require the token:

```
http://localhost/index.php?route=extension/poolbuy/cron/poolbuy.run&token=<cron token>
```

A blank token denies all HTTP runs — it is never treated as "no auth required". If
you ever see `{"error":"Forbidden"}` from that URL, the token in the URL does not
match the one in **Settings → Automation → Cron Token**. Copy the current token
from there, or just use the **Run Lifecycle Now** button instead.

> Orders created by the lifecycle carry `user_agent = 'PoolBuy lifecycle'`, which
> makes demo/test orders easy to spot and clear in **Sales → Orders**.

---

## Setting up a demo environment from scratch

From the project root:

```powershell
docker compose up -d                            # storefront :80, adminer :8080
powershell -File tools\poolbuy\seed_all.ps1     # install, configure, seed users + pools
powershell -File tools\poolbuy\verify_all.ps1   # optional: run every automated gate
```

`seed_all.ps1` is idempotent and creates all the demo logins above, including the
fresh `demo@poolbuy.test` buyer.

---

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| Marketplace/landing page shows no PoolBuy content | Extension disabled | Admin → Extensions → PoolBuy → Settings → **Status = Enabled**, save |
| `{"error":"Forbidden"}` from the cron URL | Token in URL ≠ configured token | Use the current token from Settings, or the **Run Lifecycle Now** button |
| Buyer dashboard / seller portal redirects to login | Not logged in (expected for protected pages) | Log in first |
| Seller portal product list is empty | Seller not linked/approved, or owns no products | Admin links the seller account and assigns products |
| A pool never locks | Still filling, or window not yet reached | Wait for the schedule, or click **Run Lifecycle Now** |
