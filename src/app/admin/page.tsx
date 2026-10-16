'use client';

import React from 'react';
import Link from 'next/link';
import { useMarketplace } from '@/context/MarketplaceContext';

export default function AdminOverviewPage() {
  const { pools, profiles, commitments, repricePool } = useMarketplace();

  // Metrics
  const activePools = pools.filter((p) => p.status === 'active' || p.status === 'reached');
  const reachedPools = pools.filter((p) => p.status === 'reached');
  const sellers = profiles.filter((p) => p.role === 'seller');
  const verifiedSellers = sellers.filter((p) => p.is_verified);

  // Financial Calculations
  const totalVolumeUnits = commitments.reduce((acc, c) => acc + c.quantity, 0);
  const totalGMV = commitments.reduce((acc, c) => acc + c.quantity * (c.final_unit_price || c.locked_unit_price), 0);
  const platformFees = Math.round(totalGMV * 0.02);
  const gstTotal = Math.round(totalGMV * 0.18);

  return (
    <div className="space-y-8">
      {/* Page Title */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-black text-slate-900 tracking-tight font-display">Executive Platform Overview</h1>
          <p className="text-xs text-slate-500 mt-1">
            Real-time pool state machine monitoring, escrow liquidity, seller KYC status, and settlement ledgers.
          </p>
        </div>

        <div className="flex items-center gap-2">
          <button
            onClick={() => pools.forEach((p) => repricePool(p.id))}
            className="px-3.5 py-2 rounded-xl bg-accent text-white font-bold text-xs hover:bg-accent-dark transition shadow-sm flex items-center gap-1.5"
          >
            <span className="material-symbols-outlined text-[16px]">sync</span>
            <span>Run Repricing Lifecycle</span>
          </button>
        </div>
      </div>

      {/* KPI Cards Grid */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
        <div className="bg-white rounded-2xl border border-slate-200 p-5 shadow-card">
          <div className="flex items-center justify-between text-xs text-slate-500 mb-2 font-medium">
            <span>Total Wholesale GMV</span>
            <span className="material-symbols-outlined text-accent">payments</span>
          </div>
          <div className="text-2xl font-black text-slate-900 font-mono">₹{totalGMV.toLocaleString()}</div>
          <div className="text-[11px] text-emerald-600 font-semibold mt-1">
            +18.4% volume this week
          </div>
        </div>

        <div className="bg-white rounded-2xl border border-slate-200 p-5 shadow-card">
          <div className="flex items-center justify-between text-xs text-slate-500 mb-2 font-medium">
            <span>Active Co-Buy Pools</span>
            <span className="material-symbols-outlined text-amber-600">hub</span>
          </div>
          <div className="text-2xl font-black text-slate-900 font-mono">{activePools.length}</div>
          <div className="text-[11px] text-accent font-semibold mt-1">
            {reachedPools.length} pools reached MOQ target
          </div>
        </div>

        <div className="bg-white rounded-2xl border border-slate-200 p-5 shadow-card">
          <div className="flex items-center justify-between text-xs text-slate-500 mb-2 font-medium">
            <span>Platform Fee Earned (2%)</span>
            <span className="material-symbols-outlined text-emerald-600">account_balance_wallet</span>
          </div>
          <div className="text-2xl font-black text-emerald-600 font-mono">₹{platformFees.toLocaleString()}</div>
          <div className="text-[11px] text-slate-500 mt-1">
            GST Collected: ₹{gstTotal.toLocaleString()} (18%)
          </div>
        </div>

        <div className="bg-white rounded-2xl border border-slate-200 p-5 shadow-card">
          <div className="flex items-center justify-between text-xs text-slate-500 mb-2 font-medium">
            <span>Verified Manufacturers</span>
            <span className="material-symbols-outlined text-indigo-600">verified</span>
          </div>
          <div className="text-2xl font-black text-slate-900 font-mono">{verifiedSellers.length} / {sellers.length}</div>
          <div className="text-[11px] text-amber-600 font-semibold mt-1">
            {sellers.length - verifiedSellers.length} pending KYC review
          </div>
        </div>
      </div>

      {/* Pool State Machine Pipeline */}
      <div className="bg-white rounded-3xl border border-slate-200 p-6 shadow-card space-y-4">
        <div className="flex items-center justify-between pb-3 border-b border-slate-100">
          <div>
            <h2 className="text-sm font-bold text-slate-900 uppercase tracking-wider font-display">
              Pool State Machine Pipeline
            </h2>
            <p className="text-xs text-slate-500">Live lifecycle tracking of group commitments</p>
          </div>
          <Link href="/admin/pools" className="text-xs text-accent font-bold hover:underline">
            Manage All Pools &rarr;
          </Link>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
          {pools.map((pool) => {
            const pct = Math.min(100, Math.round((pool.reserved_qty / pool.moq) * 100));
            return (
              <div key={pool.id} className="p-4 rounded-2xl bg-slate-50 border border-slate-200 space-y-3">
                <div className="flex items-center justify-between">
                  <span className="font-mono text-xs font-bold text-slate-900">{pool.pool_code}</span>
                  <span
                    className={`px-2 py-0.5 rounded text-[10px] font-bold uppercase ${
                      pool.status === 'reached'
                        ? 'bg-emerald-100 text-emerald-800'
                        : pool.status === 'active'
                        ? 'bg-accent-50 text-accent font-bold'
                        : 'bg-slate-200 text-slate-700'
                    }`}
                  >
                    {pool.status}
                  </span>
                </div>

                <div className="text-xs font-semibold text-slate-800 line-clamp-1">
                  {pool.product?.title}
                </div>

                <div>
                  <div className="flex items-center justify-between text-[11px] text-slate-500 mb-1">
                    <span>{pool.reserved_qty} / {pool.moq} MOQ</span>
                    <span className="font-bold">{pct}%</span>
                  </div>
                  <div className="w-full h-2 rounded-full bg-slate-200 overflow-hidden">
                    <div
                      className={`h-full ${pct >= 100 ? 'bg-emerald-500' : 'bg-accent'}`}
                      style={{ width: `${pct}%` }}
                    ></div>
                  </div>
                </div>

                <div className="flex items-center justify-between text-xs pt-2 border-t border-slate-200">
                  <span className="text-slate-500">Unlocked Unit:</span>
                  <span className="font-bold font-mono text-slate-900">₹{pool.current_tier_price}</span>
                </div>
              </div>
            );
          })}
        </div>
      </div>

      {/* Recent Commitments Table */}
      <div className="bg-white rounded-3xl border border-slate-200 p-6 shadow-card space-y-4">
        <h2 className="text-sm font-bold text-slate-900 uppercase tracking-wider font-display">
          Recent Atomic Reservations Ledger
        </h2>
        <div className="overflow-x-auto">
          <table className="w-full text-left text-xs">
            <thead className="bg-slate-50 border-b border-slate-200 text-slate-500">
              <tr>
                <th className="py-2.5 px-3">Commitment ID</th>
                <th className="py-2.5 px-3">Pool Reference</th>
                <th className="py-2.5 px-3">Quantity</th>
                <th className="py-2.5 px-3">Locked Price</th>
                <th className="py-2.5 px-3">Current Repriced</th>
                <th className="py-2.5 px-3">Status</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100 font-mono">
              {commitments.map((c) => (
                <tr key={c.id} className="hover:bg-slate-50/50">
                  <td className="py-2.5 px-3 text-slate-700">{c.id}</td>
                  <td className="py-2.5 px-3 font-bold text-accent">{c.pool_id}</td>
                  <td className="py-2.5 px-3 text-slate-900">{c.quantity} pcs</td>
                  <td className="py-2.5 px-3 text-slate-500">₹{c.locked_unit_price}</td>
                  <td className="py-2.5 px-3 font-bold text-emerald-600">₹{c.final_unit_price || c.locked_unit_price}</td>
                  <td className="py-2.5 px-3">
                    <span className="px-2 py-0.5 rounded bg-emerald-100 text-emerald-800 text-[10px] uppercase font-bold">
                      {c.status}
                    </span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}
