import Link from 'next/link';
import { statusBadgeClass } from '@/lib/ui';

export function ProgressBar({ percent }: { percent: number }) {
  const clamped = Math.max(0, Math.min(100, percent));
  return (
    <div className="progress-track" role="progressbar" aria-valuenow={clamped} aria-valuemin={0} aria-valuemax={100}>
      <div className="progress-bar" style={{ width: `${clamped}%` }} />
    </div>
  );
}

export function StatusBadge({ status }: { status: string }) {
  return <span className={`badge ${statusBadgeClass(status)}`}>{status}</span>;
}

export function DbNotice({ message }: { message?: string }) {
  return (
    <div className="card border-amber-200 bg-amber-50 p-6" role="status">
      <div className="flex items-start gap-3">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#b45309" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" aria-hidden>
          <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
          <line x1="12" y1="9" x2="12" y2="13" />
          <line x1="12" y1="17" x2="12.01" y2="17" />
        </svg>
        <div>
          <h3 className="text-sm font-semibold text-amber-800">Database not configured</h3>
          <p className="mt-1 text-sm text-amber-700">
            {message ??
              'Set the DATABASE_URL environment variable (a Neon / Vercel Postgres connection string) to load live data. The application is running, but data-backed views are unavailable.'}
          </p>
        </div>
      </div>
    </div>
  );
}

export function ErrorNotice({ message }: { message?: string }) {
  return (
    <div className="card border-rose-200 bg-rose-50 p-6" role="alert">
      <h3 className="text-sm font-semibold text-rose-800">Something went wrong</h3>
      <p className="mt-1 text-sm text-rose-700">{message ?? 'Unable to load data. Please try again.'}</p>
    </div>
  );
}

export function EmptyState({ title, hint }: { title: string; hint?: string }) {
  return (
    <div className="card flex flex-col items-center justify-center gap-2 p-12 text-center">
      <p className="text-base font-medium text-slate-700">{title}</p>
      {hint ? <p className="text-sm text-slate-500">{hint}</p> : null}
    </div>
  );
}

export function StatCard({ label, value, accent }: { label: string; value: string | number; accent?: boolean }) {
  return (
    <div className="card p-5">
      <p className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</p>
      <p className={`mt-1 text-2xl font-bold ${accent ? 'text-brand-600' : 'text-slate-800'}`}>{value}</p>
    </div>
  );
}

export function BackLink({ href, label }: { href: string; label: string }) {
  return (
    <Link href={href} className="inline-flex items-center gap-1 text-sm font-medium text-brand-600 hover:text-brand-700">
      <span aria-hidden>←</span> {label}
    </Link>
  );
}
