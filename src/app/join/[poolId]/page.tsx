'use client';

import React, { use, useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useMarketplace } from '@/context/MarketplaceContext';
import { useAuth } from '@/context/AuthContext';

export default function JoinPoolPage({ params }: { params: Promise<{ poolId: string }> }) {
  const resolvedParams = use(params);
  const router = useRouter();
  const { pools, joinPool } = useMarketplace();
  const { user } = useAuth();

  const pool = pools.find((p) => p.id === resolvedParams.poolId);

  const [quantity, setQuantity] = useState<number>(pool?.min_per_buyer || 25);
  const [address, setAddress] = useState({
    companyName: user?.company_name || 'Acme Wholesale Corp',
    gstin: user?.gstin || '29AABCU9012K1ZX',
    addressLine: 'Warehouse 12, Logistics Park Sector 6',
    city: 'Gurugram',
    state: 'Haryana',
    postalCode: '122001',
  });
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [isSuccess, setIsSuccess] = useState(false);

  if (!pool || !pool.product) {
    return (
      <div className="max-w-xl mx-auto px-4 py-16 text-center">
        <h2 className="text-xl font-bold text-slate-800 mb-2">Pool Unavailable</h2>
        <p className="text-sm text-slate-500 mb-6">The requested commitment pool could not be found.</p>
        <Link href="/marketplace" className="px-4 py-2 rounded-xl bg-accent hover:bg-accent-dark text-white text-xs font-semibold">
          Return to Marketplace
        </Link>
      </div>
    );
  }

  // Price Calculation Breakdown
  const unitPrice = pool.current_tier_price;
  const subtotal = Math.round(quantity * unitPrice);
  const platformFee = Math.round(subtotal * 0.02); // 2% platform fee
  const gst = Math.round(subtotal * 0.18);         // 18% GST
  const totalAmount = subtotal + platformFee + gst;

  // Potential savings if next tier is unlocked
  const nextTier = pool.tiers?.find((t) => t.min_qty > pool.reserved_qty);
  const potentialSavings = nextTier
    ? Math.round(quantity * (unitPrice - nextTier.unit_price))
    : 0;

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsSubmitting(true);
    await joinPool(pool.id, quantity, address);
    setIsSubmitting(false);
    setIsSuccess(true);
  };

  return (
    <div className="max-w-4xl mx-auto px-4 sm:px-8 py-10">
      {/* Success Modal Confirmation */}
      {isSuccess && (
        <div className="fixed inset-0 z-50 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-white rounded-3xl p-8 max-w-md w-full text-center shadow-2xl animate-in fade-in zoom-in-95">
            <div className="w-16 h-16 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center mx-auto mb-4">
              <span className="material-symbols-outlined text-4xl">check_circle</span>
            </div>
            <h2 className="text-xl font-bold text-slate-900 mb-2 font-display">Commitment Locked!</h2>
            <p className="text-xs text-slate-600 mb-6 leading-relaxed">
              You have successfully reserved <strong>{quantity} units</strong> in pool <strong>{pool.pool_code}</strong>. If the pool unlocks lower price tiers, your order will automatically be repriced down!
            </p>
            <div className="space-y-2">
              <button
                onClick={() => router.push('/buyer/dashboard')}
                className="w-full py-3 rounded-xl bg-accent hover:bg-accent-dark text-white font-bold text-xs transition shadow-md"
              >
                Go to Buyer Dashboard
              </button>
              <button
                onClick={() => router.push('/marketplace')}
                className="w-full py-2.5 rounded-xl border border-slate-200 text-slate-600 font-semibold text-xs hover:bg-slate-50 transition"
              >
                Browse More Pools
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Header */}
      <div className="mb-8">
        <div className="inline-flex items-center gap-1.5 text-xs text-accent font-semibold mb-2">
          <span className="material-symbols-outlined text-[16px]">lock</span>
          <span>Escrow-Guaranteed Wholesale Commitment Flow</span>
        </div>
        <h1 className="text-2xl sm:text-3xl font-extrabold text-slate-900 font-display">
          Join Commitment Pool: {pool.pool_code}
        </h1>
        <p className="text-xs text-slate-500 mt-1">
          Lock your order volume to push this pool towards its {pool.moq} MOQ target.
        </p>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-12 gap-8">
        {/* Left: Input Form */}
        <div className="lg:col-span-7 space-y-6">
          <form onSubmit={handleSubmit} className="space-y-6">
            {/* 1. Quantity Selection */}
            <div className="bg-white rounded-3xl border border-slate-200 p-6 shadow-card space-y-4">
              <div className="flex items-center justify-between">
                <h3 className="text-xs font-bold text-slate-900 uppercase tracking-wider">
                  1. Order Quantity (Units)
                </h3>
                <span className="text-[11px] text-slate-500">
                  Min: {pool.min_per_buyer} &bull; Max: {pool.max_per_buyer || 500}
                </span>
              </div>

              <div className="flex items-center gap-4">
                <div className="flex items-center border border-slate-200 rounded-xl overflow-hidden bg-slate-50">
                  <button
                    type="button"
                    onClick={() => setQuantity((q) => Math.max(pool.min_per_buyer, q - 10))}
                    className="px-3 py-2 text-slate-600 hover:bg-slate-200 font-bold"
                  >
                    -
                  </button>
                  <input
                    type="number"
                    min={pool.min_per_buyer}
                    max={pool.max_per_buyer || 1000}
                    value={quantity}
                    onChange={(e) => setQuantity(Number(e.target.value))}
                    className="w-24 text-center py-2 bg-transparent text-sm font-bold text-slate-900 focus:outline-none"
                  />
                  <button
                    type="button"
                    onClick={() => setQuantity((q) => q + 10)}
                    className="px-3 py-2 text-slate-600 hover:bg-slate-200 font-bold"
                  >
                    +
                  </button>
                </div>

                <div className="flex flex-wrap gap-1.5">
                  {[pool.min_per_buyer, 50, 100, 200].map((preset) => (
                    <button
                      key={preset}
                      type="button"
                      onClick={() => setQuantity(preset)}
                      className={`px-3 py-1.5 rounded-lg text-xs font-semibold border transition ${
                        quantity === preset
                          ? 'bg-[#0F1D36] border-[#0F1D36] text-amber-300'
                          : 'border-slate-200 text-slate-600 hover:bg-slate-50'
                      }`}
                    >
                      {preset} pcs
                    </button>
                  ))}
                </div>
              </div>
            </div>

            {/* 2. Destination Details */}
            <div className="bg-white rounded-3xl border border-slate-200 p-6 shadow-card space-y-4">
              <h3 className="text-xs font-bold text-slate-900 uppercase tracking-wider">
                2. Commercial Dispatch &amp; GST Details
              </h3>

              <div className="grid grid-cols-2 gap-4 text-xs">
                <div>
                  <label className="block text-slate-600 font-semibold mb-1">Company / Trade Name</label>
                  <input
                    type="text"
                    required
                    value={address.companyName}
                    onChange={(e) => setAddress({ ...address, companyName: e.target.value })}
                    className="w-full px-3 py-2 rounded-xl border border-slate-200 bg-slate-50 focus:bg-white focus:outline-none focus:ring-2 focus:ring-accent"
                  />
                </div>
                <div>
                  <label className="block text-slate-600 font-semibold mb-1">GSTIN Number</label>
                  <input
                    type="text"
                    required
                    value={address.gstin}
                    onChange={(e) => setAddress({ ...address, gstin: e.target.value })}
                    className="w-full px-3 py-2 rounded-xl border border-slate-200 bg-slate-50 focus:bg-white focus:outline-none focus:ring-2 focus:ring-accent font-mono"
                  />
                </div>
                <div className="col-span-2">
                  <label className="block text-slate-600 font-semibold mb-1">Warehouse Address Line</label>
                  <input
                    type="text"
                    required
                    value={address.addressLine}
                    onChange={(e) => setAddress({ ...address, addressLine: e.target.value })}
                    className="w-full px-3 py-2 rounded-xl border border-slate-200 bg-slate-50 focus:bg-white focus:outline-none focus:ring-2 focus:ring-accent"
                  />
                </div>
                <div>
                  <label className="block text-slate-600 font-semibold mb-1">City</label>
                  <input
                    type="text"
                    required
                    value={address.city}
                    onChange={(e) => setAddress({ ...address, city: e.target.value })}
                    className="w-full px-3 py-2 rounded-xl border border-slate-200 bg-slate-50 focus:bg-white focus:outline-none focus:ring-2 focus:ring-accent"
                  />
                </div>
                <div>
                  <label className="block text-slate-600 font-semibold mb-1">Postal Code</label>
                  <input
                    type="text"
                    required
                    value={address.postalCode}
                    onChange={(e) => setAddress({ ...address, postalCode: e.target.value })}
                    className="w-full px-3 py-2 rounded-xl border border-slate-200 bg-slate-50 focus:bg-white focus:outline-none focus:ring-2 focus:ring-accent font-mono"
                  />
                </div>
              </div>
            </div>

            {/* Submit Action */}
            <button
              type="submit"
              disabled={isSubmitting}
              className="w-full py-4 rounded-full bg-accent text-white font-extrabold text-sm hover:bg-accent-dark transition-all duration-200 shadow-elevated flex items-center justify-center gap-2 hover:scale-[1.01]"
            >
              <span className="material-symbols-outlined text-[20px]">verified</span>
              <span>{isSubmitting ? 'Confirming Atomic Lock...' : `Lock Commitment (₹${totalAmount.toLocaleString()})`}</span>
            </button>
          </form>
        </div>

        {/* Right: Commercial Ledger Summary */}
        <div className="lg:col-span-5">
          <div className="bg-white rounded-3xl border border-slate-200 p-6 shadow-card space-y-5 sticky top-24">
            <h3 className="text-xs font-bold text-slate-900 uppercase tracking-wider pb-3 border-b border-slate-100 font-display">
              Commercial Order Breakdown
            </h3>

            {/* Product Snapshot */}
            <div className="flex items-center gap-3">
              <img
                src={pool.product.images[0]}
                alt={pool.product.title}
                className="w-16 h-16 rounded-xl object-cover border border-slate-200"
              />
              <div className="text-xs">
                <div className="font-bold text-slate-900 line-clamp-1">{pool.product.title}</div>
                <div className="text-slate-500 font-mono mt-0.5">{pool.pool_code}</div>
                <div className="text-accent font-medium">₹{unitPrice} / unit</div>
              </div>
            </div>

            {/* Line items */}
            <div className="space-y-2.5 text-xs text-slate-600 pt-3 border-t border-slate-100">
              <div className="flex items-center justify-between">
                <span>Subtotal ({quantity} pcs &times; ₹{unitPrice})</span>
                <span className="font-mono font-medium text-slate-900">₹{subtotal.toLocaleString()}</span>
              </div>
              <div className="flex items-center justify-between text-slate-500">
                <span>Platform Facilitation Fee (2%)</span>
                <span className="font-mono">₹{platformFee.toLocaleString()}</span>
              </div>
              <div className="flex items-center justify-between text-slate-500">
                <span>GST (18% Input Tax Credit Eligible)</span>
                <span className="font-mono">₹{gst.toLocaleString()}</span>
              </div>
              <div className="pt-3 border-t border-slate-200 flex items-center justify-between text-sm font-extrabold text-slate-900">
                <span>Total Commitment Payable</span>
                <span className="text-base text-accent font-mono font-black">₹{totalAmount.toLocaleString()}</span>
              </div>
            </div>

            {/* Potential Savings Banner */}
            {potentialSavings > 0 && (
              <div className="p-3.5 rounded-xl bg-amber-50 border border-amber-200 text-xs text-amber-900">
                <div className="font-bold flex items-center gap-1 text-amber-800">
                  <span className="material-symbols-outlined text-[16px]">trending_down</span>
                  <span>Automated Repricing Active</span>
                </div>
                <p className="text-[11px] text-amber-700 mt-1">
                  If this pool gathers {pool.moq - pool.reserved_qty} more units, your unit price automatically lowers to ₹{nextTier?.unit_price}, saving you an additional <strong>₹{potentialSavings.toLocaleString()}</strong>!
                </p>
              </div>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}
