// GET /api/pools/[reference]
// Pool detail including the decorated tier ladder (unlocked/active/units_needed)
// resolved on the pool TOTAL reserved qty, plus lifecycle flags.

import { sql, isDbConfigured } from '@/lib/db';
import {
  json,
  errorJson,
  dbUnavailable,
  int,
  toRawTiers,
  type PoolTierRow,
  CURRENCY,
  CURRENCY_SYMBOL,
} from '@/lib/api';
import {
  poolUnitPrice,
  fillPercentage,
  isMoqReached,
  unitsRemaining,
  unitsToNextTier,
  tierLadder,
} from '@/lib/domain/poolCalculator';
import { isOpenForReservations } from '@/lib/domain/poolLifecycle';

export const dynamic = 'force-dynamic';

interface PoolDetailRow {
  pool_id: number | string;
  reference: string;
  title: string | null;
  status: string;
  moq_target: number | string;
  reserved_qty: number | string;
  min_qty_per_buyer: number | string;
  max_qty_per_buyer: number | string;
  currency_code: string | null;
  date_start: string | null;
  date_end: string | null;
  lead_time: string | null;
  shipping_terms: string | null;
  product_name: string | null;
  sku: string | null;
  product_description: string | null;
  unit: string | null;
  seller_id: number | string;
  seller_name: string | null;
  company: string | null;
}

export async function GET(
  _req: Request,
  { params }: { params: { reference: string } },
): Promise<Response> {
  if (!isDbConfigured()) return dbUnavailable();

  const reference = params.reference;

  try {
    const poolRows = (await sql`
      SELECT p.pool_id, p.reference, p.title, p.status,
             p.moq_target, p.reserved_qty,
             p.min_qty_per_buyer, p.max_qty_per_buyer,
             p.currency_code, p.date_start, p.date_end,
             p.lead_time, p.shipping_terms,
             pr.name AS product_name, pr.sku, pr.description AS product_description, pr.unit,
             s.seller_id, s.name AS seller_name, s.company
      FROM pools p
      JOIN products pr ON pr.product_id = p.product_id
      JOIN sellers  s  ON s.seller_id  = p.seller_id
      WHERE p.reference = ${reference}
      LIMIT 1
    `) as unknown as PoolDetailRow[];

    if (poolRows.length === 0) {
      return errorJson('Pool not found.', 404);
    }
    const p = poolRows[0];
    const poolId = int(p.pool_id);
    const reserved = int(p.reserved_qty);
    const moq = int(p.moq_target);

    const tierRows = (await sql`
      SELECT tier_id, min_qty, max_qty, price
      FROM pool_tiers
      WHERE pool_id = ${poolId}
      ORDER BY min_qty
    `) as unknown as PoolTierRow[];
    const tiers = toRawTiers(tierRows);

    return json({
      currency: CURRENCY,
      currency_symbol: CURRENCY_SYMBOL,
      pool: {
        reference: p.reference,
        title: p.title,
        status: p.status,
        is_open_for_reservations: isOpenForReservations(p.status),
        moq_target: moq,
        reserved_qty: reserved,
        fill_percentage: fillPercentage(reserved, moq),
        moq_reached: isMoqReached(reserved, moq),
        units_remaining: unitsRemaining(reserved, moq),
        units_to_next_tier: unitsToNextTier(tiers, reserved),
        pool_unit_price: poolUnitPrice(tiers, reserved),
        min_qty_per_buyer: int(p.min_qty_per_buyer),
        max_qty_per_buyer: int(p.max_qty_per_buyer),
        currency_code: p.currency_code ?? CURRENCY,
        date_start: p.date_start,
        date_end: p.date_end,
        lead_time: p.lead_time,
        shipping_terms: p.shipping_terms,
        product: {
          name: p.product_name,
          sku: p.sku,
          description: p.product_description,
          unit: p.unit,
        },
        seller: { seller_id: int(p.seller_id), name: p.seller_name, company: p.company },
        tier_ladder: tierLadder(tiers, reserved),
      },
    });
  } catch (err) {
    return errorJson('Failed to load pool.', 500, {
      detail: err instanceof Error ? err.message : String(err),
    });
  }
}
