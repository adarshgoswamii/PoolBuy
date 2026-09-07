// GET /api/seller/[sellerId]/pools
// A seller's own pools with their reservations. Buyer identities are masked as
// 'Buyer #' + (1000 + customer_id) — sellers never see real customer identities.

import { sql, isDbConfigured } from '@/lib/db';
import {
  json,
  errorJson,
  dbUnavailable,
  int,
  num,
  toRawTiers,
  maskBuyer,
  type PoolTierRow,
  CURRENCY,
  CURRENCY_SYMBOL,
} from '@/lib/api';
import { poolUnitPrice, fillPercentage } from '@/lib/domain/poolCalculator';
import { isOpenForReservations } from '@/lib/domain/poolLifecycle';

export const dynamic = 'force-dynamic';

interface SellerPoolRow {
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
}

interface ReservationRowDb {
  reservation_id: number | string;
  pool_id: number | string;
  customer_id: number | string;
  quantity: number | string;
  unit_price_locked: number | string;
  status: string;
  created_at: string | null;
}

export async function GET(
  _req: Request,
  { params }: { params: { sellerId: string } },
): Promise<Response> {
  if (!isDbConfigured()) return dbUnavailable();

  const sellerId = int(params.sellerId);
  if (sellerId <= 0) return errorJson('A valid sellerId is required.', 400);

  try {
    const sellerRows = (await sql`
      SELECT seller_id, name, company, email FROM sellers WHERE seller_id = ${sellerId} LIMIT 1
    `) as unknown as { seller_id: number | string; name: string | null; company: string | null; email: string | null }[];

    if (sellerRows.length === 0) return errorJson('Seller not found.', 404);
    const seller = sellerRows[0];

    const pools = (await sql`
      SELECT p.pool_id, p.reference, p.title, p.status,
             p.moq_target, p.reserved_qty, p.currency_code,
             p.date_start, p.date_end,
             pr.name AS product_name, pr.sku
      FROM pools p
      JOIN products pr ON pr.product_id = p.product_id
      WHERE p.seller_id = ${sellerId}
      ORDER BY p.created_at DESC, p.pool_id DESC
    `) as unknown as SellerPoolRow[];

    const poolIds = pools.map((p) => int(p.pool_id));

    let tierRows: (PoolTierRow & { pool_id: number | string })[] = [];
    let resRows: ReservationRowDb[] = [];
    if (poolIds.length > 0) {
      tierRows = (await sql`
        SELECT tier_id, pool_id, min_qty, max_qty, price
        FROM pool_tiers WHERE pool_id = ANY(${poolIds}) ORDER BY pool_id, min_qty
      `) as unknown as (PoolTierRow & { pool_id: number | string })[];
      resRows = (await sql`
        SELECT reservation_id, pool_id, customer_id, quantity, unit_price_locked, status, created_at
        FROM reservations WHERE pool_id = ANY(${poolIds}) ORDER BY pool_id, reservation_id
      `) as unknown as ReservationRowDb[];
    }

    const tiersByPool = new Map<number, PoolTierRow[]>();
    for (const t of tierRows) {
      const pid = int(t.pool_id);
      const list = tiersByPool.get(pid) ?? [];
      list.push({ tier_id: t.tier_id, min_qty: t.min_qty, max_qty: t.max_qty, price: t.price });
      tiersByPool.set(pid, list);
    }
    const resByPool = new Map<number, ReservationRowDb[]>();
    for (const r of resRows) {
      const pid = int(r.pool_id);
      const list = resByPool.get(pid) ?? [];
      list.push(r);
      resByPool.set(pid, list);
    }

    const result = pools.map((p) => {
      const poolId = int(p.pool_id);
      const reserved = int(p.reserved_qty);
      const moq = int(p.moq_target);
      const tiers = toRawTiers(tiersByPool.get(poolId) ?? []);
      const reservations = (resByPool.get(poolId) ?? []).map((r) => ({
        reservation_id: int(r.reservation_id),
        buyer: maskBuyer(r.customer_id), // masked — never the real identity
        quantity: int(r.quantity),
        unit_price_locked: num(r.unit_price_locked),
        status: r.status,
        created_at: r.created_at,
      }));
      return {
        reference: p.reference,
        title: p.title,
        status: p.status,
        is_open_for_reservations: isOpenForReservations(p.status),
        product: p.product_name,
        sku: p.sku,
        moq_target: moq,
        reserved_qty: reserved,
        fill_percentage: fillPercentage(reserved, moq),
        pool_unit_price: poolUnitPrice(tiers, reserved),
        currency_code: p.currency_code ?? CURRENCY,
        date_start: p.date_start,
        date_end: p.date_end,
        reservation_count: reservations.length,
        reservations,
      };
    });

    return json({
      currency: CURRENCY,
      currency_symbol: CURRENCY_SYMBOL,
      seller: { seller_id: int(seller.seller_id), name: seller.name, company: seller.company },
      pool_count: result.length,
      pools: result,
    });
  } catch (err) {
    return errorJson('Failed to load seller pools.', 500, {
      detail: err instanceof Error ? err.message : String(err),
    });
  }
}
