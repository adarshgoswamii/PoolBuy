// Database layer for PoolBuy.
// Thin wrapper around @neondatabase/serverless. Reads DATABASE_URL from the
// environment. Degrades gracefully when unset: importing this module never
// throws, and every query attempt against an unconfigured DB throws a clear,
// catchable error at CALL time (not import time).

import { neon, type NeonQueryFunction } from '@neondatabase/serverless';

const DATABASE_URL = process.env.DATABASE_URL;

/** True when DATABASE_URL is present and non-empty. */
export function isDbConfigured(): boolean {
  return typeof DATABASE_URL === 'string' && DATABASE_URL.trim().length > 0;
}

// Lazily construct the neon query function. If DATABASE_URL is missing we defer
// the failure to query time so that module import (and e.g. Next.js build /
// static analysis) never crashes.
let client: NeonQueryFunction<false, false> | null = null;

function getClient(): NeonQueryFunction<false, false> {
  if (!isDbConfigured()) {
    throw new Error(
      'DATABASE_URL is not configured. Set it in your environment (e.g. a Neon/Vercel Postgres connection string) before running queries.',
    );
  }
  if (client === null) {
    client = neon(DATABASE_URL as string);
  }
  return client;
}

/**
 * Tagged-template SQL query function backed by neon().
 *
 * Usage (parameterized — values are bound, never interpolated):
 *   const rows = await sql`SELECT * FROM pools WHERE status = ${status}`;
 *
 * Because neon's query function is only created lazily via getClient(), calling
 * `sql` when the DB is unconfigured throws a descriptive error you can catch,
 * rather than failing at import time.
 */
export const sql = ((
  strings: TemplateStringsArray,
  ...values: unknown[]
) => {
  // Delegate to the real neon tagged-template function. Its return type is a
  // thenable that resolves to the row array.
  return (getClient() as unknown as (
    s: TemplateStringsArray,
    ...v: unknown[]
  ) => Promise<Record<string, unknown>[]>)(strings, ...values);
}) as NeonQueryFunction<false, false>;

export default sql;
