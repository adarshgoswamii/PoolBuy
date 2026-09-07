<?php
namespace Opencart\System\Library\Extension\Poolbuy;
/**
 * Class PoolCalculator
 *
 * Every pricing and progress rule for pool buying lives here. The class is
 * deliberately free of any OpenCart dependency - no registry, no database, no
 * superglobals - so the rules that decide what a buyer pays can be unit tested
 * directly and reasoned about in isolation.
 *
 * All methods are static and pure: same inputs, same outputs, no side effects.
 *
 * Tier semantics
 * --------------
 * A pool's price ladder is resolved against the pool's TOTAL reserved quantity,
 * not against an individual buyer's quantity. That is the whole premise of pool
 * buying: collective volume unlocks a better price for everyone, which is also
 * what makes retroactive repricing coherent.
 *
 * Money
 * -----
 * Monetary values are rounded to self::SCALE decimal places, matching the
 * decimal(15,4) columns in the schema, so figures computed here reconcile
 * exactly with what is persisted and later written onto orders.
 *
 * @package Opencart\System\Library\Extension\Poolbuy
 */
class PoolCalculator {
	/**
	 * Decimal places used for monetary rounding, matching decimal(15,4).
	 */
	public const SCALE = 4;

	/**
	 * Normalise Tiers
	 *
	 * Coerces raw tier rows (as read from the database, where everything is a
	 * string) into typed rows sorted by ascending min_qty. An empty or null
	 * `max_qty` is normalised to null, meaning "open ended top tier".
	 *
	 * @param array<int, array<string, mixed>> $tiers
	 *
	 * @return array<int, array{min_qty: int, max_qty: int|null, price: float, tier_id: int}>
	 */
	public static function normaliseTiers(array $tiers): array {
		$normalised = [];

		foreach ($tiers as $tier) {
			$max = $tier['max_qty'] ?? null;

			$normalised[] = [
				'tier_id' => (int)($tier['tier_id'] ?? 0),
				'min_qty' => (int)($tier['min_qty'] ?? 0),
				'max_qty' => ($max === null || $max === '' || (int)$max === 0) ? null : (int)$max,
				'price'   => round((float)($tier['price'] ?? 0), self::SCALE)
			];
		}

		usort($normalised, static fn (array $a, array $b): int => $a['min_qty'] <=> $b['min_qty']);

		return $normalised;
	}

	/**
	 * Validate Tiers
	 *
	 * A valid ladder is: non-empty, starts at 1 or more, strictly ascending,
	 * contiguous (no gaps), non-overlapping, positively priced, and topped by a
	 * single open-ended tier so that arbitrarily large quantities can always be
	 * priced.
	 *
	 * @param array<int, array<string, mixed>> $tiers
	 *
	 * @return array<int, string> human readable problems; empty means valid
	 */
	public static function validateTiers(array $tiers): array {
		$errors = [];

		if (!$tiers) {
			return ['A pool needs at least one price tier.'];
		}

		$ladder = self::normaliseTiers($tiers);
		$count = count($ladder);

		foreach ($ladder as $index => $tier) {
			$position = $index + 1;

			if ($tier['min_qty'] < 1) {
				$errors[] = sprintf('Tier %d: minimum quantity must be at least 1.', $position);
			}

			if ($tier['price'] <= 0) {
				$errors[] = sprintf('Tier %d: price must be greater than zero.', $position);
			}

			if ($tier['max_qty'] !== null && $tier['max_qty'] < $tier['min_qty']) {
				$errors[] = sprintf('Tier %d: maximum quantity cannot be less than the minimum quantity.', $position);
			}

			// Only the final tier may be open ended, otherwise quantities above it
			// would fall through an unpriced hole in the middle of the ladder.
			if ($tier['max_qty'] === null && $position !== $count) {
				$errors[] = sprintf('Tier %d: only the last tier may be open ended.', $position);
			}

			if ($index > 0) {
				$previous = $ladder[$index - 1];

				if ($previous['max_qty'] === null) {
					// Already reported above; skip contiguity against an open tier.
					continue;
				}

				if ($tier['min_qty'] <= $previous['max_qty']) {
					$errors[] = sprintf('Tier %d overlaps tier %d.', $position, $position - 1);
				} elseif ($tier['min_qty'] > $previous['max_qty'] + 1) {
					$errors[] = sprintf('There is a gap in quantities between tier %d and tier %d.', $position - 1, $position);
				}
			}
		}

		if ($ladder[$count - 1]['max_qty'] !== null) {
			$errors[] = 'The highest tier must be open ended so that any quantity can be priced.';
		}

		return $errors;
	}

