<?php
namespace Opencart\Admin\Controller\Extension\Poolbuy\Poolbuy;

/**
 * Class Promotion
 *
 * Admin CRUD for PoolBuy discount codes.
 *
 * Two scopes exist. A 'buyer' code is typed in during the join flow and discounts
 * one commitment. A 'pool' code is attached to a pool and applied to EVERY
 * participant when that pool closes - nobody types it, and it takes precedence
 * over any buyer code.
 *
 * @package Opencart\Admin\Controller\Extension\Poolbuy\Poolbuy
 */
class Promotion extends \Opencart\System\Engine\Controller {
	/**
	 * Route of this controller, used for links and permission checks.
	 */
	private const ROUTE = 'extension/poolbuy/poolbuy/promotion';

	/**
	 * A code is what a buyer types, so it is restricted to characters that survive
	 * being read aloud, printed and re-typed without ambiguity.
	 */
	private const CODE_PATTERN = '/^[A-Z0-9][A-Z0-9-]{1,31}$/';

	/**
	 * Index
	 *
	 * @return void
	 */
	public function index(): void {
		$this->load->language('extension/poolbuy/poolbuy/promotion');

		$this->document->setTitle($this->language->get('heading_title'));

		$data['breadcrumbs'] = $this->breadcrumbs();

		$data['add'] = $this->url->link(self::ROUTE . '.form', 'user_token=' . $this->session->data['user_token']);
		$data['delete'] = $this->url->link(self::ROUTE . '.delete', 'user_token=' . $this->session->data['user_token']);

		$data['list'] = $this->getList();

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/poolbuy/poolbuy/promotion_list', $data));
	}

	/**
	 * List
	 *
	 * @return void
	 */
	public function list(): void {
		$this->load->language('extension/poolbuy/poolbuy/promotion');

		$this->response->setOutput($this->getList());
	}

	/**
	 * Get List
	 *
	 * @return string
	 */
	private function getList(): string {
		$filter_code = isset($this->request->get['filter_code']) ? (string)$this->request->get['filter_code'] : '';
		$filter_scope = isset($this->request->get['filter_scope']) ? (string)$this->request->get['filter_scope'] : '';
		$filter_status = isset($this->request->get['filter_status']) ? (string)$this->request->get['filter_status'] : '';

		$sort = isset($this->request->get['sort']) ? (string)$this->request->get['sort'] : 'code';
		$order = (isset($this->request->get['order']) && strtoupper((string)$this->request->get['order']) === 'DESC') ? 'DESC' : 'ASC';
		$page = max(1, (int)($this->request->get['page'] ?? 1));

		$limit = 10;

		$filter_data = [
			'filter_code'   => $filter_code,
			'filter_scope'  => $filter_scope,
			'filter_status' => $filter_status,
			'sort'          => $sort,
			'order'         => $order,
			'start'         => ($page - 1) * $limit,
			'limit'         => $limit
		];

		$this->load->model('extension/poolbuy/poolbuy/promotion');

		$data['promotions'] = [];

		foreach ($this->model_extension_poolbuy_poolbuy_promotion->getPromotions($filter_data) as $result) {
			$max = (int)$result['max_redemptions'];

			$data['promotions'][] = [
				'promotion_id'     => (int)$result['promotion_id'],
				'code'             => $result['code'],
				'name'             => $result['name'],
				'scope'            => (string)$result['scope'],
				'scope_text'       => $this->language->get('text_scope_' . $result['scope']),
				'pool_reference'   => (string)($result['pool_reference'] ?? ''),
				'value_text'       => $this->formatValue((string)$result['type'], (float)$result['value']),
				'min_qty'          => (int)$result['min_qty'],
				'redemptions_used' => (int)$result['redemptions_used'],
				// 0 means unlimited, which reads better than a bare "0".
				'redemptions_text' => $max > 0
					? sprintf('%d / %d', (int)$result['redemptions_used'], $max)
					: sprintf($this->language->get('text_unlimited_used'), (int)$result['redemptions_used']),
				'window_text' => $this->formatWindow($result),
				'status'      => (bool)$result['status'],
				'edit'        => $this->url->link(self::ROUTE . '.form', 'user_token=' . $this->session->data['user_token'] . '&promotion_id=' . (int)$result['promotion_id'])
			];
		}

		$total = $this->model_extension_poolbuy_poolbuy_promotion->getTotalPromotions($filter_data);

		$params = ['filter_code' => $filter_code, 'filter_scope' => $filter_scope, 'filter_status' => $filter_status];

		$url = '';

		foreach ($params as $key => $value) {
			if ($value !== '') {
				$url .= '&' . $key . '=' . urlencode($value);
			}
		}

		$data['sort'] = $sort;
		$data['order'] = $order;

		$reverse = ($order === 'ASC') ? 'DESC' : 'ASC';

		foreach (['code', 'scope', 'value', 'redemptions_used', 'status'] as $column) {
			$data['sort_' . $column] = $this->url->link(self::ROUTE . '.list', 'user_token=' . $this->session->data['user_token'] . '&sort=' . $column . '&order=' . (($sort === $column) ? $reverse : 'ASC') . $url);
		}

		$data['pagination'] = $this->load->controller('common/pagination', [
			'total' => $total,
			'page'  => $page,
			'limit' => $limit,
			'url'   => $this->url->link(self::ROUTE . '.list', 'user_token=' . $this->session->data['user_token'] . $url . '&page={page}')
		]);

		$data['results'] = sprintf($this->language->get('text_pagination'), $total ? (($page - 1) * $limit) + 1 : 0, min($page * $limit, $total), $total, (int)ceil($total / $limit));

		$data['filter_code'] = $filter_code;
		$data['filter_scope'] = $filter_scope;
		$data['filter_status'] = $filter_status;
		$data['user_token'] = $this->session->data['user_token'];

		return $this->load->view('extension/poolbuy/poolbuy/promotion_list_body', $data);
	}

