-- PoolBuy database schema (PostgreSQL / Neon).
-- Money columns are numeric(15,4) to match the domain layer's SCALE=4 rounding.
-- All statements are idempotent (IF NOT EXISTS) so the file can be re-run safely.

-- ---------------------------------------------------------------------------
-- sellers
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sellers (
    seller_id     SERIAL PRIMARY KEY,
    name          TEXT NOT NULL,
    email         TEXT,
    company       TEXT,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ---------------------------------------------------------------------------
-- products (owned by a seller)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS products (
    product_id    SERIAL PRIMARY KEY,
    seller_id     INTEGER NOT NULL REFERENCES sellers(seller_id) ON DELETE CASCADE,
    name          TEXT NOT NULL,
    sku           TEXT,
    description   TEXT,
    unit          TEXT,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ---------------------------------------------------------------------------
-- customers (buyers)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS customers (
    customer_id    SERIAL PRIMARY KEY,
    email          TEXT NOT NULL UNIQUE,
    password_hash  TEXT NOT NULL,
    name           TEXT,
    address        TEXT,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ---------------------------------------------------------------------------
-- pools
-- reserved_qty is the pool TOTAL reserved quantity (tier resolution basis).
-- status maps to the domain lifecycle:
--   draft, active, reached, closed, expired, fulfilled, cancelled
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pools (
    pool_id            SERIAL PRIMARY KEY,
    product_id         INTEGER NOT NULL REFERENCES products(product_id) ON DELETE CASCADE,
    seller_id          INTEGER NOT NULL REFERENCES sellers(seller_id) ON DELETE CASCADE,
    reference          TEXT NOT NULL UNIQUE,
    title              TEXT,
    moq_target         INTEGER NOT NULL DEFAULT 0,
    reserved_qty       INTEGER NOT NULL DEFAULT 0,
    status             TEXT NOT NULL DEFAULT 'draft',
    date_start         TIMESTAMPTZ,
    date_end           TIMESTAMPTZ,
    min_qty_per_buyer  INTEGER NOT NULL DEFAULT 1,
    max_qty_per_buyer  INTEGER NOT NULL DEFAULT 0,   -- 0 => no per-buyer ceiling
    currency_code      TEXT NOT NULL DEFAULT 'INR',
    lead_time          TEXT,
    shipping_terms     TEXT,
    created_at         TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ---------------------------------------------------------------------------
-- pool_tiers (the price ladder for a pool)
-- max_qty NULL => open-ended top tier. price is numeric(15,4).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pool_tiers (
    tier_id     SERIAL PRIMARY KEY,
    pool_id     INTEGER NOT NULL REFERENCES pools(pool_id) ON DELETE CASCADE,
    min_qty     INTEGER NOT NULL,
    max_qty     INTEGER,                       -- NULL => open ended
    price       NUMERIC(15,4) NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_pool_tiers_pool_id ON pool_tiers(pool_id);

-- ---------------------------------------------------------------------------
-- reservations (a buyer's commitment to a pool)
-- unit_price_locked is the price captured at reservation time (numeric(15,4)).
-- status: pending, confirmed, cancelled, converted, released
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS reservations (
    reservation_id     SERIAL PRIMARY KEY,
    pool_id            INTEGER NOT NULL REFERENCES pools(pool_id) ON DELETE CASCADE,
    customer_id        INTEGER NOT NULL REFERENCES customers(customer_id) ON DELETE CASCADE,
    quantity           INTEGER NOT NULL,
    unit_price_locked  NUMERIC(15,4) NOT NULL DEFAULT 0,
    status             TEXT NOT NULL DEFAULT 'pending',
    created_at         TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_reservations_pool_id     ON reservations(pool_id);
CREATE INDEX IF NOT EXISTS idx_reservations_customer_id ON reservations(customer_id);

-- ---------------------------------------------------------------------------
-- pool_events (append-only audit log of pool state changes / actions)
-- payload is JSONB.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pool_events (
    event_id    SERIAL PRIMARY KEY,
    pool_id     INTEGER NOT NULL REFERENCES pools(pool_id) ON DELETE CASCADE,
    type        TEXT NOT NULL,
    payload     JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_pool_events_pool_id ON pool_events(pool_id);
