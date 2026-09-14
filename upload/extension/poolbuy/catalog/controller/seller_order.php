<?php
namespace Opencart\Catalog\Controller\Extension\Poolbuy;

/**
 * Class Seller Order
 *
 * Seller order processing: the orders this seller's pools generated.
 *
 * Route: index.php?route=extension/poolbuy/seller_order
 *
 * Identity is resolved ONCE per request from the signed-in customer account to a
 * seller profile, and that resolved seller_id is the only one ever used. A crafted
 * order_id belonging to another seller simply finds nothing, and a crafted status
 * change updates zero rows.
 *
 * Buyer privacy is preserved: the list shows an opaque handle, never a name or
 * email. Delivery details appear on the order detail only once the pool is
 * fulfilled, which is the promise made on the reservation map.
 *
 * @package Opencart\Catalog\Controller\Extension\Poolbuy
 */
class SellerOrder extends \Opencart\System\Engine\Controller {
	private const LIMIT = 20;

	/**
	 * Statuses a seller may move an order into. Deliberately a forward-only working
	 * set: refunds, chargebacks and the rest stay with the operator.
	 */
	private const ALLOWED_STATUSES = [2, 3, 5];

	/**
	 * Index
	 *
	 * @return void
	 */
	public function index(): void {
		$seller = $this->resolveSeller();

		if (!$seller) {
			return;
		}

		$language = (string)$this->config->get('config_language');
		$seller_id = (int)$seller['seller_id'];

		$this->document->setTitle($this->language->get('heading_title'));

		$this->load->model('extension/poolbuy/poolbuy/seller');

		$page = max(1, (int)($this->request->get['page'] ?? 1));

		$orders = $this->model_extension_poolbuy_poolbuy_seller->getOrders($seller_id, [
			'start' => ($page - 1) * self::LIMIT,
			'limit' => self::LIMIT
		]);

		$total = $this->model_extension_poolbuy_poolbuy_seller->getTotalOrders($seller_id);

		$symbol = (string)$this->config->get('module_poolbuy_currency_symbol');

		$rows = [];

		foreach ($orders as $order) {
			$rows[] = [
				'order_id'   => (int)$order['order_id'],
				'handle'     => sprintf($this->language->get('text_buyer_handle'), (int)$order['handle']),
				'pool'       => (string)$order['pool_reference'],
				'pool_title' => (string)$order['pool_title'],
				'quantity'   => (int)$order['quantity'],
				'unit_label' => (string)$order['unit_label'],
				'total'      => $symbol . number_format((float)$order['total'], 2),
				'status'     => (string)($order['order_status'] ?? ''),
				'tracking'   => (string)$order['tracking'],
				'date_added' => date((string)$this->language->get('date_format_short'), (int)strtotime((string)$order['date_added'])),
				'view'       => $this->url->link('extension/poolbuy/seller_order.view', 'language=' . $language . '&order_id=' . (int)$order['order_id'])
			];
		}

		$data['orders_list'] = $rows;
		$data['heading_title'] = $this->language->get('heading_title');
		$data['seller_name'] = (string)$seller['name'];
		$data['pb_active'] = 'orders';
		$data['tabs'] = $this->tabs('orders');

		$data['pools'] = $this->url->link('extension/poolbuy/seller', 'language=' . $language);
		$data['products_link'] = $this->url->link('extension/poolbuy/seller_product', 'language=' . $language);
		$data['orders'] = $this->url->link('extension/poolbuy/seller_order', 'language=' . $language);

		$data['pagination'] = $this->load->controller('common/pagination', [
			'total' => $total,
			'page'  => $page,
			'limit' => self::LIMIT,
			'url'   => $this->url->link('extension/poolbuy/seller_order', 'language=' . $language . '&page={page}')
		]);

		$data['results'] = sprintf(
			$this->language->get('text_pagination'),
			$total ? (($page - 1) * self::LIMIT) + 1 : 0,
			min($page * self::LIMIT, $total),
			$total,
			(int)ceil($total / self::LIMIT)
		);

		$data['breadcrumbs'] = [
			['text' => $this->language->get('text_home'), 'href' => $this->url->link('common/home', 'language=' . $language)],
			['text' => $this->language->get('text_portal'), 'href' => $data['pools']],
			['text' => $this->language->get('heading_title'), 'href' => $data['orders']]
		];

		$data += $this->flash();
		$data += $this->chrome();

		$this->response->setOutput($this->load->view('extension/poolbuy/poolbuy/seller_orders', $data));
	}

