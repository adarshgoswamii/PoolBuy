import Link from 'next/link';
import { notFound } from 'next/navigation';
import { ProgressBar, StatusBadge, DbNotice, ErrorNotice, BackLink } from '@/components/ui';
import { IconCheck, IconLock, IconTag, IconClock, IconArrowRight, IconLayers } from '@/components/icons';
import { apiGet, formatINR, formatQty, timeLeft } from '@/lib/ui';

export const dynamic = 'force-dynamic';

interface DecoratedTier {
  tier_id: number;
  min_qty: number;
  max_qty: number | null;
  price: number;
  unlocked: boolean;
  active: boolean;
  units_needed: number;
}

interface PoolDetail {
  reference: string;
  title: string | null;
  status: string;
  is_open_for_reservations: boolean;
  moq_target: number;
  reserved_qty: number;
  fill_percentage: number;
  moq_reached: boolean;
  units_remaining: number;
  units_to_next_tier: number | null;
  pool_unit_price: number | null;
  min_qty_per_buyer: number;
  max_qty_per_buyer: number;
  currency_code: string;
  date_start: string | null;
  date_end: string | null;
  lead_time: string | null;
  shipping_terms: string | null;
  product: { name: string | null; sku: string | null; description: string | null; unit: string | null };
  seller: { seller_id: number; name: string | null; company: string | null };
  tier_ladder: DecoratedTier[];
}

interface DetailResponse {
  currency: string;
  currency_symbol: string;
  pool: PoolDetail;
}

function tierRange(t: DecoratedTier): string {
  return t.max_qty === null ? `${formatQty(t.min_qty)}+` : `${formatQty(t.min_qty)} – ${formatQty(t.max_qty)}`;
}

