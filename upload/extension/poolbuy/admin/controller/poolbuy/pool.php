<?php
namespace Opencart\Admin\Controller\Extension\Poolbuy\Poolbuy;

use Opencart\System\Library\Extension\Poolbuy\PoolCalculator;
use Opencart\System\Library\Extension\Poolbuy\PoolLifecycle;

/**
 * Class Pool
 *
 * Admin CRUD for pools, including the price tier editor.
 *
 * @package Opencart\Admin\Controller\Extension\Poolbuy\Poolbuy
 */
class Pool extends \Opencart\System\Engine\Controller {
	private const ROUTE = 'extension/poolbuy/poolbuy/pool';

	/**
	 * Index
	 *
	 * @return void
	 */
	public function index(): void {
		$this->load->language('extension/poolbuy/poolbuy/pool');

		$this->document->setTitle($this->language->get('heading_title'));

		$data['breadcrumbs'] = $this->breadcrumbs();

		$data['add'] = $this->url->link(self::ROUTE . '.form', 'user_token=' . $this->session->data['user_token']);

		$data['list'] = $this->getList();

		$data['statistics'] = $this->getStatistics();

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/poolbuy/poolbuy/pool_list', $data));
	}

	/**
	 * List
	 *
	 * @return void
	 */
	public function list(): void {
		$this->load->language('extension/poolbuy/poolbuy/pool');

		$this->response->setOutput($this->getList());
	}

	/**
	 * Get Statistics
	 *
	 * @return array<string, mixed>
	 */
	private function getStatistics(): array {
		$this->load->model('extension/poolbuy/poolbuy/pool');

		$stats = $this->model_extension_poolbuy_poolbuy_pool->getStatistics();

		return [
			'total_volume' => $this->formatMoney((float)$stats['total_volume']),
			'active_pools' => (int)$stats['active_pools'],
			'average_fill' => (float)$stats['average_fill'],
			'closing_soon' => (int)$stats['closing_soon']
		];
	}

