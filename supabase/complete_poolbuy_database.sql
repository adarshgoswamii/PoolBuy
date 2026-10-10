-- ==============================================================================
-- POOLBUY B2B WHOLESALE MARKETPLACE: COMPLETE CONSOLIDATED SUPABASE DATABASE
-- File: supabase/complete_poolbuy_database.sql
-- Description:
--   Single-file execution containing:
--     1. Extensions (uuid-ossp, pgcrypto)
--     2. Core Tables (profiles, categories, subcategories, products, pools, pool_tiers, pool_commitments, orders)
--     3. Functions & Triggers (auth hook, atomic pool join, pricing engine, order converter)
--     4. Row-Level Security (RLS) policies for Buyer, Verified Seller, and Admin roles
--     5. Comprehensive Seed Data (Auth demo users, 10 B2B categories, dynamic filter schemas, demo pools & tiers)
--
-- How to run:
--   Option A: Open Supabase Dashboard -> SQL Editor -> New Query -> Paste this entire file -> Run.
--   Option B: Supabase CLI: npx supabase db push or psql connection string.
-- ==============================================================================

-- ==============================================================================
-- SECTION 1: EXTENSIONS
-- ==============================================================================
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS "pgcrypto";

-- ==============================================================================
-- SECTION 2: CORE TABLES & INDEXES
-- ==============================================================================

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

-- 4. PRODUCTS (with dynamic JSONB attributes & specifications)
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
    pool_code TEXT UNIQUE NOT NULL, -- e.g. PB-APP-7014
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

