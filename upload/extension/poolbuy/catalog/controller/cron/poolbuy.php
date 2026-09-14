<?php
namespace Opencart\Catalog\Controller\Extension\Poolbuy\Cron;
/**
 * Class Poolbuy
 *
 * Scheduled pool lifecycle processing.
 *
 * Two entry points, deliberately separated by trust level:
 *
 *  - index()  is called by OpenCart's own cron dispatcher (cron/cron) with the
 *             cron row's metadata. That path is already server side, so it needs
 *             no secret.
 *  - run()    is reachable over HTTP for manual triggering and monitoring, and
 *             therefore REQUIRES the module's cron token. Without a valid token it
 *             returns 403 and does nothing, so an anonymous visitor can never
 *             drive pool state or order creation.
 *
 * The work itself is idempotent, so a double trigger is harmless.
 *
 * @package Opencart\Catalog\Controller\Extension\Poolbuy\Cron
 */
class Poolbuy extends \Opencart\System\Engine\Controller {
	/**
	 * Index
	 *
	 * Signature matches what catalog/controller/cron/cron.php passes to a job.
	 *
	 * @param int    $cron_id
	 * @param string $code
	 * @param string $cycle
	 * @param string $date_added
	 * @param string $date_modified
	 *
	 * @return void
	 */
	public function index(int $cron_id = 0, string $code = '', string $cycle = '', string $date_added = '', string $date_modified = ''): void {
		$summary = $this->process();

		$this->log->write('PoolBuy cron: ' . json_encode($summary));
	}

	/**
	 * Run
	 *
	 * HTTP entry point. Requires ?token=<module_poolbuy_cron_token>.
	 *
	 * @return void
	 */
	public function run(): void {
		// Rate limited BEFORE the token is examined, so an anonymous caller cannot use
		// this endpoint as an unlimited oracle for guessing the cron token.
		if (!$this->allowRequest('cron.run')) {
			return;
		}

		$expected = (string)$this->config->get('module_poolbuy_cron_token');
		$supplied = (string)($this->request->get['token'] ?? '');

		// A blank configured token must never mean "no authentication required".
		if ($expected === '' || $supplied === '' || !hash_equals($expected, $supplied)) {
			$this->response->addHeader($this->request->server['SERVER_PROTOCOL'] . ' 403 Forbidden');
			$this->response->addHeader('Content-Type: application/json');
			$this->response->setOutput((string)json_encode(['error' => 'Forbidden']));

			return;
		}

		$summary = $this->process();

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput((string)json_encode(['success' => true] + $summary));
	}

	/**
	 * Allow Request
	 *
	 * Applies the API rate limiter. Responds 429 with Retry-After and returns false
	 * when the caller is over the limit.
	 *
	 * @param string $route
	 *
	 * @return bool
	 */
	private function allowRequest(string $route): bool {
		$this->load->model('extension/poolbuy/poolbuy/rate_limit');

		$gate = $this->model_extension_poolbuy_poolbuy_rate_limit->check($route);

		if ($gate['allowed']) {
			return true;
		}

		$this->response->addHeader($this->request->server['SERVER_PROTOCOL'] . ' 429 Too Many Requests');
		$this->response->addHeader('Retry-After: ' . (int)$gate['retry_after']);
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput((string)json_encode(['error' => 'rate_limited', 'retry_after' => (int)$gate['retry_after']]));

		return false;
	}

	/**
	 * Process
	 *
	 * @return array<string, mixed>
	 */
	private function process(): array {
		if (!$this->config->get('module_poolbuy_status')) {
			return ['skipped' => 'PoolBuy is disabled'];
		}

		// Housekeeping: drop rate limit counters for windows that have closed, so the
		// table stays small without needing its own scheduled job.
		$this->load->model('extension/poolbuy/poolbuy/rate_limit');
		$pruned = $this->model_extension_poolbuy_poolbuy_rate_limit->prune();

		$this->load->model('extension/poolbuy/poolbuy/lifecycle');

		return $this->model_extension_poolbuy_poolbuy_lifecycle->run() + ['rate_limit_pruned' => $pruned];
	}
}