	/**
	 * Get List
	 *
	 * @return string
	 */
	private function getList(): string {
		$filter_product = isset($this->request->get['filter_product']) ? (string)$this->request->get['filter_product'] : '';
		$filter_status = isset($this->request->get['filter_status']) ? (string)$this->request->get['filter_status'] : '';

		$sort = isset($this->request->get['sort']) ? (string)$this->request->get['sort'] : 'date_end';
		$order = (isset($this->request->get['order']) && strtoupper((string)$this->request->get['order']) === 'DESC') ? 'DESC' : 'ASC';
		$page = max(1, (int)($this->request->get['page'] ?? 1));

		$limit = 10;

		$filter_data = [
			'filter_product' => $filter_product,
			'filter_status'  => $filter_status,
			'sort'           => $sort,
			'order'          => $order,
			'start'          => ($page - 1) * $limit,
			'limit'          => $limit
		];

		$this->load->model('extension/poolbuy/poolbuy/pool');

		$data['pools'] = [];

		foreach ($this->model_extension_poolbuy_poolbuy_pool->getPools($filter_data) as $result) {
			$pool_id = (int)$result['pool_id'];
			$moq = (int)$result['moq_target'];
			$reserved = (int)$result['reserved_qty'];

			$data['pools'][] = [
				'pool_id'       => $pool_id,
				'title'         => $result['title'] ?: (string)$result['product_name'],
				'reference'     => $result['reference'],
				'product_name'  => (string)$result['product_name'],
				'product_model' => (string)$result['product_model'],
				'seller_name'   => (string)$result['seller_name'],
				'moq_target'    => $moq,
				'reserved_qty'  => $reserved,
				'unit_label'    => $result['unit_label'],
				'fill'          => PoolCalculator::fillPercentage($reserved, $moq),
				'remaining'     => PoolCalculator::unitsRemaining($reserved, $moq),
				'status'        => (string)$result['status'],
				'status_text'   => $this->language->get('text_status_' . $result['status']),
				'date_end'      => $result['date_end'],
				'time_left'     => $this->timeLeft((string)$result['date_end']),
				'edit'          => $this->url->link(self::ROUTE . '.form', 'user_token=' . $this->session->data['user_token'] . '&pool_id=' . $pool_id)
			];
		}

		$pool_total = $this->model_extension_poolbuy_poolbuy_pool->getTotalPools($filter_data);

		$url = '';

		if ($filter_product !== '') {
			$url .= '&filter_product=' . urlencode($filter_product);
		}

		if ($filter_status !== '') {
			$url .= '&filter_status=' . urlencode($filter_status);
		}

		$data['sort'] = $sort;
		$data['order'] = $order;

		$reverse = ($order === 'ASC') ? 'DESC' : 'ASC';

		foreach (['title', 'seller_name', 'moq_target', 'reserved_qty', 'status', 'date_end'] as $column) {
			$data['sort_' . $column] = $this->url->link(self::ROUTE . '.list', 'user_token=' . $this->session->data['user_token'] . '&sort=' . $column . '&order=' . (($sort === $column) ? $reverse : 'ASC') . $url);
		}

		$data['pagination'] = $this->load->controller('common/pagination', [
			'total' => $pool_total,
			'page'  => $page,
			'limit' => $limit,
			'url'   => $this->url->link(self::ROUTE . '.list', 'user_token=' . $this->session->data['user_token'] . '&sort=' . $sort . '&order=' . $order . $url . '&page={page}')
		]);

		$data['results'] = sprintf($this->language->get('text_pagination'), $pool_total ? (($page - 1) * $limit) + 1 : 0, ((($page - 1) * $limit) > ($pool_total - $limit)) ? $pool_total : ((($page - 1) * $limit) + $limit), $pool_total, (int)ceil($pool_total / $limit));

		$data['filter_product'] = $filter_product;
		$data['filter_status'] = $filter_status;
		$data['statuses'] = $this->statusOptions();

		$data['delete'] = $this->url->link(self::ROUTE . '.delete', 'user_token=' . $this->session->data['user_token']);
		$data['user_token'] = $this->session->data['user_token'];

		return $this->load->view('extension/poolbuy/poolbuy/pool_list_body', $data);
	}

