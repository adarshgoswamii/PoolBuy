'use client';

import React, { useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useAuth } from '@/context/AuthContext';

export default function LoginPage() {
  const router = useRouter();
  const { login } = useAuth();

  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [isLoading, setIsLoading] = useState(false);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsLoading(true);
    await login(email);
    setIsLoading(false);
    router.push('/marketplace');
  };

  const handleQuickDemo = async (demoEmail: string) => {
    setIsLoading(true);
    await login(demoEmail);
    setIsLoading(false);
    if (demoEmail.includes('admin')) {
      router.push('/admin');
    } else if (demoEmail.includes('apex') || demoEmail.includes('novavolt')) {
      router.push('/seller/pools');
    } else {
      router.push('/buyer/dashboard');
    }
  };

  return (
    <div className="min-h-[80vh] flex items-center justify-center px-4 py-12">
      <div className="bg-white rounded-3xl border border-slate-200 p-8 max-w-md w-full shadow-card space-y-6">
        <div className="text-center space-y-1">
          <div className="h-12 w-12 rounded-2xl bg-[#0F1D36] text-accent border border-accent/30 font-black text-xl flex items-center justify-center mx-auto mb-3 shadow-md">
            PB
          </div>
          <h1 className="text-2xl font-black text-slate-900 tracking-tight font-display">Wholesale Portal Access</h1>
          <p className="text-xs text-slate-500">Sign in to your wholesale buyer or manufacturer account</p>
        </div>

        {/* 1-Click Demo Profiles */}
        <div className="p-3.5 rounded-2xl bg-slate-50 border border-slate-200 space-y-2">
          <div className="text-[11px] font-bold text-slate-600 uppercase tracking-wider">
            1-Click Demo Account Logins:
          </div>
          <div className="grid grid-cols-3 gap-1.5 text-xs">
            <button
              onClick={() => handleQuickDemo('procure@urbanstreet.in')}
              className="py-1.5 px-2 rounded-lg bg-accent-50 text-accent font-bold border border-accent/20 hover:bg-accent-100 transition text-[11px]"
            >
              Buyer
            </button>
            <button
              onClick={() => handleQuickDemo('textiles@apexmills.com')}
              className="py-1.5 px-2 rounded-lg bg-amber-50 text-amber-700 font-bold border border-amber-200 hover:bg-amber-100 transition text-[11px]"
            >
              Seller
            </button>
            <button
              onClick={() => handleQuickDemo('admin@poolbuy.io')}
              className="py-1.5 px-2 rounded-lg bg-emerald-50 text-emerald-700 font-bold border border-emerald-200 hover:bg-emerald-100 transition text-[11px]"
            >
              Super Admin
            </button>
          </div>
        </div>

        <form onSubmit={handleSubmit} className="space-y-4 text-xs">
          <div>
            <label className="block text-slate-600 font-semibold mb-1">Corporate Email Address</label>
            <input
              type="email"
              required
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              placeholder="e.g. procurement@company.com"
              className="w-full px-3 py-2.5 rounded-xl border border-slate-200 bg-slate-50 focus:bg-white focus:outline-none focus:ring-2 focus:ring-accent"
            />
          </div>

          <div>
            <label className="block text-slate-600 font-semibold mb-1">Account Password</label>
            <input
              type="password"
              required
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              placeholder="••••••••••••"
              className="w-full px-3 py-2.5 rounded-xl border border-slate-200 bg-slate-50 focus:bg-white focus:outline-none focus:ring-2 focus:ring-accent"
            />
          </div>

          <button
            type="submit"
            disabled={isLoading}
            className="w-full py-3.5 rounded-xl bg-accent text-white font-bold text-xs hover:bg-accent-dark transition shadow-md"
          >
            {isLoading ? 'Verifying Credentials...' : 'Sign In with Supabase Auth'}
          </button>
        </form>

        <div className="text-center text-xs text-slate-500">
          New to PoolBuy?{' '}
          <Link href="/register" className="text-accent font-bold hover:underline">
            Register Wholesale Account
          </Link>
        </div>
      </div>
    </div>
  );
}
