// Faithful TypeScript port of the PHP PoolCalculator (system/library/pool_calculator.php).
// Pure functions, no I/O. Monetary values rounded to SCALE=4 decimals to match decimal(15,4).

export const SCALE = 4;

export interface RawTier {
  tier_id?: number | string;
  min_qty?: number | string;
  max_qty?: number | string | null;
  price?: number | string;
}

export interface Tier {
  tier_id: number;
  min_qty: number;
  max_qty: number | null; // null => open-ended top tier
  price: number;
}

export interface DecoratedTier extends Tier {
  unlocked: boolean;
  active: boolean;
  units_needed: number;
}

export interface PriceBreakdown {
  quantity: number;
  unit_price: number;
  subtotal: number;
  gst: number;
  platform_fee: number;
  shipping: number;
  total: number;
}

export interface ReservationRow {
  reservation_id?: number;
  quantity?: number;
  unit_price_locked?: number | string;
}

export interface RepriceChange {
  reservation_id: number;
  quantity: number;
  old_unit_price: number;
  new_unit_price: number;
  delta: number;
}

/** Round to SCALE decimals, matching PHP round() half-away-from-zero at 4 dp. */
function round4(value: number): number {
  const f = 10 ** SCALE;
  return Math.round((value + Number.EPSILON) * f) / f;
}

/** Coerce + sort tiers by ascending min_qty; empty/0/null max_qty => null (open-ended). */
export function normaliseTiers(tiers: RawTier[]): Tier[] {
  const normalised: Tier[] = tiers.map((t) => {
    const rawMax = t.max_qty ?? null;
    const maxNum = rawMax === null || rawMax === '' ? 0 : Number(rawMax);
    return {
      tier_id: Number(t.tier_id ?? 0),
      min_qty: Number(t.min_qty ?? 0),
      max_qty: rawMax === null || rawMax === '' || maxNum === 0 ? null : maxNum,
      price: round4(Number(t.price ?? 0)),
    };
  });
  normalised.sort((a, b) => a.min_qty - b.min_qty);
  return normalised;
}

/** Returns human-readable problems; empty array means valid. */
export function validateTiers(tiers: RawTier[]): string[] {
  const errors: string[] = [];
  if (!tiers || tiers.length === 0) {
    return ['A pool needs at least one price tier.'];
  }
  const ladder = normaliseTiers(tiers);
  const count = ladder.length;

  ladder.forEach((tier, index) => {
    const position = index + 1;
    if (tier.min_qty < 1) errors.push(`Tier ${position}: minimum quantity must be at least 1.`);
    if (tier.price <= 0) errors.push(`Tier ${position}: price must be greater than zero.`);
    if (tier.max_qty !== null && tier.max_qty < tier.min_qty) {
      errors.push(`Tier ${position}: maximum quantity cannot be less than the minimum quantity.`);
    }
    if (tier.max_qty === null && position !== count) {
      errors.push(`Tier ${position}: only the last tier may be open ended.`);
    }
    if (index > 0) {
      const previous = ladder[index - 1];
      if (previous.max_qty === null) return; // reported above
      if (tier.min_qty <= previous.max_qty) {
        errors.push(`Tier ${position} overlaps tier ${position - 1}.`);
      } else if (tier.min_qty > previous.max_qty + 1) {
        errors.push(`There is a gap in quantities between tier ${position - 1} and tier ${position}.`);
      }
    }
  });

  if (ladder[count - 1].max_qty !== null) {
    errors.push('The highest tier must be open ended so that any quantity can be priced.');
  }
  return errors;
}

/** Strict containment: tier whose [min,max] holds quantity, else null. */
export function resolveTier(tiers: RawTier[], quantity: number): Tier | null {
  for (const tier of normaliseTiers(tiers)) {
    const aboveFloor = quantity >= tier.min_qty;
    const belowCeiling = tier.max_qty === null || quantity <= tier.max_qty;
    if (aboveFloor && belowCeiling) return tier;
  }
  return null;
}

/** Highest min_qty <= quantity; never falls off the top. Null if below first tier. */
export function bestUnlockedTier(tiers: RawTier[], quantity: number): Tier | null {
  let best: Tier | null = null;
  for (const tier of normaliseTiers(tiers)) {
    if (quantity >= tier.min_qty) best = tier;
  }
  return best;
}