-- 6. POOL TIERS (Volume Pricing Ladder)
CREATE TABLE IF NOT EXISTS public.pool_tiers (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    pool_id UUID NOT NULL REFERENCES public.pools(id) ON DELETE CASCADE,
    tier_number INT NOT NULL,
    min_qty INT NOT NULL CHECK (min_qty >= 0),
    max_qty INT, -- NULL indicates unbounded (e.g. 500+)
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
    UNIQUE(pool_id, buyer_id)
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
    platform_fee NUMERIC(12, 2) NOT NULL DEFAULT 0.00, -- 2% platform fee
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


-- ==============================================================================
-- SECTION 3: STORED PROCEDURES & TRIGGERS
-- ==============================================================================

-- 1. AUTH HOOK: Automatically create profile on user registration
CREATE OR REPLACE FUNCTION public.handle_new_user()
RETURNS TRIGGER
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public
AS $$
BEGIN
    INSERT INTO public.profiles (
        id,
        email,
        role,
        company_name,
        contact_name,
        phone,
        is_verified
    )
    VALUES (
        NEW.id,
        NEW.email,
        COALESCE(NEW.raw_user_meta_data->>'role', 'buyer'),
        COALESCE(NEW.raw_user_meta_data->>'company_name', ''),
        COALESCE(NEW.raw_user_meta_data->>'contact_name', ''),
        COALESCE(NEW.raw_user_meta_data->>'phone', ''),
        CASE 
            WHEN COALESCE(NEW.raw_user_meta_data->>'role', 'buyer') = 'buyer' THEN TRUE
            ELSE FALSE
        END
    )
    ON CONFLICT (id) DO UPDATE
    SET 
        email = EXCLUDED.email,
        updated_at = NOW();

    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS on_auth_user_created ON auth.users;
CREATE TRIGGER on_auth_user_created
    AFTER INSERT ON auth.users
    FOR EACH ROW EXECUTE FUNCTION public.handle_new_user();


-- 2. REPRICING ENGINE: Recalculate pool reserved quantity & tier pricing
CREATE OR REPLACE FUNCTION public.recalculate_pool_pricing(p_pool_id UUID)
RETURNS JSONB
LANGUAGE plpgsql
SECURITY DEFINER
AS $$
DECLARE
    v_total_qty INT := 0;
    v_moq INT;
    v_current_status TEXT;
    v_best_tier_price NUMERIC(12, 2);
    v_highest_tier_price NUMERIC(12, 2);
    v_result JSONB;
BEGIN
    SELECT moq, status INTO v_moq, v_current_status
    FROM public.pools
    WHERE id = p_pool_id;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Pool not found with ID %', p_pool_id;
    END IF;

    -- Calculate total reserved quantity
    SELECT COALESCE(SUM(quantity), 0) INTO v_total_qty
    FROM public.pool_commitments
    WHERE pool_id = p_pool_id AND status = 'confirmed';

    -- Base tier price
    SELECT unit_price INTO v_highest_tier_price
    FROM public.pool_tiers
    WHERE pool_id = p_pool_id
    ORDER BY min_qty ASC
    LIMIT 1;

    -- Best unlocked tier price
    SELECT unit_price INTO v_best_tier_price
    FROM public.pool_tiers
    WHERE pool_id = p_pool_id 
      AND v_total_qty >= min_qty
      AND (max_qty IS NULL OR v_total_qty <= max_qty)
    ORDER BY unit_price ASC
    LIMIT 1;

    IF v_best_tier_price IS NULL THEN
        v_best_tier_price := v_highest_tier_price;
    END IF;

    -- MOQ status upgrade
    IF v_total_qty >= v_moq AND v_current_status = 'active' THEN
        v_current_status := 'reached';
    END IF;

    -- Update pool state
    UPDATE public.pools
    SET 
        reserved_qty = v_total_qty,
        current_tier_price = v_best_tier_price,
        status = v_current_status,
        updated_at = NOW()
    WHERE id = p_pool_id;

    -- Reprice all participants
    IF v_current_status = 'reached' OR v_total_qty > 0 THEN
        UPDATE public.pool_commitments
        SET 
            final_unit_price = v_best_tier_price,
            updated_at = NOW()
        WHERE pool_id = p_pool_id AND status = 'confirmed';
    END IF;

    v_result := jsonb_build_object(
        'pool_id', p_pool_id,
        'reserved_qty', v_total_qty,
        'moq', v_moq,
        'current_tier_price', v_best_tier_price,
        'status', v_current_status
    );

    RETURN v_result;
END;
$$;


-- 3. ATOMIC POOL COMMITMENT (Row-Level Locking to Prevent Race Conditions)
CREATE OR REPLACE FUNCTION public.join_pool_atomic(
    p_pool_id UUID,
    p_buyer_id UUID,
    p_quantity INT,
    p_shipping_address JSONB DEFAULT '{}'::jsonb
)
RETURNS JSONB
LANGUAGE plpgsql
SECURITY DEFINER
AS $$
DECLARE
    v_pool RECORD;
    v_current_unit_price NUMERIC(12, 2);
    v_existing_commitment_id UUID;
    v_reprice_result JSONB;
BEGIN
    IF p_quantity <= 0 THEN
        RAISE EXCEPTION 'Commitment quantity must be greater than zero.';
    END IF;

    -- Lock the pool row
    SELECT * INTO v_pool
    FROM public.pools
    WHERE id = p_pool_id
    FOR UPDATE;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Pool does not exist.';
    END IF;

    IF v_pool.status NOT IN ('active', 'reached') THEN
        RAISE EXCEPTION 'Pool is not currently accepting commitments (Status: %).', v_pool.status;
    END IF;

    IF v_pool.expires_at < NOW() THEN
        RAISE EXCEPTION 'Pool has expired.';
    END IF;

    IF p_quantity < v_pool.min_per_buyer THEN
        RAISE EXCEPTION 'Minimum order quantity per buyer is % units.', v_pool.min_per_buyer;
    END IF;

    IF v_pool.max_per_buyer IS NOT NULL AND p_quantity > v_pool.max_per_buyer THEN
        RAISE EXCEPTION 'Maximum order quantity per buyer is % units.', v_pool.max_per_buyer;
    END IF;

    v_current_unit_price := v_pool.current_tier_price;

    -- UPSERT commitment
    INSERT INTO public.pool_commitments (
        pool_id,
        buyer_id,
        quantity,
        locked_unit_price,
        final_unit_price,
        shipping_address,
        status
    )
    VALUES (
        p_pool_id,
        p_buyer_id,
        p_quantity,
        v_current_unit_price,
        v_current_unit_price,
        p_shipping_address,
        'confirmed'
    )
    ON CONFLICT (pool_id, buyer_id) DO UPDATE
    SET 
        quantity = EXCLUDED.quantity,
        shipping_address = EXCLUDED.shipping_address,
        status = 'confirmed',
        updated_at = NOW()
    RETURNING id INTO v_existing_commitment_id;

    -- Recalculate tiers and reprice pool
    v_reprice_result := public.recalculate_pool_pricing(p_pool_id);

    RETURN jsonb_build_object(
        'success', TRUE,
        'commitment_id', v_existing_commitment_id,
        'pool_state', v_reprice_result
    );
END;
$$;


-- 4. CONVERT REACHED POOL TO ORDERS (Calculates 2% Platform Fee & 18% GST)
CREATE OR REPLACE FUNCTION public.convert_pool_to_orders(p_pool_id UUID)
RETURNS JSONB
LANGUAGE plpgsql
SECURITY DEFINER
AS $$
DECLARE
    v_pool RECORD;
    v_commitment RECORD;
    v_subtotal NUMERIC(12, 2);
    v_platform_fee NUMERIC(12, 2);
    v_gst NUMERIC(12, 2);
    v_total NUMERIC(12, 2);
    v_order_count INT := 0;
    v_order_num TEXT;
BEGIN
    SELECT * INTO v_pool
    FROM public.pools
    WHERE id = p_pool_id
    FOR UPDATE;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Pool not found.';
    END IF;

    IF v_pool.status NOT IN ('reached', 'closed') THEN
        RAISE EXCEPTION 'Only pools in reached or closed status can be converted to orders.';
    END IF;

    UPDATE public.pools
    SET status = 'closed', closed_at = NOW()
    WHERE id = p_pool_id;

    FOR v_commitment IN 
        SELECT * FROM public.pool_commitments 
        WHERE pool_id = p_pool_id AND status = 'confirmed'
    LOOP
        v_subtotal := ROUND(v_commitment.quantity * COALESCE(v_commitment.final_unit_price, v_commitment.locked_unit_price), 2);
        v_platform_fee := ROUND(v_subtotal * 0.02, 2);
        v_gst := ROUND(v_subtotal * 0.18, 2);
        v_total := v_subtotal + v_platform_fee + v_gst;

        v_order_num := 'ORD-' || UPPER(SUBSTRING(p_pool_id::text, 1, 6)) || '-' || LPAD(FLOOR(RANDOM() * 90000 + 10000)::text, 5, '0');

        INSERT INTO public.orders (
            order_number,
            pool_id,
            buyer_id,
            commitment_id,
            quantity,
            unit_price,
            subtotal,
            platform_fee,
            gst_amount,
            total_amount,
            shipping_address,
            order_status,
            payment_status
        )
        VALUES (
            v_order_num,
            p_pool_id,
            v_commitment.buyer_id,
            v_commitment.id,
            v_commitment.quantity,
            COALESCE(v_commitment.final_unit_price, v_commitment.locked_unit_price),
            v_subtotal,
            v_platform_fee,
            v_gst,
            v_total,
            v_commitment.shipping_address,
            'processing',
            'escrow_hold'
        );

        v_order_count := v_order_count + 1;
    END LOOP;

    RETURN jsonb_build_object(
        'success', TRUE,
        'pool_id', p_pool_id,
        'orders_created', v_order_count
    );
END;
$$;


-- ==============================================================================
-- SECTION 4: ROW LEVEL SECURITY (RLS) POLICIES
-- ==============================================================================

-- Security helper functions
CREATE OR REPLACE FUNCTION public.is_admin()
RETURNS BOOLEAN
LANGUAGE sql
SECURITY DEFINER
STABLE
AS $$
    SELECT EXISTS (
        SELECT 1 FROM public.profiles
        WHERE id = auth.uid() AND role = 'admin'
    );
$$;

CREATE OR REPLACE FUNCTION public.is_verified_seller()
RETURNS BOOLEAN
LANGUAGE sql
SECURITY DEFINER
STABLE
AS $$
    SELECT EXISTS (
        SELECT 1 FROM public.profiles
        WHERE id = auth.uid() AND role = 'seller' AND is_verified = TRUE
    );
$$;

-- Enable RLS on all tables
ALTER TABLE public.profiles ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.categories ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.subcategories ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.products ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.pools ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.pool_tiers ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.pool_commitments ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.orders ENABLE ROW LEVEL SECURITY;

-- Profiles Policies
CREATE POLICY "Public can view verified sellers"
ON public.profiles FOR SELECT
USING (role = 'seller' AND is_verified = TRUE);

CREATE POLICY "Users can view own profile"
ON public.profiles FOR SELECT
USING (auth.uid() = id);

CREATE POLICY "Users can update own profile"
ON public.profiles FOR UPDATE
USING (auth.uid() = id)
WITH CHECK (
    auth.uid() = id 
    AND role = (SELECT role FROM public.profiles WHERE id = auth.uid())
    AND is_verified = (SELECT is_verified FROM public.profiles WHERE id = auth.uid())
);

CREATE POLICY "Admins have full access to profiles"
ON public.profiles FOR ALL
USING (public.is_admin());

-- Categories & Subcategories Policies
CREATE POLICY "Public read active categories"
ON public.categories FOR SELECT
USING (is_active = TRUE OR public.is_admin());

CREATE POLICY "Public read active subcategories"
ON public.subcategories FOR SELECT
USING (is_active = TRUE OR public.is_admin());

CREATE POLICY "Admins manage categories"
ON public.categories FOR ALL
USING (public.is_admin());

CREATE POLICY "Admins manage subcategories"
ON public.subcategories FOR ALL
USING (public.is_admin());

-- Products Policies
CREATE POLICY "Public read approved products"
ON public.products FOR SELECT
USING ((is_approved = TRUE AND is_active = TRUE) OR public.is_admin() OR seller_id = auth.uid());

CREATE POLICY "Verified sellers create products"
ON public.products FOR INSERT
WITH CHECK (public.is_verified_seller() AND seller_id = auth.uid());

CREATE POLICY "Sellers update own products"
ON public.products FOR UPDATE
USING (seller_id = auth.uid() OR public.is_admin())
WITH CHECK (seller_id = auth.uid() OR public.is_admin());

CREATE POLICY "Admins manage all products"
ON public.products FOR ALL
USING (public.is_admin());

-- Pools & Pool Tiers Policies
CREATE POLICY "Public read active pools"
ON public.pools FOR SELECT
USING (status != 'draft' OR seller_id = auth.uid() OR public.is_admin());

CREATE POLICY "Public read pool tiers"
ON public.pool_tiers FOR SELECT
USING (TRUE);

CREATE POLICY "Sellers create pools"
ON public.pools FOR INSERT
WITH CHECK (public.is_verified_seller() AND seller_id = auth.uid());

CREATE POLICY "Sellers update own draft pools"
ON public.pools FOR UPDATE
USING (seller_id = auth.uid() OR public.is_admin());

CREATE POLICY "Sellers manage pool tiers"
ON public.pool_tiers FOR ALL
USING (
    EXISTS (SELECT 1 FROM public.pools WHERE id = pool_id AND seller_id = auth.uid())
    OR public.is_admin()
);

CREATE POLICY "Admins manage all pools"
ON public.pools FOR ALL
USING (public.is_admin());

-- Commitments Policies
CREATE POLICY "Buyers read own commitments"
ON public.pool_commitments FOR SELECT
USING (buyer_id = auth.uid() OR public.is_admin() OR EXISTS (
    SELECT 1 FROM public.pools WHERE id = pool_commitments.pool_id AND seller_id = auth.uid()
));

CREATE POLICY "Buyers insert commitments"
ON public.pool_commitments FOR INSERT
WITH CHECK (buyer_id = auth.uid());

CREATE POLICY "Admins manage all commitments"
ON public.pool_commitments FOR ALL
USING (public.is_admin());

-- Orders Policies
CREATE POLICY "Buyers view own orders"
ON public.orders FOR SELECT
USING (buyer_id = auth.uid() OR public.is_admin() OR EXISTS (
    SELECT 1 FROM public.pools WHERE id = orders.pool_id AND seller_id = auth.uid()
));

CREATE POLICY "Admins manage all orders"
ON public.orders FOR ALL
USING (public.is_admin());


-- ==============================================================================
-- SECTION 5: INITIAL SEED DATA
-- ==============================================================================

-- 1. SEED CATEGORIES
INSERT INTO public.categories (id, name, slug, description, icon, sort_order, is_active)
VALUES
    ('c0000001-0000-0000-0000-000000000001', 'Apparel & Fashion Wholesale', 'apparel-fashion', 'Bulk garment manufacturing, custom blanks, premium cotton t-shirts, hoodies, and corporate uniforms.', 'checkroom', 1, TRUE),
    ('c0000002-0000-0000-0000-000000000002', 'Durable Electronics & Hardware', 'durable-electronics', 'Climate-resilient, commercial electronics: GaN chargers, braided cables, networking gear, and LED drivers. (Strictly warehouse-stable; zero heat-degradable batteries).', 'cable', 2, TRUE),
    ('c0000003-0000-0000-0000-000000000003', 'Packaging & Supply Chain', 'packaging-supply', 'Corrugated boxes, honeycomb wrap, thermal shipping labels, and industrial strapping.', 'inventory_2', 3, TRUE),
    ('c0000004-0000-0000-0000-000000000004', 'Industrial Tools & Safety', 'tools-safety', 'Hand tool bulk lots, safety helmets, protective gloves, and workshop essentials.', 'construction', 4, TRUE),
    ('c0000005-0000-0000-0000-000000000005', 'Commercial Hospitality & Kitchen', 'hospitality-kitchen', 'Stainless steel cookware, gastronorm pans, commercial tableware, and hotel supplies.', 'restaurant', 5, TRUE)
ON CONFLICT (slug) DO UPDATE
SET name = EXCLUDED.name, description = EXCLUDED.description, icon = EXCLUDED.icon;

-- 2. SEED SUBCATEGORIES WITH DYNAMIC FILTER SCHEMAS
INSERT INTO public.subcategories (id, category_id, name, slug, description, filter_schema, sort_order, is_active)
VALUES
    -- Apparel Subcategories
    (
        's0000001-0000-0000-0000-000000000001',
        'c0000001-0000-0000-0000-000000000001',
        'Oversized & Heavyweight T-Shirts',
        'oversized-tshirts',
        'Premium drop-shoulder streetwear blanks, combed cotton.',
        '{
            "filters": [
                {"key": "size", "label": "Size", "type": "multi_select", "options": ["XS", "S", "M", "L", "XL", "2XL", "3XL"]},
                {"key": "color", "label": "Color", "type": "color_palette", "options": ["Pitch Black", "Pure White", "Navy Blue", "Olive Green", "Charcoal Gray", "Burgundy", "Sand Beige"]},
                {"key": "fabric", "label": "Fabric Composition", "type": "single_select", "options": ["100% Combed Cotton", "Cotton Blend (80/20)", "100% Organic Bio-Washed Cotton", "French Terry"]},
                {"key": "gsm", "label": "Fabric Weight (GSM)", "type": "badge_select", "options": ["180 GSM", "220 GSM", "240 GSM", "280 GSM"]},
                {"key": "fit", "label": "Fit Type", "type": "single_select", "options": ["Oversized / Drop Shoulder", "Regular Fit", "Boxy Fit"]},
                {"key": "gender", "label": "Gender", "type": "single_select", "options": ["Unisex", "Men", "Women"]}
            ]
        }'::jsonb,
        1,
        TRUE
    ),
    (
        's0000002-0000-0000-0000-000000000002',
        'c0000001-0000-0000-0000-000000000001',
        'Heavyweight Hoodies & Fleeces',
        'hoodies-fleeces',
        'Premium 350-400 GSM brushed fleece hooded sweatshirts.',
        '{
            "filters": [
                {"key": "size", "label": "Size", "type": "multi_select", "options": ["S", "M", "L", "XL", "2XL"]},
                {"key": "color", "label": "Color", "type": "color_palette", "options": ["Black", "Heather Gray", "Forest Green", "Navy Blue", "Oatmeal"]},
                {"key": "fabric", "label": "Fabric", "type": "single_select", "options": ["100% Cotton Fleece", "80/20 Poly-Cotton Heavy Fleece"]},
                {"key": "gsm", "label": "Fabric Weight", "type": "badge_select", "options": ["320 GSM", "360 GSM", "400 GSM"]},
                {"key": "fit", "label": "Fit Type", "type": "single_select", "options": ["Oversized", "Regular Fit"]}
            ]
        }'::jsonb,
        2,
        TRUE
    ),
    -- Durable Warehouse-Safe Electronics Subcategories
    (
        's0000003-0000-0000-0000-000000000002',
        'c0000002-0000-0000-0000-000000000002',
        'GaN Fast Chargers & Power Delivery Adapters',
        'gan-chargers',
        'High-efficiency Gallium Nitride wall chargers. Solid-state, climate-stable.',
        '{
            "filters": [
                {"key": "wattage", "label": "Power Rating", "type": "badge_select", "options": ["20W", "30W", "45W", "65W", "100W", "140W"]},
                {"key": "ports", "label": "Port Configuration", "type": "single_select", "options": ["Single USB-C", "Dual USB-C", "2C + 1A", "3C + 1A"]},
                {"key": "material", "label": "Casing Material", "type": "single_select", "options": ["UL94 V-0 Flame Retardant PC", "Matte ABS"]},
                {"key": "certification", "label": "Compliance & Safety", "type": "multi_select", "options": ["BIS Certified", "CE", "RoHS", "FCC"]},
                {"key": "warranty", "label": "Warranty", "type": "single_select", "options": ["1 Year Replacement", "2 Years Commercial"]}
            ]
        }'::jsonb,
        1,
        TRUE
    ),
    (
        's0000004-0000-0000-0000-000000000002',
        'c0000002-0000-0000-0000-000000000002',
        'Heavy-Duty Braided Cables & Interconnects',
        'braided-cables',
        'Commercial grade USB-C 240W, HDMI 2.1 8K, and Cat6A network cables.',
        '{
            "filters": [
                {"key": "connector", "label": "Connector Interface", "type": "single_select", "options": ["USB-C to USB-C (240W)", "USB-A to USB-C (60W)", "HDMI 2.1 Ultra High Speed", "Cat6A Shielded RJ45"]},
                {"key": "length", "label": "Cable Length", "type": "badge_select", "options": ["1 Meter (3.3ft)", "2 Meters (6.6ft)", "3 Meters (10ft)", "5 Meters (16ft)"]},
                {"key": "braiding", "label": "Outer Jacket", "type": "single_select", "options": ["Double-Braided Nylon", "Kevlar Reinforced", "Heavy PVC Jacket"]},
                {"key": "certification", "label": "Certification", "type": "multi_select", "options": ["USB-IF Certified", "HDMI 2.1 Certified", "RoHS"]}
            ]
        }'::jsonb,
        2,
        TRUE
    ),
    -- Packaging Subcategories
    (
        's0000005-0000-0000-0000-000000000003',
        'c0000003-0000-0000-0000-000000000003',
        '3-Ply & 5-Ply Corrugated Shipping Boxes',
        'corrugated-boxes',
        'Heavy-duty kraft fluted shipping cartons for e-commerce wholesale fulfillment.',
        '{
            "filters": [
                {"key": "box_ply", "label": "Board Strength", "type": "single_select", "options": ["3-Ply Single Wall", "5-Ply Double Wall", "7-Ply Heavy Duty"]},
                {"key": "paper_grade", "label": "Kraft Grade", "type": "single_select", "options": ["Virgin Kraft 140 GSM", "Semi-Kraft 120 GSM", "High-BF Recycled"]},
                {"key": "dimensions", "label": "Standard Box Size", "type": "single_select", "options": ["Small (8x6x4 in)", "Medium (12x10x8 in)", "Large (18x14x12 in)", "Pallet Size (24x18x18 in)"]}
            ]
        }'::jsonb,
        1,
        TRUE
    )
