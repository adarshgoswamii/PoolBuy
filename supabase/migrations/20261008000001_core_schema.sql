-- ==============================================================================
-- POOLBUY B2B WHOLESALE MARKETPLACE: CORE DATABASE SCHEMA
-- Migration: 20261008000001_core_schema.sql
-- Description: Core tables for profiles, taxonomy, products, pools, commitments & orders.
-- ==============================================================================

-- Enable UUID extension if not enabled
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS "pgcrypto";

-- 1. USER PROFILES & RBAC
CREATE TABLE IF NOT EXISTS public.profiles (
    id UUID PRIMARY KEY REFERENCES auth.users(id) ON DELETE CASCADE,
    email TEXT,
    role TEXT NOT NULL DEFAULT 'buyer' CHECK (role IN ('admin', 'seller', 'buyer')),
    company_name TEXT,
    contact_name TEXT,
    gstin TEXT,
    phone TEXT,
    is_verified BOOLEAN NOT NULL DEFAULT FALSE,
    verification_documents JSONB DEFAULT '[]'::jsonb,
    address JSONB DEFAULT '{}'::jsonb,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Index for role-based lookups
CREATE INDEX IF NOT EXISTS idx_profiles_role ON public.profiles(role);
CREATE INDEX IF NOT EXISTS idx_profiles_is_verified ON public.profiles(is_verified);

-- 2. CATEGORIES
CREATE TABLE IF NOT EXISTS public.categories (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name TEXT NOT NULL,
    slug TEXT UNIQUE NOT NULL,
    description TEXT,
    icon TEXT,
    image_url TEXT,
    sort_order INT NOT NULL DEFAULT 0,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_categories_slug ON public.categories(slug);
CREATE INDEX IF NOT EXISTS idx_categories_is_active ON public.categories(is_active);

-- 3. SUBCATEGORIES (with dynamic JSONB filter_schema)
CREATE TABLE IF NOT EXISTS public.subcategories (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    category_id UUID NOT NULL REFERENCES public.categories(id) ON DELETE CASCADE,
    name TEXT NOT NULL,
    slug TEXT NOT NULL,
    description TEXT,
    filter_schema JSONB NOT NULL DEFAULT '{}'::jsonb,
    sort_order INT NOT NULL DEFAULT 0,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE(category_id, slug)
);

CREATE INDEX IF NOT EXISTS idx_subcategories_category_id ON public.subcategories(category_id);
CREATE INDEX IF NOT EXISTS idx_subcategories_slug ON public.subcategories(slug);

-- 4. PRODUCTS (with dynamic JSONB attributes)
CREATE TABLE IF NOT EXISTS public.products (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    seller_id UUID NOT NULL REFERENCES public.profiles(id) ON DELETE CASCADE,
    category_id UUID NOT NULL REFERENCES public.categories(id),
    subcategory_id UUID NOT NULL REFERENCES public.subcategories(id),
    title TEXT NOT NULL,
    slug TEXT NOT NULL,
    sku TEXT,
    description TEXT,
    images JSONB NOT NULL DEFAULT '[]'::jsonb,
    base_price NUMERIC(12, 2) NOT NULL CHECK (base_price > 0),
    mrp NUMERIC(12, 2),
    stock_quantity INT NOT NULL DEFAULT 0 CHECK (stock_quantity >= 0),
    attributes JSONB NOT NULL DEFAULT '{}'::jsonb,
    specifications JSONB NOT NULL DEFAULT '{}'::jsonb,
    is_approved BOOLEAN NOT NULL DEFAULT FALSE,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_products_category ON public.products(category_id);
CREATE INDEX IF NOT EXISTS idx_products_subcategory ON public.products(subcategory_id);
CREATE INDEX IF NOT EXISTS idx_products_seller ON public.products(seller_id);
CREATE INDEX IF NOT EXISTS idx_products_is_approved ON public.products(is_approved);
CREATE INDEX IF NOT EXISTS idx_products_attributes ON public.products USING gin(attributes);

-- 5. GROUP-BUYING POOLS
CREATE TABLE IF NOT EXISTS public.pools (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    pool_code TEXT UNIQUE NOT NULL, -- e.g. PB-2026-001
    product_id UUID NOT NULL REFERENCES public.products(id) ON DELETE CASCADE,
    seller_id UUID NOT NULL REFERENCES public.profiles(id),
    moq INT NOT NULL CHECK (moq > 0),
    reserved_qty INT NOT NULL DEFAULT 0 CHECK (reserved_qty >= 0),
    min_per_buyer INT NOT NULL DEFAULT 1 CHECK (min_per_buyer > 0),
    max_per_buyer INT,
    status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'active', 'reached', 'closed', 'fulfilled', 'cancelled', 'expired')),
    current_tier_price NUMERIC(12, 2) NOT NULL,
    expires_at TIMESTAMPTZ NOT NULL,
    closed_at TIMESTAMPTZ,
    metadata JSONB DEFAULT '{}'::jsonb,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_pools_product ON public.pools(product_id);
CREATE INDEX IF NOT EXISTS idx_pools_seller ON public.pools(seller_id);
CREATE INDEX IF NOT EXISTS idx_pools_status ON public.pools(status);
CREATE INDEX IF NOT EXISTS idx_pools_expires_at ON public.pools(expires_at);

-- 6. POOL TIERS (Price Ladder)
CREATE TABLE IF NOT EXISTS public.pool_tiers (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    pool_id UUID NOT NULL REFERENCES public.pools(id) ON DELETE CASCADE,
    tier_number INT NOT NULL,
    min_qty INT NOT NULL CHECK (min_qty >= 0),
    max_qty INT, -- NULL means unbounded (e.g. 500+)
    unit_price NUMERIC(12, 2) NOT NULL CHECK (unit_price > 0),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE(pool_id, tier_number)
);

CREATE INDEX IF NOT EXISTS idx_pool_tiers_pool ON public.pool_tiers(pool_id);

-- 7. POOL COMMITMENTS (Buyer Reservations)
CREATE TABLE IF NOT EXISTS public.pool_commitments (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    pool_id UUID NOT NULL REFERENCES public.pools(id) ON DELETE CASCADE,
    buyer_id UUID NOT NULL REFERENCES public.profiles(id) ON DELETE CASCADE,
    quantity INT NOT NULL CHECK (quantity > 0),
    locked_unit_price NUMERIC(12, 2) NOT NULL,
    final_unit_price NUMERIC(12, 2),
    shipping_address JSONB NOT NULL DEFAULT '{}'::jsonb,
    status TEXT NOT NULL DEFAULT 'confirmed' CHECK (status IN ('pending', 'confirmed', 'refunded', 'cancelled')),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE(pool_id, buyer_id) -- Only 1 active commitment per buyer per pool
);

CREATE INDEX IF NOT EXISTS idx_commitments_pool ON public.pool_commitments(pool_id);
CREATE INDEX IF NOT EXISTS idx_commitments_buyer ON public.pool_commitments(buyer_id);
CREATE INDEX IF NOT EXISTS idx_commitments_status ON public.pool_commitments(status);

-- 8. ORDERS & SETTLEMENTS (Platform Fee 2% + GST 18%)
CREATE TABLE IF NOT EXISTS public.orders (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    order_number TEXT UNIQUE NOT NULL, -- e.g. ORD-PB-10023
    pool_id UUID NOT NULL REFERENCES public.pools(id),
    buyer_id UUID NOT NULL REFERENCES public.profiles(id),
    commitment_id UUID REFERENCES public.pool_commitments(id),
    quantity INT NOT NULL CHECK (quantity > 0),
    unit_price NUMERIC(12, 2) NOT NULL,
    subtotal NUMERIC(12, 2) NOT NULL,
    platform_fee NUMERIC(12, 2) NOT NULL DEFAULT 0.00, -- 2% platform commission
    gst_amount NUMERIC(12, 2) NOT NULL DEFAULT 0.00,   -- 18% GST
    total_amount NUMERIC(12, 2) NOT NULL,
    shipping_address JSONB NOT NULL DEFAULT '{}'::jsonb,
    order_status TEXT NOT NULL DEFAULT 'processing' CHECK (order_status IN ('processing', 'manufacturing', 'shipped', 'delivered', 'cancelled')),
    payment_status TEXT NOT NULL DEFAULT 'escrow_hold' CHECK (payment_status IN ('pending', 'escrow_hold', 'settled', 'refunded')),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_orders_pool ON public.orders(pool_id);
CREATE INDEX IF NOT EXISTS idx_orders_buyer ON public.orders(buyer_id);
CREATE INDEX IF NOT EXISTS idx_orders_status ON public.orders(order_status);
