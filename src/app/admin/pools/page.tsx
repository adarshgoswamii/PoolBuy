'use client';

import React from 'react';
import { useMarketplace } from '@/context/MarketplaceContext';

export default function AdminPoolsPage() {
  const { pools, forceClosePool, repricePool } = useMarketplace();

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-black text-slate-900 tracking-tight">Pool State Machine Governance</h1>
          <p className="text-xs text-slate-500 mt-1">
            Super-admin operational controls: force close early, execute retroactive repricing cycles, and review capacity reservations.
          </p>
        </div>
      </div>

      <div className="bg-white rounded-3xl border border-slate-200 p-6 shadow-card space-y-4">
        <h2 className="text-sm font-bold text-slate-900 uppercase tracking-wider pb-3 border-b border-slate-100">
          All Active Wholesale Pools ({pools.length})
        </h2>

        <div className="overflow-x-auto">
          <table className="w-full text-left text-xs">
            <thead className="bg-slate-50 border-b border-slate-200 text-slate-500">
              <tr>
                <th className="py-3 px-3">Pool Code</th>
                <th className="py-3 px-3">Product Name</th>
                <th className="py-3 px-3">Seller / Mill</th>
                <th className="py-3 px-3">Reserved / MOQ</th>
                <th className="py-3 px-3">Current Unit</th>
                <th className="py-3 px-3">Status</th>
                <th className="py-3 px-3 text-right">Admin Overrides</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {pools.map((p) => {
                const pct = Math.min(100, Math.round((p.reserved_qty / p.moq) * 100));
                return (
                  <tr key={p.id} className="hover:bg-slate-50/60">
                    <td className="py-3.5 px-3 font-mono font-bold text-accent">
                      {p.pool_code}
                    </td>
                    <td className="py-3.5 px-3 font-semibold text-slate-900 max-w-xs truncate">
                      {p.product?.title}
                    </td>
                    <td className="py-3.5 px-3 text-slate-600">
                      {p.seller?.company_name}
                    </td>
                    <td className="py-3.5 px-3">
                      <div className="flex items-center gap-2">
                        <span className="font-bold text-slate-900">{p.reserved_qty}</span>
                        <span className="text-slate-400">/ {p.moq}</span>
                        <span className="text-[11px] font-mono text-emerald-600">({pct}%)</span>
                      </div>
                    </td>
                    <td className="py-3.5 px-3 font-mono font-bold text-slate-900">
                      ₹{p.current_tier_price}
                    </td>
                    <td className="py-3.5 px-3">
                      <span
                        className={`px-2 py-0.5 rounded text-[10px] font-bold uppercase ${
                          p.status === 'reached'
                            ? 'bg-emerald-100 text-emerald-800'
                            : p.status === 'active'
                            ? 'bg-blue-100 text-blue-800'
                            : 'bg-slate-200 text-slate-700'
                        }`}
                      >
                        {p.status}
                      </span>
                    </td>
                    <td className="py-3.5 px-3 text-right space-x-2">
                      <button
                        onClick={() => repricePool(p.id)}
                        className="px-2.5 py-1 rounded-lg bg-accent-light text-accent-dark font-semibold text-[11px] hover:bg-accent/20 transition border border-accent/30"
                        title="Recalculate unlocked tiers for this pool"
                      >
                        Reprice
                      </button>
                      {p.status !== 'closed' && (
                        <button
                          onClick={() => forceClosePool(p.id)}
                          className="px-2.5 py-1 rounded-lg bg-amber-50 text-amber-700 font-semibold text-[11px] hover:bg-amber-100 transition border border-amber-200"
                          title="Force close pool early"
                        >
                          Close Early
                        </button>
                      )}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}
