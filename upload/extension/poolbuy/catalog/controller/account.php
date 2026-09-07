<?php
namespace Opencart\Catalog\Controller\Extension\Poolbuy;

use Opencart\System\Library\Extension\Poolbuy\PoolPresenter;

/**
 * Class Account
 *
 * The buyer dashboard: commitments, progress, spend and savings.
 *
 * Route: index.php?route=extension/poolbuy/account
 *
 * Every query on this page is scoped to the logged in customer id taken from the
 * session, never from the request, so one buyer can never read another's
 * commitments by editing a URL.
 *
 * @package Opencart\Catalog\Controller\Extension\Poolbuy
 */
class Account extends \Opencart\System\Engine\Controller {
	private const LIMIT = 10;

	/**
	 * Index
	 *
	 * @return void
	 */
	public function index(): void {
		$this->load->language('extension/poolbuy/poolbuy/account');

		$language = (string)$this->config->get('config_language');

		if (!$this->customer->isLogged()) {
			$this->session->data['redirect'] = $this->url->link('extension/poolbuy/account', 'language=' . $language);

			$this->response->redirect($this->url->link('account/login', 'language=' . $language));

			return;
		}

		$this->document->setTitle($this->language->get('heading_title'));

		$customer_id = (int)$this->customer->getId();
		$symbol = (string)$this->config->get('module_poolbuy_currency_symbol');

		$this->load->model('extension/poolbuy/poolbuy/reservation');
		$this->load->model('extension/poolbuy/poolbuy/pool');
		$this->load->model('tool/image');

		$data['heading_title'] = $this->language->get('heading_title');
		$data['pb_active'] = 'pools';

		$data['marketplace'] = $this->url->link('extension/poolbuy/pool', 'language=' . $language);
		$data['contact'] = $this->url->link('information/contact', 'language=' . $language);
		$data['orders'] = $this->url->link('account/order', 'language=' . $language);
		$data['export'] = $this->url->link('extension/poolbuy/account.export', 'language=' . $language);

		$data['breadcrumbs'] = [
			['text' => $this->language->get('text_home'), 'href' => $this->url->link('common/home', 'language=' . $language)],
			['text' => $this->language->get('text_account'), 'href' => $this->url->link('account/account', 'language=' . $language)],
			['text' => $this->language->get('heading_title'), 'href' => $this->url->link('extension/poolbuy/account', 'language=' . $language)]
		];

		$data['customer_name'] = $this->customer->getFirstName() . ' ' . $this->customer->getLastName();

		// ---- Statistics
		$stats = $this->model_extension_poolbuy_poolbuy_reservation->getCustomerStatistics($customer_id);

		$data['stats'] = [
			'active_pools'     => (int)$stats['active_pools'],
			'joined_this_week' => (int)$stats['joined_this_week'],
			'completed_pools'  => (int)$stats['completed_pools'],
			'fulfilment_rate'  => (float)$stats['fulfilment_rate'],
			'spend_text'       => PoolPresenter::money((float)$stats['spend_ytd'], $symbol),
			'saving_text'      => PoolPresenter::money((float)$stats['saving_ytd'], $symbol),
			'has_saving'       => (float)$stats['saving_ytd'] > 0
		];

		// ---- Filter tabs
		$filter_status = isset($this->request->get['filter_status']) ? (string)$this->request->get['filter_status'] : 'active';

		if (!in_array($filter_status, ['active', 'completed', 'cancelled'], true)) {
			$filter_status = 'active';
		}

		$data['filter_status'] = $filter_status;

		$data['tabs'] = [];

		foreach (['active', 'completed', 'cancelled'] as $status) {
			$data['tabs'][] = [
				'code'   => $status,
				'text'   => $this->language->get('text_tab_' . $status),
				'active' => $status === $filter_status,
				'href'   => $this->url->link('extension/poolbuy/account', 'language=' . $language . '&filter_status=' . $status)
			];
		}

		// ---- Commitments
		$page = max(1, (int)($this->request->get['page'] ?? 1));

		$filter_data = [
			'filter_status' => $filter_status,
			'start'         => ($page - 1) * self::LIMIT,
			'limit'         => self::LIMIT
		];

		$data['reservations'] = [];

		foreach ($this->model_extension_poolbuy_poolbuy_reservation->getReservationsByCustomer($customer_id, $filter_data) as $row) {
			$tiers = $this->model_extension_poolbuy_poolbuy_pool->getTiers((int)$row['pool_id']);

			$view = PoolPresenter::present($row, $tiers, ['symbol' => $symbol]);

			$locked = (float)$row['unit_price_locked'];
			$quantity = (int)$row['quantity'];

			$data['reservations'][] = [
				'reservation_id'   => (int)$row['reservation_id'],
				'reference'        => (string)$row['reference'],
				'status'           => (string)$row['status'],
				'status_text'      => $this->language->get('text_reservation_' . $row['status']),
				'pool_status'      => (string)$row['pool_status'],
				'pool_status_text' => $this->language->get('text_pool_' . $row['pool_status']),
				'quantity'         => $quantity,
				'unit_label'       => (string)$row['unit_label'],
				// A reservation still awaiting repricing has no final figure, so the
				// committed value is shown as pending rather than as a false zero.
				'has_price'         => $locked > 0,
				'unit_price_text'   => PoolPresenter::money($locked, $symbol),
				'committed_text'    => PoolPresenter::money($locked * $quantity, $symbol),
				'title'             => $view['title'],
				'fill'              => $view['fill'],
				'reserved_qty'      => $view['reserved_qty'],
				'moq_target'        => $view['moq_target'],
				'remaining'         => $view['remaining'],
				'is_open'           => $view['is_open'],
				'is_urgent'         => $view['is_urgent'],
				'is_complete'       => $view['is_complete'],
				'progress_modifier' => PoolPresenter::progressModifier($view),
				'seller_name'       => $view['seller_name'],
				'time_left'         => $view['time_left'],
				'end_timestamp'     => $view['end_timestamp'],
				'seconds_left'      => $view['seconds_left'],
				'thumb'             => $this->thumb((string)($row['product_image'] ?? ''), 160, 160),
				'href'              => $this->url->link('product/product', 'language=' . $language . '&product_id=' . (int)$row['product_id']),
				// Withdrawal is only offered while the pool is still filling
				'can_cancel' => (string)$row['pool_status'] === 'active' && (string)$row['status'] === 'confirmed',
				'cancel'     => $this->url->link('extension/poolbuy/account.cancel', 'language=' . $language . '&reservation_id=' . (int)$row['reservation_id'])
			];
		}

		$total = $this->model_extension_poolbuy_poolbuy_reservation->getTotalReservationsByCustomer($customer_id, $filter_data);

		$data['pagination'] = $this->load->controller('common/pagination', [
			'total' => $total,
			'page'  => $page,
			'limit' => self::LIMIT,
			'url'   => $this->url->link('extension/poolbuy/account', 'language=' . $language . '&filter_status=' . $filter_status . '&page={page}')
		]);

		$data['results'] = sprintf(
			$this->language->get('text_pagination'),
			$total ? (($page - 1) * self::LIMIT) + 1 : 0,
			((($page - 1) * self::LIMIT) > ($total - self::LIMIT)) ? $total : ((($page - 1) * self::LIMIT) + self::LIMIT),
			$total,
			(int)ceil($total / self::LIMIT)
		);

		// ---- Recommendations: live pools this buyer has not already joined
		$data['recommendations'] = [];

		$joined = [];

		foreach ($this->model_extension_poolbuy_poolbuy_reservation->getReservationsByCustomer($customer_id) as $row) {
			$joined[(int)$row['pool_id']] = true;
		}

		foreach ($this->model_extension_poolbuy_poolbuy_pool->getPools(['filter_available' => 1, 'sort' => 'progress', 'limit' => 8]) as $row) {
			if (isset($joined[(int)$row['pool_id']])) {
				continue;
			}

			$tiers = $this->model_extension_poolbuy_poolbuy_pool->getTiers((int)$row['pool_id']);

			$view = PoolPresenter::present($row, $tiers, ['symbol' => $symbol]);

			$data['recommendations'][] = $view + [
				'thumb' => $this->thumb((string)($row['product_image'] ?? ''), 320, 200),
				'href'  => $this->url->link('product/product', 'language=' . $language . '&product_id=' . $view['product_id']),
				'join'  => $this->url->link('extension/poolbuy/join', 'language=' . $language . '&pool_id=' . $view['pool_id'])
			];

			if (count($data['recommendations']) >= 3) {
				break;
			}
		}

		// ---- Converted orders, so a buyer can trace a pool through to fulfilment
		$data['pool_orders'] = [];

		foreach ($this->model_extension_poolbuy_poolbuy_reservation->getReservationsByCustomer($customer_id, ['filter_status' => 'completed', 'limit' => 5]) as $row) {
			$data['pool_orders'][] = [
				'order_id'    => (int)$row['order_id'],
				'reference'   => (string)$row['reference'],
				'title'       => (string)($row['title'] ?: $row['product_name']),
				'quantity'    => (int)$row['quantity'],
				'unit_label'  => (string)$row['unit_label'],
				'amount_text' => PoolPresenter::money((float)$row['unit_price_locked'] * (int)$row['quantity'], $symbol),
				'status_text' => $this->language->get('text_reservation_' . $row['status']),
				'href'        => (int)$row['order_id'] > 0 ? $this->url->link('account/order.info', 'language=' . $language . '&order_id=' . (int)$row['order_id']) : ''
			];
		}

		$data['success'] = '';

		if (isset($this->session->data['poolbuy_success'])) {
			$data['success'] = $this->session->data['poolbuy_success'];

			unset($this->session->data['poolbuy_success']);
		}

		$data['error'] = '';

		if (isset($this->session->data['poolbuy_error'])) {
			$data['error'] = $this->session->data['poolbuy_error'];

			unset($this->session->data['poolbuy_error']);
		}

		$data['column_left'] = $this->load->controller('common/column_left');
		$data['column_right'] = $this->load->controller('common/column_right');
		$data['content_top'] = $this->load->controller('common/content_top');
		$data['content_bottom'] = $this->load->controller('common/content_bottom');
		$data['footer'] = $this->load->controller('common/footer');
		$data['header'] = $this->load->controller('common/header');

		$this->response->setOutput($this->load->view('extension/poolbuy/poolbuy/account', $data));
	}

