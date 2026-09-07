// Faithful TypeScript port of the PHP PoolLifecycle (system/library/pool_lifecycle.php).
// The pool state machine.

export const PoolStatus = {
  DRAFT: 'draft',
  ACTIVE: 'active',
  REACHED: 'reached',
  CLOSED: 'closed',
  EXPIRED: 'expired',
  FULFILLED: 'fulfilled',
  CANCELLED: 'cancelled',
} as const;

export type PoolStatusValue = (typeof PoolStatus)[keyof typeof PoolStatus];

const TRANSITIONS: Record<string, string[]> = {
  [PoolStatus.DRAFT]: [PoolStatus.ACTIVE, PoolStatus.CANCELLED],
  [PoolStatus.ACTIVE]: [PoolStatus.REACHED, PoolStatus.EXPIRED, PoolStatus.CANCELLED],
  [PoolStatus.REACHED]: [PoolStatus.CLOSED, PoolStatus.CANCELLED],
  [PoolStatus.CLOSED]: [PoolStatus.FULFILLED],
  [PoolStatus.EXPIRED]: [],
  [PoolStatus.FULFILLED]: [],
  [PoolStatus.CANCELLED]: [],
};

export function statuses(): string[] {
  return Object.keys(TRANSITIONS);
}

export function isStatus(status: string): boolean {
  return Object.prototype.hasOwnProperty.call(TRANSITIONS, status);
}

export function allowedTransitions(from: string): string[] {
  return TRANSITIONS[from] ?? [];
}

export function canTransition(from: string, to: string): boolean {
  if (!isStatus(from) || !isStatus(to)) return false;
  if (from === to) return true;
  return TRANSITIONS[from].includes(to);
}

export function isTerminal(status: string): boolean {
  return isStatus(status) && TRANSITIONS[status].length === 0;
}

/** Only an active pool accepts new commitments. */
export function isOpenForReservations(status: string): boolean {
  return status === PoolStatus.ACTIVE;
}

/** Statuses where reservations remain binding. */
export function countsTowardsLivePool(status: string): boolean {
  return [PoolStatus.ACTIVE, PoolStatus.REACHED, PoolStatus.CLOSED].includes(status as PoolStatusValue);
}