	/**
	 * Resolve Tier
	 *
	 * Strict containment: returns the tier whose [min_qty, max_qty] range holds
	 * $quantity, or null when nothing matches. Use this when a quantity must map
	 * to an explicitly configured band.
	 *
	 * @param array<int, array<string, mixed>> $tiers
	 * @param int                              $quantity
	 *
	 * @return array{min_qty: int, max_qty: int|null, price: float, tier_id: int}|null
	 */
	public static function resolveTier(array $tiers, int $quantity): ?array {
		foreach (self::normaliseTiers($tiers) as $tier) {
			$aboveFloor = $quantity >= $tier['min_qty'];
			$belowCeiling = $tier['max_qty'] === null || $quantity <= $tier['max_qty'];

			if ($aboveFloor && $belowCeiling) {
				return $tier;
			}
		}

		return null;
	}

	/**
	 * Best Unlocked Tier
	 *
	 * The most favourable tier the given quantity has earned: the tier with the
	 * highest min_qty that is still less than or equal to $quantity. Unlike
	 * resolveTier() this never falls off the top of the ladder, which is the
	 * behaviour pools need - a pool that overshoots its largest tier keeps that
	 * tier's price rather than becoming unpriceable.
	 *
	 * Returns null only when the quantity has not reached the first tier.
	 *
	 * @param array<int, array<string, mixed>> $tiers
	 * @param int                              $quantity
	 *
	 * @return array{min_qty: int, max_qty: int|null, price: float, tier_id: int}|null
	 */
	public static function bestUnlockedTier(array $tiers, int $quantity): ?array {
		$best = null;

		foreach (self::normaliseTiers($tiers) as $tier) {
			if ($quantity >= $tier['min_qty']) {
				$best = $tier;
			}
		}

		return $best;
	}

	/**
	 * Pool Unit Price
	 *
	 * The per-unit price the pool has currently unlocked, or null if the pool has
	 * not yet reached its first tier.
	 *
	 * @param array<int, array<string, mixed>> $tiers
	 * @param int                              $reserved_qty
	 *
	 * @return float|null
	 */
	public static function poolUnitPrice(array $tiers, int $reserved_qty): ?float {
		$tier = self::bestUnlockedTier($tiers, $reserved_qty);

		return $tier === null ? null : $tier['price'];
	}

	/**
	 * Quote Unit Price
	 *
	 * The per-unit price that would apply if a buyer added $additional_qty units
	 * to a pool currently holding $reserved_qty. Quoting forward rather than
	 * backward means the figure shown in the join flow is the price the buyer
	 * actually unlocks by committing, never an optimistic one.
	 *
	 * @param array<int, array<string, mixed>> $tiers
	 * @param int                              $reserved_qty
	 * @param int                              $additional_qty
	 *
	 * @return float|null
	 */
	public static function quoteUnitPrice(array $tiers, int $reserved_qty, int $additional_qty): ?float {
		return self::poolUnitPrice($tiers, $reserved_qty + $additional_qty);
	}

	/**
	 * Next Tier
	 *
	 * The cheapest tier not yet unlocked, i.e. the next milestone the pool is
	 * working towards. Null when the pool has already unlocked the top tier.
	 *
	 * @param array<int, array<string, mixed>> $tiers
	 * @param int                              $reserved_qty
	 *
	 * @return array{min_qty: int, max_qty: int|null, price: float, tier_id: int}|null
	 */
	public static function nextTier(array $tiers, int $reserved_qty): ?array {
		foreach (self::normaliseTiers($tiers) as $tier) {
			if ($tier['min_qty'] > $reserved_qty) {
				return $tier;
			}
		}

		return null;
	}

