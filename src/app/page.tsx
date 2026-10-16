'use client';

import React from 'react';
import Link from 'next/link';
import { useMarketplace } from '@/context/MarketplaceContext';
import PoolCard from '@/components/PoolCard';

export default function HomePage() {
  const { pools, categories } = useMarketplace();

  return (
    <div className="space-y-16 pb-20">
      {/* 1. Hero Section */}
      <section className="relative bg-gradient-to-b from-[#091224] via-[#0F1D36] to-[#162B4D] text-white pt-20 pb-28 px-4 sm:px-8 overflow-hidden">
        {/* Subtle grid pattern background */}
        <div className="absolute inset-0 opacity-10 bg-[radial-gradient(#C89D46_1px,transparent_1px)] [background-size:24px_24px] pointer-events-none"></div>

        <div className="max-w-6xl mx-auto text-center relative z-10 space-y-6">
          <div className="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-amber-400/15 border border-amber-400/30 text-amber-300 text-xs font-semibold backdrop-blur-md">
            <span className="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
            <span>B2B Wholesale Group-Buying Protocol &bull; Next-Gen Factory Sourcing</span>
          </div>

          <h1 className="text-4xl sm:text-6xl font-black tracking-tight leading-tight max-w-4xl mx-auto font-display">
            Unlock Factory Volume Pricing. <br />
            <span className="bg-gradient-to-r from-amber-300 via-amber-200 to-amber-400 bg-clip-text text-transparent">
              Together in Group Pools.
            </span>
          </h1>

          <p className="text-sm sm:text-base text-slate-300 max-w-2xl mx-auto leading-relaxed">
            PoolBuy unites wholesale buyers to satisfy factory Minimum Order Quantities (MOQ). When the pool locks, <strong>every participant is repriced down</strong> to the best unlocked volume tier.
          </p>

          {/* Call to Actions */}
          <div className="pt-4 flex flex-col sm:flex-row items-center justify-center gap-4">
            <Link
              href="/marketplace"
              className="w-full sm:w-auto px-8 py-4 rounded-full bg-accent hover:bg-accent-dark text-white font-extrabold text-sm transition shadow-elevated flex items-center justify-center gap-2 group hover:scale-105"
            >
              <span className="material-symbols-outlined text-[20px] group-hover:rotate-45 transition-transform">explore</span>
              <span>Explore Active Wholesale Pools</span>
            </Link>
            <Link
              href="/marketplace?category=apparel-fashion"
              className="w-full sm:w-auto px-7 py-4 rounded-full bg-white/10 hover:bg-white/15 text-white font-bold text-sm border border-white/20 transition backdrop-blur-sm"
            >
              Browse Apparel Blanks
            </Link>
          </div>
        </div>

        {/* Live Metrics Ticker */}
        <div className="max-w-5xl mx-auto mt-16 grid grid-cols-2 md:grid-cols-4 gap-4 p-5 rounded-2xl bg-white/10 border border-white/20 backdrop-blur-md text-center text-xs">
          <div>
            <div className="text-2xl font-black text-amber-300 font-mono">100%</div>
            <div className="text-slate-200 font-medium mt-1">Escrow Protected</div>
          </div>
          <div>
            <div className="text-2xl font-black text-white font-mono">2.0%</div>
            <div className="text-slate-200 font-medium mt-1">Transparent Platform Fee</div>
          </div>
          <div>
            <div className="text-2xl font-black text-emerald-400 font-mono">18% GST</div>
            <div className="text-slate-200 font-medium mt-1">Tax Credit Invoicing</div>
          </div>
          <div>
            <div className="text-2xl font-black text-amber-300 font-mono">0% Risk</div>
            <div className="text-slate-200 font-medium mt-1">Warehouse Climate-Safe</div>
          </div>
        </div>
      </section>

      {/* 2. How the Protocol Works */}
      <section className="max-w-7xl mx-auto px-4 sm:px-8">
        <div className="text-center max-w-2xl mx-auto mb-12">
          <div className="text-xs font-bold text-accent uppercase tracking-wider mb-2">The Architecture</div>
          <h2 className="text-2xl sm:text-3xl font-extrabold text-slate-900 tracking-tight font-display">
            How Co-Buying Unlocks Tier Discounts
          </h2>
          <div className="ornament">
            <span className="orn-line"></span>
            <span className="orn-diamond">&#9670;</span>
            <span className="orn-line"></span>
          </div>
          <p className="text-sm text-slate-600 mt-2 font-medium">
            No single buyer needs to fund an entire 500-unit factory MOQ alone. Group-buying distributes capacity safely.
          </p>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
          <div className="p-6 rounded-3xl bg-white border border-slate-200/90 shadow-card space-y-3">
            <div className="w-12 h-12 rounded-2xl bg-accent-50 text-accent flex items-center justify-center font-bold text-xl font-display">
              1
            </div>
            <h3 className="font-bold text-base text-slate-900">Verified Seller Lists Pool</h3>
            <p className="text-xs sm:text-sm text-slate-700 leading-relaxed font-normal">
              Audited manufacturers set the factory MOQ, pool duration, and a tiered ladder (e.g. 100 pcs @ ₹420, 300 pcs @ ₹380, 500 pcs @ ₹295).
            </p>
          </div>

          <div className="p-6 rounded-3xl bg-white border border-slate-200/90 shadow-card space-y-3">
            <div className="w-12 h-12 rounded-2xl bg-amber-50 text-amber-600 flex items-center justify-center font-bold text-xl font-display">
              2
            </div>
            <h3 className="font-bold text-base text-slate-900">Bulk Buyers Commit Volume</h3>
            <p className="text-xs sm:text-sm text-slate-700 leading-relaxed font-normal">
              Wholesale buyers commit parts of the batch (e.g. 30, 50, 100 pcs). Capital is securely locked in escrow with zero overselling risk.
            </p>
          </div>

          <div className="p-6 rounded-3xl bg-white border border-slate-200/90 shadow-card space-y-3">
            <div className="w-12 h-12 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center font-bold text-xl font-display">
              3
            </div>
            <h3 className="font-bold text-base text-slate-900">MOQ Locks &amp; Every Buyer Reprices</h3>
            <p className="text-xs sm:text-sm text-slate-700 leading-relaxed font-normal">
              Once MOQ is fulfilled, the deal closes. <strong>All buyers are automatically rewritten to the lowest unlocked tier price</strong> before shipping.
            </p>
          </div>
        </div>
      </section>

      {/* 3. Featured Active Pools */}
      <section className="max-w-7xl mx-auto px-4 sm:px-8">
        <div className="flex items-center justify-between mb-8">
          <div>
            <div className="text-xs font-bold text-accent uppercase tracking-wider mb-1">Live Group Pools</div>
            <h2 className="text-2xl font-extrabold text-slate-900 font-display">Active Wholesale Deals</h2>
          </div>
          <Link
            href="/marketplace"
            className="text-xs font-bold text-accent hover:text-accent-dark flex items-center gap-1"
          >
            <span>View All Pools</span>
            <span className="material-symbols-outlined text-[16px]">arrow_forward</span>
          </Link>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
          {pools.slice(0, 3).map((pool) => (
            <PoolCard key={pool.id} pool={pool} />
          ))}
        </div>
      </section>

      {/* 4. Strict Electronics Safety Guarantee */}
      <section className="max-w-7xl mx-auto px-4 sm:px-8">
        <div className="rounded-3xl bg-gradient-to-r from-[#F5EFEB] via-[#FAF5EE] to-[#F5EFEB] p-8 md:p-12 border border-[#E2E6ED] shadow-[0_4px_20px_rgba(15,29,54,0.04)] flex flex-col md:flex-row items-center justify-between gap-8">
          <div className="space-y-3 max-w-xl">
            <div className="inline-flex items-center gap-1.5 text-xs text-amber-900 font-bold bg-amber-100/90 px-3 py-1 rounded-full border border-amber-300">
              <span className="material-symbols-outlined text-[16px] text-amber-700">shield</span>
              Warehouse Climate Safety Protocol
            </div>
            <h3 className="text-2xl sm:text-3xl font-black font-display text-[#0F1D36]">
              Zero Heat-Degradable Electronics. Guaranteed.
            </h3>
            <p className="text-xs sm:text-sm text-slate-700 leading-relaxed font-normal">
              We strictly forbid temperature-sensitive raw battery pouches, perishable liquid-electrolyte cells, and hazardous components subject to non-climate warehouse decay. Only durable GaN chargers, braided cables, and industrial hardware allowed.
            </p>
          </div>

          <Link
            href="/marketplace?category=durable-electronics"
            className="px-6 py-3.5 rounded-full bg-accent hover:bg-accent-dark text-white font-bold text-xs transition shadow-md whitespace-nowrap hover:scale-105"
          >
            Browse Verified Electronics
          </Link>
        </div>
      </section>

      {/* 5. Category Directory */}
      <section className="max-w-7xl mx-auto px-4 sm:px-8">
        <div className="text-center max-w-xl mx-auto mb-8">
          <h2 className="text-2xl font-extrabold text-slate-900 font-display">Wholesale Category Directory</h2>
          <div className="ornament">
            <span className="orn-line"></span>
            <span className="orn-diamond">&#9670;</span>
            <span className="orn-line"></span>
          </div>
          <p className="text-sm text-slate-700 mt-1 font-medium">Direct access to audited manufacturer supply pipelines.</p>
        </div>

        <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4">
          {categories.map((cat) => (
            <Link
              key={cat.id}
              href={`/marketplace?category=${cat.slug}`}
              className="p-5 rounded-2xl bg-white border border-slate-200/90 hover:border-accent shadow-sm hover:shadow-card transition text-center group"
            >
              <div className="w-12 h-12 rounded-xl bg-accent-50 text-accent flex items-center justify-center mx-auto mb-3 group-hover:scale-110 transition">
                <span className="material-symbols-outlined text-2xl">{cat.icon || 'category'}</span>
              </div>
              <h4 className="font-bold text-xs text-slate-900 group-hover:text-accent transition">
                {cat.name}
              </h4>
            </Link>
          ))}
        </div>
      </section>
    </div>
  );
}