ON CONFLICT (category_id, slug) DO UPDATE
SET name = EXCLUDED.name, filter_schema = EXCLUDED.filter_schema, description = EXCLUDED.description;

-- 3. SEED AUTH USERS & PROFILES
DO $$
BEGIN
    IF EXISTS (SELECT FROM pg_tables WHERE schemaname = 'auth' AND tablename = 'users') THEN
        INSERT INTO auth.users (id, instance_id, aud, role, email, encrypted_password, email_confirmed_at, raw_app_meta_data, raw_user_meta_data, created_at, updated_at)
        VALUES 
            ('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'admin@poolbuy.io', crypt('Admin@123', gen_salt('bf')), NOW(), '{"provider":"email","providers":["email"]}', '{"role":"admin","company_name":"PoolBuy Marketplace Operations"}', NOW(), NOW()),
            ('00000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'textiles@apexmills.com', crypt('Seller@123', gen_salt('bf')), NOW(), '{"provider":"email","providers":["email"]}', '{"role":"seller","company_name":"Apex TexFab Mills Ltd."}', NOW(), NOW()),
            ('00000000-0000-0000-0000-000000000003', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'sales@novavolt.in', crypt('Seller@123', gen_salt('bf')), NOW(), '{"provider":"email","providers":["email"]}', '{"role":"seller","company_name":"NovaVolt Power Electronics"}', NOW(), NOW()),
            ('00000000-0000-0000-0000-000000000004', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'procure@urbanstreet.in', crypt('Buyer@123', gen_salt('bf')), NOW(), '{"provider":"email","providers":["email"]}', '{"role":"buyer","company_name":"UrbanStreet Apparel Retail"}', NOW(), NOW()),
            ('00000000-0000-0000-0000-000000000005', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'stock@gadgetden.com', crypt('Buyer@123', gen_salt('bf')), NOW(), '{"provider":"email","providers":["email"]}', '{"role":"buyer","company_name":"GadgetDen Wholesale Distribution"}', NOW(), NOW())
        ON CONFLICT (id) DO NOTHING;
    END IF;
END $$;

INSERT INTO public.profiles (id, email, role, company_name, contact_name, gstin, phone, is_verified)
VALUES
    ('00000000-0000-0000-0000-000000000001', 'admin@poolbuy.io', 'admin', 'PoolBuy Marketplace Operations', 'Chief Platform Admin', '07AAAAA0000A1Z5', '+91 98765 00001', TRUE),
    ('00000000-0000-0000-0000-000000000002', 'textiles@apexmills.com', 'seller', 'Apex TexFab Mills Ltd.', 'Rajesh Singhania', '07AABCA1234F1Z8', '+91 98110 44552', TRUE),
    ('00000000-0000-0000-0000-000000000003', 'sales@novavolt.in', 'seller', 'NovaVolt Power Electronics', 'Arjun Mehta', '27AABCN5678M1ZQ', '+91 97223 99881', TRUE),
    ('00000000-0000-0000-0000-000000000004', 'procure@urbanstreet.in', 'buyer', 'UrbanStreet Apparel Retail', 'Kavita Verma', '29AABCU9012K1ZX', '+91 99001 22334', TRUE),
    ('00000000-0000-0000-0000-000000000005', 'stock@gadgetden.com', 'buyer', 'GadgetDen Wholesale Distribution', 'Vikram Desai', '33AABCG3456L1ZP', '+91 98840 55667', TRUE)
ON CONFLICT (id) DO UPDATE
SET role = EXCLUDED.role, company_name = EXCLUDED.company_name, is_verified = EXCLUDED.is_verified;

-- 4. SEED PRODUCTS
INSERT INTO public.products (
    id, seller_id, category_id, subcategory_id, title, slug, sku, description,
    images, base_price, mrp, stock_quantity, attributes, is_approved, is_active
)
VALUES
    (
        'p0000001-0000-0000-0000-000000000001',
        '00000000-0000-0000-0000-000000000002',
        'c0000001-0000-0000-0000-000000000001',
        's0000001-0000-0000-0000-000000000001',
        'Heavyweight 240 GSM Combed Cotton Oversized T-Shirt Blanks',
        'heavyweight-240-gsm-oversized-tee',
        'PB-TEX-TEE-240',
        'Pre-shrunk, bio-washed combed cotton oversized streetwear blanks. Double needle stitched collar and hem. Ideal for direct-to-garment, screen printing, or private label dropshipping.',
        '["https://images.unsplash.com/photo-1521572267360-ee0c2909d518?w=800", "https://images.unsplash.com/photo-1583743814966-8936f5b7be1a?w=800"]'::jsonb,
        420.00,
        999.00,
        5000,
        '{
            "size": "L",
            "color": "Pitch Black",
            "fabric": "100% Combed Cotton",
            "gsm": "240 GSM",
            "fit": "Oversized / Drop Shoulder",
            "gender": "Unisex"
        }'::jsonb,
        TRUE,
        TRUE
    ),
    (
        'p0000002-0000-0000-0000-000000000002',
        '00000000-0000-0000-0000-000000000002',
        'c0000001-0000-0000-0000-000000000001',
        's0000001-0000-0000-0000-000000000001',
        'Pure Combed Cotton 220 GSM Relaxed Fit Blanks - Navy Blue',
        'combed-cotton-220-gsm-relaxed-navy',
        'PB-TEX-TEE-220-NV',
        'Premium 220 GSM combed compact cotton. Mercerized yarn with superior colorfastness. Tailored for retail boutique collections.',
        '["https://images.unsplash.com/photo-1618354691373-d851c5c3a990?w=800"]'::jsonb,
        380.00,
        899.00,
        3500,
        '{
            "size": "XL",
            "color": "Navy Blue",
            "fabric": "100% Combed Cotton",
            "gsm": "220 GSM",
            "fit": "Regular Fit",
            "gender": "Men"
        }'::jsonb,
        TRUE,
        TRUE
    ),
    (
        'p0000003-0000-0000-0000-000000000003',
        '00000000-0000-0000-0000-000000000003',
        'c0000002-0000-0000-0000-000000000002',
        's0000003-0000-0000-0000-000000000003',
        'NovaVolt 65W GaN Dual USB-C Fast Charger (BIS Certified)',
        'novavolt-65w-gan-dual-usbc-charger',
        'PB-ELE-GAN-65W',
        'Industrial-grade Gallium Nitride (GaN III) dual port USB-C charger. Operates at 35% lower temperature than conventional silicon blocks. Zero thermal degradation risk in warehouse conditions. BIS certified for commercial redistribution.',
        '["https://images.unsplash.com/photo-1583863788434-e58a36330cf0?w=800"]'::jsonb,
        1450.00,
        2999.00,
        2000,
        '{
            "wattage": "65W",
            "ports": "Dual USB-C",
            "material": "UL94 V-0 Flame Retardant PC",
            "certification": ["BIS Certified", "CE", "RoHS"],
            "warranty": "2 Years Commercial"
        }'::jsonb,
        TRUE,
        TRUE
    ),
    (
        'p0000004-0000-0000-0000-000000000003',
        '00000000-0000-0000-0000-000000000003',
        'c0000002-0000-0000-0000-000000000002',
        's0000004-0000-0000-0000-000000000004',
        'Kevlar-Reinforced 240W USB-C to USB-C Braided Cable (2M)',
        'kevlar-240w-usbc-cable-2m',
        'PB-ELE-CBL-240W-2M',
        'Heavy-gauge 5A/48V 240W Power Delivery braided cable with E-Marker chip. Tested to 35,000+ bend cycles. Commercial grade for device manufacturers and retail accessory brands.',
        '["https://images.unsplash.com/photo-1544716278-ca5e3f4abd8c?w=800"]'::jsonb,
        280.00,
        699.00,
        10000,
        '{
            "connector": "USB-C to USB-C (240W)",
            "length": "2 Meters (6.6ft)",
            "braiding": "Kevlar Reinforced",
            "certification": ["USB-IF Certified", "RoHS"],
            "warranty": "1 Year Replacement"
        }'::jsonb,
        TRUE,
        TRUE
    )
