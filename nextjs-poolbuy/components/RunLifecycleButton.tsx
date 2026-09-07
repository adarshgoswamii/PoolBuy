'use client';

import { useState } from 'react';
import { useRouter } from 'next/navigation';
import { IconRefresh } from '@/components/icons';

interface LifecycleSummary {
  now: string;
  activated: string[];
  reached: string[];
  expired: string[];
  closed: string[];
  repriced: { reference: string; changes: number; total_delta: number }[];
}

export function RunLifecycleButton() {
  const router = useRouter();
  const [running, setRunning] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [summary, setSummary] = useState<LifecycleSummary | null>(null);

  async function run() {
    setRunning(true);
    setError(null);
    setSummary(null);
    try {
      const res = await fetch('/api/lifecycle/run', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({}),
      });
      const body = await res.json().catch(() => null);
      if (!res.ok) {
        setError((body && body.error) || `Lifecycle run failed (${res.status}).`);
        return;
      }
      setSummary((body && body.summary) as LifecycleSummary);
      // Refresh server components so the tables reflect new statuses.
      router.refresh();
    } catch (e) {
      setError(e instanceof Error ? e.message : String(e));
    } finally {
      setRunning(false);
    }
  }

  return (
    <div className="space-y-3">
      <button className="btn-primary" onClick={run} disabled={running}>
        <IconRefresh className={`h-4 w-4 ${running ? 'animate-spin' : ''}`} />
        {running ? 'Running…' : 'Run Lifecycle Now'}
      </button>

      {error ? <p className="rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700">{error}</p> : null}

      {summary ? (
        <div className="rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm">
          <p className="text-xs font-medium uppercase tracking-wide text-slate-500">
            Sweep at {new Date(summary.now).toLocaleString('en-IN')}
          </p>
          <ul className="mt-2 grid grid-cols-2 gap-x-6 gap-y-1 text-slate-700 sm:grid-cols-4">
            <SummaryLine label="Activated" value={summary.activated.length} />
            <SummaryLine label="Reached" value={summary.reached.length} />
            <SummaryLine label="Expired" value={summary.expired.length} />
            <SummaryLine label="Closed" value={summary.closed.length} />
          </ul>
          {summary.repriced.length > 0 ? (
            <div className="mt-3">
              <p className="text-xs font-medium text-slate-500">Repriced pools</p>
              <ul className="mt-1 space-y-0.5">
                {summary.repriced.map((r) => (
                  <li key={r.reference} className="text-slate-700">
                    {r.reference}: {r.changes} reservation(s), Δ ₹
                    {Number(r.total_delta).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                  </li>
                ))}
              </ul>
            </div>
          ) : null}
          {summary.activated.length + summary.reached.length + summary.expired.length + summary.closed.length === 0 ? (
            <p className="mt-2 text-slate-500">No transitions — everything is up to date.</p>
          ) : null}
        </div>
      ) : null}
    </div>
  );
}

function SummaryLine({ label, value }: { label: string; value: number }) {
  return (
    <li className="flex items-center justify-between gap-2">
      <span className="text-slate-500">{label}</span>
      <span className="font-semibold text-slate-800">{value}</span>
    </li>
  );
}
