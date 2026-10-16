'use client';

import React, { useState } from 'react';
import { useMarketplace } from '@/context/MarketplaceContext';

export default function AdminSettingsPage() {
  const { pools, repricePool } = useMarketplace();

  const [platformFeePercent, setPlatformFeePercent] = useState(2.0);
  const [gstRatePercent, setGstRatePercent] = useState(18.0);
  const [cronToken, setCronToken] = useState('pb_cron_sec_89df20489bca112');
  const [isSaved, setIsSaved] = useState(false);
  const [lifecycleTriggered, setLifecycleTriggered] = useState(false);

  const handleSave = (e: React.FormEvent) => {
    e.preventDefault();
    setIsSaved(true);
    setTimeout(() => setIsSaved(false), 2500);
  };

  const handleTriggerLifecycle = () => {
    setLifecycleTriggered(true);
    pools.forEach((p) => repricePool(p.id));
    setTimeout(() => setLifecycleTriggered(false), 2500);
  };

  return (
    <div className="space-y-6 max-w-4xl">
      <div>
        <h1 className="text-2xl font-black text-slate-900 tracking-tight">Platform Operational Settings</h1>
        <p className="text-xs text-slate-700 font-semibold mt-1">
          Configure financial margins, statutory tax parameters, warehouse safety compliance rules, and scheduled lifecycle cron triggers.
        </p>
      </div>

      {isSaved && (
        <div className="p-3.5 rounded-2xl bg-emerald-50 border border-emerald-200 text-xs font-bold text-emerald-800 flex items-center gap-2">
          <span className="material-symbols-outlined text-[18px]">check_circle</span>
          Platform settings updated successfully!
        </div>
      )}

      {lifecycleTriggered && (
        <div className="p-3.5 rounded-2xl bg-blue-50 border border-blue-200 text-xs font-bold text-blue-800 flex items-center gap-2">
          <span className="material-symbols-outlined text-[18px]">sync</span>
          Lifecycle engine executed! All active pools and buyer reservations repriced to best volume tiers.
        </div>
      )}

      <form onSubmit={handleSave} className="space-y-6">
        {/* 1. Commercial Margin & Tax Rates */}
        <div className="bg-white rounded-3xl border border-slate-200 p-6 shadow-card space-y-4">
          <h2 className="text-sm font-bold text-slate-900 uppercase tracking-wider pb-2 border-b border-slate-100 font-display">
            Commercial Parameters
          </h2>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
            <div>
              <label className="block text-slate-800 font-bold mb-1">Platform Facilitation Fee (%)</label>
              <input
                type="number"
                step="0.1"
                min="0"
                max="20"
                value={platformFeePercent}
                onChange={(e) => setPlatformFeePercent(Number(e.target.value))}
                className="w-full px-3 py-2 rounded-xl border border-slate-300 bg-slate-50 focus:bg-white focus:outline-none focus:ring-2 focus:ring-accent font-mono font-bold text-slate-900"
              />
              <span className="text-[11px] text-slate-700 font-semibold mt-1 block">Applied to buyer subtotal upon pool closure.</span>
            </div>

            <div>
              <label className="block text-slate-800 font-bold mb-1">Standard GST Invoicing Rate (%)</label>
              <input
                type="number"
                step="0.5"
                min="0"
                max="28"
                value={gstRatePercent}
                onChange={(e) => setGstRatePercent(Number(e.target.value))}
                className="w-full px-3 py-2 rounded-xl border border-slate-300 bg-slate-50 focus:bg-white focus:outline-none focus:ring-2 focus:ring-accent font-mono font-bold text-slate-900"
              />
              <span className="text-[11px] text-slate-700 font-semibold mt-1 block">Statutory GST rate for wholesale invoicing.</span>
            </div>
          </div>
        </div>

        {/* 2. Warehouse Safety Enforcement */}
        <div className="bg-white rounded-3xl border border-slate-200 p-6 shadow-card space-y-4">
          <h2 className="text-sm font-bold text-slate-900 uppercase tracking-wider pb-2 border-b border-slate-100 flex items-center justify-between font-display">
            <span>Warehouse Climate Safety Enforcement</span>
            <span className="text-xs text-emerald-800 bg-emerald-100 px-2.5 py-0.5 rounded-full font-bold">ACTIVE</span>
          </h2>

          <div className="space-y-3 text-xs">
            <label className="flex items-start gap-3 cursor-pointer">
              <input
                type="checkbox"
                defaultChecked
                disabled
                className="mt-0.5 rounded text-accent focus:ring-accent h-4 w-4"
              />
              <div>
                <span className="font-bold text-slate-900">Enforce Heat-Sensitive Electronics Ban</span>
                <p className="text-slate-700 text-[11px] mt-0.5 leading-relaxed font-medium">
                  Strictly prevents listing of bare lithium-polymer pouch cells, perishable chemical batteries, or temperature-degrading components in non-climate warehouses.
                </p>
              </div>
            </label>

            <label className="flex items-start gap-3 cursor-pointer">
              <input
                type="checkbox"
                defaultChecked
                disabled
                className="mt-0.5 rounded text-accent focus:ring-accent h-4 w-4"
              />
              <div>
                <span className="font-bold text-slate-900">Mandatory GSTIN Validation on Seller Onboarding</span>
                <p className="text-slate-700 text-[11px] mt-0.5 leading-relaxed font-medium">
                  Requires 15-digit GSTIN format verification and physical mill audit before seller pool publishing rights are granted.
                </p>
              </div>
            </label>
          </div>
        </div>

        {/* 3. Lifecycle Cron Engine & Manual Run */}
        <div className="bg-white rounded-3xl border border-slate-200 p-6 shadow-card space-y-4">
          <h2 className="text-sm font-bold text-slate-900 uppercase tracking-wider pb-2 border-b border-slate-100 font-display">
            Pool Lifecycle State Machine Cron
          </h2>

          <div className="space-y-3 text-xs">
            <div>
              <label className="block text-slate-800 font-bold mb-1">Secure Cron Authorization Token</label>
              <input
                type="text"
                value={cronToken}
                onChange={(e) => setCronToken(e.target.value)}
                className="w-full px-3 py-2 rounded-xl border border-slate-300 bg-slate-50 font-mono text-xs text-slate-900 focus:bg-white focus:outline-none focus:ring-2 focus:ring-accent font-semibold"
              />
            </div>

            <div className="pt-2 flex flex-col sm:flex-row items-center justify-between gap-3 bg-slate-50 p-4 rounded-2xl border border-slate-200">
              <div>
                <div className="font-bold text-slate-900">Manual State Machine Trigger</div>
                <div className="text-[11px] text-slate-700 font-medium">Run retroactive repricing ladder &amp; close-out verification immediately.</div>
              </div>

              <button
                type="button"
                onClick={handleTriggerLifecycle}
                className="px-4 py-2 rounded-xl bg-accent text-white font-bold hover:bg-accent-dark transition shadow-sm whitespace-nowrap text-xs"
              >
                Run Lifecycle Now
              </button>
            </div>
          </div>
        </div>

        <button
          type="submit"
          className="px-6 py-3 rounded-xl bg-accent text-white font-bold text-xs hover:bg-accent-dark transition shadow-md"
        >
          Save Platform Settings
        </button>
      </form>
    </div>
  );
}