	/**
	 * Units To Next Tier
	 *
	 * How many further units the pool needs to unlock the next price tier, or
	 * null when the top tier is already unlocked.
	 *
	 * @param array<int, array<string, mixed>> $tiers
	 * @param int                              $reserved_qty
	 *
	 * @return int|null
	 */
	public static function unitsToNextTier(array $tiers, int $reserved_qty): ?int {
		$next = self::nextTier($tiers, $reserved_qty);

		return $next === null ? null : $next['min_qty'] - $reserved_qty;
	}

	/**
	 * Fill Percentage
	 *
	 * Pool progress as a percentage, rounded to two decimals and capped at 100 so
	 * an oversubscribed pool cannot render a progress bar past its track.
	 *
	 * @param int $reserved_qty
	 * @param int $moq_target
	 *
	 * @return float
	 */
	public static function fillPercentage(int $reserved_qty, int $moq_target): float {
		if ($moq_target <= 0) {
			return 0.0;
		}

		if ($reserved_qty <= 0) {
			return 0.0;
		}

		return round(min(100.0, ($reserved_qty / $moq_target) * 100), 2);
	}

	/**
	 * Is MOQ Reached
	 *
	 * @param int $reserved_qty
	 * @param int $moq_target
	 *
	 * @return bool
	 */
	public static function isMoqReached(int $reserved_qty, int $moq_target): bool {
		if ($moq_target <= 0) {
			return false;
		}

		return $reserved_qty >= $moq_target;
	}

	/**
	 * Units Remaining
	 *
	 * Units still needed to hit the MOQ, never negative.
	 *
	 * @param int $reserved_qty
	 * @param int $moq_target
	 *
	 * @return int
	 */
	public static function unitsRemaining(int $reserved_qty, int $moq_target): int {
		return (int)max(0, $moq_target - $reserved_qty);
	}

	/**
	 * Clamp Quantity
	 *
	 * Constrains a requested quantity to what the pool will actually accept:
	 * at least the per-buyer minimum, no more than the per-buyer maximum (when
	 * set), and never more than the units still available.
	 *
	 * Returns 0 when the pool cannot accept the buyer at all, which the caller
	 * should treat as "pool full".
	 *
	 * @param int $requested
	 * @param int $min_per_buyer
	 * @param int $max_per_buyer   0 means no per-buyer ceiling
	 * @param int $units_available
	 *
	 * @return int
	 */
	public static function clampQuantity(int $requested, int $min_per_buyer, int $max_per_buyer, int $units_available): int {
		if ($units_available <= 0) {
			return 0;
		}

		$ceiling = $units_available;

		if ($max_per_buyer > 0) {
			$ceiling = min($ceiling, $max_per_buyer);
		}

		$floor = max(1, $min_per_buyer);

		// A pool with fewer units left than its own minimum cannot be joined.
		if ($ceiling < $floor) {
			return 0;
		}

		return (int)max($floor, min($requested, $ceiling));
	}

	/**
	 * Price Breakdown
	 *
	 * The figures shown in the join flow and later written onto the order.
	 * GST and the platform fee are both charged on the subtotal; shipping is
	 * added afterwards and is not itself taxed here.
	 *
	 * @param int   $quantity
	 * @param float $unit_price
	 * @param float $gst_rate          percentage, e.g. 18.0
	 * @param float $platform_fee_rate percentage, e.g. 2.0
	 * @param float $shipping_cost
	 *
	 * @return array{quantity: int, unit_price: float, subtotal: float, gst: float, platform_fee: float, shipping: float, total: float}
	 */
	public static function priceBreakdown(int $quantity, float $unit_price, float $gst_rate, float $platform_fee_rate, float $shipping_cost = 0.0): array {
		$quantity = (int)max(0, $quantity);
		$unit_price = round(max(0.0, $unit_price), self::SCALE);

		$subtotal = round($quantity * $unit_price, self::SCALE);
		$gst = round($subtotal * (max(0.0, $gst_rate) / 100), self::SCALE);
		$platform_fee = round($subtotal * (max(0.0, $platform_fee_rate) / 100), self::SCALE);
		$shipping = round(max(0.0, $shipping_cost), self::SCALE);

		return [
			'quantity'     => $quantity,
			'unit_price'   => $unit_price,
			'subtotal'     => $subtotal,
			'gst'          => $gst,
			'platform_fee' => $platform_fee,
			'shipping'     => $shipping,
			'total'        => round($subtotal + $gst + $platform_fee + $shipping, self::SCALE)
		];
	}

