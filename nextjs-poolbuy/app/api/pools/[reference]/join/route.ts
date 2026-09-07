// POST /api/pools/[reference]/join
// Body: { customer_id: number, quantity: number, shipping_cost?: number }
//
// Server-authoritative:
//   - reserved qty is recomputed from live reservation rows (not trusted from client)
//   - requested quantity is clamped to per-buyer min/max and units available
//   - unit price is resolved server-side via poolUnitPrice on the pool TOTAL
//     (post-join) reserved qty
//   - reservation insert + pool.reserved_qty update happen atomically in a single
//     CTE statement (neon HTTP driver is one round-trip per request)
//   - rejected unless the pool isOpenForReservations (active only)
//   - returns the full priceBreakdown (GST 18% + fee 2% on subtotal, shipping untaxed)

import { sql, isDbConfigured } from '@/lib/db';
import {
  json,
  errorJson,
  dbUnavailable,
  num,
  int,
  toRawTiers,
  type PoolTierRow,
  GST_RATE,
  PLATFORM_FEE_RATE,
  CURRENCY,
  CURRENCY_SYMBOL,
} from '@/lib/api';
import {
  poolUnitPrice,
  clampQuantity,
  priceBreakdown,
  unitsRemaining,
} from '@/lib/domain/poolCalculator';
import { isOpenForReservations } from '@/lib/domain/poolLifecycle';

export const dynamic = 'force-dynamic';

interface JoinPoolRow {
  pool_id: number | string;
  status: string;
  moq_target: number | string;
  min_qty_per_buyer: number | string;
  max_qty_per_buyer: number | string;
  currency_code: string | null;
}

