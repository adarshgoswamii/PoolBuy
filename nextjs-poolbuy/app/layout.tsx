import type { Metadata } from 'next';
import Link from 'next/link';
import './globals.css';
import { IconLayers, IconStore, IconShield, IconUsers } from '@/components/icons';

export const metadata: Metadata = {
  title: 'PoolBuy — B2B Group Buying Marketplace',
  description:
    'PoolBuy is a B2B pool-buying marketplace. Join demand pools, unlock volume tiers, and buy at wholesale prices. Priced in INR (₹).',
};

const navItems = [
  { href: '/marketplace', label: 'Marketplace', Icon: IconLayers },
  { href: '/account', label: 'My Pools', Icon: IconUsers },
  { href: '/seller', label: 'Seller', Icon: IconStore },
  { href: '/admin', label: 'Admin', Icon: IconShield },
];

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="en">
      <body className="flex min-h-screen flex-col">
        <header className="sticky top-0 z-20 border-b border-slate-200 bg-white/90 backdrop-blur">
          <div className="mx-auto flex h-16 max-w-6xl items-center justify-between px-4">
            <Link href="/" className="flex items-center gap-2">
              <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-brand-600 text-lg font-bold text-white">
                ₹
              </span>
              <span className="text-lg font-bold tracking-tight text-slate-800">
                Pool<span className="text-brand-600">Buy</span>
              </span>
            </Link>
            <nav className="flex items-center gap-1">
              {navItems.map(({ href, label, Icon }) => (
                <Link
                  key={href}
                  href={href}
                  className="inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-100 hover:text-brand-700"
                >
                  <Icon className="h-4 w-4" />
                  <span className="hidden sm:inline">{label}</span>
                </Link>
              ))}
            </nav>
          </div>
        </header>

        <main className="mx-auto w-full max-w-6xl flex-1 px-4 py-8">{children}</main>

        <footer className="border-t border-slate-200 bg-white">
          <div className="mx-auto flex max-w-6xl flex-col items-center justify-between gap-2 px-4 py-6 text-sm text-slate-500 sm:flex-row">
            <p>
              <span className="font-semibold text-slate-700">PoolBuy</span> · B2B group buying · Prices in INR (₹),
              inclusive of 18% GST and 2% platform fee on subtotal.
            </p>
            <p className="text-slate-400">© {new Date().getFullYear()} PoolBuy</p>
          </div>
        </footer>
      </body>
    </html>
  );
}
