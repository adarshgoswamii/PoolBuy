-- ==============================================================================
-- POOLBUY B2B WHOLESALE MARKETPLACE: COMPREHENSIVE SEED DATA
-- Seed: seed.sql
-- Description: E-commerce taxonomy, dynamic filter schemas, demo sellers, products, pools & tiers.
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
        's0000003-0000-0000-0000-000000000003',
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
        's0000004-0000-0000-0000-000000000004',
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
        's0000005-0000-0000-0000-000000000005',
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


-- 3. SEED SELLER & BUYER PROFILES (With synthetic demo IDs)
-- Ensure matching auth.users records exist in Supabase Auth to satisfy foreign key constraints
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
    -- Admin User
    ('00000000-0000-0000-0000-000000000001', 'admin@poolbuy.io', 'admin', 'PoolBuy Marketplace Operations', 'Chief Platform Admin', '07AAAAA0000A1Z5', '+91 98765 00001', TRUE),
    -- Verified Manufacturer / Seller 1
    ('00000000-0000-0000-0000-000000000002', 'textiles@apexmills.com', 'seller', 'Apex TexFab Mills Ltd.', 'Rajesh Singhania', '07AABCA1234F1Z8', '+91 98110 44552', TRUE),
    -- Verified Manufacturer / Seller 2
    ('00000000-0000-0000-0000-000000000003', 'sales@novavolt.in', 'seller', 'NovaVolt Power Electronics', 'Arjun Mehta', '27AABCN5678M1ZQ', '+91 97223 99881', TRUE),
    -- Bulk Buyer 1
    ('00000000-0000-0000-0000-000000000004', 'procure@urbanstreet.in', 'buyer', 'UrbanStreet Apparel Retail', 'Kavita Verma', '29AABCU9012K1ZX', '+91 99001 22334', TRUE),
    -- Bulk Buyer 2
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


-- 6. SEED POOL TIERS (Ladder pricing)
INSERT INTO public.pool_tiers (pool_id, tier_number, min_qty, max_qty, unit_price)
VALUES
    -- Pool 1: T-Shirt Blanks (MOQ 500)
    ('b0000001-0000-0000-0000-000000000001', 1, 0, 199, 420.00),
    ('b0000001-0000-0000-0000-000000000001', 2, 200, 399, 380.00),
    ('b0000001-0000-0000-0000-000000000001', 3, 400, 499, 340.00),
    ('b0000001-0000-0000-0000-000000000001', 4, 500, NULL, 295.00), -- Unlocks at MOQ!

    -- Pool 2: 65W GaN Chargers (MOQ 200 - Reached!)
    ('b0000002-0000-0000-0000-000000000002', 1, 0, 49, 1250.00),
    ('b0000002-0000-0000-0000-000000000002', 2, 50, 149, 1050.00),
    ('b0000002-0000-0000-0000-000000000002', 3, 150, 199, 950.00),
    ('b0000002-0000-0000-0000-000000000002', 4, 200, NULL, 890.00), -- Currently reached

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
