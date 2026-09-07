import Link from 'next/link';
import { ProgressBar, StatusBadge, DbNotice, ErrorNotice, EmptyState, StatCard } from '@/components/ui';
import { IconUsers, IconStore } from '@/components/icons';
import { apiGet, formatINR, formatQty } from '@/lib/ui';

export const dynamic = 'force-dynamic';

interface SellerReservation {
  reservation_id: number;
  buyer: string;
  quantity: number;
  unit_price_locked: number;
  status: string;
  created_at: string | null;
}

interface SellerPool {
  reference: string;
  title: string | null;
  status: string;
  is_open_for_reservations: boolean;
  product: string | null;
  sku: string | null;
  moq_target: number;
  reserved_qty: number;
  fill_percentage: number;
  pool_unit_price: number | null;
  currency_code: string;
  date_start: string | null;
  date_end: string | null;
  reservation_count: number;
  reservations: SellerReservation[];
}

interface SellerResponse {
  seller: { seller_id: number; name: string | null; company: string | null };
  pool_count: number;
  pools: SellerPool[];
}

interface SellersListResponse {
  total_sellers: number;
  sellers: { seller_id: number; name: string | null; company: string | null }[];
}

function reservationBadge(status: string): string {
  switch (status) {
    case 'confirmed':
    case 'converted':
      return 'bg-green-100 text-green-700';
    case 'pending':
      return 'bg-amber-100 text-amber-700';
    case 'cancelled':
    case 'released':
      return 'bg-rose-100 text-rose-700';
    default:
      return 'bg-slate-100 text-slate-600';
  }
}

export default async function SellerPage({ searchParams }: { searchParams: { sellerId?: string } }) {
  // Resolve a seller: explicit ?sellerId, else the first seller in the system.
  const listRes = await apiGet<SellersListResponse>('/api/admin/sellers');

  if (listRes.dbUnavailable) {
    return (
      <div className="space-y-6">
        <Header />
        <DbNotice />
      </div>
    );
  }
  if (!listRes.ok || !listRes.data) {
    return (
      <div className="space-y-6">
        <Header />
        <ErrorNotice message={listRes.error ?? undefined} />
      </div>
    );
  }

  const sellers = listRes.data.sellers;
  if (sellers.length === 0) {
    return (
      <div className="space-y-6">
        <Header />
        <EmptyState title="No sellers yet" hint="Seed the database to see seller data." />
      </div>
    );
  }

  const selectedId = searchParams.sellerId ? Number(searchParams.sellerId) : sellers[0].seller_id;
  const res = await apiGet<SellerResponse>(`/api/seller/${selectedId}/pools`);

  return (
    <div className="space-y-6">
      <Header />

      {/* Seller switcher */}
      <div className="flex flex-wrap gap-2">
        {sellers.map((s) => (
          <Link
            key={s.seller_id}
            href={`/seller?sellerId=${s.seller_id}`}
            className={`rounded-lg border px-3 py-1.5 text-sm font-medium transition ${
              s.seller_id === selectedId
                ? 'border-brand-400 bg-brand-50 text-brand-700'
                : 'border-slate-300 bg-white text-slate-600 hover:bg-slate-50'
            }`}
          >
            {s.company ?? s.name ?? `Seller #${s.seller_id}`}
          </Link>
        ))}
      </div>

      {!res.ok || !res.data ? (
        <ErrorNotice message={res.error ?? undefined} />
      ) : (
        <SellerBody data={res.data} />
      )}
    </div>
  );
}

function Header() {
  return (
    <div className="flex items-center gap-3">
      <span className="flex h-10 w-10 items-center justify-center rounded-lg bg-brand-50 text-brand-600">
        <IconStore className="h-5 w-5" />
      </span>
      <div>
        <h1 className="text-2xl font-bold text-slate-900">Seller dashboard</h1>
        <p className="text-sm text-slate-600">Your pools, fill progress and reservations (buyers are masked).</p>
      </div>
    </div>
  );
}

function SellerBody({ data }: { data: SellerResponse }) {
  const totalReserved = data.pools.reduce((sum, p) => sum + p.reserved_qty, 0);
  const activeCount = data.pools.filter((p) => p.is_open_for_reservations).length;

  return (
    <div className="space-y-6">
      <div className="grid gap-4 sm:grid-cols-3">
        <StatCard label="Total pools" value={data.pool_count} accent />
        <StatCard label="Active pools" value={activeCount} />
        <StatCard label="Units reserved" value={formatQty(totalReserved)} />
      </div>

      {data.pools.length === 0 ? (
        <EmptyState title="No pools for this seller yet" />
      ) : (
        <div className="space-y-5">
          {data.pools.map((pool) => (
            <div key={pool.reference} className="card p-6">
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                  <p className="text-xs font-medium uppercase tracking-wide text-slate-400">{pool.reference}</p>
                  <Link
                    href={`/pool/${encodeURIComponent(pool.reference)}`}
                    className="mt-0.5 block text-lg font-semibold text-slate-800 hover:text-brand-700"
                  >
                    {pool.title ?? pool.product ?? 'Pool'}
                  </Link>
                  <p className="text-sm text-slate-500">
                    {pool.product}
                    {pool.sku ? <span className="text-slate-400"> · {pool.sku}</span> : null}
                  </p>
                </div>
                <div className="text-right">
                  <StatusBadge status={pool.status} />
                  <p className="mt-1 text-sm font-semibold text-slate-800">
                    {pool.pool_unit_price !== null ? formatINR(pool.pool_unit_price) : '—'}
                  </p>
                </div>
              </div>

              <div className="mt-4">
                <div className="mb-1 flex items-center justify-between text-xs text-slate-500">
                  <span>
                    {formatQty(pool.reserved_qty)} / {formatQty(pool.moq_target)} units
                  </span>
                  <span className="font-semibold text-brand-600">{pool.fill_percentage}%</span>
                </div>
                <ProgressBar percent={pool.fill_percentage} />
              </div>

              <div className="mt-4">
                <p className="flex items-center gap-1 text-xs font-medium uppercase tracking-wide text-slate-500">
                  <IconUsers className="h-3.5 w-3.5" /> Reservations ({pool.reservation_count})
                </p>
                {pool.reservations.length > 0 ? (
                  <div className="mt-2 overflow-hidden rounded-lg border border-slate-200">
                    <table className="w-full text-sm">
                      <thead className="bg-slate-50 text-left text-xs uppercase text-slate-500">
                        <tr>
                          <th className="px-3 py-2 font-medium">Buyer</th>
                          <th className="px-3 py-2 font-medium">Qty</th>
                          <th className="px-3 py-2 font-medium">Locked price</th>
                          <th className="px-3 py-2 font-medium">Status</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-slate-100">
                        {pool.reservations.map((r) => (
                          <tr key={r.reservation_id}>
                            <td className="px-3 py-2 font-medium text-slate-700">{r.buyer}</td>
                            <td className="px-3 py-2 text-slate-600">{formatQty(r.quantity)}</td>
                            <td className="px-3 py-2 text-slate-600">{formatINR(r.unit_price_locked, 4)}</td>
                            <td className="px-3 py-2">
                              <span className={`badge ${reservationBadge(r.status)}`}>{r.status}</span>
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                ) : (
                  <p className="mt-2 text-sm text-slate-400">No reservations yet.</p>
                )}
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
