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
	 * Process
	 *
	 * @return array<string, mixed>
	 */
	private function process(): array {
		if (!$this->config->get('module_poolbuy_status')) {
			return ['skipped' => 'PoolBuy is disabled'];
		}

		$this->load->model('extension/poolbuy/poolbuy/lifecycle');

		return $this->model_extension_poolbuy_poolbuy_lifecycle->run();
	}
}
