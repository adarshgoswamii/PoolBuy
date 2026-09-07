import { describe, it, expect } from 'vitest';
import {
  normaliseTiers,
  validateTiers,
  resolveTier,
  bestUnlockedTier,
  poolUnitPrice,
  quoteUnitPrice,
  nextTier,
  unitsToNextTier,
  fillPercentage,
  isMoqReached,
  unitsRemaining,
  clampQuantity,
  priceBreakdown,
  repriceReservations,
  savingsAgainst,
  tierLadder,
} from '../lib/domain/poolCalculator';

// Standard ladder used across tests: 10-49 @1550, 50-149 @1380, 150+ @1250
const ladder = [
  { tier_id: 1, min_qty: 10, max_qty: 49, price: 1550 },
  { tier_id: 2, min_qty: 50, max_qty: 149, price: 1380 },
  { tier_id: 3, min_qty: 150, max_qty: 0, price: 1250 }, // 0 => open-ended
];

describe('normaliseTiers', () => {
  it('sorts by min_qty and treats 0 max as open-ended', () => {
    const n = normaliseTiers([...ladder].reverse());
    expect(n.map((t) => t.min_qty)).toEqual([10, 50, 150]);
    expect(n[2].max_qty).toBeNull();
  });
  it("treats '' and null max as open-ended", () => {
    expect(normaliseTiers([{ min_qty: 1, max_qty: '', price: 5 }])[0].max_qty).toBeNull();
    expect(normaliseTiers([{ min_qty: 1, max_qty: null, price: 5 }])[0].max_qty).toBeNull();
  });
});

describe('validateTiers', () => {
  it('accepts a valid contiguous ladder', () => {
    expect(validateTiers(ladder)).toEqual([]);
  });
  it('rejects empty', () => {
    expect(validateTiers([]).length).toBe(1);
  });
  it('rejects a gap', () => {
    const gap = [
      { min_qty: 10, max_qty: 49, price: 1550 },
      { min_qty: 60, max_qty: 0, price: 1250 },
    ];
    expect(validateTiers(gap).some((e) => e.includes('gap'))).toBe(true);
  });
  it('rejects overlap', () => {
    const overlap = [
      { min_qty: 10, max_qty: 60, price: 1550 },
      { min_qty: 50, max_qty: 0, price: 1250 },
    ];
    expect(validateTiers(overlap).some((e) => e.includes('overlaps'))).toBe(true);
  });
  it('requires open-ended top tier', () => {
    const closedTop = [{ min_qty: 10, max_qty: 49, price: 1550 }];
    expect(validateTiers(closedTop).some((e) => e.includes('open ended'))).toBe(true);
  });
  it('rejects non-final open-ended tier', () => {
    const bad = [
      { min_qty: 10, max_qty: 0, price: 1550 },
      { min_qty: 50, max_qty: 0, price: 1250 },
    ];
    expect(validateTiers(bad).some((e) => e.includes('only the last tier'))).toBe(true);
  });
  it('rejects min < 1 and price <= 0', () => {
    expect(validateTiers([{ min_qty: 0, max_qty: 0, price: 0 }]).length).toBeGreaterThanOrEqual(2);
  });
});

describe('resolveTier (strict containment)', () => {
  it('returns null below the first tier', () => {
    expect(resolveTier(ladder, 5)).toBeNull();
  });
  it('resolves within a band', () => {
    expect(resolveTier(ladder, 30)?.price).toBe(1550);
    expect(resolveTier(ladder, 100)?.price).toBe(1380);
  });
  it('resolves open-ended top', () => {
    expect(resolveTier(ladder, 10000)?.price).toBe(1250);
  });
});

describe('bestUnlockedTier / poolUnitPrice', () => {
  it('is null below first tier', () => {
    expect(bestUnlockedTier(ladder, 5)).toBeNull();
    expect(poolUnitPrice(ladder, 5)).toBeNull();
  });
  it('never falls off the top', () => {
    expect(bestUnlockedTier(ladder, 99999)?.price).toBe(1250);
  });
  it('picks the highest unlocked', () => {
    expect(poolUnitPrice(ladder, 49)).toBe(1550);
    expect(poolUnitPrice(ladder, 50)).toBe(1380);
    expect(poolUnitPrice(ladder, 150)).toBe(1250);
  });
});

describe('quoteUnitPrice (forward)', () => {
  it('quotes the price after adding units', () => {
    // reserved 45 + 10 = 55 -> tier 2 @1380
    expect(quoteUnitPrice(ladder, 45, 10)).toBe(1380);
  });
});