export async function POST(
  req: Request,
  { params }: { params: { reference: string } },
): Promise<Response> {
  if (!isDbConfigured()) return dbUnavailable();

  const reference = params.reference;

  let body: { customer_id?: unknown; quantity?: unknown; shipping_cost?: unknown };
  try {
    body = await req.json();
  } catch {
    return errorJson('Invalid JSON body.', 400);
  }

  const customerId = int(body.customer_id);
  const requestedQty = int(body.quantity);
  const shippingCost = num(body.shipping_cost, 0);

  if (customerId <= 0) return errorJson('A valid customer_id is required.', 400);
  if (requestedQty <= 0) return errorJson('quantity must be a positive integer.', 400);

  try {
    const poolRows = (await sql`
      SELECT pool_id, status, moq_target,
             min_qty_per_buyer, max_qty_per_buyer, currency_code
      FROM pools
      WHERE reference = ${reference}
      LIMIT 1
    `) as unknown as JoinPoolRow[];

    if (poolRows.length === 0) return errorJson('Pool not found.', 404);
    const pool = poolRows[0];
    const poolId = int(pool.pool_id);

    if (!isOpenForReservations(pool.status)) {
      return errorJson(
        'This pool is not open for reservations.',
        409,
        { status: pool.status },
      );
    }

    // Recompute reserved qty from live rows (statuses that still count).
    const liveRes = (await sql`
      SELECT COALESCE(SUM(quantity), 0) AS reserved
      FROM reservations
      WHERE pool_id = ${poolId}
        AND status IN ('pending','confirmed','converted')
    `) as unknown as { reserved: number | string }[];
    const reservedBefore = int(liveRes[0]?.reserved ?? 0);

    const moq = int(pool.moq_target);
    const minPer = int(pool.min_qty_per_buyer);
    const maxPer = int(pool.max_qty_per_buyer);

    // Units available: if the pool has an MOQ target treat it as a soft ceiling
    // is NOT correct here — pools accept beyond MOQ. Availability is only limited
    // by the per-buyer ceiling, so pass a large available window and let the
    // per-buyer max govern. Use remaining-to-MOQ only when it is larger than
    // zero AND you want a hard cap; here pools stay open past MOQ, so available
    // is unbounded except by max_qty_per_buyer.
    const unitsAvailable = Number.MAX_SAFE_INTEGER;

    const grantedQty = clampQuantity(requestedQty, minPer, maxPer, unitsAvailable);
    if (grantedQty <= 0) {
      return errorJson('Requested quantity could not be allocated for this pool.', 422, {
        requested: requestedQty,
        min_qty_per_buyer: minPer,
        max_qty_per_buyer: maxPer,
      });
    }

    // Server-authoritative unit price: resolved on the pool TOTAL reserved qty
    // AFTER this reservation is added (domain rule: tiers on pool total).
    const tierRows = (await sql`
      SELECT tier_id, min_qty, max_qty, price
      FROM pool_tiers
      WHERE pool_id = ${poolId}
      ORDER BY min_qty
    `) as unknown as PoolTierRow[];
    const tiers = toRawTiers(tierRows);

    const reservedAfter = reservedBefore + grantedQty;
    const unitPrice = poolUnitPrice(tiers, reservedAfter);
    if (unitPrice === null) {
      return errorJson('No applicable price tier for this quantity.', 422);
    }

    const breakdown = priceBreakdown(
      grantedQty,
      unitPrice,
      GST_RATE,
      PLATFORM_FEE_RATE,
      shippingCost,
    );

    // Atomic insert + reserved_qty resync in a single statement. reserved_qty is
    // recomputed from the authoritative live-row sum so it can never drift.
    const inserted = (await sql`
      WITH new_res AS (
        INSERT INTO reservations (pool_id, customer_id, quantity, unit_price_locked, status)
        VALUES (${poolId}, ${customerId}, ${grantedQty}, ${unitPrice}, 'pending')
        RETURNING reservation_id, quantity, unit_price_locked
      ),
      resync AS (
        UPDATE pools
        SET reserved_qty = (
          SELECT COALESCE(SUM(quantity), 0)
          FROM reservations
          WHERE pool_id = ${poolId}
            AND status IN ('pending','confirmed','converted')
        )
        WHERE pool_id = ${poolId}
        RETURNING reserved_qty
      )
      SELECT nr.reservation_id, nr.quantity, nr.unit_price_locked,
             (SELECT reserved_qty FROM resync) AS reserved_qty
      FROM new_res nr
    `) as unknown as {
      reservation_id: number | string;
      quantity: number | string;
      unit_price_locked: number | string;
      reserved_qty: number | string;
    }[];

    const row = inserted[0];
    const finalReserved = int(row?.reserved_qty ?? reservedAfter);

    // Log the join event (non-blocking best-effort; failure shouldn't undo the join).
    try {
      await sql`
        INSERT INTO pool_events (pool_id, type, payload)
        VALUES (${poolId}, 'reservation_created', ${JSON.stringify({
          reservation_id: int(row?.reservation_id),
          customer_id: customerId,
          requested_quantity: requestedQty,
          granted_quantity: grantedQty,
          unit_price: unitPrice,
          reserved_after: finalReserved,
        })}::jsonb)
      `;
    } catch {
      // ignore audit-log failure
    }

    return json(
      {
        currency: CURRENCY,
        currency_symbol: CURRENCY_SYMBOL,
        reservation: {
          reservation_id: int(row?.reservation_id),
          pool_reference: reference,
          customer_id: customerId,
          requested_quantity: requestedQty,
          granted_quantity: grantedQty,
          clamped: grantedQty !== requestedQty,
          unit_price_locked: num(row?.unit_price_locked, unitPrice),
          status: 'pending',
        },
        pool: {
          reference,
          reserved_qty: finalReserved,
          moq_target: moq,
          units_remaining: unitsRemaining(finalReserved, moq),
        },
        price_breakdown: breakdown,
        rates: { gst_rate: GST_RATE, platform_fee_rate: PLATFORM_FEE_RATE },
      },
      201,
    );
  } catch (err) {
    return errorJson('Failed to join pool.', 500, {
      detail: err instanceof Error ? err.message : String(err),
    });
  }
}
