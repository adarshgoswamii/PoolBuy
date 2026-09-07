<?php
namespace Opencart\Catalog\Controller\Extension\Poolbuy;

use Opencart\System\Library\Extension\Poolbuy\PoolCalculator;
use Opencart\System\Library\Extension\Poolbuy\PoolLifecycle;
use Opencart\System\Library\Extension\Poolbuy\PoolPresenter;

/**
 * Class Seller
 *
 * Seller self-service portal.
 *
 * Route: index.php?route=extension/poolbuy/seller
 *
 * Identity is resolved ONCE per request from the signed-in customer account to a
 * seller profile, and that resolved seller_id is the only one ever used. Nothing
 * in the request can influence which seller's data is touched, so a crafted
 * seller_id or pool_id belonging to someone else simply finds nothing.
 *
 * @package Opencart\Catalog\Controller\Extension\Poolbuy
 */
class Seller extends \Opencart\System\Engine\Controller {
	private const LIMIT = 20;

	/**
	 * Index
	 *
	 * The seller's pool campaigns.
	 *
	 * @return void
	 */
	public function index(): void {
		$seller = $this->resolveSeller();

		if (!$seller) {
			return;
		}

		$language = (string)$this->config->get('config_language');
		$symbol = (string)$this->config->get('module_poolbuy_currency_symbol');
		$seller_id = (int)$seller['seller_id'];

		$this->document->setTitle($this->language->get('heading_title'));

		$this->load->model('extension/poolbuy/poolbuy/seller');
		$this->load->model('tool/image');

		$data['heading_title'] = $this->language->get('heading_title');
		$data['seller_name'] = (string)$seller['name'];
		$data['is_approved'] = (bool)$seller['status'];
		$data['pb_active'] = 'pools';

		$data['add'] = $this->url->link('extension/poolbuy/seller.form', 'language=' . $language);
		$data['marketplace'] = $this->url->link('extension/poolbuy/pool', 'language=' . $language);
		$data['account'] = $this->url->link('account/account', 'language=' . $language);

		$data['breadcrumbs'] = [
			['text' => $this->language->get('text_home'), 'href' => $this->url->link('common/home', 'language=' . $language)],
			['text' => $this->language->get('heading_title'), 'href' => $this->url->link('extension/poolbuy/seller', 'language=' . $language)]
		];

		// ---- Statistics, scoped to this seller
		$stats = $this->model_extension_poolbuy_poolbuy_seller->getStatistics($seller_id);

		$data['stats'] = [
			'total_volume' => PoolPresenter::money((float)$stats['total_volume'], $symbol),
			'active_pools' => (int)$stats['active_pools'],
			'average_fill' => (float)$stats['average_fill'],
			'closing_soon' => (int)$stats['closing_soon']
		];

		// ---- Campaigns
		$page = max(1, (int)($this->request->get['page'] ?? 1));

		$filter_status = isset($this->request->get['filter_status']) ? (string)$this->request->get['filter_status'] : '';

		if ($filter_status !== '' && !PoolLifecycle::isStatus($filter_status)) {
			$filter_status = '';
		}

		$filter_data = [
			'filter_status' => $filter_status,
			'start'         => ($page - 1) * self::LIMIT,
			'limit'         => self::LIMIT
		];

		$data['pools'] = [];

		foreach ($this->model_extension_poolbuy_poolbuy_seller->getPools($seller_id, $filter_data) as $row) {
			$tiers = $this->model_extension_poolbuy_poolbuy_seller->getTiers((int)$row['pool_id'], $seller_id);

			$view = PoolPresenter::present($row, $tiers, ['symbol' => $symbol]);

			$data['pools'][] = [
				'pool_id'           => (int)$row['pool_id'],
				'title'             => $view['title'],
				'reference'         => $view['reference'],
				'product_name'      => (string)$row['product_name'],
				'product_model'     => (string)$row['product_model'],
				'moq_target'        => $view['moq_target'],
				'reserved_qty'      => $view['reserved_qty'],
				'unit_label'        => $view['unit_label'],
				'fill'              => $view['fill'],
				'remaining'         => $view['remaining'],
				'status'            => $view['status'],
				'status_text'       => $this->language->get('text_status_' . $view['status']),
				'time_left'         => $view['time_left'],
				'seconds_left'      => $view['seconds_left'],
				'end_timestamp'     => $view['end_timestamp'],
				'is_urgent'         => $view['is_urgent'],
				'is_complete'       => $view['is_complete'],
				'progress_modifier' => PoolPresenter::progressModifier($view),
				'unit_price_text'   => $view['unit_price_text'],
				'thumb'             => $this->thumb((string)($row['product_image'] ?? ''), 120, 120),
				'edit'              => $this->url->link('extension/poolbuy/seller.form', 'language=' . $language . '&pool_id=' . (int)$row['pool_id']),
				'view'              => $this->url->link('extension/poolbuy/seller.pool', 'language=' . $language . '&pool_id=' . (int)$row['pool_id'])
			];
		}

		$total = $this->model_extension_poolbuy_poolbuy_seller->getTotalPools($seller_id, $filter_data);

		$data['pagination'] = $this->load->controller('common/pagination', [
			'total' => $total,
			'page'  => $page,
			'limit' => self::LIMIT,
			'url'   => $this->url->link('extension/poolbuy/seller', 'language=' . $language . ($filter_status ? '&filter_status=' . $filter_status : '') . '&page={page}')
		]);

		$data['results'] = sprintf(
			$this->language->get('text_pagination'),
			$total ? (($page - 1) * self::LIMIT) + 1 : 0,
			((($page - 1) * self::LIMIT) > ($total - self::LIMIT)) ? $total : ((($page - 1) * self::LIMIT) + self::LIMIT),
			$total,
			(int)ceil($total / self::LIMIT)
		);

		$data['filter_status'] = $filter_status;

		$data['statuses'] = [];

		foreach (PoolLifecycle::statuses() as $status) {
			$data['statuses'][] = [
				'code'   => $status,
				'text'   => $this->language->get('text_status_' . $status),
				'href'   => $this->url->link('extension/poolbuy/seller', 'language=' . $language . '&filter_status=' . $status),
				'active' => $status === $filter_status
			];
		}

		$data['all_href'] = $this->url->link('extension/poolbuy/seller', 'language=' . $language);

		$data += $this->flash();
		$data += $this->chrome();

		$this->response->setOutput($this->load->view('extension/poolbuy/poolbuy/seller_pools', $data));
	}

