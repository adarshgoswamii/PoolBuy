import Link from 'next/link';
import { ProgressBar, StatusBadge } from '@/components/ui';
import { IconClock, IconTag, IconUsers } from '@/components/icons';
import { formatINR, formatQty, timeLeft } from '@/lib/ui';

export interface PoolCardData {
  reference: string;
  title: string | null;
  status: string;
  product: string | null;
  sku: string | null;
  seller: { seller_id: number; name: string | null };
  moq_target: number;
  reserved_qty: number;
  fill_percentage: number;
  pool_unit_price: number | null;
  currency_code: string;
  date_start: string | null;
  date_end: string | null;
}

export function PoolCard({ pool }: { pool: PoolCardData }) {
  const left = timeLeft(pool.date_end);
  return (
    <Link
      href={`/pool/${encodeURIComponent(pool.reference)}`}
      className="card group flex flex-col p-5 transition hover:border-brand-300 hover:shadow-md"
    >
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="truncate text-xs font-medium uppercase tracking-wide text-slate-400">{pool.reference}</p>
          <h3 className="mt-0.5 truncate text-base font-semibold text-slate-800 group-hover:text-brand-700">
            {pool.title ?? pool.product ?? 'Untitled pool'}
          </h3>
          <p className="truncate text-sm text-slate-500">
            {pool.product}
            {pool.sku ? <span className="text-slate-400"> · {pool.sku}</span> : null}
          </p>
        </div>
        <StatusBadge status={pool.status} />
      </div>

      <div className="mt-4">
        <div className="mb-1 flex items-center justify-between text-xs text-slate-500">
          <span className="inline-flex items-center gap-1">
            <IconUsers className="h-3.5 w-3.5" />
            {formatQty(pool.reserved_qty)} / {formatQty(pool.moq_target)} units
          </span>
          <span className="font-semibold text-brand-600">{pool.fill_percentage}%</span>
        </div>
        <ProgressBar percent={pool.fill_percentage} />
      </div>

      <div className="mt-4 flex items-end justify-between">
        <div>
          <p className="flex items-center gap-1 text-xs text-slate-500">
            <IconTag className="h-3.5 w-3.5" /> Current unit price
          </p>
          <p className="text-lg font-bold text-slate-800">
            {pool.pool_unit_price !== null ? formatINR(pool.pool_unit_price) : '—'}
          </p>
        </div>
        <div className="text-right">
          {left ? (
            <p className="inline-flex items-center gap-1 text-xs font-medium text-slate-500">
              <IconClock className="h-3.5 w-3.5" /> {left}
            </p>
          ) : null}
          <p className="mt-0.5 truncate text-xs text-slate-400">by {pool.seller.name ?? 'Seller'}</p>
        </div>
      </div>
    </Link>
  );
}
