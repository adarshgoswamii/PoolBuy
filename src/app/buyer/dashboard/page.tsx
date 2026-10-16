'use client';

import React from 'react';
import Link from 'next/link';
import { useMarketplace } from '@/context/MarketplaceContext';
import { useAuth } from '@/context/AuthContext';

export default function BuyerDashboardPage() {
  const { commitments, pools } = useMarketplace();
  const { user } = useAuth();

  const totalSpent = commitments.reduce(
    (acc, c) => acc + c.quantity * (c.final_unit_price || c.locked_unit_price),
    0
  );

  const totalUnits = commitments.reduce((acc, c) => acc + c.quantity, 0);

  const totalSaved = commitments.reduce((acc, c) => {
    if (c.final_unit_price && c.final_unit_price < c.locked_unit_price) {
      return acc + (c.locked_unit_price - c.final_unit_price) * c.quantity;
    }
    return acc;
  }, 0);

  return (
    <div className="max-w-7xl mx-auto px-4 sm:px-8 py-10 space-y-8">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <div className="inline-flex items-center gap-1.5 text-xs text-accent font-semibold mb-1">
            <span className="material-symbols-outlined text-[16px]">verified</span>
            <span>Verified Wholesale Buyer Account</span>
          </div>
          <h1 className="text-2xl sm:text-3xl font-extrabold text-slate-900 font-display">
            Buyer Procurement Dashboard
          </h1>
          <p className="text-xs text-slate-500">
            {user?.company_name || 'UrbanStreet Apparel Retail'} &bull; GSTIN: {user?.gstin || '29AABCU9012K1ZX'}
          </p>
        </div>

        <Link
          href="/marketplace"
          className="px-4 py-2.5 rounded-xl bg-accent text-white font-bold text-xs hover:bg-accent-dark transition shadow-sm flex items-center gap-1.5 self-start"
        >
          <span className="material-symbols-outlined text-[18px]">add_shopping_cart</span>
          <span>Browse Active Pools</span>
        </Link>
      </div>

      {/* Metrics Row */}
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-5">
        <div className="bg-white rounded-2xl border border-slate-200 p-5 shadow-card">
          <div className="text-xs text-slate-500 font-medium mb-1">Total Procurement Volume</div>
          <div className="text-2xl font-black text-slate-900 font-mono">{totalUnits} units</div>
          <div className="text-[11px] text-slate-400 mt-1">Across {commitments.length} group pools</div>
        </div>

        <div className="bg-white rounded-2xl border border-slate-200 p-5 shadow-card">
          <div className="text-xs text-slate-500 font-medium mb-1">Total Escrow Capital Committed</div>
          <div className="text-2xl font-black text-slate-900 font-mono">₹{totalSpent.toLocaleString()}</div>
          <div className="text-[11px] text-slate-400 mt-1">Secured in platform escrow</div>
        </div>

        <div className="bg-white rounded-2xl border border-slate-200 p-5 shadow-card">
          <div className="text-xs text-slate-500 font-medium mb-1">Volume Repricing Savings</div>
          <div className="text-2xl font-black text-emerald-600 font-mono">₹{totalSaved.toLocaleString()}</div>
          <div className="text-[11px] text-emerald-700 font-semibold mt-1">
            Retroactive ladder discounts unlocked
          </div>
        </div>
      </div>

      {/* Active Commitments Table */}
      <div className="bg-white rounded-3xl border border-slate-200 p-6 shadow-card space-y-4">
        <h2 className="text-sm font-bold text-slate-900 uppercase tracking-wider pb-3 border-b border-slate-100 font-display">
          My Active Group-Buying Commitments ({commitments.length})
        </h2>

        {commitments.length > 0 ? (
          <div className="divide-y divide-slate-100">
            {commitments.map((com) => {
              const pool = pools.find((p) => p.id === com.pool_id);
              const subtotal = com.quantity * (com.final_unit_price || com.locked_unit_price);
              const fee = Math.round(subtotal * 0.02);
              const gst = Math.round(subtotal * 0.18);
              const total = subtotal + fee + gst;

              return (
                <div key={com.id} className="py-5 flex flex-col md:flex-row md:items-center justify-between gap-6">
                  <div className="flex items-start gap-4">
                    <div className="w-16 h-16 rounded-xl bg-slate-100 overflow-hidden border border-slate-200 flex-shrink-0">
                      {pool?.product?.images?.[0] ? (
                        <img
                          src={pool.product.images[0]}
                          alt="Product"
                          className="w-full h-full object-cover"
                        />
                      ) : (
                        <div className="w-full h-full flex items-center justify-center text-slate-400">
                          <span className="material-symbols-outlined">inventory</span>
                        </div>
                      )}
                    </div>
                    <div>
                      <div className="flex items-center gap-2 mb-1">
                        <span className="font-mono text-xs font-bold text-accent bg-accent-50 px-2 py-0.5 rounded">
                          {pool?.pool_code || com.pool_id}
                        </span>
                        <span className="text-[10px] uppercase font-bold px-2 py-0.5 rounded bg-emerald-100 text-emerald-800">
                          {com.status}
                        </span>
                      </div>
                      <h3 className="font-bold text-sm text-slate-900">
                        {pool?.product?.title || 'Wholesale Group Pool Item'}
                      </h3>
                      <div className="text-xs text-slate-500 mt-1">
                        Quantity: <strong>{com.quantity} units</strong> &bull; Locked: ₹{com.locked_unit_price}/unit &bull; Repriced: <strong className="text-emerald-600">₹{com.final_unit_price || com.locked_unit_price}/unit</strong>
                      </div>
                    </div>
                  </div>

                  <div className="text-right">
                    <div className="text-xs text-slate-500">Commercial Total (incl. GST)</div>
                    <div className="text-lg font-black font-mono text-slate-900">₹{total.toLocaleString()}</div>
                    <div className="text-[11px] text-slate-400">
                      Subtotal: ₹{subtotal.toLocaleString()} | Fee (2%): ₹{fee} | GST (18%): ₹{gst}
                    </div>
                  </div>
                </div>
              );
            })}
          </div>
        ) : (
          <div className="text-center py-12">
            <span className="material-symbols-outlined text-4xl text-slate-300">shopping_bag</span>
            <p className="text-xs text-slate-500 mt-2">You haven&apos;t joined any group-buying pools yet.</p>
            <Link href="/marketplace" className="inline-block mt-4 px-4 py-2 rounded-xl bg-accent hover:bg-accent-dark text-white font-bold text-xs transition shadow-sm">
              Explore Marketplace
            </Link>
          </div>
        )}
      </div>
    </div>
  );
}
