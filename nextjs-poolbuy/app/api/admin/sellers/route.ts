// GET /api/admin/sellers
// Admin overview of all sellers with product counts, pool counts by status and
// aggregate live reserved quantity across their pools.

import { sql, isDbConfigured } from '@/lib/db';
import { json, errorJson, dbUnavailable, int } from '@/lib/api';

export const dynamic = 'force-dynamic';

interface AdminSellerRow {
  seller_id: number | string;
  name: string | null;
  email: string | null;
  company: string | null;
  created_at: string | null;
  product_count: number | string;
  pool_count: number | string;
  active_pool_count: number | string;
  live_reserved: number | string;
}

export async function GET(): Promise<Response> {
  if (!isDbConfigured()) return dbUnavailable();

  try {
    const sellers = (await sql`
      SELECT s.seller_id, s.name, s.email, s.company, s.created_at,
             COALESCE(pc.product_count, 0)     AS product_count,
             COALESCE(pl.pool_count, 0)        AS pool_count,
             COALESCE(pl.active_pool_count, 0) AS active_pool_count,
             COALESCE(rv.live_reserved, 0)     AS live_reserved
      FROM sellers s
      LEFT JOIN (
        SELECT seller_id, COUNT(*) AS product_count
        FROM products GROUP BY seller_id
      ) pc ON pc.seller_id = s.seller_id
      LEFT JOIN (
        SELECT seller_id,
               COUNT(*) AS pool_count,
               SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_pool_count
        FROM pools GROUP BY seller_id
      ) pl ON pl.seller_id = s.seller_id
      LEFT JOIN (
        SELECT p.seller_id,
               SUM(CASE WHEN r.status IN ('pending','confirmed','converted') THEN r.quantity ELSE 0 END) AS live_reserved
        FROM pools p
        JOIN reservations r ON r.pool_id = p.pool_id
        GROUP BY p.seller_id
      ) rv ON rv.seller_id = s.seller_id
      ORDER BY s.seller_id
    `) as unknown as AdminSellerRow[];

    const result = sellers.map((s) => ({
      seller_id: int(s.seller_id),
      name: s.name,
      email: s.email,
      company: s.company,
      created_at: s.created_at,
      product_count: int(s.product_count),
      pool_count: int(s.pool_count),
      active_pool_count: int(s.active_pool_count),
      live_reserved_qty: int(s.live_reserved),
    }));

    return json({ total_sellers: result.length, sellers: result });
  } catch (err) {
    return errorJson('Failed to load admin sellers.', 500, {
      detail: err instanceof Error ? err.message : String(err),
    });
  }
}
