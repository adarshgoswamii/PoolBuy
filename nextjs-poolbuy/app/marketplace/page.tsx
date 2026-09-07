import { PoolCard, type PoolCardData } from '@/components/PoolCard';
import { DbNotice, ErrorNotice, EmptyState } from '@/components/ui';
import { apiGet } from '@/lib/ui';

export const dynamic = 'force-dynamic';

interface PoolsResponse {
  currency: string;
  currency_symbol: string;
  pools: PoolCardData[];
}

export default async function MarketplacePage() {
  const res = await apiGet<PoolsResponse>('/api/pools');

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-slate-900">Marketplace</h1>
        <p className="mt-1 text-sm text-slate-600">
          Browse open demand pools. Reserve quantity to help pools reach MOQ and unlock lower unit prices.
        </p>
      </div>

      {res.dbUnavailable ? (
        <DbNotice />
      ) : !res.ok ? (
        <ErrorNotice message={res.error ?? undefined} />
      ) : res.data && res.data.pools.length > 0 ? (
        <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
          {res.data.pools.map((pool) => (
            <PoolCard key={pool.reference} pool={pool} />
          ))}
        </div>
      ) : (
        <EmptyState title="No pools yet" hint="Once sellers publish pools, they'll appear here." />
      )}
    </div>
  );
}
