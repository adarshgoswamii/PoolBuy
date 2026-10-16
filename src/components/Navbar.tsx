'use client';

import React from 'react';
import Link from 'next/link';
import { useAuth } from '@/context/AuthContext';
import { useMarketplace } from '@/context/MarketplaceContext';

export default function Navbar() {
  const { user, role, switchRole } = useAuth();
  const { commitments } = useMarketplace();

  return (
    <header className="sticky top-0 z-50 w-full shadow-md">
      {/* Top Protocol Status Notice */}
      <div className="bg-[#091224] text-slate-300 text-xs py-1.5 px-4 sm:px-8 border-b border-white/10 flex items-center justify-between">
        <div className="flex items-center gap-3">
          <span className="inline-flex items-center gap-1.5 text-amber-400 font-medium">
            <span className="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
            Protocol Active: Tiered MOQ Aggregation
          </span>
          <span className="hidden md:inline text-slate-500">|</span>
          <span className="hidden md:inline text-slate-400">
            Escrow Custody &amp; Repricing Ladder Enabled
          </span>
        </div>

        {/* 1-Click Interactive Role Switcher for Testing RBAC */}
        <div className="flex items-center gap-2">
          <span className="text-slate-400 hidden sm:inline">Active Role:</span>
          <div className="inline-flex rounded-lg bg-slate-800/80 p-0.5 border border-slate-700 text-[11px]">
            <button
              onClick={() => switchRole('buyer')}
              className={`px-2.5 py-0.5 rounded-md transition font-medium ${
                role === 'buyer' ? 'bg-accent text-white shadow-sm' : 'text-slate-400 hover:text-white'
              }`}
            >
              Buyer
            </button>
            <button
              onClick={() => switchRole('seller')}
              className={`px-2.5 py-0.5 rounded-md transition font-medium ${
                role === 'seller' ? 'bg-amber-600 text-white shadow-sm' : 'text-slate-400 hover:text-white'
              }`}
            >
              Seller
            </button>
            <button
              onClick={() => switchRole('admin')}
              className={`px-2.5 py-0.5 rounded-md transition font-medium ${
                role === 'admin' ? 'bg-emerald-600 text-white shadow-sm' : 'text-slate-400 hover:text-white'
              }`}
            >
              Super Admin
            </button>
          </div>
        </div>
      </div>

      {/* Main Luxury Executive Navbar */}
      <nav className="bg-white text-slate-900 px-4 sm:px-8 py-3.5 flex items-center justify-between border-b border-[#E2E6ED] shadow-[0_1px_4px_rgba(15,29,54,0.04)]">
        <div className="flex items-center gap-8">
          <Link href="/" className="flex items-center gap-3 group">
            <div className="h-10 w-10 rounded-xl bg-[#0F1D36] flex items-center justify-center font-black text-white text-base shadow-sm group-hover:scale-105 transition">
              PB
            </div>
            <div>
              <span className="text-xl font-bold tracking-tight text-[#0F172A] flex items-center gap-1.5 font-display">
                Pool<span className="text-accent">Buy</span> <span className="text-[10px] px-2 py-0.5 rounded-full bg-accent-light text-accent border border-accent/20 font-sans font-semibold">B2B Wholesale</span>
              </span>
              <p className="text-[10px] text-slate-400 tracking-wider uppercase font-semibold">Tiered Co-Buying Protocol</p>
            </div>
          </Link>

          {/* Primary Navigation Links */}
          <div className="hidden lg:flex items-center gap-7 text-sm font-medium text-slate-600">
            <Link href="/marketplace" className="hover:text-accent transition flex items-center gap-1.5">
              <span className="material-symbols-outlined text-[18px]">storefront</span>
              Marketplace
            </Link>
            <Link href="/protocol" className="hover:text-accent transition flex items-center gap-1.5">
              <span className="material-symbols-outlined text-[18px]">gavel</span>
              Protocol
            </Link>
            <Link href="/marketplace?category=apparel-fashion" className="hover:text-accent transition">
              Apparel Blanks
            </Link>
            <Link href="/marketplace?category=durable-electronics" className="hover:text-accent transition flex items-center gap-1.5">
              <span>Durable Electronics</span>
              <span className="text-[10px] bg-emerald-50 text-emerald-700 border border-emerald-200 px-1.5 py-0.2 rounded font-semibold">Safe</span>
            </Link>
          </div>
        </div>

        {/* Right Action Icons & Role Links */}
        <div className="flex items-center gap-3.5">
          {role === 'admin' && (
            <Link
              href="/admin"
              className="px-3.5 py-1.5 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200 text-xs font-semibold hover:bg-emerald-600 hover:text-white transition flex items-center gap-1.5"
            >
              <span className="material-symbols-outlined text-[16px]">admin_panel_settings</span>
              Super Admin
            </Link>
          )}

          {role === 'seller' && (
            <Link
              href="/seller/pools"
              className="px-3.5 py-1.5 rounded-full bg-amber-50 text-amber-800 border border-amber-200 text-xs font-semibold hover:bg-amber-600 hover:text-white transition flex items-center gap-1.5"
            >
              <span className="material-symbols-outlined text-[16px]">factory</span>
              Seller Management
            </Link>
          )}

          <Link
            href="/buyer/dashboard"
            className="px-3.5 py-2 rounded-full bg-slate-50 text-slate-800 border border-slate-200 text-xs font-semibold hover:bg-slate-100 hover:border-slate-300 transition flex items-center gap-2 relative shadow-sm"
          >
            <span className="material-symbols-outlined text-[16px] text-slate-600">shopping_bag</span>
            <span className="hidden sm:inline">My Commitments</span>
            {commitments.length > 0 && (
              <span className="w-5 h-5 rounded-full bg-accent text-white text-[10px] font-bold flex items-center justify-center">
                {commitments.length}
              </span>
            )}
          </Link>

          {/* User Profile Pill */}
          <div className="flex items-center gap-2.5 pl-2 border-l border-slate-200">
            <div className="w-8 h-8 rounded-full bg-[#0F1D36] text-amber-300 font-bold flex items-center justify-center text-xs shadow-sm">
              {user?.contact_name?.charAt(0) || 'U'}
            </div>
            <div className="hidden xl:block text-left text-xs">
              <div className="font-semibold text-slate-900 leading-tight">{user?.contact_name || 'Guest'}</div>
              <div className="text-[10px] text-slate-500 capitalize">{role} Account</div>
            </div>
          </div>
        </div>
      </nav>
    </header>
  );
}