describe('nextTier / unitsToNextTier', () => {
  it('finds the next milestone', () => {
    expect(nextTier(ladder, 30)?.min_qty).toBe(50);
    expect(unitsToNextTier(ladder, 30)).toBe(20);
  });
  it('is null when top tier unlocked', () => {
    expect(nextTier(ladder, 200)).toBeNull();
    expect(unitsToNextTier(ladder, 200)).toBeNull();
  });
});

describe('fillPercentage', () => {
  it('caps at 100 and rounds to 2dp', () => {
    expect(fillPercentage(150, 200)).toBe(75);
    expect(fillPercentage(300, 200)).toBe(100);
    expect(fillPercentage(1, 3)).toBe(33.33);
  });
  it('is 0 for non-positive inputs', () => {
    expect(fillPercentage(0, 200)).toBe(0);
    expect(fillPercentage(50, 0)).toBe(0);
  });
});

describe('isMoqReached / unitsRemaining', () => {
  it('reached when reserved >= moq', () => {
    expect(isMoqReached(200, 200)).toBe(true);
    expect(isMoqReached(199, 200)).toBe(false);
    expect(isMoqReached(5, 0)).toBe(false);
  });
  it('remaining never negative', () => {
    expect(unitsRemaining(150, 200)).toBe(50);
    expect(unitsRemaining(250, 200)).toBe(0);
  });
});

describe('clampQuantity', () => {
  it('returns 0 when nothing available', () => {
    expect(clampQuantity(10, 1, 0, 0)).toBe(0);
  });
  it('clamps to available', () => {
    expect(clampQuantity(999, 1, 0, 88)).toBe(88);
  });
  it('respects per-buyer max', () => {
    expect(clampQuantity(999, 1, 20, 88)).toBe(20);
  });
  it('respects per-buyer min floor', () => {
    expect(clampQuantity(1, 5, 0, 88)).toBe(5);
  });
  it('returns 0 when available below min', () => {
    expect(clampQuantity(10, 10, 0, 5)).toBe(0);
  });
});

describe('priceBreakdown', () => {
  it('applies GST and fee on subtotal, shipping untaxed', () => {
    const b = priceBreakdown(150, 890, 18, 2, 0);
    expect(b.subtotal).toBe(133500);
    expect(b.gst).toBe(24030); // 18%
    expect(b.platform_fee).toBe(2670); // 2%
    expect(b.total).toBe(160200);
  });
  it('adds shipping after tax', () => {
    const b = priceBreakdown(10, 100, 10, 0, 50);
    expect(b.subtotal).toBe(1000);
    expect(b.gst).toBe(100);
    expect(b.total).toBe(1150);
  });
});

describe('repriceReservations', () => {
  const reservations = [
    { reservation_id: 1, quantity: 100, unit_price_locked: 980 },
    { reservation_id: 2, quantity: 150, unit_price_locked: 980 },
    { reservation_id: 3, quantity: 150, unit_price_locked: 890 },
  ];
  const bigLadder = [
    { min_qty: 100, max_qty: 399, price: 980 },
    { min_qty: 400, max_qty: 0, price: 890 },
  ];
  it('reprices only changed reservations to the unlocked price', () => {
    const changes = repriceReservations(reservations, bigLadder, 400, true);
    // res 1 and 2 move 980->890; res 3 already 890 (no change)
    expect(changes.length).toBe(2);
    expect(changes[0]).toMatchObject({ reservation_id: 1, new_unit_price: 890, delta: 9000 });
    expect(changes[1]).toMatchObject({ reservation_id: 2, new_unit_price: 890, delta: 13500 });
  });
  it('returns [] when retro disabled', () => {
    expect(repriceReservations(reservations, bigLadder, 400, false)).toEqual([]);
  });
  it('returns [] when no tier unlocked', () => {
    expect(repriceReservations(reservations, bigLadder, 10, true)).toEqual([]);
  });
});

describe('savingsAgainst', () => {
  it('never negative', () => {
    expect(savingsAgainst(10, 90, 100)).toBe(100);
    expect(savingsAgainst(10, 120, 100)).toBe(0);
  });
});

describe('tierLadder', () => {
  it('marks unlocked and active', () => {
    const d = tierLadder(ladder, 60);
    expect(d[0].unlocked).toBe(true);
    expect(d[1].unlocked).toBe(true);
    expect(d[1].active).toBe(true);
    expect(d[2].unlocked).toBe(false);
    expect(d[2].units_needed).toBe(90);
  });
});
