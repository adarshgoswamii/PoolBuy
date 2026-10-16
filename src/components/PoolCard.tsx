'use client';

import React from 'react';
import Link from 'next/link';
import { Pool } from '@/lib/supabase/types';

interface PoolCardProps {
  pool: Pool;
}

export default function PoolCard({ pool }: PoolCardProps) {
  const percentFilled = Math.min(100, Math.round((pool.reserved_qty / pool.moq) * 100));
  const isReached = pool.status === 'reached' || pool.reserved_qty >= pool.moq;

  // Calculate lowest unlocked tier price if any
  const nextTier = pool.tiers?.find((t) => t.min_qty > pool.reserved_qty);
  const bestTierPrice = pool.tiers && pool.tiers.length > 0 ? Math.min(...pool.tiers.map((t) => t.unit_price)) : pool.current_tier_price;

  return (
    <div className="group rounded-2xl bg-white border border-slate-200/90 shadow-card hover:shadow-elevated transition-all duration-300 flex flex-col overflow-hidden">
      {/* Product Image Header with Badges */}
      <div className="relative aspect-[4/3] w-full bg-slate-100 overflow-hidden">
        {pool.product?.images?.[0] ? (
          <img
            src={pool.product.images[0]}
            alt={pool.product.title}
            className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500"
          />
        ) : (
          <div className="w-full h-full flex items-center justify-center bg-slate-200 text-slate-400">
            <span className="material-symbols-outlined text-4xl">image</span>
          </div>
        )}

        {/* Status Pill */}
        <div className="absolute top-3 left-3 flex items-center gap-1.5">
          {isReached ? (
            <span className="px-2.5 py-1 rounded-full text-xs font-bold bg-emerald-600 text-white shadow-md flex items-center gap-1">
              <span className="material-symbols-outlined text-[14px]">check_circle</span>
              MOQ Reached &bull; Locked
            </span>
          ) : (
            <span className="px-3 py-1 rounded-full text-xs font-semibold bg-[#0F1D36]/90 text-white backdrop-blur-md shadow-md border border-white/10 flex items-center gap-1.5">
              <span className="w-2 h-2 rounded-full bg-accent animate-pulse"></span>
              {percentFilled}% Filled
            </span>
          )}
        </div>

        {/* Pool Code */}
        <div className="absolute top-3 right-3 px-2 py-0.5 rounded bg-black/60 text-white font-mono text-[11px] backdrop-blur-sm">
          {pool.pool_code}
        </div>

        {/* Dynamic Category Badge */}
        <div className="absolute bottom-3 left-3 px-2.5 py-0.5 rounded-md bg-white/95 text-slate-800 text-[11px] font-semibold shadow-sm backdrop-blur-sm">
          {pool.product?.category_id === 'cat-1' ? 'Apparel Blanks' : 'Safe Electronics'}
        </div>
      </div>

      {/* Card Body */}
      <div className="p-5 flex-1 flex flex-col justify-between">
        <div>
          {/* Seller Trust Badge */}
          <div className="flex items-center gap-1.5 text-xs text-slate-500 mb-1.5">
            <span className="material-symbols-outlined text-emerald-600 text-[15px]">verified</span>
            <span className="font-medium text-slate-700">{pool.seller?.company_name || 'Verified Manufacturer'}</span>
          </div>

          {/* Title */}
          <Link href={`/product/${pool.product_id}`} className="block">
            <h3 className="font-bold text-base text-slate-900 group-hover:text-accent transition leading-snug line-clamp-2 mb-2">
              {pool.product?.title || 'Wholesale Group Pool Item'}
            </h3>
          </Link>

          {/* Key Attributes Tags */}
          {pool.product?.attributes && (
            <div className="flex flex-wrap gap-1.5 mb-4">
              {Object.entries(pool.product.attributes).slice(0, 3).map(([key, value]) => (
                <span
                  key={key}
                  className="px-2 py-0.5 rounded bg-slate-100 text-slate-600 text-[11px] font-medium border border-slate-200"
                >
                  <strong className="capitalize text-slate-800">{key}:</strong> {Array.isArray(value) ? value.join(', ') : String(value)}
                </span>
              ))}
            </div>
          )}
        </div>

        {/* Live Pool Progress Bar */}
        <div className="mt-2 pt-3 border-t border-slate-100">
          <div className="flex items-center justify-between text-xs text-slate-600 mb-1.5 font-medium">
            <span>
              Reserved: <strong className="text-slate-900">{pool.reserved_qty}</strong> / {pool.moq} MOQ
            </span>
            <span className="text-slate-500 font-mono text-[11px]">
              Min {pool.min_per_buyer} pcs
            </span>
          </div>

          <div className="w-full h-2.5 rounded-full bg-slate-100 border border-slate-200/80 overflow-hidden">
            <div
              className={`h-full rounded-full pool-progress-pulse transition-all duration-700 ${
                isReached ? 'bg-gradient-to-r from-emerald-500 to-teal-600' : 'bg-gradient-to-r from-accent to-rose-600'
              }`}
              style={{ width: `${percentFilled}%` }}
            ></div>
          </div>
        </div>

        {/* Pricing Ladder & Call to Action */}
        <div className="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between">
          <div>
            <div className="text-[11px] text-slate-500 uppercase tracking-wider font-semibold">Current Unit Price</div>
            <div className="flex items-baseline gap-1.5">
              <span className="text-xl font-extrabold text-slate-900">₹{pool.current_tier_price}</span>
              {pool.product?.base_price && pool.product.base_price > pool.current_tier_price && (
                <span className="text-xs text-slate-400 line-through">₹{pool.product.base_price}</span>
              )}
            </div>
            {nextTier && (
              <div className="text-[10px] text-emerald-700 font-medium">
                Drops to ₹{nextTier.unit_price} at {nextTier.min_qty} pcs
              </div>
            )}
          </div>

          <Link
            href={`/join/${pool.id}`}
            className="px-5 py-2.5 rounded-full bg-accent hover:bg-accent-dark text-white font-bold text-xs transition shadow-sm flex items-center gap-1.5 hover:shadow-md hover:scale-105"
          >
            <span>Join Pool</span>
            <span className="material-symbols-outlined text-[16px]">arrow_forward</span>
          </Link>
        </div>
      </div>
    </div>
  );
}
