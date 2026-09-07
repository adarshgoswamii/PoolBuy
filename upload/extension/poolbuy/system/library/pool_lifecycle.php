<?php
namespace Opencart\System\Library\Extension\Poolbuy;
/**
 * Class PoolLifecycle
 *
 * The pool state machine, kept separate from pricing so the rules about which
 * status may follow which are stated once and unit tested directly.
 *
 * draft     -> active | cancelled
 * active    -> reached | expired | cancelled
 * reached   -> closed | cancelled
 * closed    -> fulfilled
 * expired   -> terminal
 * fulfilled -> terminal
 * cancelled -> terminal
 *
 * @package Opencart\System\Library\Extension\Poolbuy
 */
class PoolLifecycle {
	public const DRAFT = 'draft';
	public const ACTIVE = 'active';
	public const REACHED = 'reached';
	public const CLOSED = 'closed';
	public const EXPIRED = 'expired';
	public const FULFILLED = 'fulfilled';
	public const CANCELLED = 'cancelled';

	/**
	 * Allowed forward transitions. Staying on the same status is always allowed
	 * and is handled in canTransition() rather than repeated here.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const TRANSITIONS = [
		self::DRAFT     => [self::ACTIVE, self::CANCELLED],
		self::ACTIVE    => [self::REACHED, self::EXPIRED, self::CANCELLED],
		self::REACHED   => [self::CLOSED, self::CANCELLED],
		self::CLOSED    => [self::FULFILLED],
		self::EXPIRED   => [],
		self::FULFILLED => [],
		self::CANCELLED => []
	];

	/**
	 * Statuses
	 *
	 * @return array<int, string> every valid status
	 */
	public static function statuses(): array {
		return array_keys(self::TRANSITIONS);
	}

	/**
	 * Is Status
	 *
	 * @param string $status
	 *
	 * @return bool
	 */
	public static function isStatus(string $status): bool {
		return isset(self::TRANSITIONS[$status]);
	}

	/**
	 * Allowed Transitions
	 *
	 * @param string $from
	 *
	 * @return array<int, string> statuses reachable from $from, excluding itself
	 */
	public static function allowedTransitions(string $from): array {
		return self::TRANSITIONS[$from] ?? [];
	}

	/**
	 * Can Transition
	 *
	 * @param string $from
	 * @param string $to
	 *
	 * @return bool
	 */
	public static function canTransition(string $from, string $to): bool {
		if (!self::isStatus($from) || !self::isStatus($to)) {
			return false;
		}

		if ($from === $to) {
			return true;
		}

		return in_array($to, self::TRANSITIONS[$from], true);
	}

	/**
	 * Is Terminal
	 *
	 * A pool in a terminal status will never change again, so schedulers can skip
	 * it entirely.
	 *
	 * @param string $status
	 *
	 * @return bool
	 */
	public static function isTerminal(string $status): bool {
		return self::isStatus($status) && self::TRANSITIONS[$status] === [];
	}

	/**
	 * Is Open For Reservations
	 *
	 * Only an active pool accepts new commitments. A pool that has already hit its
	 * MOQ keeps its participants but is no longer joinable, which prevents late
	 * joiners from inflating a deal that is being prepared for fulfilment.
	 *
	 * @param string $status
	 *
	 * @return bool
	 */
	public static function isOpenForReservations(string $status): bool {
		return $status === self::ACTIVE;
	}

	/**
	 * Counts Towards Live Pool
	 *
	 * Statuses in which the pool is still a live commitment, i.e. reservations
	 * remain binding on buyers.
	 *
	 * @param string $status
	 *
	 * @return bool
	 */
	public static function countsTowardsLivePool(string $status): bool {
		return in_array($status, [self::ACTIVE, self::REACHED, self::CLOSED], true);
	}
}
