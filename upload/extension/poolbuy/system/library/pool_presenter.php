<?php
namespace Opencart\System\Library\Extension\Poolbuy;
/**
 * Class PoolPresenter
 *
 * Turns a raw pool row into the values a template needs. Every storefront screen
 * goes through here, so a pool's progress, price and urgency read identically on
 * the landing page, the marketplace grid, the product page and the dashboard.
 *
 * Framework free and pure, like PoolCalculator, so the presentation rules are
 * unit testable without booting OpenCart.
 *
 * @package Opencart\System\Library\Extension\Poolbuy
 */
class PoolPresenter {
	/**
	 * A pool at or above this fill ratio is treated as closing soon.
	 */
	public const URGENT_FILL = 80.0;

	/**
	 * A pool ending within this many seconds is treated as urgent.
	 */
	public const URGENT_SECONDS = 86400;

	/**
	 * Format Money
	 *
	 * Thousands separated with two decimals, prefixed with the configured symbol.
	 * Deliberately independent of OpenCart's currency conversion: a pool price is
	 * authoritative in the pool's own currency and must not be silently rescaled.
	 *
	 * @param float  $value
	 * @param string $symbol
	 *
	 * @return string
	 */
	public static function money(float $value, string $symbol = ''): string {
		return $symbol . number_format($value, 2);
	}

	/**
	 * Present
	 *
	 * @param array<string, mixed>             $pool    a row from the storefront pool model
	 * @param array<int, array<string, mixed>> $tiers   the pool's price ladder
	 * @param array<string, mixed>             $options symbol, gst_rate, platform_fee, now
	 *
	 * @return array<string, mixed>
	 */
	public static function present(array $pool, array $tiers, array $options = []): array {
		$symbol = (string)($options['symbol'] ?? '');
		$now = (int)($options['now'] ?? time());

		$moq = (int)($pool['moq_target'] ?? 0);
		$reserved = (int)($pool['reserved_qty'] ?? 0);
		$status = (string)($pool['status'] ?? '');

		$fill = PoolCalculator::fillPercentage($reserved, $moq);
		$remaining = PoolCalculator::unitsRemaining($reserved, $moq);

		$unit_price = PoolCalculator::poolUnitPrice($tiers, $reserved);
		$next_tier = PoolCalculator::nextTier($tiers, $reserved);
		$units_to_next = PoolCalculator::unitsToNextTier($tiers, $reserved);

		// The entry price is what a first buyer would pay: the lowest tier's price.
		$ladder = PoolCalculator::normaliseTiers($tiers);
		$entry_price = $ladder ? $ladder[0]['price'] : 0.0;
		$best_price = $ladder ? $ladder[count($ladder) - 1]['price'] : 0.0;

		$end = strtotime((string)($pool['date_end'] ?? '')) ?: 0;
		$seconds_left = max(0, $end - $now);

		$is_open = $status === PoolLifecycle::ACTIVE && $seconds_left > 0 && $remaining > 0;
		$is_urgent = $is_open && ($fill >= self::URGENT_FILL || $seconds_left <= self::URGENT_SECONDS);

		$retail = (float)($pool['retail_price'] ?? 0);
		$effective = $unit_price ?? $entry_price;

		return [
			'pool_id'    => (int)($pool['pool_id'] ?? 0),
			'product_id' => (int)($pool['product_id'] ?? 0),
			'reference'  => (string)($pool['reference'] ?? ''),
			'title'      => (string)($pool['title'] ?? '') !== '' ? (string)$pool['title'] : (string)($pool['product_name'] ?? ''),
			'unit_label' => (string)($pool['unit_label'] ?? 'Units'),
			'status'     => $status,

			'moq_target'   => $moq,
			'reserved_qty' => $reserved,
			'fill'         => $fill,
			'remaining'    => $remaining,

			'unit_price'        => $effective,
			'unit_price_text'   => self::money($effective, $symbol),
			'entry_price'       => $entry_price,
			'entry_price_text'  => self::money($entry_price, $symbol),
			'best_price'        => $best_price,
			'best_price_text'   => self::money($best_price, $symbol),
			'retail_price'      => $retail,
			'retail_price_text' => self::money($retail, $symbol),
			'has_retail_saving' => $retail > $effective && $effective > 0,
			'saving_per_unit'   => $retail > $effective ? round($retail - $effective, PoolCalculator::SCALE) : 0.0,

			'next_tier_price'      => $next_tier['price'] ?? null,
			'next_tier_price_text' => $next_tier ? self::money((float)$next_tier['price'], $symbol) : '',
			'units_to_next_tier'   => $units_to_next,

			'date_end'      => (string)($pool['date_end'] ?? ''),
			'end_timestamp' => $end,
			'seconds_left'  => $seconds_left,
			'time_left'     => self::humaniseSeconds($seconds_left),

			'is_open'            => $is_open,
			'is_urgent'          => $is_urgent,
			'is_complete'        => PoolCalculator::isMoqReached($reserved, $moq),
			'allow_full_moq_buy' => !empty($pool['allow_full_moq_buy']),
			'min_qty_per_buyer'  => max(1, (int)($pool['min_qty_per_buyer'] ?? 1)),
			'max_qty_per_buyer'  => max(0, (int)($pool['max_qty_per_buyer'] ?? 0)),

			'seller_id'           => (int)($pool['seller_id'] ?? 0),
			'seller_name'         => (string)($pool['seller_name'] ?? ''),
			'seller_location'     => (string)($pool['seller_location'] ?? ''),
			'seller_rating'       => round((float)($pool['seller_rating'] ?? 0), 1),
			'seller_rating_count' => (int)($pool['seller_rating_count'] ?? 0),
			'gst_verified'        => !empty($pool['gst_verified']),
			'verified_seller'     => !empty($pool['verified_seller']),

			'lead_time'      => (string)($pool['lead_time'] ?? ''),
			'shipping_terms' => (string)($pool['shipping_terms'] ?? '')
		];
	}