	/**
	 * View
	 *
	 * One order, with the fulfilment controls.
	 *
	 * @return void
	 */
	public function view(): void {
		$seller = $this->resolveSeller();

		if (!$seller) {
			return;
		}

		$language = (string)$this->config->get('config_language');
		$seller_id = (int)$seller['seller_id'];

		$this->load->model('extension/poolbuy/poolbuy/seller');

		$order_id = (int)($this->request->get['order_id'] ?? 0);

		$order = $this->model_extension_poolbuy_poolbuy_seller->getOrder($order_id, $seller_id);

		// Not this seller's order: report it as missing rather than forbidden, so the
		// screen does not confirm that somebody else's order id is real.
		if (!$order) {
			$this->session->data['poolbuy_error'] = $this->language->get('error_order_missing');

			$this->response->redirect($this->url->link('extension/poolbuy/seller_order', 'language=' . $language));

			return;
		}

		$this->renderOrder($seller, $order);
	}

	/**
	 * Status
	 *
	 * Advances the order and records tracking.
	 *
	 * @return void
	 */
	public function status(): void {
		$seller = $this->resolveSeller();

		if (!$seller) {
			return;
		}

		$language = (string)$this->config->get('config_language');
		$seller_id = (int)$seller['seller_id'];

		$this->load->model('extension/poolbuy/poolbuy/seller');

		$order_id = (int)($this->request->get['order_id'] ?? $this->request->post['order_id'] ?? 0);

		$order = $this->model_extension_poolbuy_poolbuy_seller->getOrder($order_id, $seller_id);

		if (!$order) {
			$this->session->data['poolbuy_error'] = $this->language->get('error_order_missing');

			$this->response->redirect($this->url->link('extension/poolbuy/seller_order', 'language=' . $language));

			return;
		}

		$order_status_id = (int)($this->request->post['order_status_id'] ?? 0);
		$tracking = trim((string)($this->request->post['tracking'] ?? ''));

		$errors = [];

		// Only the working set is accepted. Anything else, including a status that
		// exists in the store but is not offered here, is refused.
		if (!in_array($order_status_id, self::ALLOWED_STATUSES, true)) {
			$errors['order_status_id'] = $this->language->get('error_status');
		}

		if (oc_strlen($tracking) > 64) {
			$errors['tracking'] = $this->language->get('error_tracking');
		}

		if ($errors) {
			$this->renderOrder($seller, $order, $errors);

			return;
		}

		$comment = sprintf($this->language->get('text_history_comment'), (string)$order['pool_reference']);

		if (!$this->model_extension_poolbuy_poolbuy_seller->updateOrderStatus($order_id, $seller_id, $order_status_id, $tracking, $comment)) {
			$this->session->data['poolbuy_error'] = $this->language->get('error_order_missing');

			$this->response->redirect($this->url->link('extension/poolbuy/seller_order', 'language=' . $language));

			return;
		}

		$this->session->data['poolbuy_success'] = $this->language->get('text_status_updated');

		$this->response->redirect($this->url->link('extension/poolbuy/seller_order.view', 'language=' . $language . '&order_id=' . $order_id));
	}

	/**
	 * Render Order
	 *
	 * @param array<string, mixed>  $seller
	 * @param array<string, mixed>  $order
	 * @param array<string, string> $errors
	 *
	 * @return void
	 */
	private function renderOrder(array $seller, array $order, array $errors = []): void {
		$language = (string)$this->config->get('config_language');
		$seller_id = (int)$seller['seller_id'];
		$order_id = (int)$order['order_id'];
		$symbol = (string)$this->config->get('module_poolbuy_currency_symbol');

		$this->load->model('extension/poolbuy/poolbuy/seller');

		$totals = [];

		foreach ($this->model_extension_poolbuy_poolbuy_seller->getOrderTotals($order_id, $seller_id) as $total) {
			$totals[] = [
				'title' => (string)$total['title'],
				'value' => $symbol . number_format((float)$total['value'], 2)
			];
		}

		$statuses = [];

		foreach ($this->model_extension_poolbuy_poolbuy_seller->getOrderStatuses(self::ALLOWED_STATUSES) as $status) {
			$statuses[] = [
				'order_status_id' => (int)$status['order_status_id'],
				'name'            => (string)$status['name'],
				'selected'        => (int)$status['order_status_id'] === (int)$order['order_status_id']
			];
		}

		$data = [
			'heading_title'    => sprintf($this->language->get('text_order'), $order_id),
			'order_id'         => $order_id,
			'handle'           => sprintf($this->language->get('text_buyer_handle'), (int)$order['handle']),
			'pool'             => (string)$order['pool_reference'],
			'pool_title'       => (string)$order['pool_title'],
			'pool_status'      => (string)$order['pool_status'],
			'quantity'         => (int)$order['quantity'],
			'unit_label'       => (string)$order['unit_label'],
			'unit_price'       => $symbol . number_format((float)$order['unit_price_locked'], 2),
			'commitment'       => (string)$order['commitment_reference'],
			'order_status'     => (string)($order['order_status'] ?? ''),
			'tracking'         => (string)$order['tracking'],
			'shipping_method'  => (string)$order['shipping_method'],
			'date_added'       => date((string)$this->language->get('date_format_short'), (int)strtotime((string)$order['date_added'])),
			'totals'           => $totals,
			'statuses'         => $statuses,
			'address_released' => (bool)$order['address_released'],
			'errors'           => $errors,
			'pb_active'        => 'orders',
			'tabs'             => $this->tabs('orders'),
			'action'           => $this->url->link('extension/poolbuy/seller_order.status', 'language=' . $language . '&order_id=' . $order_id),
			'back'             => $this->url->link('extension/poolbuy/seller_order', 'language=' . $language),
			'pools'            => $this->url->link('extension/poolbuy/seller', 'language=' . $language),
			'products_link'    => $this->url->link('extension/poolbuy/seller_product', 'language=' . $language),
			'orders'           => $this->url->link('extension/poolbuy/seller_order', 'language=' . $language)
		];

		// Only present once the pool is fulfilled; the model has already blanked these.
		$data['address'] = [
			'name'     => trim((string)$order['shipping_firstname'] . ' ' . (string)$order['shipping_lastname']),
			'line1'    => (string)$order['shipping_address_1'],
			'line2'    => (string)$order['shipping_address_2'],
			'city'     => (string)$order['shipping_city'],
			'postcode' => (string)$order['shipping_postcode'],
			'zone'     => (string)$order['shipping_zone'],
			'country'  => (string)$order['shipping_country'],
			'phone'    => (string)$order['telephone']
		];

		$data['breadcrumbs'] = [
			['text' => $this->language->get('text_home'), 'href' => $this->url->link('common/home', 'language=' . $language)],
			['text' => $this->language->get('text_portal'), 'href' => $data['pools']],
			['text' => $this->language->get('heading_title'), 'href' => $data['orders']],
			['text' => $data['heading_title'], 'href' => $data['action']]
		];

		$this->document->setTitle($data['heading_title']);

		$data += $this->flash();
		$data += $this->chrome();

		$this->response->setOutput($this->load->view('extension/poolbuy/poolbuy/seller_order', $data));
	}