	/**
	 * Reprice Reservations
	 *
	 * Works out the retroactive adjustment applied when a pool closes: every
	 * participant moves to the best tier the pool's final volume unlocked.
	 *
	 * Only reservations whose price actually changes are returned, so callers can
	 * write the minimum number of rows and log a meaningful audit trail. A
	 * positive `delta` means the buyer is paying less than before.
	 *
	 * When $retro_pricing is false, or no tier is unlocked at all, nothing
	 * changes and an empty array is returned.
	 *
	 * @param array<int, array<string, mixed>> $reservations       rows with reservation_id, quantity, unit_price_locked
	 * @param array<int, array<string, mixed>> $tiers
	 * @param int                              $final_reserved_qty
	 * @param bool                             $retro_pricing
	 *
	 * @return array<int, array{reservation_id: int, quantity: int, old_unit_price: float, new_unit_price: float, delta: float}>
	 */
	public static function repriceReservations(array $reservations, array $tiers, int $final_reserved_qty, bool $retro_pricing = true): array {
		if (!$retro_pricing) {
			return [];
		}

		$price = self::poolUnitPrice($tiers, $final_reserved_qty);

		if ($price === null) {
			return [];
		}

		$changes = [];

		foreach ($reservations as $reservation) {
			$old = round((float)($reservation['unit_price_locked'] ?? 0), self::SCALE);

			if ($old === $price) {
				continue;
			}

			$quantity = (int)($reservation['quantity'] ?? 0);

			$changes[] = [
				'reservation_id' => (int)($reservation['reservation_id'] ?? 0),
				'quantity'       => $quantity,
				'old_unit_price' => $old,
				'new_unit_price' => $price,
				'delta'          => round(($old - $price) * $quantity, self::SCALE)
			];
		}

		return $changes;
	}

	/**
	 * Savings Against
	 *
	 * Total saved versus a comparison (typically retail) price. Never negative,
	 * so a pool priced above retail does not advertise a "saving".
	 *
	 * @param int   $quantity
	 * @param float $pool_unit_price
	 * @param float $compare_unit_price
	 *
	 * @return float
	 */
	public static function savingsAgainst(int $quantity, float $pool_unit_price, float $compare_unit_price): float {
		$saving = ($compare_unit_price - $pool_unit_price) * max(0, $quantity);

		return round(max(0.0, $saving), self::SCALE);
	}

	/**
	 * Tier Ladder
	 *
	 * Decorates the ladder for display: each tier is marked as already unlocked,
	 * currently active (the best unlocked tier), or still locked, which is what
	 * the product page's pricing ladder renders.
	 *
	 * @param array<int, array<string, mixed>> $tiers
	 * @param int                              $reserved_qty
	 *
	 * @return array<int, array{min_qty: int, max_qty: int|null, price: float, tier_id: int, unlocked: bool, active: bool, units_needed: int}>
	 */
	public static function tierLadder(array $tiers, int $reserved_qty): array {
		$ladder = self::normaliseTiers($tiers);
		$active = self::bestUnlockedTier($tiers, $reserved_qty);

		$decorated = [];

		foreach ($ladder as $tier) {
			$unlocked = $reserved_qty >= $tier['min_qty'];

			$decorated[] = $tier + [
				'unlocked'     => $unlocked,
				'active'       => $active !== null && $active['min_qty'] === $tier['min_qty'],
				'units_needed' => $unlocked ? 0 : $tier['min_qty'] - $reserved_qty
			];
		}

		return $decorated;
	}
}
