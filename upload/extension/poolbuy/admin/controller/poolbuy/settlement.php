<?php
namespace Opencart\Admin\Controller\Extension\Poolbuy\Poolbuy;

/**
 * Class Settlement
 *
 * Platform-wide payment and settlement monitoring, plus payout marking.
 *
 * This is the operator's money screen: what buyers have paid, what is still
 * outstanding, what has been refunded, and what the platform owes each seller.
 *
 * Amounts are always presented per currency and never added across currencies,
 * because a total that mixes rupees and dollars is not a number anybody can act on.
 *
 * @package Opencart\Admin\Controller\Extension\Poolbuy\Poolbuy
 */
class Settlement extends \Opencart\System\Engine\Controller {
	/**
	 * Route of this controller, used for links and permission checks.
	 */
	private const ROUTE = 'extension/poolbuy/poolbuy/settlement';

	/**
	 * Index
	 *
	 * @return void
	 */
	public function index(): void {
		$this->load->language('extension/poolbuy/poolbuy/settlement');

		$this->document->setTitle($this->language->get('heading_title'));

		$data['breadcrumbs'] = $this->breadcrumbs();
		$data['list'] = $this->getList();

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/poolbuy/poolbuy/settlement_list', $data));
	}

	/**
	 * List
	 *
	 * @return void
	 */
	public function list(): void {
		$this->load->language('extension/poolbuy/poolbuy/settlement');

		$this->response->setOutput($this->getList());
	}