	/**
	 * Resolve Seller
	 *
	 * The single tenancy gate for this controller.
	 *
	 * @return array<string, mixed> empty when access was denied and a response is already sent
	 */
	private function resolveSeller(): array {
		$this->load->language('extension/poolbuy/poolbuy/seller_order');

		$language = (string)$this->config->get('config_language');

		if (!$this->config->get('module_poolbuy_status')) {
			$this->response->setOutput($this->language->get('error_unavailable'));

			return [];
		}

		if (!$this->customer->isLogged()) {
			$this->session->data['redirect'] = $this->url->link('extension/poolbuy/seller_order', 'language=' . $language);

			$this->response->redirect($this->url->link('account/login', 'language=' . $language));

			return [];
		}

		$this->load->model('extension/poolbuy/poolbuy/seller');

		$seller = $this->model_extension_poolbuy_poolbuy_seller->getSellerByCustomerId((int)$this->customer->getId());

		if (!$seller) {
			$this->load->language('extension/poolbuy/poolbuy/seller');

			$this->document->setTitle($this->language->get('heading_title'));

			$data = [
				'marketplace' => $this->url->link('extension/poolbuy/pool', 'language=' . $language),
				'register'    => $this->url->link('extension/poolbuy/seller.register', 'language=' . $language),
				'contact'     => $this->url->link('information/contact', 'language=' . $language),
				'account'     => $this->url->link('account/account', 'language=' . $language)
			] + $this->chrome();

			$this->response->setOutput($this->load->view('extension/poolbuy/poolbuy/seller_denied', $data));

			return [];
		}

		return $seller;
	}

	/**
	 * Tabs
	 *
	 * Rendered through load->view() rather than a Twig {% include %}, because
	 * OpenCart's Twig loader does not resolve extension-relative include paths.
	 *
	 * @param string $active
	 *
	 * @return string
	 */
	private function tabs(string $active): string {
		$language = (string)$this->config->get('config_language');

		return $this->load->view('extension/poolbuy/poolbuy/seller_tabs', [
			'pb_active'     => $active,
			'pools'         => $this->url->link('extension/poolbuy/seller', 'language=' . $language),
			'products_link' => $this->url->link('extension/poolbuy/seller_product', 'language=' . $language),
			'orders'        => $this->url->link('extension/poolbuy/seller_order', 'language=' . $language),
			'text_portal'   => $this->language->get('text_portal'),
			'tab_pools'     => $this->language->get('tab_pools'),
			'tab_products'  => $this->language->get('tab_products'),
			'tab_orders'    => $this->language->get('tab_orders')
		]);
	}

	/**
	 * Flash
	 *
	 * @return array<string, string>
	 */
	private function flash(): array {
		$out = ['success' => '', 'error' => ''];

		foreach (['poolbuy_success' => 'success', 'poolbuy_error' => 'error'] as $key => $target) {
			if (!empty($this->session->data[$key])) {
				$out[$target] = (string)$this->session->data[$key];

				unset($this->session->data[$key]);
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
}