	/**
	 * Pool
	 *
	 * Detail view for one of the seller's own pools, including the anonymised
	 * commitment list.
	 *
	 * @return void
	 */
	public function pool(): void {
		$seller = $this->resolveSeller();

		if (!$seller) {
			return;
		}

		$language = (string)$this->config->get('config_language');
		$symbol = (string)$this->config->get('module_poolbuy_currency_symbol');
		$seller_id = (int)$seller['seller_id'];

		$pool_id = (int)($this->request->get['pool_id'] ?? 0);

		$this->load->model('extension/poolbuy/poolbuy/seller');

		$pool_info = $this->model_extension_poolbuy_poolbuy_seller->getPool($pool_id, $seller_id);

		// Not found and not-yours are deliberately the same outcome, so the portal
		// cannot be used to probe which pool ids exist.
		if (!$pool_info) {
			$this->session->data['poolbuy_error'] = $this->language->get('error_pool');

			$this->response->redirect($this->url->link('extension/poolbuy/seller', 'language=' . $language));

			return;
		}

		$this->document->setTitle($this->language->get('heading_title'));

		$tiers = $this->model_extension_poolbuy_poolbuy_seller->getTiers($pool_id, $seller_id);

		$view = PoolPresenter::present($pool_info, $tiers, ['symbol' => $symbol]);

		$data['pool'] = $view + [
			'status_text' => $this->language->get('text_status_' . $view['status']),
			'edit'        => $this->url->link('extension/poolbuy/seller.form', 'language=' . $language . '&pool_id=' . $pool_id)
		];

		$data['hint'] = PoolPresenter::hint($view);
		$data['progress_modifier'] = PoolPresenter::progressModifier($view);

		// Reservation map, capped so a large MOQ does not draw thousands of cells
		$cap = 200;
		$moq = (int)$view['moq_target'];
		$reserved = (int)$view['reserved_qty'];

		$unit_per_cell = $moq > $cap ? (int)ceil($moq / $cap) : 1;

		$data['map'] = [
			'cells'         => $unit_per_cell > 1 ? (int)ceil($moq / $unit_per_cell) : $moq,
			'filled'        => $unit_per_cell > 1 ? (int)floor($reserved / $unit_per_cell) : min($reserved, $moq),
			'unit_per_cell' => $unit_per_cell,
			'scaled'        => $unit_per_cell > 1
		];

		$data['tiers'] = [];

		foreach (PoolCalculator::tierLadder($tiers, $reserved) as $index => $tier) {
			$data['tiers'][] = [
				'position'   => $index + 1,
				'min_qty'    => $tier['min_qty'],
				'max_qty'    => $tier['max_qty'],
				'price_text' => PoolPresenter::money((float)$tier['price'], $symbol),
				'active'     => (bool)$tier['active'],
				'unlocked'   => (bool)$tier['unlocked']
			];
		}

		// Anonymised commitments
		$data['reservations'] = [];

		foreach ($this->model_extension_poolbuy_poolbuy_seller->getReservations($pool_id, $seller_id) as $row) {
			$data['reservations'][] = [
				'handle'      => sprintf($this->language->get('text_buyer_handle'), (int)$row['handle']),
				'quantity'    => (int)$row['quantity'],
				'value_text'  => PoolPresenter::money((float)$row['unit_price_locked'] * (int)$row['quantity'], $symbol),
				'has_price'   => (float)$row['unit_price_locked'] > 0,
				'status_text' => $this->language->get('text_reservation_' . $row['status']),
				'tier'        => (int)$row['order_total'] >= 10 ? $this->language->get('text_gold_partner') : $this->language->get('text_gst_buyer'),
				'order_total' => (int)$row['order_total'],
				'ago'         => $this->timeAgo((string)$row['date_added'])
			];
		}

		$data['back'] = $this->url->link('extension/poolbuy/seller', 'language=' . $language);

		$data += $this->flash();
		$data += $this->chrome();

		$this->response->setOutput($this->load->view('extension/poolbuy/poolbuy/seller_pool', $data));
	}