	/**
	 * Form
	 *
	 * @return void
	 */
	public function form(): void {
		$this->load->language('extension/poolbuy/poolbuy/promotion');

		$promotion_id = (int)($this->request->get['promotion_id'] ?? 0);

		$this->document->setTitle($this->language->get('heading_title'));

		$data['breadcrumbs'] = $this->breadcrumbs();

		$this->load->model('extension/poolbuy/poolbuy/promotion');

		$promotion_info = $promotion_id ? $this->model_extension_poolbuy_poolbuy_promotion->getPromotion($promotion_id) : null;

		$defaults = [
			'code'            => '',
			'name'            => '',
			'scope'           => 'buyer',
			'pool_id'         => 0,
			'type'            => 'percent',
			'value'           => '',
			'min_qty'         => 0,
			'max_redemptions' => 0,
			'date_start'      => '',
			'date_end'        => '',
			'status'          => 1
		];

		foreach ($defaults as $key => $default) {
			// A NULL date column falls through to the default, which is an empty
			// string, so an open-ended promotion renders as a blank field rather
			// than "0000-00-00".
			$data[$key] = $promotion_info[$key] ?? $default;
		}

		$data['promotion_id'] = $promotion_id;
		$data['redemptions_used'] = (int)($promotion_info['redemptions_used'] ?? 0);

		// Pools a pool-scoped code can attach to. Only ones that could still be
		// discounted are worth offering.
		$data['pools'] = $this->db->query("SELECT `pool_id`, `reference`, `title`, `status` FROM `" . DB_PREFIX . "poolbuy_pool` WHERE `status` IN ('draft','active','reached','closed') ORDER BY `reference` ASC LIMIT 200")->rows;

		$data['scopes'] = [
			['value' => 'buyer', 'text' => $this->language->get('text_scope_buyer')],
			['value' => 'pool', 'text' => $this->language->get('text_scope_pool')]
		];

		$data['types'] = [
			['value' => 'percent', 'text' => $this->language->get('text_type_percent')],
			['value' => 'fixed', 'text' => $this->language->get('text_type_fixed')]
		];

		// Redemption audit, read only.
		$data['redemptions'] = [];

		if ($promotion_id) {
			foreach ($this->model_extension_poolbuy_poolbuy_promotion->getRedemptions($promotion_id) as $row) {
				$data['redemptions'][] = [
					'reservation_reference' => (string)($row['reservation_reference'] ?? ''),
					'pool_reference'        => (string)($row['pool_reference'] ?? ''),
					'discount_amount'       => $this->formatMoney((float)$row['discount_amount']),
					'date_added'            => date($this->language->get('datetime_format'), strtotime((string)$row['date_added']))
				];
			}
		}

		$data['save'] = $this->url->link(self::ROUTE . '.save', 'user_token=' . $this->session->data['user_token']);
		$data['back'] = $this->url->link(self::ROUTE, 'user_token=' . $this->session->data['user_token']);
		$data['user_token'] = $this->session->data['user_token'];

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/poolbuy/poolbuy/promotion_form', $data));
	}

