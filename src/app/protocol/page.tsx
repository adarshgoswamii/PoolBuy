'use client';

import React from 'react';
import Link from 'next/link';

export default function ProtocolPage() {
  return (
    <div className="max-w-7xl mx-auto px-4 sm:px-8 py-12 space-y-12">
      {/* Header */}
      <div className="text-center max-w-3xl mx-auto space-y-4">
        <div className="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-amber-400/20 text-amber-800 border border-amber-400/40 text-xs font-semibold">
          <span className="material-symbols-outlined text-[16px]">verified</span>
          Institutional Wholesale Protocol Architecture
        </div>
        <h1 className="text-3xl sm:text-5xl font-black text-slate-900 tracking-tight leading-tight">
          Executive Wholesale Protocol &amp; Enterprise Governance
        </h1>
        <p className="text-sm text-slate-600 leading-relaxed">
          The mathematical, legal, and operational specification governing PoolBuy group commitments, escrow custody, warehouse storage safety, and automated retroactive repricing.
        </p>
      </div>

      {/* Protocol Pillars Grid */}
      <div className="grid grid-cols-1 md:grid-cols-3 gap-8">
        <div className="p-8 rounded-3xl bg-white border border-slate-200/90 shadow-card space-y-4">
          <div className="w-14 h-14 rounded-2xl bg-blue-50 text-blue-600 flex items-center justify-center">
            <span className="material-symbols-outlined text-3xl">currency_rupee</span>
          </div>
          <h3 className="text-lg font-bold text-slate-900">Deterministic Repricing Ladder</h3>
          <p className="text-xs text-slate-600 leading-relaxed">
            Every commitment records the maximum unit price locked at entry. When collective reservation crosses the factory MOQ threshold, the PostgreSQL transaction engine retroactively reprices 100% of confirmed commitments to the lowest unlocked tier before invoicing.
          </p>
          <div className="pt-2 border-t border-slate-100 text-[11px] font-mono text-blue-600 font-bold">
            PostgreSQL Function: recalculate_pool_pricing()
          </div>
        </div>

        <div className="p-8 rounded-3xl bg-white border border-slate-200/90 shadow-card space-y-4">
          <div className="w-14 h-14 rounded-2xl bg-amber-50 text-amber-600 flex items-center justify-center">
            <span className="material-symbols-outlined text-3xl">lock</span>
          </div>
          <h3 className="text-lg font-bold text-slate-900">Atomic Row-Level Concurrency</h3>
          <p className="text-xs text-slate-600 leading-relaxed">
            Capacity is never over-committed. Each buyer commitment executes inside a serializable transaction with <code>SELECT ... FOR UPDATE</code> locking on the pool record. Per-buyer minimums and maximums are strictly clamped server-side.
          </p>
          <div className="pt-2 border-t border-slate-100 text-[11px] font-mono text-amber-600 font-bold">
            PostgreSQL Procedure: join_pool_atomic()
          </div>
        </div>

        <div className="p-8 rounded-3xl bg-white border border-slate-200/90 shadow-card space-y-4">
          <div className="w-14 h-14 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
            <span className="material-symbols-outlined text-3xl">shield</span>
          </div>
          <h3 className="text-lg font-bold text-slate-900">Warehouse Climate Safety Standard</h3>
          <p className="text-xs text-slate-600 leading-relaxed">
            Strict catalog governance: thermal-sensitive raw lithium battery cells, volatile electrolyte items, and temperature-perishable electronics are prohibited to prevent inventory degradation in non-climate-controlled storage.
          </p>
          <div className="pt-2 border-t border-slate-100 text-[11px] font-mono text-emerald-600 font-bold">
            Catalog Clause: 100% Climate-Resilient Hardware
          </div>
        </div>
      </div>

      {/* Commercial Fee & Tax Structure */}
      <div className="rounded-3xl bg-[#0F1D36] text-white p-8 md:p-12 border border-slate-800 shadow-elevated space-y-6">
        <div className="max-w-2xl">
          <div className="text-xs font-bold text-amber-300 uppercase tracking-wider mb-2">Statutory Transparency</div>
          <h2 className="text-2xl font-bold">Standard Commercial Fee &amp; Tax Structure</h2>
          <p className="text-xs text-slate-300 mt-1">
            Standardized commercial settlement applied to all closed pool orders.
          </p>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-3 gap-6 text-xs">
          <div className="p-5 rounded-2xl bg-white/5 border border-white/10">
            <div className="text-2xl font-black font-mono text-amber-300 mb-1">2.0%</div>
            <div className="font-bold text-white text-sm mb-1">Platform Facilitation Fee</div>
            <p className="text-slate-400 leading-relaxed text-[11px]">
              Covers escrow custody, automated repricing compute, and factory quality audit verification.
            </p>
          </div>

          <div className="p-5 rounded-2xl bg-white/5 border border-white/10">
            <div className="text-2xl font-black font-mono text-emerald-400 mb-1">18.0%</div>
            <div className="font-bold text-white text-sm mb-1">Goods &amp; Services Tax (GST)</div>
            <p className="text-slate-400 leading-relaxed text-[11px]">
              Full GST e-invoicing generated automatically. 100% eligible for Input Tax Credit (ITC) for registered businesses.
            </p>
          </div>

          <div className="p-5 rounded-2xl bg-white/5 border border-white/10">
            <div className="text-2xl font-black font-mono text-blue-400 mb-1">Zero Markup</div>
            <div className="font-bold text-white text-sm mb-1">Direct Factory Pricing</div>
            <p className="text-slate-400 leading-relaxed text-[11px]">
              Buyers transact directly at manufacturer volume tier prices without distributors or middlemen margins.
            </p>
          </div>
        </div>
      </div>
    </div>
  );
}
