import { describe, it, expect } from 'vitest';
import {
  PoolStatus,
  statuses,
  isStatus,
  allowedTransitions,
  canTransition,
  isTerminal,
  isOpenForReservations,
  countsTowardsLivePool,
} from '../lib/domain/poolLifecycle';

describe('PoolLifecycle', () => {
  it('lists all statuses', () => {
    expect(statuses()).toEqual(['draft', 'active', 'reached', 'closed', 'expired', 'fulfilled', 'cancelled']);
  });

  it('validates status names', () => {
    expect(isStatus('active')).toBe(true);
    expect(isStatus('nonsense')).toBe(false);
  });

  it('allows documented forward transitions', () => {
    expect(allowedTransitions(PoolStatus.DRAFT)).toEqual(['active', 'cancelled']);
    expect(allowedTransitions(PoolStatus.ACTIVE)).toEqual(['reached', 'expired', 'cancelled']);
    expect(allowedTransitions(PoolStatus.REACHED)).toEqual(['closed', 'cancelled']);
    expect(allowedTransitions(PoolStatus.CLOSED)).toEqual(['fulfilled']);
  });

  it('canTransition respects the machine', () => {
    expect(canTransition('draft', 'active')).toBe(true);
    expect(canTransition('active', 'reached')).toBe(true);
    expect(canTransition('reached', 'closed')).toBe(true);
    expect(canTransition('closed', 'fulfilled')).toBe(true);
    // illegal
    expect(canTransition('draft', 'reached')).toBe(false);
    expect(canTransition('active', 'fulfilled')).toBe(false);
    expect(canTransition('expired', 'active')).toBe(false);
  });

  it('same status is always allowed', () => {
    expect(canTransition('active', 'active')).toBe(true);
    expect(canTransition('fulfilled', 'fulfilled')).toBe(true);
  });

  it('identifies terminal states', () => {
    expect(isTerminal('expired')).toBe(true);
    expect(isTerminal('fulfilled')).toBe(true);
    expect(isTerminal('cancelled')).toBe(true);
    expect(isTerminal('active')).toBe(false);
  });

  it('only active pools are open for reservations', () => {
    expect(isOpenForReservations('active')).toBe(true);
    expect(isOpenForReservations('reached')).toBe(false);
    expect(isOpenForReservations('draft')).toBe(false);
  });

  it('counts live-pool statuses', () => {
    expect(countsTowardsLivePool('active')).toBe(true);
    expect(countsTowardsLivePool('reached')).toBe(true);
    expect(countsTowardsLivePool('closed')).toBe(true);
    expect(countsTowardsLivePool('expired')).toBe(false);
    expect(countsTowardsLivePool('cancelled')).toBe(false);
  });
});
