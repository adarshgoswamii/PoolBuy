'use client';

import React, { useMemo, useState } from 'react';
import { useMarketplace } from '@/context/MarketplaceContext';
import PoolCard from '@/components/PoolCard';
import CategoryFilterSidebar from '@/components/CategoryFilterSidebar';

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
    <div className="max-w-7xl mx-auto px-4 sm:px-8 py-8">
      {/* Marketplace Banner */}
      <div className="rounded-3xl bg-gradient-to-r from-[#0F1D36] via-[#162B4D] to-[#0A1426] text-white p-8 mb-8 border border-slate-800 shadow-elevated relative overflow-hidden">
        <div className="max-w-2xl relative z-10">
          <div className="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-amber-400/20 text-amber-300 border border-amber-400/30 text-xs font-semibold mb-3">
            <span className="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
            Verified Wholesale Factory Pools Active
          </div>
          <h1 className="text-3xl sm:text-4xl font-extrabold tracking-tight mb-2">
            PoolBuy Wholesale Marketplace
          </h1>
          <p className="text-sm text-slate-300 leading-relaxed">
            Co-buy with bulk retailers and manufacturers worldwide. Reach factory MOQ thresholds to unlock the lowest tier prices. Every buyer reprices retroactively.
          </p>
        </div>

        {/* Decorative Watermark */}
        <div className="absolute right-4 -bottom-10 opacity-10 pointer-events-none text-9xl font-black font-mono text-white select-none">
          POOLBUY
        </div>
      </div>

      {/* Main Layout: Sidebar & Product Grid */}
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
          <div className="flex flex-col sm:flex-row items-center justify-between gap-4 bg-white p-4 rounded-2xl border border-slate-200 shadow-sm">
            {/* Search Input */}
            <div className="relative w-full sm:w-96">
              <span className="material-symbols-outlined absolute left-3 top-2.5 text-slate-400 text-[18px]">
                search
              </span>
              <input
                type="text"
                placeholder="Search products, pool codes, fabric, wattage..."
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                className="w-full pl-9 pr-4 py-2 rounded-xl border border-slate-200 text-xs bg-slate-50 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-600 transition"
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
                className="px-3 py-2 rounded-xl border border-slate-200 text-xs bg-white text-slate-700 font-medium focus:outline-none focus:ring-2 focus:ring-blue-600"
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
                className="px-4 py-2 rounded-xl bg-blue-600 text-white text-xs font-semibold hover:bg-blue-700 transition"
              >
                Reset All Filters
              </button>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
