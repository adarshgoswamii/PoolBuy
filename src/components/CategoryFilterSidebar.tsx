'use client';

import React from 'react';
import { Category } from '@/lib/supabase/types';

interface FilterState {
  categorySlug: string;
  subcategoryId: string;
  size: string[];
  color: string[];
  fabric: string;
  gsm: string[];
  wattage: string[];
  connector: string[];
  certification: string[];
  nearMoqOnly: boolean;
  maxPrice: number;
}

interface CategoryFilterSidebarProps {
  categories: Category[];
  filters: FilterState;
  onFilterChange: (newFilters: FilterState) => void;
  onReset: () => void;
}

export default function CategoryFilterSidebar({
  categories,
  filters,
  onFilterChange,
  onReset,
}: CategoryFilterSidebarProps) {
  const isApparel = filters.categorySlug === 'apparel-fashion' || !filters.categorySlug;
  const isElectronics = filters.categorySlug === 'durable-electronics';

  const toggleArrayItem = (key: 'size' | 'color' | 'gsm' | 'wattage' | 'connector' | 'certification', value: string) => {
    const list = filters[key];
    const exists = list.includes(value);
    const updated = exists ? list.filter((i) => i !== value) : [...list, value];
    onFilterChange({ ...filters, [key]: updated });
  };

  return (
    <aside className="w-full lg:w-72 bg-white rounded-2xl border border-slate-200/90 p-5 shadow-card space-y-6">
      {/* Header & Reset Button */}
      <div className="flex items-center justify-between pb-3 border-b border-slate-100">
        <div className="flex items-center gap-2">
          <span className="material-symbols-outlined text-blue-600 text-xl">tune</span>
          <h2 className="font-bold text-slate-900 text-sm">Wholesale Filters</h2>
        </div>
        <button
          onClick={onReset}
          className="text-xs text-blue-600 hover:text-blue-800 font-semibold"
        >
          Reset All
        </button>
      </div>

      {/* 1. Category Selection */}
      <div>
        <label className="text-xs font-bold text-slate-700 uppercase tracking-wider block mb-2.5">
          Wholesale Category
        </label>
        <div className="space-y-1">
          <button
            onClick={() => onFilterChange({ ...filters, categorySlug: '', subcategoryId: '' })}
            className={`w-full text-left px-3 py-2 rounded-xl text-xs font-medium transition flex items-center justify-between ${
              filters.categorySlug === '' ? 'bg-[#0F1D36] text-amber-300 font-bold' : 'text-slate-600 hover:bg-slate-100'
            }`}
          >
            <span>All Categories</span>
            <span className="material-symbols-outlined text-[16px]">chevron_right</span>
          </button>
          {categories.map((cat) => (
            <button
              key={cat.id}
              onClick={() => onFilterChange({ ...filters, categorySlug: cat.slug, subcategoryId: '' })}
              className={`w-full text-left px-3 py-2 rounded-xl text-xs font-medium transition flex items-center justify-between ${
                filters.categorySlug === cat.slug
                  ? 'bg-[#0F1D36] text-amber-300 font-bold'
                  : 'text-slate-600 hover:bg-slate-100'
              }`}
            >
              <span className="truncate">{cat.name}</span>
              <span className="material-symbols-outlined text-[16px]">chevron_right</span>
            </button>
          ))}
        </div>
      </div>

      {/* 2. Electronics Climate Safety Notice */}
      {isElectronics && (
        <div className="p-3 rounded-xl bg-emerald-50 border border-emerald-200 text-xs text-emerald-900">
          <div className="flex items-center gap-1.5 font-bold mb-1">
            <span className="material-symbols-outlined text-emerald-700 text-[18px]">verified_user</span>
            <span>Warehouse-Safe Verified</span>
          </div>
          <p className="text-[11px] text-emerald-700 leading-relaxed">
            Strictly temperature-stable electronics (GaN chargers, braided cables, network hardware). Prohibits thermal-sensitive or unprotected lithium batteries.
          </p>
        </div>
      )}

      {/* 3. Dynamic Attribute Filters for Apparel */}
      {isApparel && (
        <>
          {/* Sizes */}
          <div>
            <label className="text-xs font-bold text-slate-700 uppercase tracking-wider block mb-2">
              Apparel Size
            </label>
            <div className="grid grid-cols-4 gap-1.5">
              {['XS', 'S', 'M', 'L', 'XL', '2XL', '3XL'].map((s) => {
                const isSelected = filters.size.includes(s);
                return (
                  <button
                    key={s}
                    onClick={() => toggleArrayItem('size', s)}
                    className={`py-1.5 rounded-lg text-xs font-semibold border transition ${
                      isSelected
                        ? 'bg-blue-600 border-blue-600 text-white'
                        : 'border-slate-200 text-slate-700 hover:border-slate-300 bg-white'
                    }`}
                  >
                    {s}
                  </button>
                );
              })}
            </div>
          </div>

          {/* Color Palette Chips */}
          <div>
            <label className="text-xs font-bold text-slate-700 uppercase tracking-wider block mb-2">
              Color Palette
            </label>
            <div className="flex flex-wrap gap-1.5">
              {[
                { name: 'Pitch Black', hex: '#111827' },
                { name: 'Pure White', hex: '#F9FAFB' },
                { name: 'Navy Blue', hex: '#1E3A8A' },
                { name: 'Olive Green', hex: '#3F6212' },
                { name: 'Charcoal Gray', hex: '#4B5563' },
                { name: 'Burgundy', hex: '#881337' },
              ].map((c) => {
                const isSelected = filters.color.includes(c.name);
                return (
                  <button
                    key={c.name}
                    onClick={() => toggleArrayItem('color', c.name)}
                    className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium border transition ${
                      isSelected
                        ? 'border-blue-600 bg-blue-50 text-blue-900 ring-2 ring-blue-600/20'
                        : 'border-slate-200 text-slate-700 hover:bg-slate-50'
                    }`}
                  >
                    <span
                      className="w-3 h-3 rounded-full border border-slate-300"
                      style={{ backgroundColor: c.hex }}
                    ></span>
                    <span>{c.name}</span>
                  </button>
                );
              })}
            </div>
          </div>

          {/* GSM Weight Badges */}
          <div>
            <label className="text-xs font-bold text-slate-700 uppercase tracking-wider block mb-2">
              Fabric Weight (GSM)
            </label>
            <div className="grid grid-cols-2 gap-1.5">
              {['180 GSM', '220 GSM', '240 GSM', '280 GSM'].map((gsm) => {
                const isSelected = filters.gsm.includes(gsm);
                return (
                  <button
                    key={gsm}
                    onClick={() => toggleArrayItem('gsm', gsm)}
                    className={`py-1.5 px-2 rounded-lg text-xs font-medium border text-center transition ${
                      isSelected
                        ? 'bg-amber-600 border-amber-600 text-white font-bold'
                        : 'border-slate-200 text-slate-700 hover:bg-slate-50'
                    }`}
                  >
                    {gsm}
                  </button>
                );
              })}
            </div>
          </div>

          {/* Fabric Type */}
          <div>
            <label className="text-xs font-bold text-slate-700 uppercase tracking-wider block mb-2">
              Fabric Composition
            </label>
            <select
              value={filters.fabric}
              onChange={(e) => onFilterChange({ ...filters, fabric: e.target.value })}
              className="w-full rounded-xl border border-slate-200 px-3 py-2 text-xs bg-white text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-600"
            >
              <option value="">All Fabrics</option>
              <option value="100% Combed Cotton">100% Combed Cotton</option>
              <option value="Cotton Blend">Cotton Blend (80/20)</option>
              <option value="French Terry">French Terry</option>
              <option value="Fleece">Heavy Brushed Fleece</option>
            </select>
          </div>
        </>
      )}

      {/* 4. Dynamic Attribute Filters for Electronics */}
      {isElectronics && (
        <>
          {/* Wattage */}
          <div>
            <label className="text-xs font-bold text-slate-700 uppercase tracking-wider block mb-2">
              Wattage / Power
            </label>
            <div className="grid grid-cols-3 gap-1.5">
              {['20W', '30W', '65W', '100W', '140W', '240W'].map((w) => {
                const isSelected = filters.wattage.includes(w);
                return (
                  <button
                    key={w}
                    onClick={() => toggleArrayItem('wattage', w)}
                    className={`py-1.5 rounded-lg text-xs font-semibold border transition ${
                      isSelected
                        ? 'bg-blue-600 border-blue-600 text-white'
                        : 'border-slate-200 text-slate-700 hover:bg-slate-50'
                    }`}
                  >
                    {w}
                  </button>
                );
              })}
            </div>
          </div>

          {/* Certifications */}
          <div>
            <label className="text-xs font-bold text-slate-700 uppercase tracking-wider block mb-2">
              Compliance &amp; Certifications
            </label>
            <div className="space-y-1.5">
              {['BIS Certified', 'CE Mark', 'RoHS Compliant'].map((cert) => {
                const isSelected = filters.certification.includes(cert);
                return (
                  <label
                    key={cert}
                    className="flex items-center gap-2 text-xs text-slate-700 cursor-pointer"
                  >
                    <input
                      type="checkbox"
                      checked={isSelected}
                      onChange={() => toggleArrayItem('certification', cert)}
                      className="rounded text-blue-600 focus:ring-blue-500 h-4 w-4"
                    />
                    <span>{cert}</span>
                  </label>
                );
              })}
            </div>
          </div>
        </>
      )}

      {/* 5. Universal Pool State Filter */}
      <div className="pt-3 border-t border-slate-100">
        <label className="flex items-center gap-2 cursor-pointer text-xs font-semibold text-slate-800">
          <input
            type="checkbox"
            checked={filters.nearMoqOnly}
            onChange={(e) => onFilterChange({ ...filters, nearMoqOnly: e.target.checked })}
            className="rounded text-blue-600 focus:ring-blue-500 h-4 w-4"
          />
          <span>Near MOQ Target (&gt; 70% filled)</span>
        </label>
      </div>
    </aside>
  );
}