export default async function PoolDetailPage({ params }: { params: { reference: string } }) {
  const res = await apiGet<DetailResponse>(`/api/pools/${encodeURIComponent(params.reference)}`);

  if (res.dbUnavailable) {
    return (
      <div className="space-y-6">
        <BackLink href="/marketplace" label="Back to marketplace" />
        <DbNotice />
      </div>
    );
  }
  if (res.status === 404) notFound();
  if (!res.ok || !res.data) {
    return (
      <div className="space-y-6">
        <BackLink href="/marketplace" label="Back to marketplace" />
        <ErrorNotice message={res.error ?? undefined} />
      </div>
    );
  }

  const pool = res.data.pool;
  const left = timeLeft(pool.date_end);

  return (
    <div className="space-y-6">
      <BackLink href="/marketplace" label="Back to marketplace" />

      <div className="grid gap-6 lg:grid-cols-3">
        {/* Main column */}
        <div className="space-y-6 lg:col-span-2">
          <div className="card p-6">
            <div className="flex items-start justify-between gap-3">
              <div>
                <p className="text-xs font-medium uppercase tracking-wide text-slate-400">{pool.reference}</p>
                <h1 className="mt-1 text-2xl font-bold text-slate-900">
                  {pool.title ?? pool.product.name ?? 'Pool'}
                </h1>
                <p className="mt-1 text-sm text-slate-600">
                  {pool.product.name}
                  {pool.product.sku ? <span className="text-slate-400"> · {pool.product.sku}</span> : null}
                  {pool.product.unit ? <span className="text-slate-400"> · per {pool.product.unit}</span> : null}
                </p>
              </div>
              <StatusBadge status={pool.status} />
            </div>

            {pool.product.description ? (
              <p className="mt-4 text-sm leading-relaxed text-slate-600">{pool.product.description}</p>
            ) : null}

            <div className="mt-6">
              <div className="mb-1 flex items-center justify-between text-sm text-slate-600">
                <span>
                  {formatQty(pool.reserved_qty)} / {formatQty(pool.moq_target)} units reserved
                </span>
                <span className="font-semibold text-brand-600">{pool.fill_percentage}%</span>
              </div>
              <ProgressBar percent={pool.fill_percentage} />
              <div className="mt-2 flex flex-wrap gap-x-6 gap-y-1 text-xs text-slate-500">
                <span>
                  {pool.moq_reached ? (
                    <span className="inline-flex items-center gap-1 font-medium text-green-600">
                      <IconCheck className="h-3.5 w-3.5" /> MOQ reached
                    </span>
                  ) : (
                    <>{formatQty(pool.units_remaining)} units to reach MOQ</>
                  )}
                </span>
                {pool.units_to_next_tier !== null ? (
                  <span>{formatQty(pool.units_to_next_tier)} units to next tier</span>
                ) : null}
                {left ? (
                  <span className="inline-flex items-center gap-1">
                    <IconClock className="h-3.5 w-3.5" /> {left}
                  </span>
                ) : null}
              </div>
            </div>
          </div>

          {/* Tier ladder */}
          <div className="card p-6">
            <div className="flex items-center gap-2">
              <IconLayers className="h-5 w-5 text-brand-600" />
              <h2 className="text-lg font-semibold text-slate-800">Price tier ladder</h2>
            </div>
            <p className="mt-1 text-sm text-slate-500">
              Tiers resolve on the pool total reserved quantity. The active tier is the current unit price for
              everyone.
            </p>
            <div className="mt-4 space-y-2">
              {pool.tier_ladder.map((t) => {
                const state = t.active ? 'active' : t.unlocked ? 'unlocked' : 'locked';
                return (
                  <div
                    key={t.tier_id}
                    className={`flex items-center justify-between rounded-lg border px-4 py-3 ${
                      state === 'active'
                        ? 'border-brand-400 bg-brand-50 ring-1 ring-brand-200'
                        : state === 'unlocked'
                          ? 'border-green-200 bg-green-50'
                          : 'border-slate-200 bg-white'
                    }`}
                  >
                    <div className="flex items-center gap-3">
                      <span
                        className={`flex h-7 w-7 items-center justify-center rounded-full ${
                          state === 'locked' ? 'bg-slate-100 text-slate-400' : 'bg-green-100 text-green-600'
                        }`}
                      >
                        {state === 'locked' ? <IconLock className="h-4 w-4" /> : <IconCheck className="h-4 w-4" />}
                      </span>
                      <div>
                        <p className="text-sm font-medium text-slate-800">{tierRange(t)} units</p>
                        <p className="text-xs text-slate-500">
                          {state === 'active'
                            ? 'Active tier'
                            : state === 'unlocked'
                              ? 'Unlocked'
                              : `${formatQty(t.units_needed)} more units to unlock`}
                        </p>
                      </div>
                    </div>
                    <div className="text-right">
                      <p className="text-base font-bold text-slate-800">{formatINR(t.price)}</p>
                      {state === 'active' ? (
                        <span className="badge bg-brand-100 text-brand-700">current</span>
                      ) : null}
                    </div>
                  </div>
                );
              })}
            </div>
          </div>
        </div>

        {/* Sidebar */}
        <aside className="space-y-6">
          <div className="card p-6">
            <p className="flex items-center gap-1 text-xs font-medium uppercase tracking-wide text-slate-500">
              <IconTag className="h-3.5 w-3.5" /> Current unit price
            </p>
            <p className="mt-1 text-3xl font-bold text-slate-900">
              {pool.pool_unit_price !== null ? formatINR(pool.pool_unit_price) : '—'}
            </p>
            <p className="mt-0.5 text-xs text-slate-500">
              Resolved on {formatQty(pool.reserved_qty)} reserved units
            </p>

            <div className="mt-4">
              {pool.is_open_for_reservations ? (
                <Link href={`/join/${encodeURIComponent(pool.reference)}`} className="btn-primary w-full">
                  Join this pool <IconArrowRight className="h-4 w-4" />
                </Link>
              ) : (
                <button className="btn-secondary w-full" disabled>
                  {pool.status === 'draft' ? 'Not yet open' : 'Closed for reservations'}
                </button>
              )}
            </div>
            {!pool.is_open_for_reservations ? (
              <p className="mt-2 text-center text-xs text-slate-500">
                Only active pools accept new reservations.
              </p>
            ) : null}
          </div>

          <div className="card space-y-3 p-6 text-sm">
            <h3 className="text-xs font-medium uppercase tracking-wide text-slate-500">Pool details</h3>
            <Detail label="Seller" value={pool.seller.company ?? pool.seller.name ?? '—'} />
            <Detail label="Min per buyer" value={formatQty(pool.min_qty_per_buyer)} />
            <Detail
              label="Max per buyer"
              value={pool.max_qty_per_buyer > 0 ? formatQty(pool.max_qty_per_buyer) : 'No limit'}
            />
            <Detail label="Currency" value={pool.currency_code} />
            {pool.lead_time ? <Detail label="Lead time" value={pool.lead_time} /> : null}
            {pool.shipping_terms ? <Detail label="Shipping" value={pool.shipping_terms} /> : null}
          </div>
        </aside>
      </div>
    </div>
  );
}

function Detail({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex items-center justify-between gap-2">
      <span className="text-slate-500">{label}</span>
      <span className="text-right font-medium text-slate-800">{value}</span>
    </div>
  );
}