	/**
	 * Humanise Seconds
	 *
	 * A compact countdown such as "3d 08h" or "14h 22m". Rendered server side so
	 * the value is correct before JavaScript takes over ticking it.
	 *
	 * @param int $seconds
	 *
	 * @return string
	 */
	public static function humaniseSeconds(int $seconds): string {
		if ($seconds <= 0) {
			return '';
		}

		$days = (int)floor($seconds / 86400);
		$hours = (int)floor(($seconds % 86400) / 3600);
		$minutes = (int)floor(($seconds % 3600) / 60);

		if ($days > 0) {
			return sprintf('%dd %02dh', $days, $hours);
		}

		if ($hours > 0) {
			return sprintf('%02dh %02dm', $hours, $minutes);
		}

		return sprintf('%dm', max(1, $minutes));
	}

	/**
	 * Progress Modifier
	 *
	 * Which progress bar treatment a pool should use: complete, urgent, or the
	 * default primary fill.
	 *
	 * @param array<string, mixed> $view a presented pool
	 *
	 * @return string CSS modifier suffix, or an empty string for the default
	 */
	public static function progressModifier(array $view): string {
		if (!empty($view['is_complete'])) {
			return 'complete';
		}

		if (!empty($view['is_urgent'])) {
			return 'urgent';
		}

		return '';
	}

	/**
	 * Pool Hint
	 *
	 * The one-line nudge under a progress bar, mirroring the copy in the design:
	 * how many units unlock the next tier, or how many remain to reach the MOQ.
	 *
	 * @param array<string, mixed> $view a presented pool
	 *
	 * @return string
	 */
	public static function hint(array $view): string {
		if (!empty($view['is_complete'])) {
			return 'MOQ reached. This pool is being prepared for fulfilment.';
		}

		if (!empty($view['units_to_next_tier']) && !empty($view['next_tier_price_text'])) {
			return sprintf(
				'Only %d more %s to unlock %s per unit.',
				(int)$view['units_to_next_tier'],
				strtolower((string)$view['unit_label']),
				(string)$view['next_tier_price_text']
			);
		}

		if ((int)$view['reserved_qty'] === 0) {
			return 'New pool. Be one of the first participants.';
		}

		return sprintf('%d %s left to reach the MOQ.', (int)$view['remaining'], strtolower((string)$view['unit_label']));
	}
}
