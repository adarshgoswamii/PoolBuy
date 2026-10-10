-- ==============================================================================
-- POOLBUY B2B WHOLESALE MARKETPLACE: ROW LEVEL SECURITY (RLS) POLICIES
-- Migration: 20261008000003_rls_policies.sql
-- Description: Strict isolation, role-based governance and public catalog access.
-- ==============================================================================

-- 1. HELPER SECURITY FUNCTIONS
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

-- 2. ENABLE RLS ON ALL TABLES
ALTER TABLE public.profiles ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.categories ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.subcategories ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.products ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.pools ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.pool_tiers ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.pool_commitments ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.orders ENABLE ROW LEVEL SECURITY;

-- 3. PROFILES POLICIES
-- Anyone can view verified seller profiles (for storefront seller info)
CREATE POLICY "Public can view verified sellers"
ON public.profiles FOR SELECT
USING (role = 'seller' AND is_verified = TRUE);

-- Users can view their own profile
CREATE POLICY "Users can view own profile"
ON public.profiles FOR SELECT
USING (auth.uid() = id);

-- Users can update their own profile (cannot change role or verification directly)
CREATE POLICY "Users can update own profile"
ON public.profiles FOR UPDATE
USING (auth.uid() = id)
WITH CHECK (
    auth.uid() = id 
    AND role = (SELECT role FROM public.profiles WHERE id = auth.uid())
    AND is_verified = (SELECT is_verified FROM public.profiles WHERE id = auth.uid())
);

-- Admin can manage all profiles
CREATE POLICY "Admins have full access to profiles"
ON public.profiles FOR ALL
USING (public.is_admin());


-- 4. CATEGORIES & SUBCATEGORIES POLICIES
-- Anyone can read active categories
CREATE POLICY "Public read active categories"
ON public.categories FOR SELECT
USING (is_active = TRUE OR public.is_admin());

CREATE POLICY "Public read active subcategories"
ON public.subcategories FOR SELECT
USING (is_active = TRUE OR public.is_admin());

-- Only admins can modify taxonomy
CREATE POLICY "Admins manage categories"
ON public.categories FOR ALL
USING (public.is_admin());

CREATE POLICY "Admins manage subcategories"
ON public.subcategories FOR ALL
USING (public.is_admin());


-- 5. PRODUCTS POLICIES
-- Public can view approved active products
CREATE POLICY "Public read approved products"
ON public.products FOR SELECT
USING ((is_approved = TRUE AND is_active = TRUE) OR public.is_admin() OR seller_id = auth.uid());

-- Verified sellers can insert and update their own products
CREATE POLICY "Verified sellers create products"
ON public.products FOR INSERT
WITH CHECK (public.is_verified_seller() AND seller_id = auth.uid());

CREATE POLICY "Sellers update own products"
ON public.products FOR UPDATE
USING (seller_id = auth.uid() OR public.is_admin())
WITH CHECK (seller_id = auth.uid() OR public.is_admin());

-- Admins manage all products
CREATE POLICY "Admins manage all products"
ON public.products FOR ALL
USING (public.is_admin());


-- 6. POOLS & POOL TIERS POLICIES
-- Public can view non-draft pools
CREATE POLICY "Public read active pools"
ON public.pools FOR SELECT
USING (status != 'draft' OR seller_id = auth.uid() OR public.is_admin());

CREATE POLICY "Public read pool tiers"
ON public.pool_tiers FOR SELECT
USING (TRUE);

-- Verified sellers can manage their own pools
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

-- Admins manage all pools
CREATE POLICY "Admins manage all pools"
ON public.pools FOR ALL
USING (public.is_admin());


-- 7. POOL COMMITMENTS POLICIES
-- Buyers can read their own commitments
CREATE POLICY "Buyers read own commitments"
ON public.pool_commitments FOR SELECT
USING (buyer_id = auth.uid() OR public.is_admin() OR EXISTS (
    SELECT 1 FROM public.pools WHERE id = pool_commitments.pool_id AND seller_id = auth.uid()
));

-- Buyers can insert own commitments
CREATE POLICY "Buyers insert commitments"
ON public.pool_commitments FOR INSERT
WITH CHECK (buyer_id = auth.uid());

-- Admins manage all commitments
CREATE POLICY "Admins manage all commitments"
ON public.pool_commitments FOR ALL
USING (public.is_admin());


-- 8. ORDERS POLICIES
-- Buyers can view their own orders
CREATE POLICY "Buyers view own orders"
ON public.orders FOR SELECT
USING (buyer_id = auth.uid() OR public.is_admin() OR EXISTS (
    SELECT 1 FROM public.pools WHERE id = orders.pool_id AND seller_id = auth.uid()
));

-- Admins manage all orders
CREATE POLICY "Admins manage all orders"
ON public.orders FOR ALL
USING (public.is_admin());