	/**
	 * Form
	 *
	 * @return void
	 */
	public function form(): void {
		$this->load->language('extension/poolbuy/poolbuy/pool');

		$this->document->setTitle($this->language->get('heading_title'));

		$pool_id = (int)($this->request->get['pool_id'] ?? 0);

		$data['breadcrumbs'] = $this->breadcrumbs();

		$data['breadcrumbs'][] = [
			'text' => $pool_id ? $this->language->get('text_edit') : $this->language->get('text_add'),
			'href' => $this->url->link(self::ROUTE . '.form', 'user_token=' . $this->session->data['user_token'] . ($pool_id ? '&pool_id=' . $pool_id : ''))
		];

		$data['text_form'] = $pool_id ? $this->language->get('text_edit') : $this->language->get('text_add');

		$data['save'] = $this->url->link(self::ROUTE . '.save', 'user_token=' . $this->session->data['user_token']);
		$data['back'] = $this->url->link(self::ROUTE, 'user_token=' . $this->session->data['user_token']);

		$this->load->model('extension/poolbuy/poolbuy/pool');

		$pool_info = $pool_id ? $this->model_extension_poolbuy_poolbuy_pool->getPool($pool_id) : [];

		$duration = max(1, (int)$this->config->get('module_poolbuy_pool_duration'));

		$defaults = [
			'product_id'         => 0,
			'seller_id'          => 0,
			'title'              => '',
			'reference'          => '',
			'moq_target'         => 100,
			'unit_label'         => $this->language->get('text_default_unit_label'),
			'currency_code'      => (string)$this->config->get('module_poolbuy_currency_code'),
			'status'             => PoolLifecycle::DRAFT,
			'date_start'         => date('Y-m-d H:i:s'),
			'date_end'           => date('Y-m-d H:i:s', strtotime('+' . $duration . ' days')),
			'retro_pricing'      => (int)$this->config->get('module_poolbuy_retro_pricing'),
			'allow_full_moq_buy' => 1,
			'min_qty_per_buyer'  => 1,
			'max_qty_per_buyer'  => 0,
			'lead_time'          => '',
			'shipping_terms'     => ''
		];

		foreach ($defaults as $key => $default) {
			$data[$key] = $pool_info[$key] ?? $default;
		}

		$data['pool_id'] = $pool_id;
		$data['reserved_qty'] = (int)($pool_info['reserved_qty'] ?? 0);

		// Product and seller names for the autocomplete inputs
		$data['product_name'] = '';

		if (!empty($data['product_id'])) {
			$this->load->model('catalog/product');

			$product_info = $this->model_catalog_product->getProduct((int)$data['product_id']);

			$data['product_name'] = $product_info['name'] ?? '';
		}

		$data['seller_name'] = '';

		if (!empty($data['seller_id'])) {
			$this->load->model('extension/poolbuy/poolbuy/seller');

			$seller_info = $this->model_extension_poolbuy_poolbuy_seller->getSeller((int)$data['seller_id']);

			$data['seller_name'] = $seller_info['name'] ?? '';
		}

		// Price ladder. A brand new pool starts with one open-ended row so the
		// operator is never staring at an empty editor.
		$data['pool_tiers'] = [];

		if ($pool_id) {
			foreach ($this->model_extension_poolbuy_poolbuy_pool->getTiers($pool_id) as $tier) {
				$data['pool_tiers'][] = [
					'min_qty' => (int)$tier['min_qty'],
					'max_qty' => $tier['max_qty'] === null ? '' : (int)$tier['max_qty'],
					'price'   => number_format((float)$tier['price'], 4, '.', '')
				];
			}
		}

		if (!$data['pool_tiers']) {
			$data['pool_tiers'] = [
				['min_qty' => 1, 'max_qty' => '', 'price' => '0.0000']
			];
		}

		// Only statuses reachable from the current one, so the form cannot offer an
		// illegal transition in the first place.
		$current = (string)$data['status'];

		$data['statuses'] = [];

		foreach ($this->statusOptions() as $value => $text) {
			if ($value === $current || PoolLifecycle::canTransition($current, $value)) {
				$data['statuses'][$value] = $text;
			}
		}

		$data['user_token'] = $this->session->data['user_token'];

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/poolbuy/poolbuy/pool_form', $data));
	}

