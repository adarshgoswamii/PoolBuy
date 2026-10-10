'use client';

import React from 'react';
import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { useAuth } from '@/context/AuthContext';

export default function AdminLayout({ children }: { children: React.ReactNode }) {
  const pathname = usePathname();
  const { role, switchRole } = useAuth();

  const navItems = [
    { label: 'Platform Overview', href: '/admin', icon: 'dashboard' },
    { label: 'Pool State Machine', href: '/admin/pools', icon: 'hub' },
    { label: 'Seller KYC & Verification', href: '/admin/sellers', icon: 'verified_user' },
    { label: 'Taxonomy & Dynamic Filters', href: '/admin/taxonomy', icon: 'schema' },
    { label: 'Settlement & Escrow Ledger', href: '/admin/settlements', icon: 'account_balance' },
    { label: 'Platform Settings', href: '/admin/settings', icon: 'settings' },
  ];

  return (
    <div className="min-h-screen bg-[#FAF8F5] flex flex-col">
      {/* Top Admin Security Notice */}
      <div className="bg-[#091224] text-white px-6 py-2 border-b border-white/10 flex items-center justify-between text-xs">
        <div className="flex items-center gap-2">
          <span className="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
          <span className="font-bold text-amber-300">SUPER ADMIN CONTROL CENTER</span>
          <span className="text-slate-400 hidden sm:inline">&bull; Role-Based Governance Active</span>
        </div>
        <div className="flex items-center gap-3">
          <span className="text-slate-400">Current Role: <strong className="text-emerald-400 uppercase">{role}</strong></span>
          {role !== 'admin' && (
            <button
              onClick={() => switchRole('admin')}
              className="px-2 py-0.5 rounded bg-emerald-600 text-white font-bold hover:bg-emerald-500"
            >
              Switch to Super Admin
            </button>
          )}
        </div>
      </div>

      <div className="flex-1 flex">
        {/* Admin Sidebar Navigation */}
        <aside className="w-64 bg-[#0F1D36] text-slate-300 border-r border-slate-800 p-5 hidden md:flex flex-col justify-between">
          <div className="space-y-6">
            <div className="flex items-center gap-3 px-2">
              <div className="h-9 w-9 rounded-xl bg-gradient-to-br from-amber-400 to-amber-600 text-slate-950 font-black flex items-center justify-center text-sm shadow-md">
                PB
              </div>
              <div>
                <div className="text-sm font-bold text-white leading-tight">PoolBuy Admin</div>
                <div className="text-[10px] text-amber-400 font-mono">EXECUTIVE PROTOCOL</div>
              </div>
            </div>

            <nav className="space-y-1">
              {navItems.map((item) => {
                const isActive = pathname === item.href;
                return (
                  <Link
                    key={item.href}
                    href={item.href}
                    className={`flex items-center gap-3 px-3 py-2.5 rounded-xl text-xs font-semibold transition ${
                      isActive
                        ? 'bg-blue-600 text-white shadow-md'
                        : 'text-slate-400 hover:bg-slate-800/80 hover:text-white'
                    }`}
                  >
                    <span className="material-symbols-outlined text-[18px]">{item.icon}</span>
                    <span>{item.label}</span>
                  </Link>
                );
              })}
            </nav>
          </div>

          <div className="p-3 rounded-xl bg-slate-900/60 border border-slate-800 text-xs text-slate-400">
            <div className="text-slate-300 font-bold mb-1">Escrow Custody</div>
            <div>Platform Fee: 2.0%</div>
            <div>GST Audit: 18.0%</div>
            <div className="text-[10px] text-emerald-400 mt-1">● Supabase RLS Protected</div>
          </div>
        </aside>

        {/* Content Viewport */}
        <main className="flex-1 p-6 md:p-10 overflow-y-auto">
          {children}
        </main>
      </div>
    </div>
  );
}
