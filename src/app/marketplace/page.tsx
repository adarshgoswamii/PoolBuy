'use client';

import React, { useMemo, useState } from 'react';
import { useMarketplace } from '@/context/MarketplaceContext';
import PoolCard from '@/components/PoolCard';
import CategoryChips from '@/components/CategoryChips';
import CategoryFilterSidebar from '@/components/CategoryFilterSidebar';
import Link from 'next/link';

export default function MarketplacePage() {
  const { categories, pools } = useMarketplace();

  const [searchQuery, setSearchQuery] = useState('');
  const [sortBy, setSortBy] = useState<'fill' | 'price' | 'moq'>('fill');

  const [filters, setFilters] = useState({
    categorySlug: '',
    subcategoryId: '',
    size: [] as string[],
    color: [] as string[],
    fabric: '',
    gsm: [] as string[],
    wattage: [] as string[],
    connector: [] as string[],
    certification: [] as string[],
    nearMoqOnly: false,
    maxPrice: 5000,
  });

  const handleReset = () => {
    setFilters({
      categorySlug: '',
      subcategoryId: '',
      size: [],
      color: [],
      fabric: '',
      gsm: [],
      wattage: [],
      connector: [],
      certification: [],
      nearMoqOnly: false,
      maxPrice: 5000,
    });
    setSearchQuery('');
  };

  const handleSelectCategory = (slug: string) => {
    setFilters((prev) => ({
      ...prev,
      categorySlug: slug,
      subcategoryId: '',
    }));
  };

  const filteredPools = useMemo(() => {
    return pools.filter((pool) => {
      // 1. Search Query
      if (searchQuery) {
        const query = searchQuery.toLowerCase();
        const matchTitle = pool.product?.title.toLowerCase().includes(query);
        const matchCode = pool.pool_code.toLowerCase().includes(query);
        const matchSeller = pool.seller?.company_name?.toLowerCase().includes(query);
        if (!matchTitle && !matchCode && !matchSeller) return false;
      }

      // 2. Category Filter
      if (filters.categorySlug) {
        const cat = categories.find((c) => c.slug === filters.categorySlug);
        if (cat && pool.product?.category_id !== cat.id) return false;
      }

      // 3. Near MOQ only
      if (filters.nearMoqOnly) {
        const percent = (pool.reserved_qty / pool.moq) * 100;
        if (percent < 70) return false;
      }

      // 4. Apparel Filters
      const attrs = pool.product?.attributes || {};
      if (filters.size.length > 0 && attrs.size && !filters.size.includes(attrs.size)) {
        return false;
      }
      if (filters.color.length > 0 && attrs.color && !filters.color.includes(attrs.color)) {
        return false;
      }
      if (filters.fabric && attrs.fabric && attrs.fabric !== filters.fabric) {
        return false;
      }
      if (filters.gsm.length > 0 && attrs.gsm && !filters.gsm.includes(attrs.gsm)) {
        return false;
      }

      // 5. Electronics Filters
      if (filters.wattage.length > 0 && attrs.wattage && !filters.wattage.includes(attrs.wattage)) {
        return false;
      }
      if (filters.connector.length > 0 && attrs.connector && !filters.connector.includes(attrs.connector)) {
        return false;
      }
      if (filters.certification.length > 0) {
        const certs = Array.isArray(attrs.certification) ? attrs.certification : [attrs.certification];
        const hasCert = filters.certification.some((c) => certs.includes(c));
        if (!hasCert) return false;
      }

      return true;
    }).sort((a, b) => {
      if (sortBy === 'fill') {
        return (b.reserved_qty / b.moq) - (a.reserved_qty / a.moq);
      }
      if (sortBy === 'price') {
        return a.current_tier_price - b.current_tier_price;
      }
      if (sortBy === 'moq') {
        return a.moq - b.moq;
      }
      return 0;
    });
  }, [pools, categories, filters, searchQuery, sortBy]);

  return (
    <div className="bg-[#FAF8F5] min-h-screen">
      {/* 1. Warm Luxury Cashmere Shop Hero */}
      <section className="shop-hero-bg border-b border-[#E2E6ED] px-4 sm:px-8 py-10 sm:py-14">
        <div className="max-w-7xl mx-auto grid grid-cols-1 lg:grid-cols-12 gap-8 items-center">
          {/* Left Column: Editorial Headline & Value Props */}
          <div className="lg:col-span-7 space-y-4">
            {/* Breadcrumb */}
            <div className="flex items-center gap-2 text-xs text-slate-500 font-medium">
              <Link href="/" className="hover:text-accent transition">Home</Link>
              <span>/</span>
              <span className="text-accent font-semibold">Wholesale Marketplace</span>
            </div>

            <h1 className="text-3xl sm:text-5xl font-bold tracking-tight text-[#0F172A] font-display leading-[1.15]">
              Wholesale <em className="text-accent not-italic font-serif italic">Buying Pools</em>
            </h1>

            {/* Gold Ornament Line */}
            <div className="ornament left">
              <span className="orn-line"></span>
              <span className="orn-diamond">◆</span>
              <span className="orn-line"></span>
            </div>

            <p className="text-sm sm:text-base text-slate-600 leading-relaxed max-w-xl">
              Collaborative B2B procurement connecting bulk retail buyers directly with verified apparel mills, durable electronics manufacturers, and commercial suppliers across India.
            </p>

            <div className="pt-2 flex flex-wrap items-center gap-3 text-xs text-slate-700 font-medium">
              <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-white/80 border border-slate-200/80 shadow-sm backdrop-blur-sm">
                <span className="material-symbols-outlined text-accent text-[16px]">verified</span>
                Audited GSTIN Factories
              </span>
              <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-white/80 border border-slate-200/80 shadow-sm backdrop-blur-sm">
                <span className="material-symbols-outlined text-emerald-600 text-[16px]">lock</span>
                100% Escrow Protection
              </span>
              <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-white/80 border border-slate-200/80 shadow-sm backdrop-blur-sm">
                <span className="material-symbols-outlined text-amber-600 text-[16px]">trending_down</span>
                Auto Price Repricing
              </span>
            </div>
          </div>

          {/* Right Column: Editorial Wholesale Photography Card */}
          <div className="lg:col-span-5 relative">
            <div className="relative rounded-2xl overflow-hidden shadow-elevated border-2 border-white aspect-[16/10] group">
              <img
                src="https://images.unsplash.com/photo-1558769132-cb1aea458c5e?w=800"
                alt="Wholesale manufacturing warehouse textiles"
                className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-700"
              />
              <div className="absolute inset-0 bg-gradient-to-t from-[#0F1D36]/80 via-transparent to-transparent flex items-end p-5">
                <div className="text-white space-y-0.5">
                  <span className="text-[10px] font-bold uppercase tracking-wider text-amber-300">Direct From Mill</span>
                  <div className="text-sm font-bold">Apex TexFab Mills &amp; NovaVolt GaN Supply</div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

      {/* 2. Category Chips Bar */}
      <CategoryChips
        categories={categories}
        selectedCategorySlug={filters.categorySlug}
        onSelectCategory={handleSelectCategory}
      />

      {/* 3. Main Catalog Section */}
      <div className="max-w-7xl mx-auto px-4 sm:px-8 py-8">
        <div className="flex flex-col lg:flex-row gap-8 items-start">
          {/* Dynamic Filter Sidebar */}
          <CategoryFilterSidebar
            categories={categories}
            filters={filters}
            onFilterChange={setFilters}
            onReset={handleReset}
          />

          {/* Product Cards Grid Area */}
          <div className="flex-1 w-full space-y-6">
            {/* Controls Bar: Search & Sorting */}
            <div className="flex flex-col sm:flex-row items-center justify-between gap-4 bg-white p-3.5 sm:p-4 rounded-2xl border border-[#E2E6ED] shadow-sm">
              {/* Search Pill Input */}
              <div className="relative w-full sm:w-96">
                <span className="material-symbols-outlined absolute left-3.5 top-2.5 text-slate-400 text-[18px]">
                  search
                </span>
                <input
                  type="text"
                  placeholder="Search products, pool codes, fabric..."
                  value={searchQuery}
                  onChange={(e) => setSearchQuery(e.target.value)}
                  className="w-full pl-10 pr-4 py-2 rounded-full border border-slate-200 text-xs bg-slate-50 focus:bg-white focus:outline-none focus:ring-2 focus:ring-accent transition shadow-inner"
                />
              </div>

              {/* Sorting Controls */}
              <div className="flex items-center gap-3 w-full sm:w-auto justify-between sm:justify-end">
                <span className="text-xs text-slate-500 font-medium">
                  Showing <strong className="text-slate-900">{filteredPools.length}</strong> active pools
                </span>
                <select
                  value={sortBy}
                  onChange={(e) => setSortBy(e.target.value as any)}
                  className="px-3.5 py-2 rounded-full border border-slate-200 text-xs bg-white text-slate-700 font-semibold focus:outline-none focus:ring-2 focus:ring-accent shadow-sm"
                >
                  <option value="fill">Highest Fill Rate</option>
                  <option value="price">Lowest Unit Price</option>
                  <option value="moq">Lowest MOQ</option>
                </select>
              </div>
            </div>

            {/* Pools Grid */}
            {filteredPools.length > 0 ? (
              <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
                {filteredPools.map((pool) => (
                  <PoolCard key={pool.id} pool={pool} />
                ))}
              </div>
            ) : (
              <div className="bg-white rounded-2xl border border-slate-200 p-12 text-center shadow-sm">
                <div className="w-16 h-16 rounded-full bg-slate-100 text-slate-400 flex items-center justify-center mx-auto mb-4">
                  <span className="material-symbols-outlined text-3xl">filter_list_off</span>
                </div>
                <h3 className="text-base font-bold text-slate-800 mb-1">No pools match your filter criteria</h3>
                <p className="text-xs text-slate-500 mb-4 max-w-sm mx-auto">
                  Try clearing selected sizes, colors, or wattage filters to view other wholesale pools.
                </p>
                <button
                  onClick={handleReset}
                  className="px-6 py-2.5 rounded-full bg-accent text-white text-xs font-bold hover:bg-accent-dark transition shadow-sm"
                >
                  Reset All Filters
                </button>
              </div>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}
