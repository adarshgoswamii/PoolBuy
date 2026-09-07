<?php
namespace Opencart\Admin\Controller\Extension\Poolbuy\Module;
/**
 * Class Poolbuy
 *
 * Settings screen for the PoolBuy marketplace, plus the install/uninstall hooks
 * OpenCart calls from Extensions > Modules.
 *
 * This is a settings-only module (it is not placed on a layout), so values are
 * stored in oc_setting under the `module_poolbuy` code and read back anywhere
 * via $this->config->get('module_poolbuy_<key>').
 *
 * @package Opencart\Admin\Controller\Extension\Poolbuy\Module
 */
class Poolbuy extends \Opencart\System\Engine\Controller {
	/**
	 * Setting keys owned by this screen, mapped to their factory defaults.
	 *
	 * Kept as a single source of truth so index(), save() and install() cannot
	 * drift apart.
	 *
	 * @var array<string, mixed>
	 */
	private array $defaults = [
		'module_poolbuy_status'          => 0,
		'module_poolbuy_currency_code'   => 'INR',
		'module_poolbuy_currency_symbol' => '₹',
		'module_poolbuy_gst_rate'        => 18.0,
		'module_poolbuy_platform_fee'    => 2.0,
		'module_poolbuy_pool_duration'   => 7,
		'module_poolbuy_retro_pricing'   => 1,
		'module_poolbuy_cron_token'      => ''
	];

	/**
	 * Additional admin routes this extension exposes beyond the settings module.
	 *
	 * OpenCart only grants a permission for the module route itself, but
	 * admin/controller/startup/permission.php refuses any route the user lacks
	 * `access` for. Without granting these, the seller and pool screens would
	 * return a permission error even for the administrator who installed the
	 * extension.
	 *
	 * @var array<int, string>
	 */
	private array $routes = [
		'extension/poolbuy/poolbuy/seller',
		'extension/poolbuy/poolbuy/pool',
		'extension/poolbuy/poolbuy/reservation'
	];