	/**
	 * Save
	 *
	 * @return void
	 */
	public function save(): void {
		$this->load->language('extension/poolbuy/poolbuy/promotion');

		$json = [];

		if (!$this->user->hasPermission('modify', self::ROUTE)) {
			$json['error']['warning'] = $this->language->get('error_permission');
		}

		$required = [
			'promotion_id'    => 0,
			'code'            => '',
			'name'            => '',
			'scope'           => 'buyer',
			'pool_id'         => 0,
			'type'            => 'percent',
			'value'           => 0,
			'min_qty'         => 0,
			'max_redemptions' => 0,
			'date_start'      => '',
			'date_end'        => ''
		];

		$post = $this->request->post + $required;

		$promotion_id = (int)$post['promotion_id'];
		$code = strtoupper(trim((string)$post['code']));
		$scope = (string)$post['scope'];
		$type = (string)$post['type'];

		if (!preg_match(self::CODE_PATTERN, $code)) {
			$json['error']['code'] = $this->language->get('error_code');
		}

		if (!oc_validate_length(trim((string)$post['name']), 2, 128)) {
			$json['error']['name'] = $this->language->get('error_name');
		}

		if (!in_array($scope, ['pool', 'buyer'], true)) {
			$json['error']['scope'] = $this->language->get('error_scope');
		}

		if (!in_array($type, ['percent', 'fixed'], true)) {
			$json['error']['type'] = $this->language->get('error_type');
		}

		$value = $post['value'];

		if (!is_numeric($value) || (float)$value <= 0) {
			$json['error']['value'] = $this->language->get('error_value');
		} elseif ($type === 'percent' && (float)$value > 100) {
			$json['error']['value'] = $this->language->get('error_percent_range');
		}

		// A pool-scoped code has no meaning without a pool to attach to.
		$pool_id = (int)$post['pool_id'];

		if ($scope === 'pool') {
			if ($pool_id <= 0) {
				$json['error']['pool_id'] = $this->language->get('error_pool_required');
			} else {
				$pool = $this->db->query("SELECT `pool_id` FROM `" . DB_PREFIX . "poolbuy_pool` WHERE `pool_id` = '" . $pool_id . "'");

				if (!$pool->num_rows) {
					$json['error']['pool_id'] = $this->language->get('error_pool');
				} else {
					// Two pool-wide codes on one pool would make the discount depend
					// on row order, so only one is allowed.
					$clash = $this->db->query("SELECT `promotion_id` FROM `" . DB_PREFIX . "poolbuy_promotion` WHERE `scope` = 'pool' AND `pool_id` = '" . $pool_id . "' AND `status` = '1' AND `promotion_id` != '" . $promotion_id . "'");

					if ($clash->num_rows && !empty($this->request->post['status'])) {
						$json['error']['pool_id'] = $this->language->get('error_pool_taken');
					}
				}
			}
		}

		if ((int)$post['min_qty'] < 0) {
			$json['error']['min_qty'] = $this->language->get('error_min_qty');
		}

		if ((int)$post['max_redemptions'] < 0) {
			$json['error']['max_redemptions'] = $this->language->get('error_max_redemptions');
		}

		// Dates are optional, but anything supplied must parse and be ordered.
		$start = trim((string)$post['date_start']);
		$end = trim((string)$post['date_end']);

		if ($start !== '' && strtotime($start) === false) {
			$json['error']['date_start'] = $this->language->get('error_date');
		}

		if ($end !== '' && strtotime($end) === false) {
			$json['error']['date_end'] = $this->language->get('error_date');
		}

		if ($start !== '' && $end !== '' && strtotime($start) !== false && strtotime($end) !== false && strtotime($end) <= strtotime($start)) {
			$json['error']['date_end'] = $this->language->get('error_date_order');
		}

		$this->load->model('extension/poolbuy/poolbuy/promotion');

		if (!isset($json['error']['code'])) {
			$existing = $this->model_extension_poolbuy_poolbuy_promotion->getPromotionByCode($code);

			if ($existing && (int)$existing['promotion_id'] !== $promotion_id) {
				$json['error']['code'] = $this->language->get('error_code_unique');
			}
		}

		if (!$json) {
			$post['code'] = $code;

			// A buyer code that is not pinned to a pool works on any pool.
			if ($scope === 'buyer' && $pool_id < 0) {
				$post['pool_id'] = 0;
			}

			if ($promotion_id) {
				$this->model_extension_poolbuy_poolbuy_promotion->editPromotion($promotion_id, $post);
			} else {
				$promotion_id = $this->model_extension_poolbuy_poolbuy_promotion->addPromotion($post);
			}

			$json['promotion_id'] = $promotion_id;
			$json['success'] = $this->language->get('text_success');
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput((string)json_encode($json));
	}

	/**
	 * Delete
	 *
	 * @return void
	 */
	public function delete(): void {
		$this->load->language('extension/poolbuy/poolbuy/promotion');

		$json = [];

		if (!$this->user->hasPermission('modify', self::ROUTE)) {
			$json['error']['warning'] = $this->language->get('error_permission');
		}

		$selected = $this->request->post['selected'] ?? [];

		if (!is_array($selected) || !$selected) {
			$json['error']['warning'] = $this->language->get('error_selection');
		}

		if (!$json) {
			$this->load->model('extension/poolbuy/poolbuy/promotion');

			foreach ($selected as $promotion_id) {
				$this->model_extension_poolbuy_poolbuy_promotion->deletePromotion((int)$promotion_id);
			}

			$json['success'] = $this->language->get('text_success');
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput((string)json_encode($json));
	}

	/**
	 * Format Value
	 *
	 * @param string $type
	 * @param float  $value
	 *
	 * @return string
	 */
	private function formatValue(string $type, float $value): string {
		if ($type === 'percent') {
			return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') . '%';
		}

		return $this->formatMoney($value);
	}

	/**
	 * Format Window
	 *
	 * @param array<string, mixed> $promotion
	 *
	 * @return string
	 */
	private function formatWindow(array $promotion): string {
		$format = $this->language->get('date_format_short');

		$start = $promotion['date_start'] ?? null;
		$end = $promotion['date_end'] ?? null;

		$has_start = $start !== null && $start !== '' && $start !== '0000-00-00 00:00:00';
		$has_end = $end !== null && $end !== '' && $end !== '0000-00-00 00:00:00';

		if (!$has_start && !$has_end) {
			return $this->language->get('text_always');
		}

		if ($has_start && !$has_end) {
			return sprintf($this->language->get('text_from'), date($format, (int)strtotime((string)$start)));
		}

		if (!$has_start && $has_end) {
			return sprintf($this->language->get('text_until'), date($format, (int)strtotime((string)$end)));
		}

		return date($format, (int)strtotime((string)$start)) . ' - ' . date($format, (int)strtotime((string)$end));
	}

	/**
	 * Format Money
	 *
	 * Uses the pool currency symbol so figures read the same as they do on the
	 * storefront.
	 *
	 * @param float $value
	 *
	 * @return string
	 */
	private function formatMoney(float $value): string {
		return (string)$this->config->get('module_poolbuy_currency_symbol') . number_format($value, 2);
	}

	/**
	 * Breadcrumbs
	 *
	 * @return array<int, array<string, string>>
	 */
	private function breadcrumbs(): array {
		return [
			[
				'text' => $this->language->get('text_home'),
				'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'])
			],
			[
				'text' => $this->language->get('text_poolbuy'),
				'href' => $this->url->link('extension/poolbuy/module/poolbuy', 'user_token=' . $this->session->data['user_token'])
			],
			[
				'text' => $this->language->get('heading_title'),
				'href' => $this->url->link(self::ROUTE, 'user_token=' . $this->session->data['user_token'])
			]
		];
	}
}
