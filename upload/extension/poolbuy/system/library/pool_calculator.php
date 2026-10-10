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
	 *
	 * The order of operations is deliberate and is the single place it is defined:
	 *
	 *     subtotal = quantity x unit price
	 *     discounted subtotal = subtotal - promotion discount
	 *     GST and platform fee are charged on the DISCOUNTED subtotal
	 *     freight is added last and is not itself taxed
	 *
	 * A promotion therefore reduces the tax and the platform fee as well as the
	 * goods value, which is what a buyer reasonably expects from a discount. The
	 * discount is clamped to the subtotal, so no combination of inputs can produce
	 * a negative line or a negative total.
	 *
	 * `subtotal` remains the GROSS goods value so that existing callers and the
	 * order's sub-total line are unchanged; `discounted_subtotal` is the taxable
	 * base. With the default zero discount the two are identical and every figure
	 * matches the pre-promotion behaviour exactly.
	 *
	 * @param int   $quantity
	 * @param float $unit_price
	 * @param float $gst_rate          percentage, e.g. 18.0
	 * @param float $platform_fee_rate percentage, e.g. 2.0
	 * @param float $shipping_cost
	 * @param float $discount          promotion discount against the subtotal
	 *
	 * @return array{quantity: int, unit_price: float, subtotal: float, discount: float, discounted_subtotal: float, gst: float, platform_fee: float, shipping: float, total: float}
	 */
	public static function priceBreakdown(int $quantity, float $unit_price, float $gst_rate, float $platform_fee_rate, float $shipping_cost = 0.0, float $discount = 0.0): array {
		$quantity = (int)max(0, $quantity);
		$unit_price = round(max(0.0, $unit_price), self::SCALE);

		$subtotal = round($quantity * $unit_price, self::SCALE);

		// Never more than the goods are worth, and never negative.
		$discount = round(min(max(0.0, $discount), $subtotal), self::SCALE);

		$discounted = round($subtotal - $discount, self::SCALE);

		$gst = round($discounted * (max(0.0, $gst_rate) / 100), self::SCALE);
		$platform_fee = round($discounted * (max(0.0, $platform_fee_rate) / 100), self::SCALE);
		$shipping = round(max(0.0, $shipping_cost), self::SCALE);

		return [
			'quantity'            => $quantity,
			'unit_price'          => $unit_price,
			'subtotal'            => $subtotal,
			'discount'            => $discount,
			'discounted_subtotal' => $discounted,
			'gst'                 => $gst,
			'platform_fee'        => $platform_fee,
			'shipping'            => $shipping,
			'total'               => round($discounted + $gst + $platform_fee + $shipping, self::SCALE)
		];
	}

	/**
	 * Allocate Freight
	 *
	 * Splits one pool-level freight charge across the pool's confirmed
	 * commitments, pro rata by quantity.
	 *
	 * Freight is quoted for the pool as a whole because a single consolidated
	 * shipment is what makes pooling worth doing: the cost is a property of the
	 * pool, not of any one buyer. Splitting it by quantity means each buyer's
	 * share FALLS as the pool fills, which is the same collective-volume logic
	 * that drives the tier ladder.
	 *
	 * Allocation uses the largest-remainder method, working in integer units of
	 * 10^-SCALE. Naive per-buyer rounding would let the shares drift from the
	 * quoted total by a few paise in either direction - either silently
	 * overcharging buyers or leaving the platform short. Here the shares are
	 * guaranteed to sum to EXACTLY the quoted freight total: every unit is
	 * accounted for, and the leftover units go to the buyers with the largest
	 * fractional remainders.
	 *
	 * Ties are broken by larger quantity first, then by lower reservation id, so
	 * the allocation is deterministic and a re-run produces identical figures.
	 *
	 * @param array<int, array<string, mixed>> $reservations  rows with reservation_id and quantity
	 * @param float                            $freight_total the pool's quoted freight
	 *
	 * @return array<int, float> reservation_id => freight share
	 */
	public static function allocateFreight(array $reservations, float $freight_total): array {
		$shares = [];
		$rows = [];
		$total_qty = 0;

		foreach ($reservations as $reservation) {
			$reservation_id = (int)($reservation['reservation_id'] ?? 0);

			if ($reservation_id <= 0) {
				continue;
			}

			$quantity = (int)max(0, (int)($reservation['quantity'] ?? 0));

			$shares[$reservation_id] = 0.0;
			$rows[] = ['reservation_id' => $reservation_id, 'quantity' => $quantity];
			$total_qty += $quantity;
		}

		// Nothing to charge, or nothing to charge it against. Either way every
		// share is zero rather than undefined, so callers can write the column
		// unconditionally.
		if (!$rows || $total_qty <= 0 || $freight_total <= 0) {
			return $shares;
		}

		$factor = 10 ** self::SCALE;

		// Work in whole minor units so the arithmetic is exact.
		$total_units = (int)round($freight_total * $factor);

		if ($total_units <= 0) {
			return $shares;
		}

		$assigned = 0;

		foreach ($rows as $index => $row) {
			$exact = $total_units * $row['quantity'] / $total_qty;
			$floor = (int)floor($exact);

			$rows[$index]['units'] = $floor;
			$rows[$index]['remainder'] = $exact - $floor;

			$assigned += $floor;
		}

		// Hand out the units lost to flooring, largest fractional remainder first.
		$leftover = $total_units - $assigned;

		if ($leftover > 0) {
			$order = $rows;

			usort($order, static function(array $a, array $b): int {
				// Largest fractional remainder first.
				$compare = $b['remainder'] <=> $a['remainder'];

				if ($compare !== 0) {
					return $compare;
				}

				// Then the larger commitment, which is the fairer place to put a
				// spare unit.
				$compare = $b['quantity'] <=> $a['quantity'];

				if ($compare !== 0) {
					return $compare;
				}

				// Finally the lower id, purely so the result is deterministic.
				return $a['reservation_id'] <=> $b['reservation_id'];
			});

			$positions = [];

			foreach ($rows as $index => $row) {
				$positions[$row['reservation_id']] = $index;
			}

			for ($i = 0; $i < $leftover; $i++) {
				// More leftover units than rows cannot happen (leftover < row count),
				// but wrapping keeps the loop total-preserving regardless.
				$target = $order[$i % count($order)]['reservation_id'];

				$rows[$positions[$target]]['units']++;
			}
		}

		foreach ($rows as $row) {
			$shares[$row['reservation_id']] = round($row['units'] / $factor, self::SCALE);
		}

		return $shares;
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
	 * Resolve Discount
	 *
	 * Decides whether a promotion may be applied to a commitment, and what it is
	 * worth. Every rule that could reject a code lives here, so the join flow,
	 * the pool-close path and the admin preview all agree.
	 *
	 * The reason for a rejection is returned rather than a bare false, because a
	 * buyer who typed a real code that has simply run out deserves to be told
	 * that rather than "invalid code".
	 *
	 * A percentage is clamped to 0-100 and a fixed amount to the subtotal, so no
	 * configuration - however wrong - can discount more than the goods are worth
	 * or produce a negative line.
	 *
	 * @param array<string, mixed> $promotion a poolbuy_promotion row
	 * @param int                  $quantity  units being committed
	 * @param float                $subtotal  quantity x unit price
	 * @param int|null             $now       unix time, for testability
	 *
	 * @return array{valid: bool, reason: string, discount: float}
	 */
	public static function resolveDiscount(array $promotion, int $quantity, float $subtotal, ?int $now = null): array {
		$now ??= time();
		$subtotal = round(max(0.0, $subtotal), self::SCALE);

		$reject = static fn (string $reason): array => ['valid' => false, 'reason' => $reason, 'discount' => 0.0];

		if (!$promotion) {
			return $reject('not_found');
		}

		if (empty($promotion['status'])) {
			return $reject('inactive');
		}

		// A blank or zero date means "no bound", so an open-ended promotion needs no
		// sentinel value in the database.
		$start = self::timestampOrNull($promotion['date_start'] ?? null);
		$end = self::timestampOrNull($promotion['date_end'] ?? null);

		if ($start !== null && $now < $start) {
			return $reject('not_started');
		}

		if ($end !== null && $now > $end) {
			return $reject('expired');
		}

		$min_qty = (int)($promotion['min_qty'] ?? 0);

		if ($min_qty > 0 && $quantity < $min_qty) {
			return $reject('min_qty');
		}

		// 0 means unlimited. Anything else is a hard ceiling.
		$max = (int)($promotion['max_redemptions'] ?? 0);
		$used = (int)($promotion['redemptions_used'] ?? 0);

		if ($max > 0 && $used >= $max) {
			return $reject('exhausted');
		}

		if ($subtotal <= 0) {
			return $reject('nothing_to_discount');
		}

		$type = (string)($promotion['type'] ?? '');
		$value = (float)($promotion['value'] ?? 0);

		if ($value <= 0) {
			return $reject('worthless');
		}

		if ($type === 'percent') {
			$percent = min(100.0, max(0.0, $value));
			$discount = round($subtotal * ($percent / 100), self::SCALE);
		} elseif ($type === 'fixed') {
			$discount = round(max(0.0, $value), self::SCALE);
		} else {
			return $reject('unknown_type');
		}

		// Never more than the goods are worth.
		$discount = round(min($discount, $subtotal), self::SCALE);

		if ($discount <= 0) {
			return $reject('worthless');
		}

		return ['valid' => true, 'reason' => '', 'discount' => $discount];
	}

	/**
	 * Timestamp Or Null
	 *
	 * Treats null, an empty string and MySQL's zero date as "no bound".
	 *
	 * @param mixed $value
	 *
	 * @return int|null
	 */
	private static function timestampOrNull($value): ?int {
		if ($value === null || $value === '' || $value === '0000-00-00 00:00:00' || $value === '0000-00-00') {
			return null;
		}

		$timestamp = strtotime((string)$value);

		return $timestamp === false ? null : $timestamp;
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
