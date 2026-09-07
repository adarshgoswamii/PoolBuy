// Idempotent seed script for PoolBuy. Run with: npm run seed  (tsx scripts/seed.ts)
//
// Steps:
//   1. Apply db/schema.sql (all CREATE ... IF NOT EXISTS, safe to re-run).
//   2. Seed 5 sellers, ~10 products, 10 pools (mix of active/expired/fulfilled),
//      each with a 3-tier ladder, realistic reserved_qty, reservations (some
//      near/at MOQ), and 2 demo customers.
//
// Idempotency strategy: every row keyed on a natural unique column
// (sellers.email, products.sku, pools.reference, customers.email). We upsert on
// those keys. Tiers/reservations/events are cleared per-pool then re-inserted so
// re-running produces the same final state without duplicates.

import { readFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { neon } from '@neondatabase/serverless';

import { poolUnitPrice, type RawTier } from '../lib/domain/poolCalculator';

const __dirname = dirname(fileURLToPath(import.meta.url));
const SCHEMA_PATH = join(__dirname, '..', 'db', 'schema.sql');

const DATABASE_URL = process.env.DATABASE_URL;
if (!DATABASE_URL || DATABASE_URL.trim().length === 0) {
  console.error(
    'DATABASE_URL is not set. Point it at a Neon/Vercel Postgres connection string, e.g.\n' +
      '  DATABASE_URL="postgres://user:pass@host/db?sslmode=require" npm run seed',
  );
  process.exit(1);
}

const sql = neon(DATABASE_URL);

// A standard 3-tier ladder shared by pools (10-49@1550, 50-149@1380, 150+@1250).
const STANDARD_TIERS: RawTier[] = [
  { min_qty: 10, max_qty: 49, price: 1550 },
  { min_qty: 50, max_qty: 149, price: 1380 },
  { min_qty: 150, max_qty: null, price: 1250 },
];

const DAY = 24 * 60 * 60 * 1000;
const now = Date.now();
const iso = (ms: number) => new Date(ms).toISOString();

/** Split a SQL script into individual statements, ignoring line comments. */
function splitStatements(script: string): string[] {
  return script
    .split('\n')
    .filter((line) => !line.trim().startsWith('--'))
    .join('\n')
    .split(';')
    .map((s) => s.trim())
    .filter((s) => s.length > 0);
}

async function applySchema(): Promise<void> {
  const schema = readFileSync(SCHEMA_PATH, 'utf8');
  // The neon HTTP driver executes one statement per request, so run each
  // CREATE / CREATE INDEX statement separately (all are IF NOT EXISTS).
  for (const statement of splitStatements(schema)) {
    await sql(statement);
  }
  console.log('✓ schema applied');
}

async function seedSellers(): Promise<Record<string, number>> {
  const sellers = [
    { email: 'orders@bluewave-pools.in', name: 'Bluewave Pool Supplies', company: 'Bluewave Pvt Ltd' },
    { email: 'sales@aquatech-india.in', name: 'AquaTech India', company: 'AquaTech Systems' },
    { email: 'hello@poolpro.co.in', name: 'PoolPro Distributors', company: 'PoolPro LLP' },
    { email: 'contact@chlorinehub.in', name: 'ChlorineHub', company: 'ChlorineHub Chemicals' },
    { email: 'info@deepend-equip.in', name: 'DeepEnd Equipment', company: 'DeepEnd Equip Co' },
  ];
  const map: Record<string, number> = {};
  for (const s of sellers) {
    const rows = await sql`
      INSERT INTO sellers (email, name, company)
      VALUES (${s.email}, ${s.name}, ${s.company})
      ON CONFLICT (email) DO UPDATE
        SET name = EXCLUDED.name, company = EXCLUDED.company
      RETURNING seller_id
    `;
    map[s.email] = Number((rows[0] as { seller_id: number }).seller_id);
  }
  console.log(`✓ ${sellers.length} sellers`);
  return map;
}

async function seedCustomers(): Promise<number[]> {
  const customers = [
    {
      email: 'buyer1@demo.poolbuy.in',
      // NOTE: demo bcrypt-style placeholder hash for password "demo1234"; not a real credential.
      password_hash: '$2y$10$demoDEMOdemoDEMOdemoDEuLC4Z6b6QF6yqk0m2h8e0k0m2h8e0k0m',
      name: 'Ravi Enterprises',
      address: '12 MG Road, Bengaluru, KA 560001',
    },
    {
      email: 'buyer2@demo.poolbuy.in',
      password_hash: '$2y$10$demoDEMOdemoDEMOdemoDEuLC4Z6b6QF6yqk0m2h8e0k0m2h8e0k0m',
      name: 'Sunrise Resorts',
      address: '88 Beach Road, Chennai, TN 600006',
    },
  ];
  const ids: number[] = [];
  for (const c of customers) {
    const rows = await sql`
      INSERT INTO customers (email, password_hash, name, address)
      VALUES (${c.email}, ${c.password_hash}, ${c.name}, ${c.address})
      ON CONFLICT (email) DO UPDATE
        SET password_hash = EXCLUDED.password_hash,
            name = EXCLUDED.name,
            address = EXCLUDED.address
      RETURNING customer_id
    `;
    ids.push(Number((rows[0] as { customer_id: number }).customer_id));
  }
  console.log(`✓ ${customers.length} demo customers`);
  return ids;
}

interface ProductSpec {
  sku: string;
  name: string;
  sellerEmail: string;
  unit: string;
  description: string;
}

async function seedProducts(sellerMap: Record<string, number>): Promise<Record<string, number>> {
  const products: ProductSpec[] = [
    { sku: 'BW-PUMP-15', name: '1.5 HP Pool Pump', sellerEmail: 'orders@bluewave-pools.in', unit: 'unit', description: 'Self-priming 1.5 HP circulation pump.' },
    { sku: 'BW-FILT-SAND', name: 'Sand Filter 24"', sellerEmail: 'orders@bluewave-pools.in', unit: 'unit', description: 'Top-mount sand filter, 24 inch tank.' },
    { sku: 'AT-HEAT-9KW', name: 'Electric Pool Heater 9kW', sellerEmail: 'sales@aquatech-india.in', unit: 'unit', description: 'Titanium element 9kW inline heater.' },
    { sku: 'AT-SALT-CELL', name: 'Salt Chlorinator Cell', sellerEmail: 'sales@aquatech-india.in', unit: 'unit', description: 'Replacement salt chlorinator cell.' },
    { sku: 'PP-LED-RGB', name: 'RGB LED Pool Light', sellerEmail: 'hello@poolpro.co.in', unit: 'unit', description: 'Colour-changing PAR56 LED pool light.' },
    { sku: 'PP-COVER-SOLAR', name: 'Solar Pool Cover (per m²)', sellerEmail: 'hello@poolpro.co.in', unit: 'm²', description: 'Bubble-type solar heat-retention cover.' },
    { sku: 'CH-TABS-200', name: 'Chlorine Tablets 200g (case)', sellerEmail: 'contact@chlorinehub.in', unit: 'case', description: 'Stabilised trichlor 200g tablets, 5kg case.' },
    { sku: 'CH-ALG-5L', name: 'Algaecide 5L', sellerEmail: 'contact@chlorinehub.in', unit: 'can', description: 'Concentrated non-foaming algaecide.' },
    { sku: 'DE-LADDER-SS', name: 'Stainless Steel Pool Ladder', sellerEmail: 'info@deepend-equip.in', unit: 'unit', description: '3-step 304 stainless ladder.' },
    { sku: 'DE-VAC-ROBOT', name: 'Robotic Pool Cleaner', sellerEmail: 'info@deepend-equip.in', unit: 'unit', description: 'Automatic robotic floor & wall cleaner.' },
  ];
  const map: Record<string, number> = {};
  for (const p of products) {
    const sellerId = sellerMap[p.sellerEmail];
    const rows = await sql`
      INSERT INTO products (seller_id, name, sku, description, unit)
      VALUES (${sellerId}, ${p.name}, ${p.sku}, ${p.description}, ${p.unit})
      ON CONFLICT (sku) DO UPDATE
        SET name = EXCLUDED.name,
            description = EXCLUDED.description,
            unit = EXCLUDED.unit,
            seller_id = EXCLUDED.seller_id
      RETURNING product_id
    `;
    map[p.sku] = Number((rows[0] as { product_id: number }).product_id);
  }
  console.log(`✓ ${products.length} products`);
  return map;
}

interface PoolSpec {
  reference: string;
  sku: string;
  title: string;
  moq_target: number;
  reserved_qty: number;
  status: string;
  date_start: string;
  date_end: string;
  min_qty_per_buyer: number;
  max_qty_per_buyer: number;
  lead_time: string;
  shipping_terms: string;
  // Reservations: [customerIndex(0|1), quantity]. unit_price_locked derived from ladder.
  reservations: Array<{ customer: number; quantity: number; status: string }>;
}

function buildPoolSpecs(): PoolSpec[] {
  // Mix: active (open), expired (ended, some reached MOQ some not), fulfilled.
  return [
    {
      reference: 'POOL-2024-0001', sku: 'BW-PUMP-15', title: 'Bulk 1.5HP Pumps — Q3 Buy',
      moq_target: 50, reserved_qty: 48, status: 'active',
      date_start: iso(now - 5 * DAY), date_end: iso(now + 10 * DAY),
      min_qty_per_buyer: 1, max_qty_per_buyer: 40, lead_time: '3-4 weeks',
      shipping_terms: 'FOB Bengaluru; freight at buyer cost.',
      reservations: [
        { customer: 0, quantity: 30, status: 'confirmed' },
        { customer: 1, quantity: 18, status: 'pending' },
      ],
    },
    {
      reference: 'POOL-2024-0002', sku: 'BW-FILT-SAND', title: 'Sand Filters Group Buy',
      moq_target: 30, reserved_qty: 12, status: 'active',
      date_start: iso(now - 2 * DAY), date_end: iso(now + 20 * DAY),
      min_qty_per_buyer: 1, max_qty_per_buyer: 0, lead_time: '2 weeks',
      shipping_terms: 'Ex-works; pickup or arranged courier.',
      reservations: [
        { customer: 0, quantity: 12, status: 'pending' },
      ],
    },
    {
      reference: 'POOL-2024-0003', sku: 'AT-HEAT-9KW', title: '9kW Heaters Pre-Season Pool',
      moq_target: 50, reserved_qty: 50, status: 'active',
      date_start: iso(now - 8 * DAY), date_end: iso(now + 6 * DAY),
      min_qty_per_buyer: 1, max_qty_per_buyer: 30, lead_time: '4-5 weeks',
      shipping_terms: 'Delivered duty paid, metros only.',
      reservations: [
        { customer: 1, quantity: 28, status: 'confirmed' },
        { customer: 0, quantity: 22, status: 'confirmed' },
      ],
    },
    {
      reference: 'POOL-2024-0004', sku: 'AT-SALT-CELL', title: 'Salt Cells Replacement Buy',
      moq_target: 40, reserved_qty: 9, status: 'active',
      date_start: iso(now - 1 * DAY), date_end: iso(now + 25 * DAY),
      min_qty_per_buyer: 1, max_qty_per_buyer: 20, lead_time: '2-3 weeks',
      shipping_terms: 'FOB Mumbai.',
      reservations: [
        { customer: 0, quantity: 9, status: 'pending' },
      ],
    },
    {
      reference: 'POOL-2024-0005', sku: 'PP-LED-RGB', title: 'RGB LED Lights Bulk',
      moq_target: 100, reserved_qty: 150, status: 'active',
      date_start: iso(now - 3 * DAY), date_end: iso(now + 12 * DAY),
      min_qty_per_buyer: 1, max_qty_per_buyer: 0, lead_time: '10 days',
      shipping_terms: 'Free delivery pan-India above 100 units.',
      reservations: [
        { customer: 0, quantity: 90, status: 'confirmed' },
        { customer: 1, quantity: 60, status: 'confirmed' },
      ],
    },
    {
      reference: 'POOL-2024-0006', sku: 'PP-COVER-SOLAR', title: 'Solar Covers Winter Pool',
      moq_target: 200, reserved_qty: 75, status: 'expired',
      date_start: iso(now - 40 * DAY), date_end: iso(now - 5 * DAY),
      min_qty_per_buyer: 10, max_qty_per_buyer: 0, lead_time: '3 weeks',
      shipping_terms: 'Ex-works Chennai.',
      reservations: [
        { customer: 1, quantity: 75, status: 'released' },
      ],
    },
    {
      reference: 'POOL-2024-0007', sku: 'CH-TABS-200', title: 'Chlorine Tablets Case Buy',
      moq_target: 60, reserved_qty: 62, status: 'expired',
      date_start: iso(now - 30 * DAY), date_end: iso(now - 2 * DAY),
      min_qty_per_buyer: 1, max_qty_per_buyer: 40, lead_time: '1 week',
      shipping_terms: 'Delivered, hazmat surcharge applies.',
      reservations: [
        { customer: 0, quantity: 40, status: 'confirmed' },
        { customer: 1, quantity: 22, status: 'confirmed' },
      ],
    },
    {
      reference: 'POOL-2024-0008', sku: 'CH-ALG-5L', title: 'Algaecide Group Order',
      moq_target: 80, reserved_qty: 35, status: 'expired',
      date_start: iso(now - 35 * DAY), date_end: iso(now - 8 * DAY),
      min_qty_per_buyer: 1, max_qty_per_buyer: 25, lead_time: '1 week',
      shipping_terms: 'FOB Ahmedabad.',
      reservations: [
        { customer: 0, quantity: 20, status: 'released' },
        { customer: 1, quantity: 15, status: 'released' },
      ],
    },
    {
      reference: 'POOL-2024-0009', sku: 'DE-LADDER-SS', title: 'SS Ladders Bulk (Completed)',
      moq_target: 40, reserved_qty: 55, status: 'fulfilled',
      date_start: iso(now - 60 * DAY), date_end: iso(now - 20 * DAY),
      min_qty_per_buyer: 1, max_qty_per_buyer: 30, lead_time: '4 weeks',
      shipping_terms: 'Delivered duty paid.',
      reservations: [
        { customer: 0, quantity: 30, status: 'converted' },
        { customer: 1, quantity: 25, status: 'converted' },
      ],
    },
    {
      reference: 'POOL-2024-0010', sku: 'DE-VAC-ROBOT', title: 'Robotic Cleaners Bulk (Completed)',
      moq_target: 150, reserved_qty: 165, status: 'fulfilled',
      date_start: iso(now - 90 * DAY), date_end: iso(now - 30 * DAY),
      min_qty_per_buyer: 1, max_qty_per_buyer: 0, lead_time: '5-6 weeks',
      shipping_terms: 'Ex-works; freight prepaid & added.',
      reservations: [
        { customer: 0, quantity: 100, status: 'converted' },
        { customer: 1, quantity: 65, status: 'converted' },
      ],
    },
  ];
}

async function seedPools(
  productMap: Record<string, number>,
  sellerMap: Record<string, number>,
  productSellerBySku: Record<string, string>,
  customerIds: number[],
): Promise<void> {
  const specs = buildPoolSpecs();

  for (const p of specs) {
    const productId = productMap[p.sku];
    const sellerId = sellerMap[productSellerBySku[p.sku]];

    const rows = await sql`
      INSERT INTO pools (
        product_id, seller_id, reference, title, moq_target, reserved_qty, status,
        date_start, date_end, min_qty_per_buyer, max_qty_per_buyer,
        currency_code, lead_time, shipping_terms
      ) VALUES (
        ${productId}, ${sellerId}, ${p.reference}, ${p.title}, ${p.moq_target}, ${p.reserved_qty}, ${p.status},
        ${p.date_start}, ${p.date_end}, ${p.min_qty_per_buyer}, ${p.max_qty_per_buyer},
        ${'INR'}, ${p.lead_time}, ${p.shipping_terms}
      )
      ON CONFLICT (reference) DO UPDATE SET
        product_id = EXCLUDED.product_id,
        seller_id = EXCLUDED.seller_id,
        title = EXCLUDED.title,
        moq_target = EXCLUDED.moq_target,
        reserved_qty = EXCLUDED.reserved_qty,
        status = EXCLUDED.status,
        date_start = EXCLUDED.date_start,
        date_end = EXCLUDED.date_end,
        min_qty_per_buyer = EXCLUDED.min_qty_per_buyer,
        max_qty_per_buyer = EXCLUDED.max_qty_per_buyer,
        lead_time = EXCLUDED.lead_time,
        shipping_terms = EXCLUDED.shipping_terms
      RETURNING pool_id
    `;
    const poolId = Number((rows[0] as { pool_id: number }).pool_id);

    // Rebuild tiers for this pool (idempotent: clear then insert the ladder).
    await sql`DELETE FROM pool_tiers WHERE pool_id = ${poolId}`;
    for (const t of STANDARD_TIERS) {
      await sql`
        INSERT INTO pool_tiers (pool_id, min_qty, max_qty, price)
        VALUES (${poolId}, ${t.min_qty}, ${t.max_qty}, ${t.price})
      `;
    }

    // Rebuild reservations. unit_price_locked = pool unit price at the pool's
    // total reserved_qty (tiers resolve on the pool TOTAL, per domain rules).
    const lockedPrice = poolUnitPrice(STANDARD_TIERS, p.reserved_qty) ?? STANDARD_TIERS[0].price;
    await sql`DELETE FROM reservations WHERE pool_id = ${poolId}`;
    for (const r of p.reservations) {
      const customerId = customerIds[r.customer];
      await sql`
        INSERT INTO reservations (pool_id, customer_id, quantity, unit_price_locked, status)
        VALUES (${poolId}, ${customerId}, ${r.quantity}, ${lockedPrice}, ${r.status})
      `;
    }

    // Rebuild a single 'seeded' pool event (idempotent per pool).
    await sql`DELETE FROM pool_events WHERE pool_id = ${poolId} AND type = 'seeded'`;
    await sql`
      INSERT INTO pool_events (pool_id, type, payload)
      VALUES (${poolId}, ${'seeded'}, ${JSON.stringify({ status: p.status, reserved_qty: p.reserved_qty, moq_target: p.moq_target })}::jsonb)
    `;
  }
  console.log(`✓ ${specs.length} pools (with tiers, reservations, events)`);
}

async function main(): Promise<void> {
  console.log('Seeding PoolBuy database…');
  await applySchema();

  const sellerMap = await seedSellers();
  const productMap = await seedProducts(sellerMap);
  const customerIds = await seedCustomers();

  // Map each SKU to its seller email so pools inherit the right seller.
  const productSellerBySku: Record<string, string> = {
    'BW-PUMP-15': 'orders@bluewave-pools.in',
    'BW-FILT-SAND': 'orders@bluewave-pools.in',
    'AT-HEAT-9KW': 'sales@aquatech-india.in',
    'AT-SALT-CELL': 'sales@aquatech-india.in',
    'PP-LED-RGB': 'hello@poolpro.co.in',
    'PP-COVER-SOLAR': 'hello@poolpro.co.in',
    'CH-TABS-200': 'contact@chlorinehub.in',
    'CH-ALG-5L': 'contact@chlorinehub.in',
    'DE-LADDER-SS': 'info@deepend-equip.in',
    'DE-VAC-ROBOT': 'info@deepend-equip.in',
  };

  await seedPools(productMap, sellerMap, productSellerBySku, customerIds);

  console.log('\n✅ Seed complete.');
}

main().catch((err) => {
  console.error('\n❌ Seed failed:', err);
  process.exit(1);
});
