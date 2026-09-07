'use client';

import { useMemo, useState } from 'react';
import Link from 'next/link';
import { IconArrowRight, IconCheck, IconTag } from '@/components/icons';

const GST_RATE = 18;
const PLATFORM_FEE_RATE = 2;

function formatINR(v: number | null | undefined, decimals = 2): string {
  if (v === null || v === undefined || Number.isNaN(Number(v))) return '—';
  return `₹${Number(v).toLocaleString('en-IN', { minimumFractionDigits: decimals, maximumFractionDigits: decimals })}`;
}

export interface JoinPoolInfo {
  reference: string;
  title: string;
  product: string;
  unit_price: number | null;
  min_qty_per_buyer: number;
  max_qty_per_buyer: number;
  units_remaining: number;
  currency_code: string;
}

interface PriceBreakdown {
  quantity: number;
  unit_price: number;
  subtotal: number;
  gst: number;
  platform_fee: number;
  shipping: number;
  total: number;
}

interface JoinResponse {
  reservation: {
    reservation_id: number;
    pool_reference: string;
    requested_quantity: number;
    granted_quantity: number;
    clamped: boolean;
    unit_price_locked: number;
    status: string;
  };
  pool: { reference: string; reserved_qty: number; moq_target: number; units_remaining: number };
  price_breakdown: PriceBreakdown;
  rates: { gst_rate: number; platform_fee_rate: number };
}

type Step = 1 | 2 | 3;

export function JoinForm({ pool }: { pool: JoinPoolInfo }) {
  const [step, setStep] = useState<Step>(1);
  const [customerId, setCustomerId] = useState<string>('1');
  const [quantity, setQuantity] = useState<string>(String(Math.max(1, pool.min_qty_per_buyer)));
  const [shipping, setShipping] = useState<string>('0');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [result, setResult] = useState<JoinResponse | null>(null);

  const qtyNum = Math.max(0, Math.trunc(Number(quantity) || 0));
  const shipNum = Math.max(0, Number(shipping) || 0);
  const unit = pool.unit_price ?? 0;

  // Client-side preview only. Server is authoritative on submit.
  const preview: PriceBreakdown = useMemo(() => {
    const subtotal = round4(qtyNum * unit);
    const gst = round4(subtotal * (GST_RATE / 100));
    const fee = round4(subtotal * (PLATFORM_FEE_RATE / 100));
    const ship = round4(shipNum);
    return {
      quantity: qtyNum,
      unit_price: round4(unit),
      subtotal,
      gst,
      platform_fee: fee,
      shipping: ship,
      total: round4(subtotal + gst + fee + ship),
    };
  }, [qtyNum, unit, shipNum]);

  const cidValid = Number.isFinite(Number(customerId)) && Number(customerId) > 0;
  const qtyValid = qtyNum >= Math.max(1, pool.min_qty_per_buyer);

  async function submit() {
    setSubmitting(true);
    setError(null);
    try {
      const res = await fetch(`/api/pools/${encodeURIComponent(pool.reference)}/join`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ customer_id: Number(customerId), quantity: qtyNum, shipping_cost: shipNum }),
      });
      const body = await res.json().catch(() => null);
      if (!res.ok) {
        setError((body && body.error) || `Reservation failed (${res.status}).`);
        setSubmitting(false);
        return;
      }
      setResult(body as JoinResponse);
      setStep(3);
    } catch (e) {
      setError(e instanceof Error ? e.message : String(e));
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="card p-6">
      <Stepper step={step} />

      {step === 1 ? (
        <div className="mt-6 space-y-5">
          <div>
            <label className="block text-sm font-medium text-slate-700" htmlFor="customer_id">
              Buyer account ID
            </label>
            <input
              id="customer_id"
              type="number"
              min={1}
              value={customerId}
              onChange={(e) => setCustomerId(e.target.value)}
              className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-400 focus:outline-none focus:ring-2 focus:ring-brand-200"
            />
            <p className="mt-1 text-xs text-slate-500">Your customer account identifier.</p>
          </div>

          <div>
            <label className="block text-sm font-medium text-slate-700" htmlFor="quantity">
              Quantity to reserve
            </label>
            <input
              id="quantity"
              type="number"
              min={Math.max(1, pool.min_qty_per_buyer)}
              value={quantity}
              onChange={(e) => setQuantity(e.target.value)}
              className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-400 focus:outline-none focus:ring-2 focus:ring-brand-200"
            />
            <p className="mt-1 text-xs text-slate-500">
              Min {pool.min_qty_per_buyer} per buyer
              {pool.max_qty_per_buyer > 0 ? ` · max ${pool.max_qty_per_buyer} per buyer` : ' · no per-buyer max'}. The
              server may clamp your quantity to the allowed range.
            </p>
          </div>

          <div>
            <label className="block text-sm font-medium text-slate-700" htmlFor="shipping">
              Estimated shipping cost (untaxed)
            </label>
            <input
              id="shipping"
              type="number"
              min={0}
              step="0.01"
              value={shipping}
              onChange={(e) => setShipping(e.target.value)}
              className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-400 focus:outline-none focus:ring-2 focus:ring-brand-200"
            />
          </div>

          <div className="flex justify-end">
            <button
              className="btn-primary"
              disabled={!cidValid || !qtyValid}
              onClick={() => setStep(2)}
            >
              Review price <IconArrowRight className="h-4 w-4" />
            </button>
          </div>
        </div>
      ) : null}

      {step === 2 ? (
        <div className="mt-6 space-y-5">
          <div className="rounded-lg bg-slate-50 p-4">
            <p className="flex items-center gap-1 text-xs font-medium uppercase tracking-wide text-slate-500">
              <IconTag className="h-3.5 w-3.5" /> Estimated price breakdown
            </p>
            <p className="mt-1 text-xs text-slate-500">
              Preview only — the server recomputes the authoritative price on the pool total when you confirm.
            </p>
            <dl className="mt-3 space-y-2 text-sm">
              <Row label={`Unit price × ${preview.quantity}`} value={formatINR(preview.unit_price, 4)} />
              <Row label="Subtotal" value={formatINR(preview.subtotal)} strong />
              <Row label={`GST (${GST_RATE}%)`} value={formatINR(preview.gst)} />
              <Row label={`Platform fee (${PLATFORM_FEE_RATE}%)`} value={formatINR(preview.platform_fee)} />
              <Row label="Shipping (untaxed)" value={formatINR(preview.shipping)} />
              <div className="border-t border-slate-200 pt-2">
                <Row label="Total" value={formatINR(preview.total)} strong big />
              </div>
            </dl>
          </div>

          {error ? <p className="rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700">{error}</p> : null}

          <div className="flex justify-between">
            <button className="btn-secondary" onClick={() => setStep(1)} disabled={submitting}>
              Back
            </button>
            <button className="btn-primary" onClick={submit} disabled={submitting}>
              {submitting ? 'Reserving…' : 'Confirm reservation'} <IconCheck className="h-4 w-4" />
            </button>
          </div>
        </div>
      ) : null}

      {step === 3 && result ? (
        <div className="mt-6 space-y-5">
          <div className="flex flex-col items-center gap-2 text-center">
            <span className="flex h-12 w-12 items-center justify-center rounded-full bg-green-100 text-green-600">
              <IconCheck className="h-6 w-6" />
            </span>
            <h3 className="text-lg font-semibold text-slate-800">Reservation confirmed</h3>
            <p className="text-sm text-slate-500">
              Reservation #{result.reservation.reservation_id} · status {result.reservation.status}
            </p>
          </div>

          {result.reservation.clamped ? (
            <p className="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-700">
              Your requested {result.reservation.requested_quantity} units were adjusted to{' '}
              {result.reservation.granted_quantity} to fit the pool&apos;s per-buyer limits.
            </p>
          ) : null}

          <dl className="space-y-2 rounded-lg bg-slate-50 p-4 text-sm">
            <Row label="Granted quantity" value={String(result.reservation.granted_quantity)} />
            <Row label="Locked unit price" value={formatINR(result.reservation.unit_price_locked, 4)} />
            <Row label={`GST (${result.rates.gst_rate}%)`} value={formatINR(result.price_breakdown.gst)} />
            <Row
              label={`Platform fee (${result.rates.platform_fee_rate}%)`}
              value={formatINR(result.price_breakdown.platform_fee)}
            />
            <Row label="Shipping" value={formatINR(result.price_breakdown.shipping)} />
            <div className="border-t border-slate-200 pt-2">
              <Row label="Total" value={formatINR(result.price_breakdown.total)} strong big />
            </div>
          </dl>

          <p className="text-center text-xs text-slate-500">
            Pool now at {result.pool.reserved_qty} / {result.pool.moq_target} units.
          </p>

          <div className="flex justify-center gap-3">
            <Link href={`/pool/${encodeURIComponent(pool.reference)}`} className="btn-secondary">
              Back to pool
            </Link>
            <Link href="/account" className="btn-primary">
              View my pools <IconArrowRight className="h-4 w-4" />
            </Link>
          </div>
        </div>
      ) : null}
    </div>
  );
}

