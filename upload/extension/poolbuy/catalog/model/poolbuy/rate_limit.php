<?php
namespace Opencart\Catalog\Model\Extension\Poolbuy\Poolbuy;

use Opencart\System\Library\Extension\Poolbuy\RateLimiter;

/**
 * Class Rate Limit
 *
 * Storage and enforcement for the fixed-window request limiter.
 *
 * The arithmetic lives in the framework-free RateLimiter library; this model only
 * counts and prunes.
 *
 * @package Opencart\Catalog\Model\Extension\Poolbuy\Poolbuy
 */
class RateLimit extends \Opencart\System\Engine\Model {
	/**
	 * Check
	 *
	 * Records this request against the caller's counter and reports whether it is
	 * over the limit.
	 *
	 * Counting happens BEFORE the verdict, so a refused request still counts. That
	 * is deliberate: otherwise a caller who is already over the limit could hammer
	 * the endpoint for free.
	 *
	 * @param string $route  logical endpoint name, e.g. 'cron.run'
	 * @param string $client caller identity; defaults to the remote address
	 *
	 * @return array{allowed: bool, hits: int, limit: int, retry_after: int}
	 */
	public function check(string $route, string $client = ''): array {
		$limit = RateLimiter::resolveLimit($this->config->get('module_poolbuy_rate_limit'));
		$window = RateLimiter::resolveWindow($this->config->get('module_poolbuy_rate_window'));

		if ($limit < 1) {
			// Explicitly disabled by configuration.
			return ['allowed' => true, 'hits' => 0, 'limit' => 0, 'retry_after' => 0];
		}

		if ($client === '') {
			$client = RateLimiter::clientKey((string)($this->request->server['REMOTE_ADDR'] ?? ''));
		}

		$now = time();
		$window_start = RateLimiter::windowStart($now, $window);
		$bucket = RateLimiter::bucket($route, $client, $window_start);

		$hits = $this->hit($bucket, $window_start);

		$allowed = !RateLimiter::exceeded($hits, $limit);

		return [
			'allowed'     => $allowed,
			'hits'        => $hits,
			'limit'       => $limit,
			'retry_after' => $allowed ? 0 : RateLimiter::retryAfter($window_start, $window, $now)
		];
	}

	/**
	 * Hit
	 *
	 * Increments the counter for one bucket and returns the new value.
	 *
	 * A single upsert does the increment so two concurrent requests cannot both
	 * read-then-write the same stale count. The follow-up SELECT can in principle
	 * observe a slightly higher value under concurrency, which errs towards
	 * limiting rather than towards letting traffic through.
	 *
	 * @param string $bucket
	 * @param int    $window_start
	 *
	 * @return int
	 */
	private function hit(string $bucket, int $window_start): int {
		$this->db->query("INSERT INTO `" . DB_PREFIX . "poolbuy_rate_limit` SET `bucket` = '" . $this->db->escape($bucket) . "', `hits` = '1', `window_start` = '" . (int)$window_start . "' ON DUPLICATE KEY UPDATE `hits` = `hits` + 1");

		$query = $this->db->query("SELECT `hits` FROM `" . DB_PREFIX . "poolbuy_rate_limit` WHERE `bucket` = '" . $this->db->escape($bucket) . "'");

		// Occasionally pruned rows or a lost race leave nothing to read; treat that as
		// this being the first request rather than as an error.
		return $query->num_rows ? (int)$query->row['hits'] : 1;
	}

	/**
	 * Prune
	 *
	 * Drops counters for windows that have closed. Called from the lifecycle cron so
	 * the table cannot grow without bound.
	 *
	 * @param int $older_than seconds of history to keep
	 *
	 * @return int rows removed
	 */
	public function prune(int $older_than = 3600): int {
		if ($older_than < 1) {
			$older_than = 3600;
		}

		$cutoff = time() - $older_than;

		$this->db->query("DELETE FROM `" . DB_PREFIX . "poolbuy_rate_limit` WHERE `window_start` < '" . (int)$cutoff . "'");

		return (int)$this->db->countAffected();
	}
}
