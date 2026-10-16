'use client';

import React, { useState } from 'react';
import { useMarketplace } from '@/context/MarketplaceContext';
import { useAuth } from '@/context/AuthContext';

export default function SellerPoolsPage() {
  const { pools, createPool } = useMarketplace();
  const { user } = useAuth();

  const [isModalOpen, setIsModalOpen] = useState(false);
  const [moq, setMoq] = useState(200);
  const [minBuyer, setMinBuyer] = useState(20);
  const [basePrice, setBasePrice] = useState(400);
  const [productTitle, setProductTitle] = useState('Custom 220 GSM Cotton Tees');

  const myPools = pools; // In mock/demo mode shows active catalog

  const handleCreatePool = (e: React.FormEvent) => {
    e.preventDefault();
    createPool({
      moq,
      min_per_buyer: minBuyer,
      current_tier_price: basePrice,
      product: {
        id: `prod-${Date.now()}`,
        seller_id: user?.id || 'usr-seller-1',
        category_id: 'cat-1',
        subcategory_id: 'sub-1',
        title: productTitle,
        slug: productTitle.toLowerCase().replace(/\s+/g, '-'),
        description: 'Direct mill run batch with verified commercial quality.',
        images: ['https://images.unsplash.com/photo-1521572267360-ee0c2909d518?w=800'],
        base_price: basePrice,
        stock_quantity: 5000,
        attributes: { size: 'L', color: 'Black', gsm: '220 GSM' },
        is_approved: true,
        is_active: true,
        created_at: new Date().toISOString(),
      },
      seller: {
        company_name: user?.company_name || 'Verified Manufacturer',
        is_verified: true,
      },
      tiers: [
        { id: 't1', pool_id: 'new', tier_number: 1, min_qty: 0, max_qty: Math.round(moq * 0.4), unit_price: basePrice },
        { id: 't2', pool_id: 'new', tier_number: 2, min_qty: Math.round(moq * 0.4), max_qty: moq - 1, unit_price: Math.round(basePrice * 0.9) },
        { id: 't3', pool_id: 'new', tier_number: 3, min_qty: moq, max_qty: null, unit_price: Math.round(basePrice * 0.8) },
      ],
    });
    setIsModalOpen(false);
  };

  return (
    <div className="max-w-7xl mx-auto px-4 sm:px-8 py-10 space-y-8">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <div className="inline-flex items-center gap-1.5 text-xs text-amber-600 font-semibold mb-1">
            <span className="material-symbols-outlined text-[16px]">factory</span>
            <span>Manufacturer Pool Operations</span>
          </div>
          <h1 className="text-2xl sm:text-3xl font-extrabold text-slate-900 font-display">
            Seller Pool Management
          </h1>
          <p className="text-xs text-slate-700 font-semibold">
            {user?.company_name || 'Apex TexFab Mills Ltd.'} &bull; Verified Manufacturer
          </p>
        </div>

        <button
          onClick={() => setIsModalOpen(true)}
          className="px-4 py-2.5 rounded-xl bg-accent text-white font-bold text-xs hover:bg-accent-dark transition shadow-sm flex items-center gap-1.5 self-start"
        >
          <span className="material-symbols-outlined text-[18px]">add_circle</span>
          <span>Launch New Group Pool</span>
        </button>
      </div>

      {/* Creation Modal */}
      {isModalOpen && (
        <div className="fixed inset-0 z-50 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-white rounded-3xl p-6 sm:p-8 max-w-lg w-full shadow-2xl animate-in fade-in zoom-in-95">
            <div className="flex items-center justify-between pb-3 border-b border-slate-100 mb-4">
              <h3 className="text-base font-bold text-slate-900 font-display">Launch New Wholesale Pool</h3>
              <button onClick={() => setIsModalOpen(false)} className="text-slate-600 hover:text-slate-900 font-bold text-base">
                ✕
              </button>
            </div>

            <form onSubmit={handleCreatePool} className="space-y-4 text-xs">
              <div>
                <label className="block text-slate-800 font-bold mb-1">Product Title</label>
                <input
                  type="text"
                  required
                  value={productTitle}
                  onChange={(e) => setProductTitle(e.target.value)}
                  className="w-full px-3 py-2 rounded-xl border border-slate-300 bg-slate-50 focus:bg-white focus:outline-none focus:ring-2 focus:ring-accent font-medium text-slate-900"
                />
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-slate-800 font-bold mb-1">Minimum Order Qty (MOQ)</label>
                  <input
                    type="number"
                    required
                    min={10}
                    value={moq}
                    onChange={(e) => setMoq(Number(e.target.value))}
                    className="w-full px-3 py-2 rounded-xl border border-slate-300 bg-slate-50 focus:bg-white focus:outline-none focus:ring-2 focus:ring-accent font-mono font-bold text-slate-900"
                  />
                </div>
                <div>
                  <label className="block text-slate-800 font-bold mb-1">Min Per Buyer (pcs)</label>
                  <input
                    type="number"
                    required
                    min={1}
                    value={minBuyer}
                    onChange={(e) => setMinBuyer(Number(e.target.value))}
                    className="w-full px-3 py-2 rounded-xl border border-slate-300 bg-slate-50 focus:bg-white focus:outline-none focus:ring-2 focus:ring-accent font-mono font-bold text-slate-900"
                  />
                </div>
              </div>

              <div>
                <label className="block text-slate-800 font-bold mb-1">Base Starting Unit Price (₹)</label>
                <input
                  type="number"
                  required
                  min={1}
                  value={basePrice}
                  onChange={(e) => setBasePrice(Number(e.target.value))}
                  className="w-full px-3 py-2 rounded-xl border border-slate-300 bg-slate-50 focus:bg-white focus:outline-none focus:ring-2 focus:ring-accent font-mono font-bold text-slate-900"
                />
              </div>

              <div className="p-3 rounded-xl bg-slate-50 border border-slate-300 text-slate-800">
                <div className="font-bold text-slate-900 mb-1">Automatic Tier Ladder Generated:</div>
                <div className="space-y-0.5 text-[11px] font-mono font-semibold">
                  <div>Tier 1: 0 - {Math.round(moq * 0.4)} pcs &rarr; ₹{basePrice}</div>
                  <div>Tier 2: {Math.round(moq * 0.4)} - {moq - 1} pcs &rarr; ₹{Math.round(basePrice * 0.9)}</div>
                  <div className="text-emerald-800 font-bold">Tier 3 (MOQ): {moq}+ pcs &rarr; ₹{Math.round(basePrice * 0.8)}</div>
                </div>
              </div>

              <button
                type="submit"
                className="w-full py-3 rounded-xl bg-accent text-white font-bold hover:bg-accent-dark transition shadow-md"
              >
                Publish Pool to Marketplace
              </button>
            </form>
          </div>
        </div>
      )}

      {/* Pools Table */}
      <div className="bg-white rounded-3xl border border-slate-200 p-6 shadow-card space-y-4">
        <h2 className="text-sm font-bold text-slate-900 uppercase tracking-wider pb-3 border-b border-slate-100 font-display">
          My Active Group Pools ({myPools.length})
        </h2>

        <div className="overflow-x-auto">
          <table className="w-full text-left text-xs">
            <thead className="bg-slate-100 border-b border-slate-300 text-slate-800 font-bold">
              <tr>
                <th className="py-3 px-3">Pool Reference</th>
                <th className="py-3 px-3">Product Name</th>
                <th className="py-3 px-3">Fill Progress</th>
                <th className="py-3 px-3">Current Unit Price</th>
                <th className="py-3 px-3">Status</th>
                <th className="py-3 px-3 text-right">Commitments</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {myPools.map((p) => {
                const pct = Math.min(100, Math.round((p.reserved_qty / p.moq) * 100));
                return (
                  <tr key={p.id} className="hover:bg-slate-50/60">
                    <td className="py-3.5 px-3 font-mono font-bold text-accent">
                      {p.pool_code}
                    </td>
                    <td className="py-3.5 px-3 font-semibold text-slate-900 max-w-xs truncate">
                      {p.product?.title}
                    </td>
                    <td className="py-3.5 px-3">
                      <div className="flex items-center gap-2">
                        <span className="font-bold">{p.reserved_qty} / {p.moq}</span>
                        <span className="font-mono text-[11px] text-emerald-600">({pct}%)</span>
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
                            : 'bg-amber-100 text-amber-800'
                        }`}
                      >
                        {p.status}
                      </span>
                    </td>
                    <td className="py-3.5 px-3 text-right font-mono font-bold text-slate-700">
                      {p.commitments_count || 1} buyers
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