	/**
	 * Pay
	 *
	 * Marks one pending seller settlement as paid out.
	 *
	 * @return void
	 */
	public function pay(): void {
		$this->load->language('extension/poolbuy/poolbuy/settlement');

		$json = [];

		if (!$this->user->hasPermission('modify', self::ROUTE)) {
			$json['error']['warning'] = $this->language->get('error_permission');
		}

		$settlement_id = (int)($this->request->get['settlement_id'] ?? $this->request->post['settlement_id'] ?? 0);

		if (!$settlement_id) {
			$json['error']['warning'] = $this->language->get('error_settlement');
		}

		if (!$json) {
			$this->load->model('extension/poolbuy/poolbuy/settlement');

			if (!$this->model_extension_poolbuy_poolbuy_settlement->markPaid($settlement_id)) {
				// Either it does not exist or it was already paid. Both are refusals
				// rather than errors, and neither should look like a fresh payout.
				$json['error']['warning'] = $this->language->get('error_not_pending');
			} else {
				$json['success'] = $this->language->get('text_paid');
			}
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput((string)json_encode($json));
	}

	/**
	 * Get List
	 *
	 * @return string
	 */
	private function getList(): string {
		$filter_status = isset($this->request->get['filter_status']) ? (string)$this->request->get['filter_status'] : '';
		$filter_settlement_status = isset($this->request->get['filter_settlement_status']) ? (string)$this->request->get['filter_settlement_status'] : '';
		$filter_order_id = isset($this->request->get['filter_order_id']) ? (string)$this->request->get['filter_order_id'] : '';
		$filter_reference = isset($this->request->get['filter_reference']) ? (string)$this->request->get['filter_reference'] : '';

		$sort = isset($this->request->get['sort']) ? (string)$this->request->get['sort'] : 'date_added';
		$order = (isset($this->request->get['order']) && strtoupper((string)$this->request->get['order']) === 'ASC') ? 'ASC' : 'DESC';
		$page = max(1, (int)($this->request->get['page'] ?? 1));

		$limit = 10;

		$filter_data = [
			'filter_status'            => $filter_status,
			'filter_settlement_status' => $filter_settlement_status,
			'filter_order_id'          => $filter_order_id,
			'filter_reference'         => $filter_reference,
			'sort'                     => $sort,
			'order'                    => $order,
			'start'                    => ($page - 1) * $limit,
			'limit'                    => $limit
		];

		$this->load->model('extension/poolbuy/poolbuy/settlement');

		// ---- Payments
		$data['payments'] = [];

		foreach ($this->model_extension_poolbuy_poolbuy_settlement->getPayments($filter_data) as $row) {
			$data['payments'][] = [
				'payment_id'     => (int)$row['payment_id'],
				'order_id'       => (int)$row['order_id'],
				'gateway'        => (string)$row['gateway'],
				'gateway_ref'    => (string)$row['gateway_ref'],
				'refund_ref'     => (string)$row['refund_ref'],
				'amount'         => $this->money((float)$row['amount'], (string)$row['currency']),
				'currency'       => (string)$row['currency'],
				'status'         => (string)$row['status'],
				'status_text'    => $this->language->get('text_payment_' . $row['status']),
				'pool_reference' => (string)($row['pool_reference'] ?? ''),
				'seller_name'    => (string)($row['seller_name'] ?? ''),
				'date_added'     => date((string)$this->language->get('date_format_short'), (int)strtotime((string)$row['date_added'])),
				'order_href'     => $this->url->link('sale/order.info', 'user_token=' . $this->session->data['user_token'] . '&order_id=' . (int)$row['order_id'])
			];
		}

		$total_payments = $this->model_extension_poolbuy_poolbuy_settlement->getTotalPayments($filter_data);

		// ---- Settlements
		$data['settlements'] = [];

		foreach ($this->model_extension_poolbuy_poolbuy_settlement->getSettlements($filter_data) as $row) {
			$data['settlements'][] = [
				'settlement_id'  => (int)$row['settlement_id'],
				'seller_name'    => (string)($row['seller_name'] ?? ''),
				'pool_reference' => (string)($row['pool_reference'] ?? ''),
				'order_count'    => (int)$row['order_count'],
				'gross'          => $this->money((float)$row['gross'], (string)$row['currency']),
				'platform_fee'   => $this->money((float)$row['platform_fee'], (string)$row['currency']),
				'net'            => $this->money((float)$row['net'], (string)$row['currency']),
				'currency'       => (string)$row['currency'],
				'status'         => (string)$row['status'],
				'status_text'    => $this->language->get('text_settlement_' . $row['status']),
				'is_pending'     => (string)$row['status'] === 'pending',
				'date_added'     => date((string)$this->language->get('date_format_short'), (int)strtotime((string)$row['date_added'])),
				'pay'            => $this->url->link(self::ROUTE . '.pay', 'user_token=' . $this->session->data['user_token'] . '&settlement_id=' . (int)$row['settlement_id'])
			];
		}

		$total_settlements = $this->model_extension_poolbuy_poolbuy_settlement->getTotalSettlements($filter_data);

		// ---- Reporting summaries, grouped by currency
		$data['payment_totals'] = [];

		foreach ($this->model_extension_poolbuy_poolbuy_settlement->getPaymentTotals() as $row) {
			$data['payment_totals'][] = [
				'currency'    => (string)$row['currency'],
				'status'      => (string)$row['status'],
				'status_text' => $this->language->get('text_payment_' . $row['status']),
				'count'       => (int)$row['count'],
				'value'       => $this->money((float)$row['value'], (string)$row['currency'])
			];
		}

		$data['settlement_totals'] = [];

		foreach ($this->model_extension_poolbuy_poolbuy_settlement->getSettlementTotals() as $row) {
			$data['settlement_totals'][] = [
				'currency'     => (string)$row['currency'],
				'status'       => (string)$row['status'],
				'status_text'  => $this->language->get('text_settlement_' . $row['status']),
				'count'        => (int)$row['count'],
				'gross'        => $this->money((float)$row['gross'], (string)$row['currency']),
				'platform_fee' => $this->money((float)$row['platform_fee'], (string)$row['currency']),
				'net'          => $this->money((float)$row['net'], (string)$row['currency'])
			];
		}

		$url = '';

		foreach (['filter_status' => $filter_status, 'filter_settlement_status' => $filter_settlement_status, 'filter_order_id' => $filter_order_id, 'filter_reference' => $filter_reference] as $key => $value) {
			if ($value !== '') {
				$url .= '&' . $key . '=' . urlencode($value);
			}
		}

		$data['sort'] = $sort;
		$data['order'] = $order;

		$reverse = ($order === 'ASC') ? 'DESC' : 'ASC';

		foreach (['order_id', 'amount', 'status', 'date_added'] as $column) {
			$data['sort_' . $column] = $this->url->link(self::ROUTE . '.list', 'user_token=' . $this->session->data['user_token'] . '&sort=' . $column . '&order=' . (($sort === $column) ? $reverse : 'DESC') . $url);
		}

		$data['pagination'] = $this->load->controller('common/pagination', [
			'total' => $total_payments,
			'page'  => $page,
			'limit' => $limit,
			'url'   => $this->url->link(self::ROUTE . '.list', 'user_token=' . $this->session->data['user_token'] . $url . '&page={page}')
		]);

		$data['results'] = sprintf($this->language->get('text_pagination'), $total_payments ? (($page - 1) * $limit) + 1 : 0, min($page * $limit, $total_payments), $total_payments, (int)ceil($total_payments / $limit));

		$data['total_payments'] = $total_payments;
		$data['total_settlements'] = $total_settlements;
		$data['pending_settlements'] = $this->model_extension_poolbuy_poolbuy_settlement->getPendingSettlementCount();

		$data['filter_status'] = $filter_status;
		$data['filter_settlement_status'] = $filter_settlement_status;
		$data['filter_order_id'] = $filter_order_id;
		$data['filter_reference'] = $filter_reference;
		$data['user_token'] = $this->session->data['user_token'];
		$data['list_url'] = $this->url->link(self::ROUTE . '.list', 'user_token=' . $this->session->data['user_token']);

		return $this->load->view('extension/poolbuy/poolbuy/settlement_list_body', $data);
	}

	/**
	 * Money
	 *
	 * Formats an amount in the currency it was actually traded in. The configured
	 * PoolBuy symbol is used only when the row carries the configured currency, so a
	 * foreign-currency row is never dressed up with the wrong symbol.
	 *
	 * @param float  $value
	 * @param string $currency
	 *
	 * @return string
	 */
	private function money(float $value, string $currency): string {
		$configured = strtoupper((string)$this->config->get('module_poolbuy_currency_code'));
		$symbol = (string)$this->config->get('module_poolbuy_currency_symbol');

		if ($currency !== '' && strtoupper($currency) === $configured && $symbol !== '') {
			return $symbol . number_format($value, 2);
		}

		return trim(strtoupper($currency) . ' ' . number_format($value, 2));
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
