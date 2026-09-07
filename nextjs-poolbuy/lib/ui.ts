// UI helpers shared across server components.
// Server components fetch from our own API routes. We build an absolute base URL
// from the incoming request headers (works on Vercel and locally) and always
// degrade gracefully: when DATABASE_URL is unset the API returns 503 and these
// helpers surface a `dbUnavailable` flag instead of throwing.

import { headers } from 'next/headers';
import { isDbConfigured } from '@/lib/db';

export const CURRENCY_SYMBOL = '₹';

/** True when the DB connection string is present. Safe on server. */
export function dbConfigured(): boolean {
  return isDbConfigured();
}

/** Format a number as INR currency with the ₹ symbol. */
export function formatINR(value: number | null | undefined, decimals = 2): string {
  if (value === null || value === undefined || Number.isNaN(Number(value))) return '—';
  const n = Number(value);
  const formatted = n.toLocaleString('en-IN', {
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  });
  return `${CURRENCY_SYMBOL}${formatted}`;
}

/** Format an integer with Indian grouping. */
export function formatQty(value: number | null | undefined): string {
  if (value === null || value === undefined || Number.isNaN(Number(value))) return '0';
  return Number(value).toLocaleString('en-IN');
}

/** Human-readable "time left" until an ISO date. Returns null when no date. */
export function timeLeft(dateEnd: string | null | undefined, now: Date = new Date()): string | null {
  if (!dateEnd) return null;
  const end = new Date(dateEnd);
  if (Number.isNaN(end.getTime())) return null;
  const ms = end.getTime() - now.getTime();
  if (ms <= 0) return 'Ended';
  const mins = Math.floor(ms / 60000);
  const days = Math.floor(mins / (60 * 24));
  const hours = Math.floor((mins % (60 * 24)) / 60);
  if (days > 0) return `${days}d ${hours}h left`;
  if (hours > 0) return `${hours}h ${mins % 60}m left`;
  return `${mins}m left`;
}

/** Build an absolute base URL from request headers. */
function baseUrl(): string {
  const h = headers();
  const host = h.get('x-forwarded-host') ?? h.get('host') ?? 'localhost:3000';
  const proto = h.get('x-forwarded-proto') ?? (host.startsWith('localhost') ? 'http' : 'https');
  return `${proto}://${host}`;
}

export interface FetchResult<T> {
  ok: boolean;
  status: number;
  data: T | null;
  dbUnavailable: boolean;
  error: string | null;
}

/**
 * Server-side GET against our own API. Never throws; always returns a
 * discriminated result so pages can render friendly fallbacks.
 */
export async function apiGet<T>(path: string): Promise<FetchResult<T>> {
  if (!isDbConfigured()) {
    return { ok: false, status: 503, data: null, dbUnavailable: true, error: 'Database not configured.' };
  }
  try {
    const res = await fetch(`${baseUrl()}${path}`, { cache: 'no-store' });
    let body: unknown = null;
    try {
      body = await res.json();
    } catch {
      body = null;
    }
    if (!res.ok) {
      const err = (body as { error?: string } | null)?.error ?? `Request failed (${res.status}).`;
      return {
        ok: false,
        status: res.status,
        data: null,
        dbUnavailable: res.status === 503,
        error: err,
      };
    }
    return { ok: true, status: res.status, data: body as T, dbUnavailable: false, error: null };
  } catch (err) {
    return {
      ok: false,
      status: 0,
      data: null,
      dbUnavailable: false,
      error: err instanceof Error ? err.message : String(err),
    };
  }
}

/** Tailwind classes for a pool status badge. */
export function statusBadgeClass(status: string): string {
  switch (status) {
    case 'active':
      return 'bg-green-100 text-green-700';
    case 'reached':
      return 'bg-brand-100 text-brand-700';
    case 'closed':
    case 'fulfilled':
      return 'bg-slate-200 text-slate-700';
    case 'draft':
      return 'bg-amber-100 text-amber-700';
    case 'expired':
    case 'cancelled':
      return 'bg-rose-100 text-rose-700';
    default:
      return 'bg-slate-100 text-slate-600';
  }
}
