import React from 'react';
import Link from 'next/link';

export default function Footer() {
  return (
    <footer className="bg-[#091224] text-slate-400 text-sm border-t border-slate-800">
      {/* Safety Compliance Banner */}
      <div className="bg-[#050B17] py-4 px-4 sm:px-8 border-b border-slate-800/80">
        <div className="max-w-7xl mx-auto flex flex-col md:flex-row items-center justify-between gap-3 text-xs">
          <div className="flex items-center gap-2 text-slate-300">
            <span className="material-symbols-outlined text-amber-400 text-[18px]">verified_user</span>
            <span>
              <strong className="text-white">Warehouse Safety Standard:</strong> Prohibiting thermal-sensitive, lithium-pouch, or warehouse-perishable electronics. Only climate-resilient, commercial hardware permitted.
            </span>
          </div>
          <div className="flex items-center gap-4 text-slate-400">
            <span>2% Platform Fee</span>
            <span>•</span>
            <span>18% GST Compliant Invoicing</span>
            <span>•</span>
            <span>Atomic Escrow Locking</span>
          </div>
        </div>
      </div>

      <div className="max-w-7xl mx-auto px-4 sm:px-8 py-12 grid grid-cols-1 md:grid-cols-4 gap-8">
        <div>
          <div className="flex items-center gap-2 mb-4">
            <div className="h-8 w-8 rounded-lg bg-accent text-white font-black flex items-center justify-center text-sm shadow-sm">
              PB
            </div>
            <span className="text-lg font-bold text-white tracking-tight font-display">Pool<span className="text-accent">Buy</span> Wholesale</span>
          </div>
          <p className="text-xs text-slate-400 leading-relaxed mb-4">
            The decentralized wholesale co-buying protocol connecting verified apparel mills, industrial manufacturers, and commercial distributors with bulk retail buyers.
          </p>
          <div className="text-xs text-slate-500">
            Enterprise Architecture &bull; Supabase PostgreSQL &bull; Vercel Edge Runtime
          </div>
        </div>

        <div>
          <h4 className="text-white font-semibold text-xs tracking-wider uppercase mb-3">Wholesale Categories</h4>
          <ul className="space-y-2 text-xs">
            <li>
              <Link href="/marketplace?category=apparel-fashion" className="hover:text-amber-400 transition">
                Apparel &amp; Custom Blanks
              </Link>
            </li>
            <li>
              <Link href="/marketplace?category=durable-electronics" className="hover:text-amber-400 transition">
                Durable GaN Electronics &amp; Cables
              </Link>
            </li>
            <li>
              <Link href="/marketplace?category=packaging-supply" className="hover:text-amber-400 transition">
                Corrugated Boxes &amp; Packaging
              </Link>
            </li>
            <li>
              <Link href="/marketplace?category=tools-safety" className="hover:text-amber-400 transition">
                Industrial Safety &amp; Tools
              </Link>
            </li>
          </ul>
        </div>

        <div>
          <h4 className="text-white font-semibold text-xs tracking-wider uppercase mb-3">Platform Protocols</h4>
          <ul className="space-y-2 text-xs">
            <li><span className="text-slate-300">Tier Repricing Ladder:</span> Automatic retroactive discounts upon MOQ unlocking</li>
            <li><span className="text-slate-300">Escrow Custody:</span> Buyer capital locked safely until factory quality clearance</li>
            <li><span className="text-slate-300">Verified Factory Direct:</span> Stringent GSTIN &amp; physical plant audit</li>
          </ul>
        </div>

        <div>
          <h4 className="text-white font-semibold text-xs tracking-wider uppercase mb-3">Governance Portals</h4>
          <div className="space-y-2">
            <Link href="/admin" className="block p-2.5 rounded-lg bg-slate-800/80 hover:bg-slate-800 text-xs border border-slate-700/80 text-slate-300 hover:text-white transition">
              <div className="font-semibold text-white flex items-center justify-between">
                <span>Super Admin Center</span>
                <span className="material-symbols-outlined text-[16px]">arrow_forward</span>
              </div>
              <span className="text-[11px] text-slate-400">KYC, Pool state machine, GST ledgers</span>
            </Link>
            <Link href="/seller/pools" className="block p-2.5 rounded-lg bg-slate-800/80 hover:bg-slate-800 text-xs border border-slate-700/80 text-slate-300 hover:text-white transition">
              <div className="font-semibold text-white flex items-center justify-between">
                <span>Seller Management Portal</span>
                <span className="material-symbols-outlined text-[16px]">arrow_forward</span>
              </div>
              <span className="text-[11px] text-slate-400">List wholesale pools &amp; tier ladders</span>
            </Link>
          </div>
        </div>
      </div>

      <div className="border-t border-slate-800/80 py-6 text-center text-xs text-slate-500">
        &copy; {new Date().getFullYear()} PoolBuy Wholesale Marketplace Protocol. All rights reserved. Built with Supabase &amp; Next.js.
      </div>
    </footer>
  );
}