	/**
	 * Cancel
	 *
	 * Withdraws the buyer's own commitment. The model scopes the update by
	 * customer id, so a crafted reservation_id belonging to someone else simply
	 * matches nothing.
	 *
	 * @return void
	 */
	public function cancel(): void {
		$this->load->language('extension/poolbuy/poolbuy/account');

		$language = (string)$this->config->get('config_language');

		if (!$this->customer->isLogged()) {
			$this->response->redirect($this->url->link('account/login', 'language=' . $language));

			return;
		}

		$reservation_id = (int)($this->request->get['reservation_id'] ?? 0);

		$this->load->model('extension/poolbuy/poolbuy/reservation');

		if ($this->model_extension_poolbuy_poolbuy_reservation->cancelReservation($reservation_id, (int)$this->customer->getId())) {
			$this->session->data['poolbuy_success'] = $this->language->get('text_cancel_success');
		} else {
			$this->session->data['poolbuy_error'] = $this->language->get('error_cancel');
		}

		$this->response->redirect($this->url->link('extension/poolbuy/account', 'language=' . $language));
	}

	/**
	 * Export
	 *
	 * Streams the buyer's own commitments as CSV.
	 *
	 * @return void
	 */
	public function export(): void {
		$this->load->language('extension/poolbuy/poolbuy/account');

		if (!$this->customer->isLogged()) {
			$this->response->redirect($this->url->link('account/login', 'language=' . $this->config->get('config_language')));

			return;
		}

		$customer_id = (int)$this->customer->getId();

		$this->load->model('extension/poolbuy/poolbuy/reservation');

		$rows = $this->model_extension_poolbuy_poolbuy_reservation->getReservationsByCustomer($customer_id);

		$handle = fopen('php://temp', 'r+');

		if ($handle === false) {
			$this->response->setOutput('');

			return;
		}

		fputcsv($handle, [
			$this->language->get('column_reference'),
			$this->language->get('column_pool'),
			$this->language->get('column_seller'),
			$this->language->get('column_quantity'),
			$this->language->get('column_unit_price'),
			$this->language->get('column_committed'),
			$this->language->get('column_status'),
			$this->language->get('column_pool_status'),
			$this->language->get('column_date')
		]);

		foreach ($rows as $row) {
			$quantity = (int)$row['quantity'];
			$unit = (float)$row['unit_price_locked'];

			fputcsv($handle, [
				(string)$row['reference'],
				(string)($row['title'] ?: $row['product_name']),
				(string)$row['seller_name'],
				$quantity,
				number_format($unit, 2, '.', ''),
				number_format($unit * $quantity, 2, '.', ''),
				(string)$row['status'],
				(string)$row['pool_status'],
				(string)$row['date_added']
			]);
		}

		rewind($handle);

		$csv = (string)stream_get_contents($handle);

		fclose($handle);

		$filename = 'poolbuy-commitments-' . date('Y-m-d') . '.csv';

		$this->response->addHeader('Content-Type: text/csv; charset=utf-8');
		$this->response->addHeader('Content-Disposition: attachment; filename="' . $filename . '"');
		$this->response->setOutput($csv);
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
}