ON CONFLICT (id) DO NOTHING;

-- 5. SEED POOLS
INSERT INTO public.pools (
    id, pool_code, product_id, seller_id, moq, reserved_qty, min_per_buyer, max_per_buyer,
    status, current_tier_price, expires_at
)
VALUES
    (
        'b0000001-0000-0000-0000-000000000001',
        'PB-APP-7014',
        'p0000001-0000-0000-0000-000000000001',
        '00000000-0000-0000-0000-000000000002',
        500,
        380,
        25,
        200,
        'active',
        340.00,
        NOW() + INTERVAL '7 days'
    ),
    (
        'b0000002-0000-0000-0000-000000000002',
        'PB-ELE-8821',
        'p0000003-0000-0000-0000-000000000003',
        '00000000-0000-0000-0000-000000000003',
        200,
        210,
        10,
        100,
        'reached',
        890.00,
        NOW() + INTERVAL '4 days'
    ),
    (
        'b0000003-0000-0000-0000-000000000003',
        'PB-APP-9204',
        'p0000002-0000-0000-0000-000000000002',
        '00000000-0000-0000-0000-000000000002',
        300,
        90,
        20,
        150,
        'active',
        380.00,
        NOW() + INTERVAL '12 days'
    )
ON CONFLICT (pool_code) DO NOTHING;

