<?php
namespace Opencart\System\Library\Extension\Poolbuy;

/**
 * Class Rate Limiter
 *
 * Fixed-window request accounting for the extension's machine-facing and
 * write endpoints.
 *
 * Framework free on purpose: this class holds only the arithmetic and the key
 * naming, so the decisions can be unit tested without a database, a request or an
 * OpenCart registry. Storage lives in the rate_limit model.
 *
 * A fixed window is used rather than a sliding log because it needs one counter
 * row per window instead of one row per request, which keeps the table small
 * enough to prune trivially. The trade-off is the usual one: a caller can send up
 * to 2x the limit across a window boundary. That is acceptable here, because the
 * point is to blunt scripted abuse of the cron, webhook and write routes, not to
 * meter a paid API.
 *
 * @package Opencart\System\Library\Extension\Poolbuy
 */
class RateLimiter {
	/**
	 * Used when the setting is absent or blank.
	 *
	 * A blank configured limit must never mean "unlimited", for the same reason a
	 * blank cron token must never mean "no authentication required". Only an
	 * explicit 0 disables the limiter.
	 */
	public const DEFAULT_LIMIT = 120;
	public const DEFAULT_WINDOW = 60;

	/**
	 * Window Start
	 *
	 * Floors a timestamp to the start of its window, which is what makes two
	 * requests in the same window share a counter.
	 *
	 * @param int $now
	 * @param int $window seconds
	 *
	 * @return int
	 */
	public static function windowStart(int $now, int $window): int {
		if ($window < 1) {
			$window = self::DEFAULT_WINDOW;
		}

		return (int)(floor($now / $window) * $window);
	}

	/**
	 * Bucket
	 *
	 * The counter key. Hashed so that a long route name or an IPv6 address cannot
	 * overflow the column, and so no caller-controlled text is stored verbatim.
	 *
	 * @param string $route        logical endpoint name, e.g. 'cron.run'
	 * @param string $client       caller identity, normally the remote address
	 * @param int    $window_start
	 *
	 * @return string
	 */
	public static function bucket(string $route, string $client, int $window_start): string {
		return hash('sha256', $route . '|' . $client . '|' . $window_start);
	}

	/**
	 * Resolve Limit
	 *
	 * Turns a configured value into an effective limit.
	 *
	 * @param mixed $configured
	 *
	 * @return int 0 means explicitly disabled
	 */
	public static function resolveLimit($configured): int {
		if ($configured === null || $configured === '' || !is_numeric($configured)) {
			return self::DEFAULT_LIMIT;
		}

		$limit = (int)$configured;

		return $limit < 0 ? self::DEFAULT_LIMIT : $limit;
	}

	/**
	 * Resolve Window
	 *
	 * @param mixed $configured
	 *
	 * @return int seconds, always at least 1
	 */
	public static function resolveWindow($configured): int {
		if ($configured === null || $configured === '' || !is_numeric($configured)) {
			return self::DEFAULT_WINDOW;
		}

		$window = (int)$configured;

		return $window < 1 ? self::DEFAULT_WINDOW : $window;
	}

	/**
	 * Exceeded
	 *
	 * @param int $hits  count INCLUDING the request being judged
	 * @param int $limit 0 disables the limiter
	 *
	 * @return bool
	 */
	public static function exceeded(int $hits, int $limit): bool {
		if ($limit < 1) {
			return false;
		}

		return $hits > $limit;
	}

	/**
	 * Retry After
	 *
	 * Seconds until the current window rolls over, for the Retry-After header.
	 * Always at least 1, because a header of 0 tells a client nothing useful.
	 *
	 * @param int $window_start
	 * @param int $window
	 * @param int $now
	 *
	 * @return int
	 */
	public static function retryAfter(int $window_start, int $window, int $now): int {
		if ($window < 1) {
			$window = self::DEFAULT_WINDOW;
		}

		$remaining = ($window_start + $window) - $now;

		return $remaining > 0 ? $remaining : 1;
	}

	/**
	 * Client Key
	 *
	 * Derives the caller identity from the remote address.
	 *
	 * A forwarded-for header is deliberately NOT consulted: it is caller supplied,
	 * so trusting it would let anyone reset their own counter by rotating a header
	 * value. Behind a reverse proxy the real address should be restored by the
	 * proxy into REMOTE_ADDR.
	 *
	 * @param string $remote_addr
	 *
	 * @return string
	 */
	public static function clientKey(string $remote_addr): string {
		$remote_addr = trim($remote_addr);

		return $remote_addr !== '' ? $remote_addr : 'unknown';
	}
}
