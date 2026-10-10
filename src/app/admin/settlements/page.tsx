'use client';

import React from 'react';
import { useMarketplace } from '@/context/MarketplaceContext';

export default function AdminSettlementsPage() {
  const { commitments, pools } = useMarketplace();

  // Calculate platform financial totals
  const totalVolume = commitments.reduce((acc, c) => acc + c.quantity, 0);
  const totalGMV = commitments.reduce(
    (acc, c) => acc + c.quantity * (c.final_unit_price || c.locked_unit_price),
    0
  );
  const totalPlatformFees = Math.round(totalGMV * 0.02);
  const totalGST = Math.round(totalGMV * 0.18);
  const totalSettlement = totalGMV + totalPlatformFees + totalGST;

  const handleExportCSV = () => {
    const headers = ['Commitment ID', 'Pool Code', 'Quantity', 'Final Unit Price', 'Subtotal', 'Platform Fee (2%)', 'GST (18%)', 'Total Amount', 'Status'];
    const rows = commitments.map((c) => {
      const pool = pools.find((p) => p.id === c.pool_id);
      const subtotal = c.quantity * (c.final_unit_price || c.locked_unit_price);
      const fee = Math.round(subtotal * 0.02);
      const gst = Math.round(subtotal * 0.18);
      const total = subtotal + fee + gst;
      return [
        c.id,
        pool?.pool_code || c.pool_id,
        c.quantity,
        c.final_unit_price || c.locked_unit_price,
        subtotal,
        fee,
        gst,
        total,
        c.status,
      ].join(',');
    });

    const csvContent = 'data:text/csv;charset=utf-8,' + [headers.join(','), ...rows].join('\n');
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement('a');
    link.setAttribute('href', encodedUri);
    link.setAttribute('download', `poolbuy_settlements_${Date.now()}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
  };

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-black text-slate-900 tracking-tight">
            Escrow &amp; Financial Settlement Ledger
          </h1>
          <p className="text-xs text-slate-500 mt-1">
            Reconciliation of wholesale group commitment conversion, 2.0% platform facilitation commissions, and 18.0% GST tax ledgers.
          </p>
        </div>

        <button
          onClick={handleExportCSV}
          className="px-4 py-2.5 rounded-xl bg-blue-600 text-white font-bold text-xs hover:bg-blue-700 transition shadow-sm flex items-center gap-1.5 self-start"
        >
          <span className="material-symbols-outlined text-[18px]">download</span>
          <span>Export Settlement CSV</span>
        </button>
      </div>

      {/* Financial Metrics Cards */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
        <div className="bg-white rounded-2xl border border-slate-200 p-5 shadow-card">
          <div className="text-xs text-slate-500 font-medium mb-1">Gross Merchandise Value (GMV)</div>
          <div className="text-2xl font-black text-slate-900 font-mono">₹{totalGMV.toLocaleString()}</div>
          <div className="text-[11px] text-slate-400 mt-1">{totalVolume} units contracted</div>
        </div>

        <div className="bg-white rounded-2xl border border-slate-200 p-5 shadow-card">
          <div className="text-xs text-slate-500 font-medium mb-1">Platform Commission (2%)</div>
          <div className="text-2xl font-black text-emerald-600 font-mono">₹{totalPlatformFees.toLocaleString()}</div>
          <div className="text-[11px] text-emerald-700 font-semibold mt-1">Direct platform revenue</div>
        </div>

        <div className="bg-white rounded-2xl border border-slate-200 p-5 shadow-card">
          <div className="text-xs text-slate-500 font-medium mb-1">Total GST Invoiced (18%)</div>
          <div className="text-2xl font-black text-blue-600 font-mono">₹{totalGST.toLocaleString()}</div>
          <div className="text-[11px] text-slate-400 mt-1">Statutory tax liability</div>
        </div>

        <div className="bg-white rounded-2xl border border-slate-200 p-5 shadow-card">
          <div className="text-xs text-slate-500 font-medium mb-1">Total Escrow Capital Under Custody</div>
          <div className="text-2xl font-black text-amber-600 font-mono">₹{totalSettlement.toLocaleString()}</div>
          <div className="text-[11px] text-slate-400 mt-1">Guaranteed in smart custody</div>
        </div>
      </div>

      {/* Settlements Table */}
      <div className="bg-white rounded-3xl border border-slate-200 p-6 shadow-card space-y-4">
        <h2 className="text-sm font-bold text-slate-900 uppercase tracking-wider pb-3 border-b border-slate-100">
          Conversion Ledger Entries ({commitments.length})
        </h2>

        <div className="overflow-x-auto">
          <table className="w-full text-left text-xs">
            <thead className="bg-slate-50 border-b border-slate-200 text-slate-500">
              <tr>
                <th className="py-3 px-3">Reference</th>
                <th className="py-3 px-3">Pool</th>
                <th className="py-3 px-3">Volume</th>
                <th className="py-3 px-3">Unit Price</th>
                <th className="py-3 px-3">Subtotal</th>
                <th className="py-3 px-3">Fee (2%)</th>
                <th className="py-3 px-3">GST (18%)</th>
                <th className="py-3 px-3">Total Payable</th>
                <th className="py-3 px-3">Escrow Status</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100 font-mono">
              {commitments.map((c) => {
                const pool = pools.find((p) => p.id === c.pool_id);
                const unitPrice = c.final_unit_price || c.locked_unit_price;
                const subtotal = c.quantity * unitPrice;
                const fee = Math.round(subtotal * 0.02);
                const gst = Math.round(subtotal * 0.18);
                const total = subtotal + fee + gst;

                return (
                  <tr key={c.id} className="hover:bg-slate-50/60">
                    <td className="py-3 px-3 text-slate-700 font-bold">{c.id}</td>
                    <td className="py-3 px-3 text-blue-600">{pool?.pool_code || c.pool_id}</td>
                    <td className="py-3 px-3 text-slate-900">{c.quantity} pcs</td>
                    <td className="py-3 px-3 text-slate-900">₹{unitPrice}</td>
                    <td className="py-3 px-3 text-slate-900">₹{subtotal.toLocaleString()}</td>
                    <td className="py-3 px-3 text-emerald-600 font-bold">₹{fee.toLocaleString()}</td>
                    <td className="py-3 px-3 text-slate-500">₹{gst.toLocaleString()}</td>
                    <td className="py-3 px-3 text-slate-900 font-black">₹{total.toLocaleString()}</td>
                    <td className="py-3 px-3 font-sans">
                      <span className="px-2 py-0.5 rounded text-[10px] font-bold uppercase bg-emerald-100 text-emerald-800">
                        Escrow Hold
                      </span>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}