	/**
	 * Index
	 *
	 * @return void
	 */
	public function index(): void {
		$this->load->language('extension/poolbuy/module/poolbuy');

		$this->document->setTitle($this->language->get('heading_title'));

		$data['breadcrumbs'] = [];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('text_home'),
			'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'])
		];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('text_extension'),
			'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module')
		];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('heading_title'),
			'href' => $this->url->link('extension/poolbuy/module/poolbuy', 'user_token=' . $this->session->data['user_token'])
		];

		$data['save'] = $this->url->link('extension/poolbuy/module/poolbuy.save', 'user_token=' . $this->session->data['user_token']);
		$data['back'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module');

		// Current values, falling back to the factory defaults on a fresh install
		foreach ($this->defaults as $key => $default) {
			$value = $this->config->get($key);

			$data[$key] = ($value === null || $value === '') ? $default : $value;
		}

		// A cron token is generated on first view so the scheduled endpoint added in a
		// later task is never reachable without a secret.
		if (!$data['module_poolbuy_cron_token']) {
			$data['module_poolbuy_cron_token'] = oc_token(32);
		}

		$data['schema_installed'] = $this->isSchemaInstalled();
		$data['user_token'] = $this->session->data['user_token'];

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/poolbuy/module/poolbuy', $data));
	}

	/**
	 * Save
	 *
	 * @return void
	 */
	public function save(): void {
		$this->load->language('extension/poolbuy/module/poolbuy');

		$json = [];

		if (!$this->user->hasPermission('modify', 'extension/poolbuy/module/poolbuy')) {
			$json['error']['warning'] = $this->language->get('error_permission');
		}

		$post_info = $this->request->post + $this->defaults;

		// Currency code: exactly three letters, matching OpenCart's own currency codes
		if (!preg_match('/^[A-Za-z]{3}$/', (string)$post_info['module_poolbuy_currency_code'])) {
			$json['error']['currency_code'] = $this->language->get('error_currency_code');
		}

		if ((string)$post_info['module_poolbuy_currency_symbol'] === '') {
			$json['error']['currency_symbol'] = $this->language->get('error_currency_symbol');
		}

		// Percentages are bounded to a sane range rather than merely "numeric"
		if (!is_numeric($post_info['module_poolbuy_gst_rate']) || (float)$post_info['module_poolbuy_gst_rate'] < 0 || (float)$post_info['module_poolbuy_gst_rate'] > 100) {
			$json['error']['gst_rate'] = $this->language->get('error_gst_rate');
		}

		if (!is_numeric($post_info['module_poolbuy_platform_fee']) || (float)$post_info['module_poolbuy_platform_fee'] < 0 || (float)$post_info['module_poolbuy_platform_fee'] > 100) {
			$json['error']['platform_fee'] = $this->language->get('error_platform_fee');
		}

		if (!ctype_digit((string)$post_info['module_poolbuy_pool_duration']) || (int)$post_info['module_poolbuy_pool_duration'] < 1 || (int)$post_info['module_poolbuy_pool_duration'] > 365) {
			$json['error']['pool_duration'] = $this->language->get('error_pool_duration');
		}

		if (!$json) {
			// Normalise before persisting so downstream code can trust the types
			$setting = [
				'module_poolbuy_status'          => (int)!empty($post_info['module_poolbuy_status']),
				'module_poolbuy_currency_code'   => strtoupper((string)$post_info['module_poolbuy_currency_code']),
				'module_poolbuy_currency_symbol' => (string)$post_info['module_poolbuy_currency_symbol'],
				'module_poolbuy_gst_rate'        => (float)$post_info['module_poolbuy_gst_rate'],
				'module_poolbuy_platform_fee'    => (float)$post_info['module_poolbuy_platform_fee'],
				'module_poolbuy_pool_duration'   => (int)$post_info['module_poolbuy_pool_duration'],
				'module_poolbuy_retro_pricing'   => (int)!empty($post_info['module_poolbuy_retro_pricing']),
				'module_poolbuy_cron_token'      => (string)$post_info['module_poolbuy_cron_token']
			];

			if (!$setting['module_poolbuy_cron_token']) {
				$setting['module_poolbuy_cron_token'] = oc_token(32);
			}

			$this->load->model('setting/setting');

			$this->model_setting_setting->editSetting('module_poolbuy', $setting);

			$json['success'] = $this->language->get('text_success');
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	/**
	 * Run Lifecycle
	 *
	 * A user-friendly, no-token way for an administrator to drive the pool
	 * lifecycle on demand (activate, reach, expire, close, fulfil, reprice and
	 * order creation) straight from the settings screen.
	 *
	 * The operator is already authenticated by the admin `user_token` and must
	 * hold `modify` on this route, so no cron token is required from them. To keep
	 * the single, tested lifecycle code path (which runs in the catalog store
	 * context, where the checkout/order model and store config live), this action
	 * calls the catalog cron endpoint over the loopback interface, supplying the
	 * configured cron token server-side. The token is read from config and never
	 * exposed to, or entered by, the operator.
	 *
	 * @return void
	 */
	public function runLifecycle(): void {
		$this->load->language('extension/poolbuy/module/poolbuy');

		$json = [];

		if (!$this->user->hasPermission('modify', 'extension/poolbuy/module/poolbuy')) {
			$json['error'] = $this->language->get('error_permission');
		} elseif (!$this->config->get('module_poolbuy_status')) {
			$json['error'] = $this->language->get('error_disabled');
		} else {
			$token = (string)$this->config->get('module_poolbuy_cron_token');

			if ($token === '') {
				$json['error'] = $this->language->get('error_no_token');
			} else {
				// Build the catalog cron URL on the same host this admin request
				// arrived on, so it works regardless of the configured store URL.
				$scheme = (!empty($this->request->server['HTTPS']) && $this->request->server['HTTPS'] !== 'off') ? 'https' : 'http';
				$host = (string)($this->request->server['HTTP_HOST'] ?? 'localhost');

				$url = $scheme . '://' . $host . '/index.php?route=extension/poolbuy/cron/poolbuy.run&token=' . rawurlencode($token);

				$summary = $this->callCron($url);

				if ($summary === null || empty($summary['success'])) {
					$json['error'] = $this->language->get('error_lifecycle');
				} else {
					// A compact, human-readable outcome for the toast on the settings page.
					$counts = [
						sprintf($this->language->get('text_lifecycle_activated'), count((array)($summary['activated'] ?? []))),
						sprintf($this->language->get('text_lifecycle_reached'), count((array)($summary['reached'] ?? []))),
						sprintf($this->language->get('text_lifecycle_expired'), count((array)($summary['expired'] ?? []))),
						sprintf($this->language->get('text_lifecycle_closed'), count((array)($summary['closed'] ?? []))),
						sprintf($this->language->get('text_lifecycle_fulfilled'), count((array)($summary['fulfilled'] ?? []))),
						sprintf($this->language->get('text_lifecycle_orders'), (int)($summary['orders'] ?? 0)),
						sprintf($this->language->get('text_lifecycle_repriced'), (int)($summary['repriced'] ?? 0))
					];

					$json['success'] = $this->language->get('text_lifecycle_ran') . ' ' . implode(', ', $counts) . '.';
					$json['summary'] = $summary;
				}
			}
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	/**
	 * Call Cron
	 *
	 * Server-side loopback request to the catalog cron endpoint. Uses cURL when
	 * available and falls back to the stream wrapper, so it works in a bare PHP
	 * image without the cURL extension.
	 *
	 * @param string $url
	 *
	 * @return array<string, mixed>|null
	 */
	private function callCron(string $url): ?array {
		$body = null;

		if (function_exists('curl_init')) {
			$ch = curl_init($url);

			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_TIMEOUT, 120);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);

			$result = curl_exec($ch);

			if ($result !== false) {
				$body = (string)$result;
			}

			curl_close($ch);
		}

		if ($body === null) {
			$context = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 120, 'ignore_errors' => true]]);

			$result = @file_get_contents($url, false, $context);

			if ($result !== false) {
				$body = (string)$result;
			}
		}

		if ($body === null) {
			return null;
		}

		$decoded = json_decode($body, true);

		return is_array($decoded) ? $decoded : null;
	}

	/**
	 * Install
	 *
	 * Called by OpenCart when the extension is installed from
	 * Extensions > Modules. Creates the schema and seeds default settings.
	 *
	 * Authorisation note: this checks `modify` on `extension/module` rather than on
	 * this extension's own route. OpenCart grants the per-extension permission
	 * during the very same request that calls this hook, so `$this->user` still
	 * holds the pre-grant permission set and a check against
	 * `extension/poolbuy/module/poolbuy` would always fail on a first install -
	 * silently creating no tables. `extension/module` is the capability that
	 * actually governs installing extensions and is exactly what
	 * Extensions > Modules checks before invoking this hook.
	 *
	 * @return void
	 */
	public function install(): void {
		if (!$this->user->hasPermission('modify', 'extension/module')) {
			return;
		}

		$this->load->model('extension/poolbuy/module/poolbuy');

		$this->model_extension_poolbuy_module_poolbuy->install();

		// Grant the current user group access to PoolBuy's own admin screens.
		$this->load->model('user/user_group');

		foreach ($this->routes as $route) {
			$this->model_user_user_group->addPermission($this->user->getGroupId(), 'access', $route);
			$this->model_user_user_group->addPermission($this->user->getGroupId(), 'modify', $route);
		}

		// Seed defaults only if this is a first install, so an uninstall/reinstall
		// cycle does not silently wipe a store's configured rates.
		$this->load->model('setting/setting');

		if (!$this->config->get('module_poolbuy_currency_code')) {
			$setting = $this->defaults;

			$setting['module_poolbuy_cron_token'] = oc_token(32);

			$this->model_setting_setting->editSetting('module_poolbuy', $setting);
		}
	}

	/**
	 * Uninstall
	 *
	 * Called by OpenCart when the extension is uninstalled. Drops the schema so
	 * no orphan tables are left behind. OpenCart itself removes the
	 * `module_poolbuy` settings rows.
	 *
	 * See install() for why the permission checked here is `extension/module`.
	 *
	 * @return void
	 */
	public function uninstall(): void {
		if (!$this->user->hasPermission('modify', 'extension/module')) {
			return;
		}

		$this->load->model('extension/poolbuy/module/poolbuy');

		$this->model_extension_poolbuy_module_poolbuy->uninstall();
	}

	/**
	 * Is Schema Installed
	 *
	 * Surfaced on the settings screen so an operator can see at a glance whether
	 * the tables are present.
	 *
	 * @return bool
	 */
	private function isSchemaInstalled(): bool {
		$this->load->model('extension/poolbuy/module/poolbuy');

		foreach ($this->model_extension_poolbuy_module_poolbuy->getTables() as $table) {
			$query = $this->db->query("SHOW TABLES LIKE '" . $this->db->escape(DB_PREFIX . $table) . "'");

			if (!$query->num_rows) {
				return false;
			}
		}

		return true;
	}
}
