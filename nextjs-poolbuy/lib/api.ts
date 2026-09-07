// Shared helpers for the App Router API handlers.
// Server-authoritative money/quantity: rates and formatting live here, never
// trusted from the client. All handlers import from the domain layer for the
// actual calculations (do not reimplement domain rules).

import { NextResponse } from 'next/server';
import type { RawTier } from '@/lib/domain/poolCalculator';

/** Business rates (server-authoritative). */
export const GST_RATE = 18; // percent, applied to subtotal
export const PLATFORM_FEE_RATE = 2; // percent, applied to subtotal
export const DEFAULT_SHIPPING = 0; // untaxed; shipping is quoted per pool terms
export const CURRENCY = 'INR';
export const CURRENCY_SYMBOL = '₹';

/** Standard JSON success response. */
export function json<T>(data: T, init?: number | ResponseInit): NextResponse {
  const responseInit = typeof init === 'number' ? { status: init } : init;
  return NextResponse.json(data, responseInit);
}

/** Standard JSON error response. */
export function errorJson(message: string, status = 400, extra?: Record<string, unknown>): NextResponse {
  return NextResponse.json({ error: message, ...(extra ?? {}) }, { status });
}

/** DB unavailable -> 503 with a clear, catchable message. */
export function dbUnavailable(): NextResponse {
  return errorJson(
    'Database is not configured. Set DATABASE_URL to enable this endpoint.',
    503,
  );
}

/** Coerce a possibly-string numeric DB value to a JS number. */
export function num(v: unknown, fallback = 0): number {
  if (v === null || v === undefined || v === '') return fallback;
  const n = Number(v);
  return Number.isFinite(n) ? n : fallback;
}

/** Coerce to an integer. */
export function int(v: unknown, fallback = 0): number {
  return Math.trunc(num(v, fallback));
}

/** Map raw pool_tiers rows into the domain RawTier shape. */
export interface PoolTierRow {
  tier_id: number | string;
  min_qty: number | string;
  max_qty: number | string | null;
  price: number | string;
}

export function toRawTiers(rows: PoolTierRow[]): RawTier[] {
  return rows.map((r) => ({
    tier_id: r.tier_id,
    min_qty: r.min_qty,
    max_qty: r.max_qty,
    price: r.price,
  }));
}

/** Buyer masking for seller-facing views. */
export function maskBuyer(customerId: number | string): string {
  return 'Buyer #' + (1000 + int(customerId));
}
