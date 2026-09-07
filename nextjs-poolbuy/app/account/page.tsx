import { PoolCard, type PoolCardData } from '@/components/PoolCard';
import { DbNotice, ErrorNotice, EmptyState, StatCard } from '@/components/ui';
import { apiGet } from '@/lib/ui';

export const dynamic = 'force-dynamic';

interface PoolsResponse {
  currency: string;
  currency_symbol: string;
  pools: PoolCardData[];
}

const ACTIVE = new Set(['active', 'reached']);
const COMPLETED = new Set(['closed', 'fulfilled']);
const UNFULFILLED = new Set(['expired', 'cancelled']);

function Section({ title, subtitle, pools }: { title: string; subtitle: string; pools: PoolCardData[] }) {
  return (
    <section className="space-y-3">
      <div>
        <h2 className="text-lg font-semibold text-slate-800">
          {title} <span className="text-sm font-normal text-slate-400">({pools.length})</span>
        </h2>
        <p className="text-sm text-slate-500">{subtitle}</p>
      </div>
      {pools.length > 0 ? (
        <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
          {pools.map((p) => (
            <PoolCard key={p.reference} pool={p} />
          ))}
        </div>
      ) : (
        <EmptyState title="Nothing here yet" />
      )}
    </section>
  );
}

export default async function AccountPage() {
  const res = await apiGet<PoolsResponse>('/api/pools');

  return (
    <div className="space-y-8">
      <div>
        <h1 className="text-2xl font-bold text-slate-900">My Pools</h1>
        <p className="mt-1 text-sm text-slate-600">
          Track the pools you&apos;re participating in — active commitments, completed buys, and pools that didn&apos;t
          reach their target.
        </p>
      </div>

      {res.dbUnavailable ? (
        <DbNotice />
      ) : !res.ok || !res.data ? (
        <ErrorNotice message={res.error ?? undefined} />
      ) : (
        <AccountBody pools={res.data.pools} />
      )}
    </div>
  );
}

function AccountBody({ pools }: { pools: PoolCardData[] }) {
  const active = pools.filter((p) => ACTIVE.has(p.status));
  const completed = pools.filter((p) => COMPLETED.has(p.status));
  const unfulfilled = pools.filter((p) => UNFULFILLED.has(p.status));

  return (
    <div className="space-y-10">
      <div className="grid gap-4 sm:grid-cols-3">
        <StatCard label="Active" value={active.length} accent />
        <StatCard label="Completed" value={completed.length} />
        <StatCard label="Unfulfilled" value={unfulfilled.length} />
      </div>

      <Section title="Active" subtitle="Pools accepting or holding your reservations." pools={active} />
      <Section title="Completed" subtitle="Pools that closed or were fulfilled." pools={completed} />
      <Section
        title="Unfulfilled"
        subtitle="Pools that expired or were cancelled before reaching MOQ."
        pools={unfulfilled}
      />
    </div>
  );
}
