'use client';

import React from 'react';
import { useMarketplace } from '@/context/MarketplaceContext';

export default function AdminSellersPage() {
  const { profiles, approveSeller } = useMarketplace();

  const sellers = profiles.filter((p) => p.role === 'seller');

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-black text-slate-900 tracking-tight">Seller KYC &amp; Verification Center</h1>
        <p className="text-xs text-slate-700 font-semibold mt-1">
          Review manufacturer business registrations, GSTIN authenticity, and approve factory pool creation privileges.
        </p>
      </div>

      <div className="bg-white rounded-3xl border border-slate-200 p-6 shadow-card space-y-4">
        <div className="flex items-center justify-between pb-3 border-b border-slate-100">
          <h2 className="text-sm font-bold text-slate-900 uppercase tracking-wider font-display">
            Manufacturer Directory ({sellers.length} registered)
          </h2>
        </div>

        <div className="overflow-x-auto">
          <table className="w-full text-left text-xs">
            <thead className="bg-slate-100 border-b border-slate-300 text-slate-800 font-bold">
              <tr>
                <th className="py-3 px-4">Company Name</th>
                <th className="py-3 px-4">Contact Officer</th>
                <th className="py-3 px-4">GSTIN Number</th>
                <th className="py-3 px-4">Phone</th>
                <th className="py-3 px-4">KYC Status</th>
                <th className="py-3 px-4 text-right">Administrative Action</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {sellers.map((seller) => (
                <tr key={seller.id} className="hover:bg-slate-50/60">
                  <td className="py-3.5 px-4 font-bold text-slate-900">
                    {seller.company_name}
                  </td>
                  <td className="py-3.5 px-4 text-slate-800 font-semibold">
                    {seller.contact_name}
                    <div className="text-[11px] text-slate-600 font-medium">{seller.email}</div>
                  </td>
                  <td className="py-3.5 px-4 font-mono font-bold text-slate-900">
                    {seller.gstin}
                  </td>
                  <td className="py-3.5 px-4 text-slate-800 font-mono font-semibold">
                    {seller.phone}
                  </td>
                  <td className="py-3.5 px-4">
                    {seller.is_verified ? (
                      <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-emerald-100 text-emerald-800 font-bold text-[10px]">
                        <span className="material-symbols-outlined text-[13px]">verified</span>
                        Verified &amp; Active
                      </span>
                    ) : (
                      <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-amber-100 text-amber-800 font-bold text-[10px]">
                        <span className="material-symbols-outlined text-[13px]">pending</span>
                        Pending KYC Review
                      </span>
                    )}
                  </td>
                  <td className="py-3.5 px-4 text-right">
                    {seller.is_verified ? (
                      <span className="text-slate-700 font-bold text-[11px]">Approved</span>
                    ) : (
                      <button
                        onClick={() => approveSeller(seller.id)}
                        className="px-3 py-1.5 rounded-lg bg-emerald-600 text-white font-bold text-xs hover:bg-emerald-700 transition shadow-sm"
                      >
                        Approve Seller
                      </button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}