export function poolUnitPrice(tiers: RawTier[], reservedQty: number): number | null {
  const tier = bestUnlockedTier(tiers, reservedQty);
  return tier === null ? null : tier.price;
}

/** Quote FORWARD: price if additionalQty added to a pool holding reservedQty. */
export function quoteUnitPrice(tiers: RawTier[], reservedQty: number, additionalQty: number): number | null {
  return poolUnitPrice(tiers, reservedQty + additionalQty);
}

export function nextTier(tiers: RawTier[], reservedQty: number): Tier | null {
  for (const tier of normaliseTiers(tiers)) {
    if (tier.min_qty > reservedQty) return tier;
  }
  return null;
}

export function unitsToNextTier(tiers: RawTier[], reservedQty: number): number | null {
  const next = nextTier(tiers, reservedQty);
  return next === null ? null : next.min_qty - reservedQty;
}

export function fillPercentage(reservedQty: number, moqTarget: number): number {
  if (moqTarget <= 0) return 0;
  if (reservedQty <= 0) return 0;
  return Math.round(Math.min(100, (reservedQty / moqTarget) * 100) * 100) / 100;
}

export function isMoqReached(reservedQty: number, moqTarget: number): boolean {
  if (moqTarget <= 0) return false;
  return reservedQty >= moqTarget;
}

export function unitsRemaining(reservedQty: number, moqTarget: number): number {
  return Math.max(0, moqTarget - reservedQty);
}

/** Constrain requested qty to per-buyer min/max and units available. 0 => pool full. */
export function clampQuantity(
  requested: number,
  minPerBuyer: number,
  maxPerBuyer: number,
  unitsAvailable: number,
): number {
  if (unitsAvailable <= 0) return 0;
  let ceiling = unitsAvailable;
  if (maxPerBuyer > 0) ceiling = Math.min(ceiling, maxPerBuyer);
  const floor = Math.max(1, minPerBuyer);
  if (ceiling < floor) return 0;
  return Math.max(floor, Math.min(requested, ceiling));
}

/** GST + platform fee both on subtotal; shipping added afterwards, untaxed. */
export function priceBreakdown(
  quantity: number,
  unitPrice: number,
  gstRate: number,
  platformFeeRate: number,
  shippingCost = 0,
): PriceBreakdown {
  const q = Math.max(0, Math.trunc(quantity));
  const up = round4(Math.max(0, unitPrice));
  const subtotal = round4(q * up);
  const gst = round4(subtotal * (Math.max(0, gstRate) / 100));
  const platform_fee = round4(subtotal * (Math.max(0, platformFeeRate) / 100));
  const shipping = round4(Math.max(0, shippingCost));
  return {
    quantity: q,
    unit_price: up,
    subtotal,
    gst,
    platform_fee,
    shipping,
    total: round4(subtotal + gst + platform_fee + shipping),
  };
}

/** Retroactive repricing at close: everyone moves to the best unlocked tier. */
export function repriceReservations(
  reservations: ReservationRow[],
  tiers: RawTier[],
  finalReservedQty: number,
  retroPricing = true,
): RepriceChange[] {
  if (!retroPricing) return [];
  const price = poolUnitPrice(tiers, finalReservedQty);
  if (price === null) return [];

  const changes: RepriceChange[] = [];
  for (const r of reservations) {
    const old = round4(Number(r.unit_price_locked ?? 0));
    if (old === price) continue;
    const quantity = Number(r.quantity ?? 0);
    changes.push({
      reservation_id: Number(r.reservation_id ?? 0),
      quantity,
      old_unit_price: old,
      new_unit_price: price,
      delta: round4((old - price) * quantity),
    });
  }
  return changes;
}

export function savingsAgainst(quantity: number, poolUnit: number, compareUnit: number): number {
  const saving = (compareUnit - poolUnit) * Math.max(0, quantity);
  return round4(Math.max(0, saving));
}

export function tierLadder(tiers: RawTier[], reservedQty: number): DecoratedTier[] {
  const ladder = normaliseTiers(tiers);
  const active = bestUnlockedTier(tiers, reservedQty);
  return ladder.map((tier) => {
    const unlocked = reservedQty >= tier.min_qty;
    return {
      ...tier,
      unlocked,
      active: active !== null && active.min_qty === tier.min_qty,
      units_needed: unlocked ? 0 : tier.min_qty - reservedQty,
    };
  });
}
