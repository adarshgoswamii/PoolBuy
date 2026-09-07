// POST /api/lifecycle/run
// Idempotent lifecycle sweep. Uses server time as "now" (or an optional body.now
// ISO string for testing). Each transition is guarded by canTransition and only
// applied when the domain predicate says so, so repeated runs are no-ops.
//
// Steps:
//   1. activate  : draft pools whose date_start <= now  -> active
//   2. reach     : active pools where isMoqReached(reserved, moq) -> reached
//   3. expire    : active pools past date_end and below MOQ -> expired
//   4. close     : reached pools -> closed, then repriceReservations retroactively
//                  (everyone moves to the best unlocked tier at final reserved qty)
//   5. every transition writes a pool_events row
//
// Returns a summary of what changed this run.

import { sql, isDbConfigured } from '@/lib/db';
import { json, errorJson, dbUnavailable, int, num, toRawTiers, type PoolTierRow } from '@/lib/api';
import { isMoqReached, repriceReservations, type ReservationRow } from '@/lib/domain/poolCalculator';
import {
  PoolStatus,
  canTransition,
  type PoolStatusValue,
} from '@/lib/domain/poolLifecycle';

export const dynamic = 'force-dynamic';

interface LifecyclePoolRow {
  pool_id: number | string;
  reference: string;
  status: string;
  moq_target: number | string;
  reserved_qty: number | string;
  date_start: string | null;
  date_end: string | null;
}

async function logEvent(poolId: number, type: string, payload: Record<string, unknown>): Promise<void> {
  await sql`
    INSERT INTO pool_events (pool_id, type, payload)
    VALUES (${poolId}, ${type}, ${JSON.stringify(payload)}::jsonb)
  `;
}

/** Transition a pool only if the domain state machine allows it; log the event. */
async function transition(
  poolId: number,
  reference: string,
  from: string,
  to: PoolStatusValue,
  extra: Record<string, unknown> = {},
): Promise<boolean> {
  if (!canTransition(from, to)) return false;
  await sql`UPDATE pools SET status = ${to} WHERE pool_id = ${poolId} AND status = ${from}`;
  await logEvent(poolId, 'status_changed', { from, to, reference, ...extra });
  return true;
}

export async function POST(req: Request): Promise<Response> {
  if (!isDbConfigured()) return dbUnavailable();

  // Optional injected clock for deterministic testing.
  let nowIso: string;
  try {
    const body = (await req.json().catch(() => ({}))) as { now?: unknown };
    nowIso = typeof body?.now === 'string' && body.now ? new Date(body.now).toISOString() : new Date().toISOString();
  } catch {
    nowIso = new Date().toISOString();
  }
  const now = new Date(nowIso);

  const summary = {
    now: nowIso,
    activated: [] as string[],
    reached: [] as string[],
    expired: [] as string[],
    closed: [] as string[],
    repriced: [] as { reference: string; changes: number; total_delta: number }[],
  };

  try {
    const pools = (await sql`
      SELECT pool_id, reference, status, moq_target, reserved_qty, date_start, date_end
      FROM pools
      WHERE status IN ('draft','active','reached')
      ORDER BY pool_id
    `) as unknown as LifecyclePoolRow[];

    for (const p of pools) {
      const poolId = int(p.pool_id);
      const status = p.status;
      const reserved = int(p.reserved_qty);
      const moq = int(p.moq_target);
      const start = p.date_start ? new Date(p.date_start) : null;
      const end = p.date_end ? new Date(p.date_end) : null;

      // 1. activate due drafts
      if (status === PoolStatus.DRAFT) {
        if (start !== null && start.getTime() <= now.getTime()) {
          if (await transition(poolId, p.reference, status, PoolStatus.ACTIVE, { date_start: p.date_start })) {
            summary.activated.push(p.reference);
          }
        }
        continue;
      }

      // 2/3. active -> reached (MOQ) OR expired (past end, below MOQ)
      if (status === PoolStatus.ACTIVE) {
        if (isMoqReached(reserved, moq)) {
          if (await transition(poolId, p.reference, status, PoolStatus.REACHED, { reserved_qty: reserved, moq_target: moq })) {
            summary.reached.push(p.reference);
          }
        } else if (end !== null && end.getTime() < now.getTime()) {
          if (await transition(poolId, p.reference, status, PoolStatus.EXPIRED, { reserved_qty: reserved, moq_target: moq, date_end: p.date_end })) {
            summary.expired.push(p.reference);
          }
        }
        continue;
      }

      // 4. reached -> closed + retroactive reprice
      if (status === PoolStatus.REACHED) {
        if (await transition(poolId, p.reference, status, PoolStatus.CLOSED, { reserved_qty: reserved })) {
          summary.closed.push(p.reference);
          await repricePool(poolId, p.reference, reserved, summary);
        }
        continue;
      }
    }

    return json({ summary });
  } catch (err) {
    return errorJson('Lifecycle run failed.', 500, {
      detail: err instanceof Error ? err.message : String(err),
      partial_summary: summary,
    });
  }
}

/** Retroactively reprice all live reservations to the final tier and persist. */
async function repricePool(
  poolId: number,
  reference: string,
  finalReserved: number,
  summary: { repriced: { reference: string; changes: number; total_delta: number }[] },
): Promise<void> {
  const tierRows = (await sql`
    SELECT tier_id, min_qty, max_qty, price FROM pool_tiers WHERE pool_id = ${poolId} ORDER BY min_qty
  `) as unknown as PoolTierRow[];
  const tiers = toRawTiers(tierRows);

  const resRows = (await sql`
    SELECT reservation_id, quantity, unit_price_locked
    FROM reservations
    WHERE pool_id = ${poolId}
      AND status IN ('pending','confirmed','converted')
  `) as unknown as { reservation_id: number | string; quantity: number | string; unit_price_locked: number | string }[];

  const reservations: ReservationRow[] = resRows.map((r) => ({
    reservation_id: int(r.reservation_id),
    quantity: int(r.quantity),
    unit_price_locked: num(r.unit_price_locked),
  }));

  const changes = repriceReservations(reservations, tiers, finalReserved, true);
  if (changes.length === 0) return;

  let totalDelta = 0;
  for (const c of changes) {
    await sql`UPDATE reservations SET unit_price_locked = ${c.new_unit_price} WHERE reservation_id = ${c.reservation_id}`;
    totalDelta += c.delta;
  }
  await logEvent(poolId, 'repriced', {
    reference,
    final_reserved_qty: finalReserved,
    changes,
    total_delta: Math.round((totalDelta + Number.EPSILON) * 10000) / 10000,
  });
  summary.repriced.push({
    reference,
    changes: changes.length,
    total_delta: Math.round((totalDelta + Number.EPSILON) * 10000) / 10000,
  });
}