	/**
	 * Form
	 *
	 * Create or edit one of the seller's own pools.
	 *
	 * @return void
	 */
	public function form(): void {
		$seller = $this->resolveSeller();

		if (!$seller) {
			return;
		}

		$language = (string)$this->config->get('config_language');
		$seller_id = (int)$seller['seller_id'];

		$this->document->setTitle($this->language->get('heading_title'));

		$this->load->model('extension/poolbuy/poolbuy/seller');

		$pool_id = (int)($this->request->get['pool_id'] ?? 0);

		$pool_info = [];

		if ($pool_id) {
			$pool_info = $this->model_extension_poolbuy_poolbuy_seller->getPool($pool_id, $seller_id);

			if (!$pool_info) {
				$this->session->data['poolbuy_error'] = $this->language->get('error_pool');

				$this->response->redirect($this->url->link('extension/poolbuy/seller', 'language=' . $language));

				return;
			}
		}

		$duration = max(1, (int)$this->config->get('module_poolbuy_pool_duration'));

		$defaults = [
			'product_id'         => 0,
			'title'              => '',
			'moq_target'         => 100,
			'unit_label'         => 'Units',
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
		$data['reference'] = (string)($pool_info['reference'] ?? '');
		$data['reserved_qty'] = (int)($pool_info['reserved_qty'] ?? 0);
		$data['is_approved'] = (bool)$seller['status'];

		// Only the seller's own products may carry a pool
		$data['products'] = $this->model_extension_poolbuy_poolbuy_seller->getProducts($seller_id);

		// Tier ladder
		$data['pool_tiers'] = [];

		if ($pool_id) {
			foreach ($this->model_extension_poolbuy_poolbuy_seller->getTiers($pool_id, $seller_id) as $tier) {
				$data['pool_tiers'][] = [
					'min_qty' => (int)$tier['min_qty'],
					'max_qty' => $tier['max_qty'] === null ? '' : (int)$tier['max_qty'],
					'price'   => number_format((float)$tier['price'], 4, '.', '')
				];
			}
		}

		if (!$data['pool_tiers']) {
			$data['pool_tiers'] = [['min_qty' => 1, 'max_qty' => '', 'price' => '0.0000']];
		}

		// A seller may only move a pool between draft and active. Reached, closed,
		// expired and fulfilled are outcomes the platform decides, not the seller.
		$current = (string)$data['status'];

		$data['statuses'] = [];

		foreach ([PoolLifecycle::DRAFT, PoolLifecycle::ACTIVE, PoolLifecycle::CANCELLED] as $status) {
			if ($status === $current || PoolLifecycle::canTransition($current, $status)) {
				$data['statuses'][] = ['code' => $status, 'text' => $this->language->get('text_status_' . $status)];
			}
		}

		$data['locked'] = !in_array($current, [PoolLifecycle::DRAFT, PoolLifecycle::ACTIVE], true);

		$data['text_form'] = $pool_id ? $this->language->get('text_edit_pool') : $this->language->get('text_add_pool');
		$data['save'] = $this->url->link('extension/poolbuy/seller.save', 'language=' . $language . ($pool_id ? '&pool_id=' . $pool_id : ''));
		$data['back'] = $this->url->link('extension/poolbuy/seller', 'language=' . $language);

		$data['breadcrumbs'] = [
			['text' => $this->language->get('text_home'), 'href' => $this->url->link('common/home', 'language=' . $language)],
			['text' => $this->language->get('heading_title'), 'href' => $data['back']],
			['text' => $data['text_form'], 'href' => $data['save']]
		];

		$data += $this->flash();
		$data += $this->chrome();

		$this->response->setOutput($this->load->view('extension/poolbuy/poolbuy/seller_form', $data));
	}

	/**
	 * Save
	 *
	 * @return void
	 */
	public function save(): void {
		$seller = $this->resolveSeller(true);

		if (!$seller) {
			return;
		}

		$seller_id = (int)$seller['seller_id'];

		$json = [];

		$this->load->model('extension/poolbuy/poolbuy/seller');

		$pool_id = (int)($this->request->get['pool_id'] ?? 0);

		$post = $this->request->post;

		$product_id = (int)($post['product_id'] ?? 0);
		$moq_target = (int)($post['moq_target'] ?? 0);
		$status = (string)($post['status'] ?? PoolLifecycle::DRAFT);

		// A seller may only attach a pool to a product that is theirs
		if (!$product_id || !$this->model_extension_poolbuy_poolbuy_seller->ownsProduct($product_id, $seller_id)) {
			$json['error']['product'] = $this->language->get('error_product');
		}

		if ($moq_target < 1) {
			$json['error']['moq_target'] = $this->language->get('error_moq_target');
		}

		$start = strtotime((string)($post['date_start'] ?? ''));
		$end = strtotime((string)($post['date_end'] ?? ''));

		if (!$start) {
			$json['error']['date_start'] = $this->language->get('error_date_start');
		}

		if (!$end) {
			$json['error']['date_end'] = $this->language->get('error_date_end');
		} elseif ($start && $end <= $start) {
			$json['error']['date_end'] = $this->language->get('error_date_order');
		}

		$min_per_buyer = (int)($post['min_qty_per_buyer'] ?? 1);
		$max_per_buyer = (int)($post['max_qty_per_buyer'] ?? 0);

		if ($min_per_buyer < 1) {
			$json['error']['min_qty_per_buyer'] = $this->language->get('error_min_qty');
		}

		if ($max_per_buyer < 0 || ($max_per_buyer > 0 && $max_per_buyer < $min_per_buyer)) {
			$json['error']['max_qty_per_buyer'] = $this->language->get('error_max_qty');
		}

		// Sellers may only set draft, active or cancelled
		if (!in_array($status, [PoolLifecycle::DRAFT, PoolLifecycle::ACTIVE, PoolLifecycle::CANCELLED], true)) {
			$json['error']['status'] = $this->language->get('error_status');
		}

		// An unapproved seller may save drafts but never publish
		if ($status === PoolLifecycle::ACTIVE && empty($seller['status'])) {
			$json['error']['status'] = $this->language->get('error_not_approved');
		}

		$tier_errors = PoolCalculator::validateTiers(isset($post['pool_tier']) && is_array($post['pool_tier']) ? $post['pool_tier'] : []);

		if ($tier_errors) {
			$json['error']['pool_tier'] = implode(' ', $tier_errors);
		}

		// Editing an existing pool: confirm ownership and that it is still editable
		if (!$json && $pool_id) {
			$existing = $this->model_extension_poolbuy_poolbuy_seller->getPool($pool_id, $seller_id);

			if (!$existing) {
				$json['error']['warning'] = $this->language->get('error_pool');
			} elseif (!in_array((string)$existing['status'], [PoolLifecycle::DRAFT, PoolLifecycle::ACTIVE], true)) {
				$json['error']['warning'] = $this->language->get('error_locked');
			} elseif (!PoolLifecycle::canTransition((string)$existing['status'], $status)) {
				$json['error']['status'] = $this->language->get('error_status');
			}
		}

		if (!$json) {
			$post['currency_code'] = (string)$this->config->get('module_poolbuy_currency_code');

			if (trim((string)($post['title'] ?? '')) === '') {
				$this->load->model('catalog/product');

				$product_info = $this->model_catalog_product->getProduct($product_id);

				$post['title'] = $product_info['name'] ?? '';
			}

			if ($pool_id) {
				$this->model_extension_poolbuy_poolbuy_seller->editPool($pool_id, $seller_id, $post);
			} else {
				// The platform issues the reference, not the seller
				do {
					$reference = 'PB-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
				} while ($this->model_extension_poolbuy_poolbuy_seller->referenceExists($reference));

				$post['reference'] = $reference;

				$pool_id = $this->model_extension_poolbuy_poolbuy_seller->addPool($seller_id, $post);

				$json['pool_id'] = $pool_id;
			}

			$json['success'] = $this->language->get('text_saved');
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput((string)json_encode($json));
	}

	/**
	 * Resolve Seller
	 *
	 * Turns the signed-in customer into a seller profile, or ends the request.
	 * This is the single tenancy gate for the whole portal.
	 *
	 * @param bool $json respond as JSON rather than redirecting
	 *
	 * @return array<string, mixed> empty when access was denied and the response is already sent
	 */
	private function resolveSeller(bool $json = false): array {
		$this->load->language('extension/poolbuy/poolbuy/seller');

		$language = (string)$this->config->get('config_language');

		if (!$this->config->get('module_poolbuy_status')) {
			$this->respondDenied($json, $this->language->get('error_unavailable'));

			return [];
		}

		if (!$this->customer->isLogged()) {
			if ($json) {
				$this->respondDenied(true, $this->language->get('error_login'));

				return [];
			}

			$this->session->data['redirect'] = $this->url->link('extension/poolbuy/seller', 'language=' . $language);

			$this->response->redirect($this->url->link('account/login', 'language=' . $language));

			return [];
		}

		$this->load->model('extension/poolbuy/poolbuy/seller');

		$seller = $this->model_extension_poolbuy_poolbuy_seller->getSellerByCustomerId((int)$this->customer->getId());

		if (!$seller) {
			// A signed-in shopper who is not a seller gets a plain explanation rather
			// than a 403, since being a buyer is perfectly legitimate.
			if ($json) {
				$this->respondDenied(true, $this->language->get('error_not_seller'));

				return [];
			}

			$this->document->setTitle($this->language->get('heading_title'));

			$data = [
				'marketplace' => $this->url->link('extension/poolbuy/pool', 'language=' . $language),
				'contact'     => $this->url->link('information/contact', 'language=' . $language),
				'account'     => $this->url->link('account/account', 'language=' . $language)
			] + $this->chrome();

			$this->response->setOutput($this->load->view('extension/poolbuy/poolbuy/seller_denied', $data));

			return [];
		}

		return $seller;
	}

	/**
	 * Respond Denied
	 *
	 * @param bool   $json
	 * @param string $message
	 *
	 * @return void
	 */
	private function respondDenied(bool $json, string $message): void {
		if ($json) {
			$this->response->addHeader('Content-Type: application/json');
			$this->response->setOutput((string)json_encode(['error' => ['warning' => $message]]));

			return;
		}

		$this->response->addHeader($this->request->server['SERVER_PROTOCOL'] . ' 403 Forbidden');
		$this->response->setOutput($message);
	}

	/**
	 * Flash
	 *
	 * @return array<string, string>
	 */
	private function flash(): array {
		$out = ['success' => '', 'error' => ''];

		foreach (['success' => 'poolbuy_success', 'error' => 'poolbuy_error'] as $key => $session_key) {
			if (isset($this->session->data[$session_key])) {
				$out[$key] = (string)$this->session->data[$session_key];

				unset($this->session->data[$session_key]);
			}
		}

		return $out;
	}

	/**
	 * Chrome
	 *
	 * @return array<string, string>
	 */
	private function chrome(): array {
		return [
			'column_left'    => $this->load->controller('common/column_left'),
			'column_right'   => $this->load->controller('common/column_right'),
			'content_top'    => $this->load->controller('common/content_top'),
			'content_bottom' => $this->load->controller('common/content_bottom'),
			'footer'         => $this->load->controller('common/footer'),
			'header'         => $this->load->controller('common/header')
		];
	}

	/**
	 * Thumb
	 *
	 * @param string $image
	 * @param int    $width
	 * @param int    $height
	 *
	 * @return string
	 */
	private function thumb(string $image, int $width, int $height): string {
		$decoded = html_entity_decode($image, ENT_QUOTES, 'UTF-8');

		if ($image !== '' && is_file(DIR_IMAGE . $decoded)) {
			return $this->model_tool_image->resize($decoded, $width, $height);
		}

		return $this->model_tool_image->resize('placeholder.png', $width, $height);
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
}