function round4(v: number): number {
  return Math.round((v + Number.EPSILON) * 1e4) / 1e4;
}

function Stepper({ step }: { step: Step }) {
  const labels = ['Quantity', 'Review', 'Confirm'];
  return (
    <div className="flex items-center gap-2">
      {labels.map((label, i) => {
        const n = (i + 1) as Step;
        const active = step === n;
        const done = step > n;
        return (
          <div key={label} className="flex flex-1 items-center gap-2">
            <div
              className={`flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-semibold ${
                done
                  ? 'bg-green-500 text-white'
                  : active
                    ? 'bg-brand-600 text-white'
                    : 'bg-slate-200 text-slate-500'
              }`}
            >
              {done ? '✓' : n}
            </div>
            <span className={`text-sm font-medium ${active ? 'text-slate-800' : 'text-slate-400'}`}>{label}</span>
            {i < labels.length - 1 ? <div className="mx-1 h-px flex-1 bg-slate-200" /> : null}
          </div>
        );
      })}
    </div>
  );
}

function Row({ label, value, strong, big }: { label: string; value: string; strong?: boolean; big?: boolean }) {
  return (
    <div className="flex items-center justify-between">
      <dt className={strong ? 'font-medium text-slate-700' : 'text-slate-500'}>{label}</dt>
      <dd className={`${strong ? 'font-semibold text-slate-800' : 'text-slate-700'} ${big ? 'text-lg' : ''}`}>
        {value}
      </dd>
    </div>
  );
}