-- 6. SEED POOL TIERS
INSERT INTO public.pool_tiers (pool_id, tier_number, min_qty, max_qty, unit_price)
VALUES
    -- Pool 1: T-Shirt Blanks (MOQ 500)
    ('b0000001-0000-0000-0000-000000000001', 1, 0, 199, 420.00),
    ('b0000001-0000-0000-0000-000000000001', 2, 200, 399, 380.00),
    ('b0000001-0000-0000-0000-000000000001', 3, 400, 499, 340.00),
    ('b0000001-0000-0000-0000-000000000001', 4, 500, NULL, 295.00),

    -- Pool 2: 65W GaN Chargers (MOQ 200 - Reached!)
    ('b0000002-0000-0000-0000-000000000002', 1, 0, 49, 1250.00),
    ('b0000002-0000-0000-0000-000000000002', 2, 50, 149, 1050.00),
    ('b0000002-0000-0000-0000-000000000002', 3, 150, 199, 950.00),
    ('b0000002-0000-0000-0000-000000000002', 4, 200, NULL, 890.00),

    -- Pool 3: 220 GSM Navy Blanks (MOQ 300)
    ('b0000003-0000-0000-0000-000000000003', 1, 0, 99, 380.00),
    ('b0000003-0000-0000-0000-000000000003', 2, 100, 199, 340.00),
    ('b0000003-0000-0000-0000-000000000003', 3, 200, 299, 310.00),
    ('b0000003-0000-0000-0000-000000000003', 4, 300, NULL, 275.00)
