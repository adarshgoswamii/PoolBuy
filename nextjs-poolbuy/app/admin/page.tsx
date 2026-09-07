import Link from 'next/link';
import { StatusBadge, DbNotice, ErrorNotice, EmptyState, StatCard } from '@/components/ui';
import { ProgressBar } from '@/components/ui';
import { IconShield } from '@/components/icons';
import { RunLifecycleButton } from '@/components/RunLifecycleButton';
import { apiGet, formatINR, formatQty } from '@/lib/ui';

export const dynamic = 'force-dynamic';

interface AdminPool {
  reference: string;
  title: string | null;
  status: string;
  seller: { seller_id: number; name: string | null; company: string | null };
  product: string | null;
  sku: string | null;
  moq_target: number;
  reserved_qty: number;
  live_reserved_qty: number;
  fill_percentage: number;
  moq_reached: boolean;
  pool_unit_price: number | null;
  currency_code: string;
  reservation_count: number;
}

interface AdminPoolsResponse {
  total_pools: number;
  status_counts: Record<string, number>;
  pools: AdminPool[];
}

interface AdminSeller {
  seller_id: number;
  name: string | null;
  email: string | null;
  company: string | null;
  product_count: number;
  pool_count: number;
  active_pool_count: number;
  live_reserved_qty: number;
}

interface AdminSellersResponse {
  total_sellers: number;
  sellers: AdminSeller[];
}

export default async function AdminPage() {
  const [poolsRes, sellersRes] = await Promise.all([
    apiGet<AdminPoolsResponse>('/api/admin/pools'),
    apiGet<AdminSellersResponse>('/api/admin/sellers'),
  ]);

  return (
    <div className="space-y-8">
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div className="flex items-center gap-3">
          <span className="flex h-10 w-10 items-center justify-center rounded-lg bg-brand-50 text-brand-600">
            <IconShield className="h-5 w-5" />
          </span>
          <div>
            <h1 className="text-2xl font-bold text-slate-900">Admin console</h1>
            <p className="text-sm text-slate-600">System-wide pools, sellers and lifecycle controls.</p>
          </div>
        </div>
        <RunLifecycleButton />
      </div>

      {poolsRes.dbUnavailable ? (
        <DbNotice />
      ) : !poolsRes.ok || !poolsRes.data ? (
        <ErrorNotice message={poolsRes.error ?? undefined} />
      ) : (
        <>
          <StatusStats data={poolsRes.data} />
          <PoolsTable pools={poolsRes.data.pools} />
        </>
      )}

      {sellersRes.dbUnavailable ? null : !sellersRes.ok || !sellersRes.data ? (
        <ErrorNotice message={sellersRes.error ?? undefined} />
      ) : (
        <SellersTable sellers={sellersRes.data.sellers} />
      )}
    </div>
  );
}

function StatusStats({ data }: { data: AdminPoolsResponse }) {
  const c = data.status_counts;
  return (
    <div className="grid gap-4 sm:grid-cols-3 lg:grid-cols-6">
      <StatCard label="Total pools" value={data.total_pools} accent />
      <StatCard label="Active" value={c.active ?? 0} />
      <StatCard label="Reached" value={c.reached ?? 0} />
      <StatCard label="Closed" value={c.closed ?? 0} />
      <StatCard label="Expired" value={c.expired ?? 0} />
      <StatCard label="Draft" value={c.draft ?? 0} />
    </div>
  );
}

function PoolsTable({ pools }: { pools: AdminPool[] }) {
  return (
    <section className="space-y-3">
      <h2 className="text-lg font-semibold text-slate-800">Pools</h2>
      {pools.length === 0 ? (
        <EmptyState title="No pools" />
      ) : (
        <div className="card overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-slate-50 text-left text-xs uppercase text-slate-500">
                <tr>
                  <th className="px-4 py-3 font-medium">Pool</th>
                  <th className="px-4 py-3 font-medium">Seller</th>
                  <th className="px-4 py-3 font-medium">Status</th>
                  <th className="px-4 py-3 font-medium">Fill</th>
                  <th className="px-4 py-3 font-medium">Unit price</th>
                  <th className="px-4 py-3 font-medium">Reservations</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {pools.map((p) => (
                  <tr key={p.reference} className="hover:bg-slate-50">
                    <td className="px-4 py-3">
                      <Link
                        href={`/pool/${encodeURIComponent(p.reference)}`}
                        className="font-medium text-slate-800 hover:text-brand-700"
                      >
                        {p.title ?? p.product ?? p.reference}
                      </Link>
                      <p className="text-xs text-slate-400">{p.reference}</p>
                    </td>
                    <td className="px-4 py-3 text-slate-600">{p.seller.company ?? p.seller.name ?? '—'}</td>
                    <td className="px-4 py-3">
                      <StatusBadge status={p.status} />
                    </td>
                    <td className="px-4 py-3">
                      <div className="w-32">
                        <div className="mb-1 flex justify-between text-xs text-slate-500">
                          <span>
                            {formatQty(p.reserved_qty)}/{formatQty(p.moq_target)}
                          </span>
                          <span className="font-semibold text-brand-600">{p.fill_percentage}%</span>
                        </div>
                        <ProgressBar percent={p.fill_percentage} />
                      </div>
                    </td>
                    <td className="px-4 py-3 font-medium text-slate-700">
                      {p.pool_unit_price !== null ? formatINR(p.pool_unit_price) : '—'}
                    </td>
                    <td className="px-4 py-3 text-slate-600">
                      {p.reservation_count}
                      <span className="text-xs text-slate-400"> · {formatQty(p.live_reserved_qty)} live</span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </section>
  );
}

function SellersTable({ sellers }: { sellers: AdminSeller[] }) {
  return (
    <section className="space-y-3">
      <h2 className="text-lg font-semibold text-slate-800">Sellers</h2>
      {sellers.length === 0 ? (
        <EmptyState title="No sellers" />
      ) : (
        <div className="card overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-slate-50 text-left text-xs uppercase text-slate-500">
                <tr>
                  <th className="px-4 py-3 font-medium">Seller</th>
                  <th className="px-4 py-3 font-medium">Email</th>
                  <th className="px-4 py-3 font-medium">Products</th>
                  <th className="px-4 py-3 font-medium">Pools</th>
                  <th className="px-4 py-3 font-medium">Active</th>
                  <th className="px-4 py-3 font-medium">Live reserved</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {sellers.map((s) => (
                  <tr key={s.seller_id} className="hover:bg-slate-50">
                    <td className="px-4 py-3">
                      <Link
                        href={`/seller?sellerId=${s.seller_id}`}
                        className="font-medium text-slate-800 hover:text-brand-700"
                      >
                        {s.company ?? s.name ?? `Seller #${s.seller_id}`}
                      </Link>
                    </td>
                    <td className="px-4 py-3 text-slate-600">{s.email ?? '—'}</td>
                    <td className="px-4 py-3 text-slate-600">{s.product_count}</td>
                    <td className="px-4 py-3 text-slate-600">{s.pool_count}</td>
                    <td className="px-4 py-3 text-slate-600">{s.active_pool_count}</td>
                    <td className="px-4 py-3 text-slate-600">{formatQty(s.live_reserved_qty)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </section>
  );
}
