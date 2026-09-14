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
	 * A pool trades in ONE currency and its prices are authoritative in that
	 * currency. This map exists to render those figures correctly - the symbol to
	 * show and how many minor digits the currency actually has - and for nothing
	 * else. There is deliberately no exchange rate here.
	 *
	 * Most currencies use two decimal places; JPY has none, so formatting it with
	 * two would invent precision that does not exist.
	 *
	 * @var array<string, array{symbol: string, decimals: int}>
	 */
	public const CURRENCIES = [
		'INR' => ['symbol' => "\u{20B9}", 'decimals' => 2],
		'USD' => ['symbol' => '$', 'decimals' => 2],
		'EUR' => ['symbol' => "\u{20AC}", 'decimals' => 2],
		'GBP' => ['symbol' => "\u{00A3}", 'decimals' => 2],
		'AED' => ['symbol' => "\u{062F}.\u{0625}\u{200F} ", 'decimals' => 2],
		'JPY' => ['symbol' => "\u{00A5}", 'decimals' => 0]
	];

	/**
	 * Currency Meta
	 *
	 * The symbol and decimal places for a currency code. An unknown code falls
	 * back to showing the code itself rather than guessing a symbol, so a
	 * misconfigured pool reads as "XYZ 1,500.00" instead of silently borrowing
	 * some other currency's sign.
	 *
	 * @param string $code   ISO 4217 code
	 * @param string $symbol optional override, used when a store has configured its own
	 *
	 * @return array{symbol: string, decimals: int, code: string}
	 */
	public static function currencyMeta(string $code, string $symbol = ''): array {
		$code = strtoupper(trim($code));

		if (isset(self::CURRENCIES[$code])) {
			$meta = self::CURRENCIES[$code];

			return [
				'symbol'   => $symbol !== '' ? $symbol : $meta['symbol'],
				'decimals' => $meta['decimals'],
				'code'     => $code
			];
		}

		return [
			'symbol'   => $symbol !== '' ? $symbol : ($code !== '' ? $code . ' ' : ''),
			'decimals' => 2,
			'code'     => $code
		];
	}

	/**
	 * Format Money
	 *
	 * Thousands separated, prefixed with the currency symbol, using that
	 * currency's own number of decimal places.
	 *
	 * Deliberately independent of OpenCart's currency conversion: a pool price is
	 * authoritative in the pool's own currency and must not be silently rescaled.
	 * Multi-currency here means each pool is DISPLAYED in its own currency, never
	 * converted into another one behind the buyer's back.
	 *
	 * @param float  $value
	 * @param string $symbol
	 * @param int    $decimals
	 *
	 * @return string
	 */
	public static function money(float $value, string $symbol = '', int $decimals = 2): string {
		return $symbol . number_format($value, max(0, $decimals));
	}

	/**
	 * Money In
	 *
	 * Formats a value in a named currency, resolving the symbol and decimals from
	 * the currency map. This is the form to prefer when a pool's own currency is
	 * known.
	 *
	 * @param float  $value
	 * @param string $code
	 * @param string $symbol optional override
	 *
	 * @return string
	 */
	public static function moneyIn(float $value, string $code, string $symbol = ''): string {
		$meta = self::currencyMeta($code, $symbol);

		return self::money($value, $meta['symbol'], $meta['decimals']);
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
		$now = (int)($options['now'] ?? time());

		// Each pool is rendered in ITS OWN currency. The configured symbol is only a
		// fallback for a pool that has no currency of its own, so two pools trading
		// in different currencies can sit side by side on the marketplace without
		// either being relabelled as the other.
		$currency_code = strtoupper(trim((string)($pool['currency_code'] ?? '')));
		$configured_symbol = (string)($options['symbol'] ?? '');

		// An explicit per-pool currency wins; only fall back to the store-wide
		// symbol when the pool does not name one.
		$meta = $currency_code !== ''
			? self::currencyMeta($currency_code)
			: self::currencyMeta('', $configured_symbol);

		$symbol = $meta['symbol'];
		$decimals = $meta['decimals'];

		$money = static fn (float $value): string => self::money($value, $symbol, $decimals);

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

		// Freight is a pool-level charge. Dividing it by the units committed so far
		// gives each buyer's current indicative share per unit; it is only final
		// once the pool closes and the real allocation is written.
		$freight = round(max(0.0, (float)($pool['freight_total'] ?? 0)), PoolCalculator::SCALE);
		$freight_per_unit = ($freight > 0 && $reserved > 0) ? round($freight / $reserved, PoolCalculator::SCALE) : null;

		return [
			'pool_id'    => (int)($pool['pool_id'] ?? 0),
			'product_id' => (int)($pool['product_id'] ?? 0),
			'reference'  => (string)($pool['reference'] ?? ''),
			'title'      => (string)($pool['title'] ?? '') !== '' ? (string)$pool['title'] : (string)($pool['product_name'] ?? ''),
			'unit_label' => (string)($pool['unit_label'] ?? 'Units'),
			'status'     => $status,

			// The currency this pool trades in, resolved once here so every caller
			// formats its figures the same way and nothing is converted.
			'currency_code'     => $meta['code'],
			'currency_symbol'   => $symbol,
			'currency_decimals' => $decimals,

			'moq_target'   => $moq,
			'reserved_qty' => $reserved,
			'fill'         => $fill,
			'remaining'    => $remaining,

			'unit_price'        => $effective,
			'unit_price_text'   => $money($effective),
			'entry_price'       => $entry_price,
			'entry_price_text'  => $money($entry_price),
			'best_price'        => $best_price,
			'best_price_text'   => $money($best_price),
			'retail_price'      => $retail,
			'retail_price_text' => $money($retail),
			'has_retail_saving' => $retail > $effective && $effective > 0,
			'saving_per_unit'   => $retail > $effective ? round($retail - $effective, PoolCalculator::SCALE) : 0.0,

			'next_tier_price'      => $next_tier['price'] ?? null,
			'next_tier_price_text' => $next_tier ? $money((float)$next_tier['price']) : '',
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
			'shipping_terms' => (string)($pool['shipping_terms'] ?? ''),

			// Freight is quoted for the pool as a whole and divided by units at
			// close. Until then the per-unit figure is an ESTIMATE against the
			// quantity committed so far, and it falls as the pool fills. It is
			// deliberately null rather than zero when no freight is charged, so
			// templates can distinguish "no freight" from "freight of nothing".
			'freight_total'         => $freight,
			'freight_total_text'    => $freight > 0 ? $money($freight) : '',
			'has_freight'           => $freight > 0,
			'freight_per_unit'      => $freight_per_unit,
			'freight_per_unit_text' => $freight_per_unit !== null ? $money($freight_per_unit) : ''
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
