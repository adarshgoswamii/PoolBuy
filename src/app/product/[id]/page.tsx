'use client';

import React, { use } from 'react';
import Link from 'next/link';
import { notFound } from 'next/navigation';
import { useMarketplace } from '@/context/MarketplaceContext';

export default function ProductDetailPage({ params }: { params: Promise<{ id: string }> }) {
  const resolvedParams = use(params);
  const { pools } = useMarketplace();

  const pool = pools.find((p) => p.product_id === resolvedParams.id || p.id === resolvedParams.id);

  if (!pool || !pool.product) {
    return (
      <div className="max-w-4xl mx-auto px-4 py-16 text-center">
        <h2 className="text-xl font-bold text-slate-900 mb-2 font-display">Product Not Found</h2>
        <p className="text-sm text-slate-700 mb-6 font-medium">The requested product pool could not be located.</p>
        <Link href="/marketplace" className="px-5 py-2.5 rounded-full bg-accent hover:bg-accent-dark text-white text-xs font-bold transition shadow-sm">
          Return to Marketplace
        </Link>
      </div>
    );
  }

  const { product, tiers } = pool;
  const percentFilled = Math.min(100, Math.round((pool.reserved_qty / pool.moq) * 100));
  const isReached = pool.status === 'reached' || pool.reserved_qty >= pool.moq;

  return (
    <div className="max-w-7xl mx-auto px-4 sm:px-8 py-10">
      {/* Breadcrumbs */}
      <div className="flex items-center gap-2 text-xs text-slate-700 font-bold mb-6">
        <Link href="/" className="hover:text-accent">Home</Link>
        <span>/</span>
        <Link href="/marketplace" className="hover:text-accent">Marketplace</Link>
        <span>/</span>
        <span className="text-slate-900 font-bold truncate max-w-xs">{product.title}</span>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-12 gap-10">
        {/* Left Column: Images & Specs */}
        <div className="lg:col-span-7 space-y-6">
          <div className="rounded-3xl bg-white border border-slate-200 p-4 shadow-card overflow-hidden">
            <div className="aspect-[4/3] rounded-2xl overflow-hidden bg-slate-100">
              <img
                src={product.images[0] || 'https://images.unsplash.com/photo-1521572267360-ee0c2909d518?w=800'}
                alt={product.title}
                className="w-full h-full object-cover"
              />
            </div>
          </div>

          {/* Description & Technical Specs */}
          <div className="rounded-3xl bg-white border border-slate-200 p-6 shadow-card space-y-4">
            <h3 className="text-sm font-bold text-slate-900 uppercase tracking-wider font-display">Product Overview</h3>
            <p className="text-xs sm:text-sm text-slate-800 leading-relaxed font-normal">{product.description}</p>

            {product.specifications && (
              <div className="pt-4 border-t border-slate-100">
                <h4 className="text-xs font-bold text-slate-900 uppercase tracking-wider mb-3">
                  Technical Specifications
                </h4>
                <div className="grid grid-cols-2 gap-3">
                  {Object.entries(product.specifications).map(([key, val]) => (
                    <div key={key} className="p-3 rounded-xl bg-slate-50 border border-slate-200 text-xs">
                      <div className="text-[11px] text-slate-700 font-bold">{key}</div>
                      <div className="text-slate-950 font-semibold mt-0.5">{val}</div>
                    </div>
                  ))}
                </div>
              </div>
            )}
          </div>
        </div>

        {/* Right Column: Pool Pricing Ladder & Commitment Action */}
        <div className="lg:col-span-5 space-y-6">
          <div className="rounded-3xl bg-white border border-slate-200 p-6 shadow-card space-y-6 sticky top-24">
            {/* Seller Info */}
            <div className="flex items-center justify-between pb-4 border-b border-slate-100">
              <div className="flex items-center gap-2">
                <div className="w-10 h-10 rounded-xl bg-accent-50 text-accent flex items-center justify-center font-bold">
                  <span className="material-symbols-outlined text-2xl">factory</span>
                </div>
                <div>
                  <div className="text-xs font-bold text-slate-900">{pool.seller?.company_name}</div>
                  <div className="text-[10px] text-emerald-700 flex items-center gap-1 font-bold">
                    <span className="material-symbols-outlined text-[13px]">verified</span> Verified Manufacturer &bull; Physical Audit Passed
                  </div>
                </div>
              </div>
              <span className="font-mono text-xs text-slate-800 bg-slate-100 px-2.5 py-1 rounded-md font-bold">
                {pool.pool_code}
              </span>
            </div>

            {/* Title & Current Price */}
            <div>
              <h1 className="text-xl font-extrabold text-slate-900 leading-snug mb-3 font-display">
                {product.title}
              </h1>

              <div className="p-4 rounded-2xl bg-[#0F1D36] text-white">
                <div className="flex items-center justify-between mb-1">
                  <span className="text-xs text-slate-200 font-medium">Current Unlocked Price</span>
                  <span className="text-[11px] px-2 py-0.5 rounded bg-accent/20 text-amber-300 font-mono font-bold">
                    {percentFilled}% OF MOQ
                  </span>
                </div>
                <div className="flex items-baseline gap-2">
                  <span className="text-3xl font-black text-amber-300 font-mono">₹{pool.current_tier_price}</span>
                  <span className="text-xs text-slate-200 font-medium">/ unit (excl. 18% GST)</span>
                </div>
              </div>
            </div>

            {/* Live Progress Bar */}
            <div className="space-y-2">
              <div className="flex items-center justify-between text-xs text-slate-800 font-bold">
                <span>Total Reserved: {pool.reserved_qty} units</span>
                <span>Target MOQ: {pool.moq} units</span>
              </div>
              <div className="w-full h-3 rounded-full bg-slate-100 border border-slate-200 overflow-hidden">
                <div
                  className={`h-full pool-progress-pulse transition-all duration-700 ${
                    isReached ? 'bg-gradient-to-r from-emerald-500 to-teal-600' : 'bg-gradient-to-r from-accent to-rose-600'
                  }`}
                  style={{ width: `${percentFilled}%` }}
                ></div>
              </div>
              <div className="flex items-center justify-between text-[11px] text-slate-700 font-semibold">
                <span>Per-buyer limit: Min {pool.min_per_buyer} pcs</span>
                <span className="text-amber-800 font-bold">
                  {pool.moq - pool.reserved_qty > 0 ? `${pool.moq - pool.reserved_qty} units to next target` : 'Target Unlocked!'}
                </span>
              </div>
            </div>

            {/* Tiered Price Ladder Table */}
            <div>
              <h4 className="text-xs font-bold text-slate-900 uppercase tracking-wider mb-2 font-display">
                Wholesale Tier Pricing Ladder
              </h4>
              <div className="border border-slate-300 rounded-xl overflow-hidden text-xs">
                <table className="w-full text-left">
                  <thead className="bg-slate-100 border-b border-slate-300 text-slate-800 font-bold">
                    <tr>
                      <th className="py-2.5 px-3">Volume Tier</th>
                      <th className="py-2.5 px-3">Quantity Threshold</th>
                      <th className="py-2.5 px-3 text-right">Unit Price</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {tiers?.map((t) => {
                      const isUnlocked = pool.reserved_qty >= t.min_qty;
                      return (
                        <tr
                          key={t.id}
                          className={`${
                            isUnlocked ? 'bg-emerald-50/80 font-bold text-emerald-950' : 'text-slate-800 font-medium'
                          }`}
                        >
                          <td className="py-2.5 px-3">Tier #{t.tier_number}</td>
                          <td className="py-2.5 px-3">
                            {t.min_qty} {t.max_qty ? `- ${t.max_qty}` : '+'} units
                          </td>
                          <td className="py-2.5 px-3 text-right font-mono font-bold">
                            ₹{t.unit_price} {isUnlocked && '✓'}
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            </div>

            {/* CTA Button */}
            <Link
              href={`/join/${pool.id}`}
              className="w-full py-4 rounded-full bg-accent text-white font-bold text-sm hover:bg-accent-dark transition-all duration-200 shadow-md flex items-center justify-center gap-2 hover:shadow-lg hover:scale-[1.01]"
            >
              <span className="material-symbols-outlined text-[20px]">add_shopping_cart</span>
              <span>Join Group Commitment Pool</span>
            </Link>

            <div className="text-center text-[11px] text-slate-600 font-medium">
              Escrow-locked commitments &bull; Auto-repricing applies to every unit committed
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
