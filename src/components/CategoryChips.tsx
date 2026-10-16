'use client';

import React from 'react';
import { Category } from '@/lib/supabase/types';

interface CategoryChipsProps {
  categories: Category[];
  selectedCategorySlug: string;
  onSelectCategory: (slug: string) => void;
}

const CATEGORY_ICONS: Record<string, string> = {
  '': 'storefront',
  'apparel-fashion': 'checkroom',
  'durable-electronics': 'cable',
  'packaging-supply': 'inventory_2',
  'tools-safety': 'construction',
  'hospitality-kitchen': 'restaurant',
};

export default function CategoryChips({
  categories,
  selectedCategorySlug,
  onSelectCategory,
}: CategoryChipsProps) {
  return (
    <div className="bg-white border-y border-[#E2E6ED] py-3.5 px-4 sm:px-8 shadow-[0_1px_3px_rgba(15,29,54,0.03)]">
      <div className="max-w-7xl mx-auto flex items-center gap-3 overflow-x-auto no-scrollbar py-0.5">
        {/* All Categories Chip */}
        <button
          onClick={() => onSelectCategory('')}
          className={`flex items-center gap-2.5 px-5 py-2.5 rounded-full text-xs font-bold whitespace-nowrap transition-all duration-200 shadow-sm ${
            selectedCategorySlug === ''
              ? 'bg-[#0F1D36] text-white border-2 border-[#0F1D36] shadow-md'
              : 'bg-white text-slate-800 border-2 border-slate-300 hover:border-slate-500 hover:bg-slate-50'
          }`}
        >
          <span className="material-symbols-outlined text-[18px]">storefront</span>
          <span>All Wholesale Pools</span>
        </button>

        {/* Dynamic Category Chips */}
        {categories.map((cat) => {
          const isSelected = selectedCategorySlug === cat.slug;
          const icon = CATEGORY_ICONS[cat.slug] || cat.icon || 'category';
          return (
            <button
              key={cat.id}
              onClick={() => onSelectCategory(cat.slug)}
              className={`flex items-center gap-2.5 px-5 py-2.5 rounded-full text-xs font-bold whitespace-nowrap transition-all duration-200 shadow-sm ${
                isSelected
                  ? 'bg-accent text-white border-2 border-accent shadow-md scale-105'
                  : 'bg-white text-slate-800 border-2 border-slate-300 hover:border-slate-500 hover:bg-slate-50'
              }`}
            >
              <span className={`material-symbols-outlined text-[18px] ${isSelected ? 'text-white' : 'text-slate-600'}`}>
                {icon}
              </span>
              <span>{cat.name}</span>
            </button>
          );
        })}
      </div>
    </div>
  );
}
