<?php
namespace Opencart\Admin\Controller\Common;
/**
 * Class Dashboard
 *
 * Can be loaded using $this->load->controller('common/dashboard');
 *
 * @package Opencart\Admin\Controller\Common
 */
class Dashboard extends \Opencart\System\Engine\Controller {
	/**
	 * Index
	 *
	 * @return void
	 */
	public function index(): void {
		$this->load->language('common/dashboard');

		$this->document->setTitle($this->language->get('heading_title'));

		$data['breadcrumbs'] = [];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('text_home'),
			'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'])
		];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('heading_title'),
			'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'])
		];

		// Dashboard Extensions
		$dashboards = [];

		$this->load->model('setting/extension');

		// Get a list of installed modules
		$extensions = $this->model_setting_extension->getExtensionsByType('dashboard');

		// Add all the modules which have multiple settings for each module
		foreach ($extensions as $extension) {
			if ($this->config->get('dashboard_' . $extension['code'] . '_status') && $this->user->hasPermission('access', 'extension/' . $extension['extension'] . '/dashboard/' . $extension['code'])) {
				$output = $this->load->controller('extension/' . $extension['extension'] . '/dashboard/' . $extension['code'] . '.dashboard');

				if (!$output instanceof \Exception) {
					$dashboards[] = [
						'code'       => $extension['code'],
						'width'      => $this->config->get('dashboard_' . $extension['code'] . '_width'),
						'sort_order' => $this->config->get('dashboard_' . $extension['code'] . '_sort_order'),
						'output'     => $output
					];
				}
			}
		}

		$sort_order = [];

		foreach ($dashboards as $key => $value) {
			$sort_order[$key] = $value['sort_order'];
		}

		array_multisort($sort_order, SORT_ASC, $dashboards);

		// Split the array so the columns width is not more than 12 on each row.
		$width = 0;
		$column = [];
		$data['rows'] = [];

		foreach ($dashboards as $dashboard) {
			$column[] = $dashboard;

			$width = ($width + $dashboard['width']);

			if ($width >= 12) {
				$data['rows'][] = $column;

				$width = 0;
				$column = [];
			}
		}

		if ($column) {
			$data['rows'][] = $column;
		}

		// PoolBuy Super Admin Metrics & Data
		$data['poolbuy_active'] = false;
		$check = $this->db->query("SHOW TABLES LIKE '" . DB_PREFIX . "poolbuy_pool'");
		if ($check->num_rows) {
			$data['poolbuy_active'] = true;

			// Metrics
			$q_pools = $this->db->query("SELECT COUNT(*) AS total FROM `" . DB_PREFIX . "poolbuy_pool` WHERE `status` = 'active'");
			$data['metric_active_pools'] = (int)($q_pools->row['total'] ?? 0);

			$q_total_pools = $this->db->query("SELECT COUNT(*) AS total FROM `" . DB_PREFIX . "poolbuy_pool`");
			$data['metric_total_pools'] = (int)($q_total_pools->row['total'] ?? 0);

			$q_escrow = $this->db->query("SELECT COALESCE(SUM(p.`reserved_qty` * (SELECT COALESCE(MIN(t.`price`), 500) FROM `" . DB_PREFIX . "poolbuy_pool_tier` t WHERE t.`pool_id` = p.`pool_id`)), 0) AS total FROM `" . DB_PREFIX . "poolbuy_pool` p");
			$data['metric_escrow_val'] = (float)($q_escrow->row['total'] ?? 0);

			$q_sellers = $this->db->query("SELECT COUNT(*) AS total FROM `" . DB_PREFIX . "poolbuy_seller` WHERE `verified_seller` = 1");
			$data['metric_verified_sellers'] = (int)($q_sellers->row['total'] ?? 0);

			$q_moq = $this->db->query("SELECT COUNT(*) AS total FROM `" . DB_PREFIX . "poolbuy_pool` WHERE `reserved_qty` >= `moq_target`");
			$data['metric_moq_unlocked'] = (int)($q_moq->row['total'] ?? 0);

			// Real Pool Governance Records
			$q_list = $this->db->query("SELECT p.*, s.`name` AS seller_name, s.`location` AS seller_location, s.`rating` AS seller_rating FROM `" . DB_PREFIX . "poolbuy_pool` p LEFT JOIN `" . DB_PREFIX . "poolbuy_seller` s ON (s.`seller_id` = p.`seller_id`) ORDER BY p.`pool_id` DESC LIMIT 20");
			$data['poolbuy_pools'] = [];
			foreach ($q_list->rows as $row) {
				$moq = max(1, (int)$row['moq_target']);
				$reserved = (int)$row['reserved_qty'];
				$pct = min(100, (int)round(($reserved / $moq) * 100));
				$data['poolbuy_pools'][] = [
					'pool_id'         => (int)$row['pool_id'],
					'reference'       => $row['reference'],
					'title'           => $row['title'],
					'seller_name'     => $row['seller_name'] ?: 'Direct Manufacturer',
					'seller_location' => $row['seller_location'] ?: 'Warehouse',
					'seller_rating'   => $row['seller_rating'] ?: '4.8',
					'moq_target'      => $moq,
					'reserved_qty'    => $reserved,
					'progress_pct'    => $pct,
					'status'          => $row['status'],
					'climate_safe'    => (int)($row['climate_safe'] ?? 0),
					'unit_label'      => $row['unit_label'] ?: 'Units',
					'date_end'        => date('M d, Y', strtotime($row['date_end']))
				];
			}

			// Sellers list
			$q_sellers_list = $this->db->query("SELECT * FROM `" . DB_PREFIX . "poolbuy_seller` ORDER BY `seller_id` ASC LIMIT 8");
			$data['poolbuy_sellers'] = $q_sellers_list->rows;
		}

		if ($this->user->hasPermission('access', 'common/developer')) {
			$data['developer_status'] = true;
		} else {
			$data['developer_status'] = false;
		}

		$data['security'] = $this->load->controller('common/security');

		$data['user_token'] = $this->session->data['user_token'];

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('common/dashboard', $data));
	}
}
