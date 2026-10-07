-- ==============================================================================
-- POOLBUY B2B WHOLESALE MARKETPLACE: STORED PROCEDURES & TRIGGERS
-- Migration: 20261008000002_functions_triggers.sql
-- Description: Auth profile trigger, atomic pool reservation, repricing engine & order generation.
-- ==============================================================================

-- 1. AUTH TRIGGER: Automatically create profile on user registration
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
        -- Auto-verify buyers; sellers require admin KYC review
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

-- Drop trigger if already exists
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
    -- Get pool details
    SELECT moq, status INTO v_moq, v_current_status
    FROM public.pools
    WHERE id = p_pool_id;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Pool not found with ID %', p_pool_id;
    END IF;

    -- Calculate total reserved quantity from confirmed commitments
    SELECT COALESCE(SUM(quantity), 0) INTO v_total_qty
    FROM public.pool_commitments
    WHERE pool_id = p_pool_id AND status = 'confirmed';

    -- Get highest (base) tier price as fallback
    SELECT unit_price INTO v_highest_tier_price
    FROM public.pool_tiers
    WHERE pool_id = p_pool_id
    ORDER BY min_qty ASC
    LIMIT 1;

    -- Find the best unlocked tier price based on current reserved quantity
    SELECT unit_price INTO v_best_tier_price
    FROM public.pool_tiers
    WHERE pool_id = p_pool_id 
      AND v_total_qty >= min_qty
      AND (max_qty IS NULL OR v_total_qty <= max_qty)
    ORDER BY unit_price ASC
    LIMIT 1;

    -- If no specific tier matched, default to the base price
    IF v_best_tier_price IS NULL THEN
        v_best_tier_price := v_highest_tier_price;
    END IF;

    -- Determine new status if MOQ threshold is reached
    IF v_total_qty >= v_moq AND v_current_status = 'active' THEN
        v_current_status := 'reached';
    END IF;

    -- Update the pool with new reserved_qty and current_tier_price
    UPDATE public.pools
    SET 
        reserved_qty = v_total_qty,
        current_tier_price = v_best_tier_price,
        status = v_current_status,
        updated_at = NOW()
    WHERE id = p_pool_id;

    -- If status is reached or tiered pricing improved, reprice all confirmed commitments
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


-- 3. ATOMIC POOL COMMITMENT (With Row-Level Locking to Prevent Overselling)
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

    -- Lock the pool row for update (prevents race conditions)
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

    -- Validate per-buyer limits
    IF p_quantity < v_pool.min_per_buyer THEN
        RAISE EXCEPTION 'Minimum order quantity per buyer is % units.', v_pool.min_per_buyer;
    END IF;

    IF v_pool.max_per_buyer IS NOT NULL AND p_quantity > v_pool.max_per_buyer THEN
        RAISE EXCEPTION 'Maximum order quantity per buyer is % units.', v_pool.max_per_buyer;
    END IF;

    -- Determine locked unit price
    v_current_unit_price := v_pool.current_tier_price;

    -- Insert or update commitment (UPSERT per buyer per pool)
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

    -- Recalculate pool tiers and reprice participants
    v_reprice_result := public.recalculate_pool_pricing(p_pool_id);

    RETURN jsonb_build_object(
        'success', TRUE,
        'commitment_id', v_existing_commitment_id,
        'pool_state', v_reprice_result
    );
END;
$$;


-- 4. CONVERT REACHED POOL TO ORDERS (With 2% Platform Fee & 18% GST)
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

    -- Mark pool as closed for conversion
    UPDATE public.pools
    SET status = 'closed', closed_at = NOW()
    WHERE id = p_pool_id;

    -- Iterate through all confirmed commitments
    FOR v_commitment IN 
        SELECT * FROM public.pool_commitments 
        WHERE pool_id = p_pool_id AND status = 'confirmed'
    LOOP
        -- Calculate commercial pricing breakdown
        v_subtotal := ROUND(v_commitment.quantity * COALESCE(v_commitment.final_unit_price, v_commitment.locked_unit_price), 2);
        v_platform_fee := ROUND(v_subtotal * 0.02, 2); -- 2% platform fee
        v_gst := ROUND(v_subtotal * 0.18, 2);          -- 18% GST
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
