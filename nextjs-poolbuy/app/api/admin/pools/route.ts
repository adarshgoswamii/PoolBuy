// GET /api/admin/pools
// Admin-wide pool overview across all sellers. Admin context: buyers are shown by
// real customer_id (not masked) since admins have full visibility. Includes
// aggregate reservation stats and current server-resolved pool unit price.

import { sql, isDbConfigured } from '@/lib/db';
import {
  json,
  errorJson,
  dbUnavailable,
  int,
  num,
  toRawTiers,
  type PoolTierRow,
  CURRENCY,
  CURRENCY_SYMBOL,
} from '@/lib/api';
import { poolUnitPrice, fillPercentage, isMoqReached } from '@/lib/domain/poolCalculator';

export const dynamic = 'force-dynamic';

interface AdminPoolRow {
  pool_id: number | string;
  reference: string;
  title: string | null;
  status: string;
  moq_target: number | string;
  reserved_qty: number | string;
  currency_code: string | null;
  date_start: string | null;
  date_end: string | null;
  created_at: string | null;
  product_name: string | null;
  sku: string | null;
  seller_id: number | string;
  seller_name: string | null;
  company: string | null;
  reservation_count: number | string;
  live_reserved: number | string;
}

export async function GET(): Promise<Response> {
  if (!isDbConfigured()) return dbUnavailable();

  try {
    const pools = (await sql`
      SELECT p.pool_id, p.reference, p.title, p.status,
             p.moq_target, p.reserved_qty, p.currency_code,
             p.date_start, p.date_end, p.created_at,
             pr.name AS product_name, pr.sku,
             s.seller_id, s.name AS seller_name, s.company,
             COALESCE(r.reservation_count, 0) AS reservation_count,
             COALESCE(r.live_reserved, 0)     AS live_reserved
      FROM pools p
      JOIN products pr ON pr.product_id = p.product_id
      JOIN sellers  s  ON s.seller_id  = p.seller_id
      LEFT JOIN (
        SELECT pool_id,
               COUNT(*) AS reservation_count,
               SUM(CASE WHEN status IN ('pending','confirmed','converted') THEN quantity ELSE 0 END) AS live_reserved
        FROM reservations
        GROUP BY pool_id
      ) r ON r.pool_id = p.pool_id
      ORDER BY p.created_at DESC, p.pool_id DESC
    `) as unknown as AdminPoolRow[];

    const poolIds = pools.map((p) => int(p.pool_id));
    let tierRows: (PoolTierRow & { pool_id: number | string })[] = [];
    if (poolIds.length > 0) {
      tierRows = (await sql`
        SELECT tier_id, pool_id, min_qty, max_qty, price
        FROM pool_tiers WHERE pool_id = ANY(${poolIds}) ORDER BY pool_id, min_qty
      `) as unknown as (PoolTierRow & { pool_id: number | string })[];
    }
    const tiersByPool = new Map<number, PoolTierRow[]>();
    for (const t of tierRows) {
      const pid = int(t.pool_id);
      const list = tiersByPool.get(pid) ?? [];
      list.push({ tier_id: t.tier_id, min_qty: t.min_qty, max_qty: t.max_qty, price: t.price });
      tiersByPool.set(pid, list);
    }

    const statusCounts: Record<string, number> = {};
    const result = pools.map((p) => {
      const reserved = int(p.reserved_qty);
      const moq = int(p.moq_target);
      const tiers = toRawTiers(tiersByPool.get(int(p.pool_id)) ?? []);
      statusCounts[p.status] = (statusCounts[p.status] ?? 0) + 1;
      return {
        reference: p.reference,
        title: p.title,
        status: p.status,
        seller: { seller_id: int(p.seller_id), name: p.seller_name, company: p.company },
        product: p.product_name,
        sku: p.sku,
        moq_target: moq,
        reserved_qty: reserved,
        live_reserved_qty: int(p.live_reserved),
        fill_percentage: fillPercentage(reserved, moq),
        moq_reached: isMoqReached(reserved, moq),
        pool_unit_price: poolUnitPrice(tiers, reserved),
        currency_code: p.currency_code ?? CURRENCY,
        reservation_count: int(p.reservation_count),
        date_start: p.date_start,
        date_end: p.date_end,
        created_at: p.created_at,
      };
    });

    return json({
      currency: CURRENCY,
      currency_symbol: CURRENCY_SYMBOL,
      total_pools: result.length,
      status_counts: statusCounts,
      pools: result,
    });
  } catch (err) {
    return errorJson('Failed to load admin pools.', 500, {
      detail: err instanceof Error ? err.message : String(err),
    });
  }
}
