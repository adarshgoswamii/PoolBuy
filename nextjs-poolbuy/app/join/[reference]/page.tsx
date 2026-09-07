import { notFound } from 'next/navigation';
import { JoinForm, type JoinPoolInfo } from '@/components/JoinForm';
import { DbNotice, ErrorNotice, BackLink } from '@/components/ui';
import { apiGet } from '@/lib/ui';

export const dynamic = 'force-dynamic';

interface DetailResponse {
  pool: {
    reference: string;
    title: string | null;
    status: string;
    is_open_for_reservations: boolean;
    units_remaining: number;
    pool_unit_price: number | null;
    min_qty_per_buyer: number;
    max_qty_per_buyer: number;
    currency_code: string;
    product: { name: string | null };
  };
}

export default async function JoinPage({ params }: { params: { reference: string } }) {
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
        <BackLink href={`/pool/${encodeURIComponent(params.reference)}`} label="Back to pool" />
        <ErrorNotice message={res.error ?? undefined} />
      </div>
    );
  }

  const p = res.data.pool;

  if (!p.is_open_for_reservations) {
    return (
      <div className="space-y-6">
        <BackLink href={`/pool/${encodeURIComponent(params.reference)}`} label="Back to pool" />
        <div className="card p-6">
          <h1 className="text-lg font-semibold text-slate-800">This pool is not open for reservations</h1>
          <p className="mt-1 text-sm text-slate-600">
            Only active pools accept new reservations. This pool is currently <strong>{p.status}</strong>.
          </p>
        </div>
      </div>
    );
  }

  const info: JoinPoolInfo = {
    reference: p.reference,
    title: p.title ?? p.product.name ?? 'Pool',
    product: p.product.name ?? '',
    unit_price: p.pool_unit_price,
    min_qty_per_buyer: p.min_qty_per_buyer,
    max_qty_per_buyer: p.max_qty_per_buyer,
    units_remaining: p.units_remaining,
    currency_code: p.currency_code,
  };

  return (
    <div className="mx-auto max-w-2xl space-y-6">
      <BackLink href={`/pool/${encodeURIComponent(params.reference)}`} label="Back to pool" />
      <div>
        <h1 className="text-2xl font-bold text-slate-900">Join pool</h1>
        <p className="mt-1 text-sm text-slate-600">
          {info.title}
          <span className="text-slate-400"> · {info.reference}</span>
        </p>
      </div>
      <JoinForm pool={info} />
    </div>
  );
}
