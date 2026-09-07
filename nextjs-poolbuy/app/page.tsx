import Link from 'next/link';
import { IconArrowRight, IconLayers, IconTrendingDown, IconUsers, IconShield } from '@/components/icons';

export default function HomePage() {
  return (
    <div className="space-y-16">
      {/* Hero */}
      <section className="grid items-center gap-10 lg:grid-cols-2">
        <div>
          <span className="badge bg-brand-100 text-brand-700">B2B Group Buying · INR ₹</span>
          <h1 className="mt-4 text-4xl font-bold tracking-tight text-slate-900 sm:text-5xl">
            Pool demand. <span className="text-brand-600">Unlock wholesale prices.</span>
          </h1>
          <p className="mt-4 max-w-xl text-lg text-slate-600">
            PoolBuy lets businesses combine orders into a single demand pool. As the pool fills, everyone unlocks
            better volume tiers — and when the pool closes, pricing is applied retroactively so every buyer pays the
            best unlocked rate.
          </p>
          <div className="mt-8 flex flex-wrap gap-3">
            <Link href="/marketplace" className="btn-primary">
              Browse the marketplace <IconArrowRight className="h-4 w-4" />
            </Link>
            <Link href="/seller" className="btn-secondary">
              I&apos;m a seller
            </Link>
          </div>
        </div>

        <div className="card p-6">
          <div className="flex items-center justify-between">
            <p className="text-sm font-semibold text-slate-700">How a pool fills</p>
            <span className="badge bg-green-100 text-green-700">active</span>
          </div>
          <div className="mt-4 progress-track">
            <div className="progress-bar" style={{ width: '72%' }} />
          </div>
          <p className="mt-2 text-xs text-slate-500">720 / 1,000 units reserved · 72%</p>
          <div className="mt-6 space-y-2">
            {[
              { qty: '1–199', price: '₹120.00', state: 'unlocked' },
              { qty: '200–699', price: '₹104.00', state: 'unlocked' },
              { qty: '700+', price: '₹92.00', state: 'active' },
            ].map((t) => (
              <div
                key={t.qty}
                className={`flex items-center justify-between rounded-lg border px-3 py-2 text-sm ${
                  t.state === 'active' ? 'border-brand-300 bg-brand-50' : 'border-slate-200 bg-white'
                }`}
              >
                <span className="text-slate-600">{t.qty} units</span>
                <span className="font-semibold text-slate-800">{t.price}</span>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* Value props */}
      <section className="grid gap-6 sm:grid-cols-3">
        {[
          {
            Icon: IconUsers,
            title: 'Pool your demand',
            body: 'Join an open pool and reserve the quantity you need. Tiers resolve on the pool total, not per buyer.',
          },
          {
            Icon: IconTrendingDown,
            title: 'Retroactive best price',
            body: 'When the pool closes at its target, every live reservation is repriced to the best unlocked tier.',
          },
          {
            Icon: IconShield,
            title: 'Transparent pricing',
            body: 'Every quote breaks out subtotal, 18% GST and a 2% platform fee. Shipping is quoted untaxed.',
          },
        ].map(({ Icon, title, body }) => (
          <div key={title} className="card p-6">
            <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-brand-50 text-brand-600">
              <Icon className="h-5 w-5" />
            </div>
            <h3 className="mt-4 text-base font-semibold text-slate-800">{title}</h3>
            <p className="mt-1 text-sm text-slate-600">{body}</p>
          </div>
        ))}
      </section>

      {/* CTA */}
      <section className="card flex flex-col items-center gap-4 bg-brand-600 p-10 text-center text-white">
        <IconLayers className="h-8 w-8" />
        <h2 className="text-2xl font-bold">Ready to buy smarter?</h2>
        <p className="max-w-lg text-brand-100">
          Explore active pools across categories and reserve your quantity in a few clicks.
        </p>
        <Link href="/marketplace" className="btn bg-white text-brand-700 hover:bg-brand-50">
          Explore pools <IconArrowRight className="h-4 w-4" />
        </Link>
      </section>
    </div>
  );
}