ON CONFLICT (pool_id, tier_number) DO NOTHING;

-- 7. SEED COMMITMENTS
INSERT INTO public.pool_commitments (
    id, pool_id, buyer_id, quantity, locked_unit_price, final_unit_price, shipping_address, status
)
VALUES
    (
        'm0000001-0000-0000-0000-000000000001',
        'b0000001-0000-0000-0000-000000000001',
        '00000000-0000-0000-0000-000000000004',
        180,
        420.00,
        340.00,
        '{"address_line": "Warehouse 4B, Okhla Phase III", "city": "New Delhi", "state": "Delhi", "postal_code": "110020", "country": "India"}'::jsonb,
        'confirmed'
    ),
    (
        'm0000002-0000-0000-0000-000000000002',
        'b0000001-0000-0000-0000-000000000001',
        '00000000-0000-0000-0000-000000000005',
        200,
        380.00,
        340.00,
        '{"address_line": "Plot 12, Whitefield Tech Zone", "city": "Bengaluru", "state": "Karnataka", "postal_code": "560066", "country": "India"}'::jsonb,
        'confirmed'
    ),
    (
        'm0000003-0000-0000-0000-000000000003',
        'b0000002-0000-0000-0000-000000000002',
        '00000000-0000-0000-0000-000000000005',
        210,
        1250.00,
        890.00,
        '{"address_line": "Plot 12, Whitefield Tech Zone", "city": "Bengaluru", "state": "Karnataka", "postal_code": "560066", "country": "India"}'::jsonb,
        'confirmed'
    )
ON CONFLICT (pool_id, buyer_id) DO NOTHING;
