// GET /api/pools
// Lists pools with server-computed fill percentage, current pool unit price
// (resolved on the pool TOTAL reserved qty), and status.

import { sql, isDbConfigured } from '@/lib/db';
import {
  json,
  errorJson,
  dbUnavailable,
  num,
  int,
  toRawTiers,
  type PoolTierRow,
  CURRENCY,
  CURRENCY_SYMBOL,
} from '@/lib/api';
import { poolUnitPrice, fillPercentage } from '@/lib/domain/poolCalculator';

export const dynamic = 'force-dynamic';

interface PoolRow {
  pool_id: number | string;
  reference: string;
  title: string | null;
  status: string;
  moq_target: number | string;
  reserved_qty: number | string;
  currency_code: string | null;
  date_start: string | null;
  date_end: string | null;
  product_name: string | null;
  sku: string | null;
  seller_id: number | string;
  seller_name: string | null;
}

export async function GET(): Promise<Response> {
  if (!isDbConfigured()) return dbUnavailable();

  try {
    const pools = (await sql`
      SELECT p.pool_id, p.reference, p.title, p.status,
             p.moq_target, p.reserved_qty, p.currency_code,
             p.date_start, p.date_end,
             pr.name AS product_name, pr.sku,
             s.seller_id, s.name AS seller_name
      FROM pools p
      JOIN products pr ON pr.product_id = p.product_id
      JOIN sellers  s  ON s.seller_id  = p.seller_id
      ORDER BY p.created_at DESC, p.pool_id DESC
    `) as unknown as PoolRow[];

    if (pools.length === 0) {
      return json({ currency: CURRENCY, currency_symbol: CURRENCY_SYMBOL, pools: [] });
    }

    // Fetch all tiers in one query, then group by pool.
    const poolIds = pools.map((p) => int(p.pool_id));
    const tierRows = (await sql`
      SELECT tier_id, pool_id, min_qty, max_qty, price
      FROM pool_tiers
      WHERE pool_id = ANY(${poolIds})
      ORDER BY pool_id, min_qty
    `) as unknown as (PoolTierRow & { pool_id: number | string })[];

    const tiersByPool = new Map<number, PoolTierRow[]>();
    for (const t of tierRows) {
      const pid = int(t.pool_id);
      const list = tiersByPool.get(pid) ?? [];
      list.push({ tier_id: t.tier_id, min_qty: t.min_qty, max_qty: t.max_qty, price: t.price });
      tiersByPool.set(pid, list);
    }

    const result = pools.map((p) => {
      const reserved = int(p.reserved_qty);
      const moq = int(p.moq_target);
      const tiers = toRawTiers(tiersByPool.get(int(p.pool_id)) ?? []);
      const unitPrice = poolUnitPrice(tiers, reserved);
      return {
        reference: p.reference,
        title: p.title,
        status: p.status,
        product: p.product_name,
        sku: p.sku,
        seller: { seller_id: int(p.seller_id), name: p.seller_name },
        moq_target: moq,
        reserved_qty: reserved,
        fill_percentage: fillPercentage(reserved, moq),
        pool_unit_price: unitPrice,
        currency_code: p.currency_code ?? CURRENCY,
        date_start: p.date_start,
        date_end: p.date_end,
      };
    });

    return json({ currency: CURRENCY, currency_symbol: CURRENCY_SYMBOL, pools: result });
  } catch (err) {
    return errorJson('Failed to list pools.', 500, {
      detail: err instanceof Error ? err.message : String(err),
    });
  }
}