	/**
	 * Save
	 *
	 * @return void
	 */
	public function save(): void {
		$this->load->language('extension/poolbuy/poolbuy/pool');

		$json = [];

		if (!$this->user->hasPermission('modify', self::ROUTE)) {
			$json['error']['warning'] = $this->language->get('error_permission');
		}

		$required = [
			'pool_id'           => 0,
			'product_id'        => 0,
			'seller_id'         => 0,
			'title'             => '',
			'reference'         => '',
			'moq_target'        => 0,
			'status'            => PoolLifecycle::DRAFT,
			'date_start'        => '',
			'date_end'          => '',
			'min_qty_per_buyer' => 1,
			'max_qty_per_buyer' => 0,
			'currency_code'     => '',
			'pool_tier'         => []
		];

		$post_info = $this->request->post + $required;

		$pool_id = (int)$post_info['pool_id'];
		$product_id = (int)$post_info['product_id'];
		$seller_id = (int)$post_info['seller_id'];
		$moq_target = (int)$post_info['moq_target'];
		$status = (string)$post_info['status'];

		$this->load->model('extension/poolbuy/poolbuy/pool');

		// Product must exist, otherwise the pool would render an empty listing
		$this->load->model('catalog/product');

		if (!$product_id || !$this->model_catalog_product->getProduct($product_id)) {
			$json['error']['product'] = $this->language->get('error_product');
		}

		// Seller must exist, otherwise the storefront has no supplier to show
		$this->load->model('extension/poolbuy/poolbuy/seller');

		if (!$seller_id || !$this->model_extension_poolbuy_poolbuy_seller->getSeller($seller_id)) {
			$json['error']['seller'] = $this->language->get('error_seller');
		}

		if ($moq_target < 1) {
			$json['error']['moq_target'] = $this->language->get('error_moq_target');
		}

		$start = strtotime((string)$post_info['date_start']);
		$end = strtotime((string)$post_info['date_end']);

		if ($start === false || !$post_info['date_start']) {
			$json['error']['date_start'] = $this->language->get('error_date_start');
		}

		if ($end === false || !$post_info['date_end']) {
			$json['error']['date_end'] = $this->language->get('error_date_end');
		} elseif ($start !== false && $end <= $start) {
			$json['error']['date_end'] = $this->language->get('error_date_order');
		}

		$min_per_buyer = (int)$post_info['min_qty_per_buyer'];
		$max_per_buyer = (int)$post_info['max_qty_per_buyer'];

		if ($min_per_buyer < 1) {
			$json['error']['min_qty_per_buyer'] = $this->language->get('error_min_qty');
		}

		if ($max_per_buyer < 0 || ($max_per_buyer > 0 && $max_per_buyer < $min_per_buyer)) {
			$json['error']['max_qty_per_buyer'] = $this->language->get('error_max_qty');
		}

		if (!PoolLifecycle::isStatus($status)) {
			$json['error']['status'] = $this->language->get('error_status');
		}

		// Status transitions are checked against the state machine, not merely
		// against the list the form happened to render.
		if ($pool_id && PoolLifecycle::isStatus($status)) {
			$existing = $this->model_extension_poolbuy_poolbuy_pool->getPool($pool_id);

			if ($existing && !PoolLifecycle::canTransition((string)$existing['status'], $status)) {
				$json['error']['status'] = sprintf($this->language->get('error_transition'), $this->language->get('text_status_' . $existing['status']), $this->language->get('text_status_' . $status));
			}
		}

		// The price ladder is validated by the same domain rules the storefront
		// prices against, so an invalid ladder can never reach a buyer.
		$tiers = is_array($post_info['pool_tier']) ? $post_info['pool_tier'] : [];

		$tier_errors = PoolCalculator::validateTiers($tiers);

		if ($tier_errors) {
			$json['error']['pool_tier'] = implode(' ', $tier_errors);
		}

		// Reference: generate when blank, then enforce uniqueness
		$reference = strtoupper(trim((string)$post_info['reference']));

		if ($reference === '') {
			$reference = 'PB-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
		}

		if (!preg_match('/^[A-Z0-9\-]{3,32}$/', $reference)) {
			$json['error']['reference'] = $this->language->get('error_reference');
		}

		if (!$json) {
			$existing_reference = $this->model_extension_poolbuy_poolbuy_pool->getPoolByReference($reference);

			if ($existing_reference && (int)$existing_reference['pool_id'] !== $pool_id) {
				$json['error']['reference'] = $this->language->get('error_reference_unique');
			}
		}

		if (!$json) {
			$post_info['reference'] = $reference;

			// Fall back to the product name so a pool always has something to show
			if (trim((string)$post_info['title']) === '') {
				$product_info = $this->model_catalog_product->getProduct($product_id);

				$post_info['title'] = $product_info['name'] ?? $reference;
			}

			if (trim((string)$post_info['currency_code']) === '') {
				$post_info['currency_code'] = (string)$this->config->get('module_poolbuy_currency_code');
			}

			if ($pool_id) {
				$this->model_extension_poolbuy_poolbuy_pool->editPool($pool_id, $post_info);
			} else {
				$pool_id = $this->model_extension_poolbuy_poolbuy_pool->addPool($post_info);

				$json['pool_id'] = $pool_id;
			}

			// Keep the cached total honest after any administrative edit
			$this->model_extension_poolbuy_poolbuy_pool->recalculateReservedQty($pool_id);

			$json['reference'] = $reference;
			$json['success'] = $this->language->get('text_success');
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	/**
	 * Delete
	 *
	 * @return void
	 */
	public function delete(): void {
		$this->load->language('extension/poolbuy/poolbuy/pool');

		$json = [];

		if (!$this->user->hasPermission('modify', self::ROUTE)) {
			$json['error']['warning'] = $this->language->get('error_permission');
		}

		$selected = (isset($this->request->post['selected']) && is_array($this->request->post['selected'])) ? array_map('intval', $this->request->post['selected']) : [];

		if (!$selected) {
			$json['error']['warning'] = $this->language->get('error_selection');
		}

		if (!$json) {
			$this->load->model('extension/poolbuy/poolbuy/pool');

			// Deleting a pool that buyers have committed to would silently discard
			// their reservations, so that is refused outright.
			foreach ($selected as $pool_id) {
				$pool_info = $this->model_extension_poolbuy_poolbuy_pool->getPool($pool_id);

				if (!$pool_info) {
					continue;
				}

				if ((int)$pool_info['reserved_qty'] > 0) {
					$json['error']['warning'] = sprintf($this->language->get('error_has_reservations'), $pool_info['reference']);

					break;
				}
			}
		}

		if (!$json) {
			foreach ($selected as $pool_id) {
				$this->model_extension_poolbuy_poolbuy_pool->deletePool($pool_id);
			}

			$json['success'] = $this->language->get('text_success_delete');
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	/**
	 * Detail
	 *
	 * The expandable pool detail panel: reservation map, recent commitments and
	 * quick actions. Returned as a fragment so the list can load it inline.
	 *
	 * @return void
	 */
	public function detail(): void {
		$this->load->language('extension/poolbuy/poolbuy/pool');

		$this->response->setOutput($this->getDetail((int)($this->request->get['pool_id'] ?? 0)));
	}

	/**
	 * Get Detail
	 *
	 * @param int $pool_id
	 *
	 * @return string
	 */
	private function getDetail(int $pool_id): string {
		$this->load->model('extension/poolbuy/poolbuy/pool');

		$pool_info = $this->model_extension_poolbuy_poolbuy_pool->getPool($pool_id);

		if (!$pool_info) {
			return $this->load->view('extension/poolbuy/poolbuy/pool_detail', ['pool' => []]);
		}

		$this->load->model('extension/poolbuy/poolbuy/seller');
		$this->load->model('catalog/product');

		$tiers = $this->model_extension_poolbuy_poolbuy_pool->getTiers($pool_id);

		$moq = (int)$pool_info['moq_target'];
		$reserved = (int)$pool_info['reserved_qty'];

		$seller_info = $this->model_extension_poolbuy_poolbuy_seller->getSeller((int)$pool_info['seller_id']);
		$product_info = $this->model_catalog_product->getProduct((int)$pool_info['product_id']);

		$data['pool'] = [
			'pool_id'        => $pool_id,
			'title'          => $pool_info['title'] ?: ($product_info['name'] ?? ''),
			'reference'      => $pool_info['reference'],
			'moq_target'     => $moq,
			'reserved_qty'   => $reserved,
			'unit_label'     => $pool_info['unit_label'],
			'remaining'      => PoolCalculator::unitsRemaining($reserved, $moq),
			'fill'           => PoolCalculator::fillPercentage($reserved, $moq),
			'status'         => (string)$pool_info['status'],
			'status_text'    => $this->language->get('text_status_' . $pool_info['status']),
			'time_left'      => $this->timeLeft((string)$pool_info['date_end']),
			'seller_name'    => $seller_info['name'] ?? '',
			'location'       => $seller_info['location'] ?? '',
			'lead_time'      => $pool_info['lead_time'],
			'shipping_terms' => $pool_info['shipping_terms'],
			'edit'           => $this->url->link(self::ROUTE . '.form', 'user_token=' . $this->session->data['user_token'] . '&pool_id=' . $pool_id)
		];

		// The current tier and what the next milestone would unlock
		$active_tier = PoolCalculator::bestUnlockedTier($tiers, $reserved);
		$units_to_next = PoolCalculator::unitsToNextTier($tiers, $reserved);
		$next_tier = PoolCalculator::nextTier($tiers, $reserved);

		$data['pool']['unit_price_text'] = $active_tier ? $this->formatMoney((float)$active_tier['price']) : $this->language->get('text_no_tier');
		$data['pool']['units_to_next'] = $units_to_next;
		$data['pool']['next_tier_text'] = $next_tier ? $this->formatMoney((float)$next_tier['price']) : '';

		// ---- Reservation map. Each cell is one unit of the MOQ. Very large MOQs are
		// rendered as blocks rather than thousands of cells so the page stays usable.
		$cap = 200;

		if ($moq > $cap) {
			$unit_per_cell = (int)ceil($moq / $cap);
			$cells = (int)ceil($moq / $unit_per_cell);
			$filled = (int)floor($reserved / $unit_per_cell);
		} else {
			$unit_per_cell = 1;
			$cells = $moq;
			$filled = min($reserved, $moq);
		}

		$data['map'] = [
			'cells'         => max(0, $cells),
			'filled'        => max(0, min($filled, $cells)),
			'unit_per_cell' => $unit_per_cell,
			'scaled'        => $unit_per_cell > 1
		];

		// ---- Recent commitments. Buyers are shown as an opaque handle plus trust
		// signals, never as a name or email, so seller-side screens cannot be used
		// to harvest the buyer list.
		$data['reservations'] = [];

		foreach ($this->model_extension_poolbuy_poolbuy_pool->getReservations($pool_id) as $reservation) {
			$customer_id = (int)$reservation['customer_id'];

			$order_total = 0;

			if ($customer_id) {
				$query = $this->db->query("SELECT COUNT(*) AS `total` FROM `" . DB_PREFIX . "order` WHERE `customer_id` = '" . $customer_id . "' AND `order_status_id` > 0");

				$order_total = (int)$query->row['total'];
			}

			$data['reservations'][] = [
				'handle'      => sprintf($this->language->get('text_buyer_handle'), 1000 + $customer_id),
				'initials'    => 'B' . (($customer_id % 9) + 1),
				'quantity'    => (int)$reservation['quantity'],
				'status'      => (string)$reservation['status'],
				'status_text' => $this->language->get('text_reservation_' . $reservation['status']),
				'value_text'  => $this->formatMoney((float)$reservation['unit_price_locked'] * (int)$reservation['quantity']),
				'has_price'   => (float)$reservation['unit_price_locked'] > 0,
				'tier'        => $order_total >= 10 ? $this->language->get('text_gold_partner') : $this->language->get('text_gst_buyer'),
				'order_total' => $order_total,
				'ago'         => $this->timeAgo((string)$reservation['date_added'])
			];
		}

		$data['reservation_total'] = count($data['reservations']);

		// ---- Quick actions. Close Pool Early is the only one that mutates state
		// here; the rest link to existing OpenCart tooling rather than pretending
		// bespoke messaging exists.
		$data['can_close'] = (string)$pool_info['status'] === 'active';
		$data['close'] = $this->url->link(self::ROUTE . '.close', 'user_token=' . $this->session->data['user_token'] . '&pool_id=' . $pool_id);
		$data['orders'] = $this->url->link('sale/order', 'user_token=' . $this->session->data['user_token']);
		$data['product'] = $this->url->link('catalog/product.form', 'user_token=' . $this->session->data['user_token'] . '&product_id=' . (int)$pool_info['product_id']);
		$data['seller'] = $this->url->link('extension/poolbuy/poolbuy/seller.form', 'user_token=' . $this->session->data['user_token'] . '&seller_id=' . (int)$pool_info['seller_id']);

		// ---- Readiness callout
		$data['is_ready'] = $data['pool']['fill'] >= 80.0;

		$data['user_token'] = $this->session->data['user_token'];

		return $this->load->view('extension/poolbuy/poolbuy/pool_detail', $data);
	}

	/**
	 * Close
	 *
	 * Closes a still-filling pool early. Whether that lands on 'reached' or
	 * 'expired' is decided by the actual volume, not by the operator: a pool that
	 * met its MOQ becomes a live deal, one that did not is expired and its
	 * reservations released.
	 *
	 * @return void
	 */
	public function close(): void {
		$this->load->language('extension/poolbuy/poolbuy/pool');

		$json = [];

		if (!$this->user->hasPermission('modify', self::ROUTE)) {
			$json['error'] = $this->language->get('error_permission');
		}

		$pool_id = (int)($this->request->get['pool_id'] ?? 0);

		if (!$json) {
			$this->load->model('extension/poolbuy/poolbuy/pool');

			$pool_info = $this->model_extension_poolbuy_poolbuy_pool->getPool($pool_id);

			if (!$pool_info) {
				$json['error'] = $this->language->get('error_not_found');
			} elseif ((string)$pool_info['status'] !== 'active') {
				$json['error'] = $this->language->get('error_not_active');
			} else {
				$reserved = $this->model_extension_poolbuy_poolbuy_pool->recalculateReservedQty($pool_id);
				$moq = (int)$pool_info['moq_target'];

				$target = PoolCalculator::isMoqReached($reserved, $moq) ? PoolLifecycle::REACHED : PoolLifecycle::EXPIRED;

				$this->model_extension_poolbuy_poolbuy_pool->setStatus($pool_id, $target);

				$this->model_extension_poolbuy_poolbuy_pool->addEvent($pool_id, 'pool_closed_early', [
					'reserved_qty' => $reserved,
					'moq_target'   => $moq,
					'result'       => $target
				]);

				// A pool closed without meeting its MOQ must not leave buyers holding
				// commitments that will never be fulfilled.
				if ($target === PoolLifecycle::EXPIRED) {
					$this->model_extension_poolbuy_poolbuy_pool->releaseReservations($pool_id);
					$this->model_extension_poolbuy_poolbuy_pool->recalculateReservedQty($pool_id);
				}

				$json['success'] = sprintf($this->language->get('text_closed_' . $target), $reserved, $moq);
				$json['status'] = $target;
			}
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	/**
	 * Time Ago
	 *
	 * @param string $date
	 *
	 * @return string
	 */
	private function timeAgo(string $date): string {
		$timestamp = strtotime($date);

		if ($timestamp === false) {
			return '';
		}

		$seconds = max(0, time() - $timestamp);

		if ($seconds < 3600) {
			return sprintf($this->language->get('text_minutes_ago'), max(1, (int)floor($seconds / 60)));
		}

		if ($seconds < 86400) {
			return sprintf($this->language->get('text_hours_ago'), (int)floor($seconds / 3600));
		}

		return sprintf($this->language->get('text_days_ago'), (int)floor($seconds / 86400));
	}

	/**
	 * Status Options
	 *
	 * @return array<string, string>
	 */
	private function statusOptions(): array {
		$options = [];

		foreach (PoolLifecycle::statuses() as $status) {
			$options[$status] = $this->language->get('text_status_' . $status);
		}

		return $options;
	}

	/**
	 * Time Left
	 *
	 * Human readable countdown, or the past-due label when the window has closed.
	 *
	 * @param string $date_end
	 *
	 * @return string
	 */
	private function timeLeft(string $date_end): string {
		$end = strtotime($date_end);

		if ($end === false) {
			return '';
		}

		$seconds = $end - time();

		if ($seconds <= 0) {
			return $this->language->get('text_ended');
		}

		$days = (int)floor($seconds / 86400);
		$hours = (int)floor(($seconds % 86400) / 3600);
		$minutes = (int)floor(($seconds % 3600) / 60);

		if ($days > 0) {
			return sprintf($this->language->get('text_time_days'), $days, $hours);
		}

		return sprintf($this->language->get('text_time_hours'), $hours, $minutes);
	}

	/**
	 * Format Money
	 *
	 * @param float $value
	 *
	 * @return string
	 */
	private function formatMoney(float $value): string {
		$symbol = (string)$this->config->get('module_poolbuy_currency_symbol');

		return $symbol . number_format($value, 2);
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
				'text' => $this->language->get('text_extension'),
				'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module')
			],
			[
				'text' => $this->language->get('heading_title'),
				'href' => $this->url->link(self::ROUTE, 'user_token=' . $this->session->data['user_token'])
			]
		];
	}
}
