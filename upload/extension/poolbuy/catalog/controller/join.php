<?php
namespace Opencart\Catalog\Controller\Extension\Poolbuy;

use Opencart\System\Library\Extension\Poolbuy\PoolCalculator;
use Opencart\System\Library\Extension\Poolbuy\PoolPresenter;

/**
 * Class Join
 *
 * The four step commitment flow: quantity, price breakdown, shipping, confirm.
 *
 * Route: index.php?route=extension/poolbuy/join&pool_id=N
 *
 * Implemented as a server rendered multi step page rather than the JavaScript
 * modal in the design. The visual treatment is the same modal panel, but a real
 * page means the flow works without JavaScript, survives a refresh, and can be
 * returned to - which matters when the thing being submitted is a binding
 * purchase commitment.
 *
 * Nothing the browser sends is trusted: the quantity is re-clamped and the price
 * is recomputed from the database on every step and again inside the transaction
 * that writes the reservation.
 *
 * @package Opencart\Catalog\Controller\Extension\Poolbuy
 */
class Join extends \Opencart\System\Engine\Controller {
	private const STEP_QUANTITY = 1;
	private const STEP_BREAKDOWN = 2;
	private const STEP_SHIPPING = 3;
	private const STEP_CONFIRMED = 4;

	/**
	 * Index
	 *
	 * @return \Opencart\System\Engine\Action|null
	 */
	public function index(): ?\Opencart\System\Engine\Action {
		$this->load->language('extension/poolbuy/poolbuy/join');

		$language = (string)$this->config->get('config_language');

		$pool_id = (int)($this->request->get['pool_id'] ?? 0);

		// A commitment is tied to an account, so require a login and come back here
		if (!$this->customer->isLogged()) {
			$this->session->data['redirect'] = $this->url->link('extension/poolbuy/join', 'language=' . $language . '&pool_id=' . $pool_id);

			$this->response->redirect($this->url->link('account/login', 'language=' . $language));

			return null;
		}

		$this->load->model('extension/poolbuy/poolbuy/pool');
		$this->load->model('extension/poolbuy/poolbuy/reservation');

		$pool = $this->model_extension_poolbuy_poolbuy_pool->getPool($pool_id);

		if (!$pool) {
			return new \Opencart\System\Engine\Action('error/not_found');
		}

		$symbol = (string)$this->config->get('module_poolbuy_currency_symbol');
		$gst_rate = (float)$this->config->get('module_poolbuy_gst_rate');
		$fee_rate = (float)$this->config->get('module_poolbuy_platform_fee');

		$tiers = $this->model_extension_poolbuy_poolbuy_pool->getTiers($pool_id);

		$view = PoolPresenter::present($pool, $tiers, ['symbol' => $symbol]);

		$this->document->setTitle($this->language->get('heading_title'));

		$data['pool'] = $view;
		$data['hint'] = PoolPresenter::hint($view);
		$data['progress_modifier'] = PoolPresenter::progressModifier($view);

		$data['product_href'] = $this->url->link('product/product', 'language=' . $language . '&product_id=' . $view['product_id']);
		$data['marketplace'] = $this->url->link('extension/poolbuy/pool', 'language=' . $language);
		$data['account'] = $this->url->link('extension/poolbuy/account', 'language=' . $language);
		$data['action'] = $this->url->link('extension/poolbuy/join', 'language=' . $language . '&pool_id=' . $pool_id);
		$data['pool_id'] = $pool_id;

		$data['breadcrumbs'] = [
			['text' => $this->language->get('text_home'), 'href' => $this->url->link('common/home', 'language=' . $language)],
			['text' => $this->language->get('text_marketplace'), 'href' => $data['marketplace']],
			['text' => $this->language->get('heading_title'), 'href' => $data['action']]
		];

		// ---- Blocking conditions, checked before any step is rendered
		$existing = $this->model_extension_poolbuy_poolbuy_reservation->getActiveReservation($pool_id, (int)$this->customer->getId());

		$data['blocked'] = '';

		if ($existing) {
			$data['blocked'] = 'already_joined';
			$data['existing_quantity'] = (int)$existing['quantity'];
			$data['existing_reference'] = (string)$existing['reference'];
		} elseif (!$view['is_open']) {
			$data['blocked'] = $view['is_complete'] ? 'complete' : 'closed';
		}

		$data['step'] = self::STEP_QUANTITY;
		$data['error'] = '';
		$data['errors'] = [];

		if (!$data['blocked']) {
			$this->runFlow($data, $pool, $view, $tiers, $gst_rate, $fee_rate, $symbol);
		}

		$data['step_total'] = self::STEP_CONFIRMED;
		$data['step_title'] = $this->language->get('text_step_' . $data['step'] . '_title');
		$data['pb_active'] = 'pools';

		$data['column_left'] = $this->load->controller('common/column_left');
		$data['column_right'] = $this->load->controller('common/column_right');
		$data['content_top'] = $this->load->controller('common/content_top');
		$data['content_bottom'] = $this->load->controller('common/content_bottom');
		$data['footer'] = $this->load->controller('common/footer');
		$data['header'] = $this->load->controller('common/header');

		$this->response->setOutput($this->load->view('extension/poolbuy/poolbuy/join', $data));

		return null;
	}

	/**
	 * Run Flow
	 *
	 * Decides which step to render and, on the final step, writes the reservation.
	 *
	 * @param array<string, mixed>             $data     passed by reference and populated for the view
	 * @param array<string, mixed>             $pool
	 * @param array<string, mixed>             $view
	 * @param array<int, array<string, mixed>> $tiers
	 * @param float                            $gst_rate
	 * @param float                            $fee_rate
	 * @param string                           $symbol
	 *
	 * @return void
	 */
	private function runFlow(array &$data, array $pool, array $view, array $tiers, float $gst_rate, float $fee_rate, string $symbol): void {
		$pool_id = (int)$pool['pool_id'];
		$customer_id = (int)$this->customer->getId();

		$requested_step = (int)($this->request->post['step'] ?? 0);

		// Quantity always comes from the request, never from a hidden total, and is
		// clamped to what the pool can actually accept.
		$raw_quantity = $this->request->post['quantity'] ?? $this->request->get['quantity'] ?? $view['min_qty_per_buyer'];

		$quantity = PoolCalculator::clampQuantity(
			(int)$raw_quantity,
			(int)$view['min_qty_per_buyer'],
			(int)$view['max_qty_per_buyer'],
			(int)$view['remaining']
		);

		if ($quantity <= 0) {
			$data['blocked'] = 'full';

			return;
		}

		$data['quantity'] = $quantity;
		$data['quantity_min'] = (int)$view['min_qty_per_buyer'];
		$data['quantity_max'] = $view['max_qty_per_buyer'] > 0 ? min((int)$view['max_qty_per_buyer'], (int)$view['remaining']) : (int)$view['remaining'];
		$data['quantity_step'] = 5;

		// Price is always recomputed here. The forward looking quote is the price
		// the pool would be on once this buyer's units are added, so the figure
		// shown is the one they actually unlock.
		$unit_price = PoolCalculator::quoteUnitPrice($tiers, (int)$view['reserved_qty'], $quantity) ?? (float)$view['entry_price'];

		$data['unit_price'] = $unit_price;
		$data['unit_price_text'] = PoolPresenter::money($unit_price, $symbol);

		$breakdown = PoolCalculator::priceBreakdown($quantity, $unit_price, $gst_rate, $fee_rate);

		$data['breakdown'] = [
			'quantity'        => $breakdown['quantity'],
			'unit_price_text' => PoolPresenter::money($breakdown['unit_price'], $symbol),
			'subtotal_text'   => PoolPresenter::money($breakdown['subtotal'], $symbol),
			'gst_text'        => PoolPresenter::money($breakdown['gst'], $symbol),
			'fee_text'        => PoolPresenter::money($breakdown['platform_fee'], $symbol),
			'total_text'      => PoolPresenter::money($breakdown['total'], $symbol),
			'gst_rate'        => $gst_rate,
			'fee_rate'        => $fee_rate
		];

		// ---- Addresses for the shipping step. OpenCart 4.1 requires the customer id
		// explicitly, and passing it keeps the query scoped to this buyer.
		$this->load->model('account/address');

		$data['addresses'] = [];

		foreach ($this->model_account_address->getAddresses($customer_id) as $address) {
			$data['addresses'][] = [
				'address_id' => (int)$address['address_id'],
				'label'      => $this->formatAddress($address),
				'selected'   => (int)$address['address_id'] === (int)($this->request->post['address_id'] ?? $this->customer->getAddressId())
			];
		}

		$data['address_add'] = $this->url->link('account/address.form', 'language=' . $this->config->get('config_language'));

		$address_id = (int)($this->request->post['address_id'] ?? 0);

		// ---- Step routing. Each step validates before advancing.
		switch ($requested_step) {
			case self::STEP_QUANTITY:
				// Submitted the quantity, show the breakdown
				$data['step'] = self::STEP_BREAKDOWN;
				break;

			case self::STEP_BREAKDOWN:
				// Accepted the breakdown, choose shipping
				$data['step'] = self::STEP_SHIPPING;
				break;

			case self::STEP_SHIPPING:
				// Confirming. Validate the address, then commit.
				if (!$data['addresses']) {
					$data['step'] = self::STEP_SHIPPING;
					$data['errors']['address'] = $this->language->get('error_no_address');

					break;
				}

				$valid_address = false;

				foreach ($data['addresses'] as $address) {
					if ($address['address_id'] === $address_id) {
						$valid_address = true;

						break;
					}
				}

				if (!$valid_address) {
					$data['step'] = self::STEP_SHIPPING;
					$data['errors']['address'] = $this->language->get('error_address');

					break;
				}

				$result = $this->model_extension_poolbuy_poolbuy_reservation->addReservation($pool_id, $customer_id, $quantity, [
					'address_id'      => $address_id,
					'unit_price'      => $unit_price,
					'shipping_cost'   => 0.0,
					'shipping_method' => [
						'code'  => 'poolbuy.pool_freight',
						'title' => $this->language->get('text_freight_title'),
						'terms' => (string)$view['shipping_terms'],
						'lead'  => (string)$view['lead_time']
					]
				]);

				if ($result['success']) {
					$data['step'] = self::STEP_CONFIRMED;
					$data['reference'] = $result['reference'];
					$data['confirmed_quantity'] = $quantity;

					// Reflect the new pool state on the confirmation screen
					$data['pool']['reserved_qty'] = $result['reserved_qty'];
					$data['pool']['fill'] = PoolCalculator::fillPercentage($result['reserved_qty'], (int)$view['moq_target']);
					$data['pool']['remaining'] = PoolCalculator::unitsRemaining($result['reserved_qty'], (int)$view['moq_target']);
				} else {
					// Report the reason honestly rather than a generic failure
					$data['step'] = self::STEP_SHIPPING;
					$data['error'] = $this->language->get('error_' . $result['error']) ?: $this->language->get('error_exception');
				}

				break;

			default:
				$data['step'] = self::STEP_QUANTITY;
				break;
		}
	}

	/**
	 * Format Address
	 *
	 * A single line label for the address picker.
	 *
	 * @param array<string, mixed> $address
	 *
	 * @return string
	 */
	private function formatAddress(array $address): string {
		$parts = [
			trim(($address['firstname'] ?? '') . ' ' . ($address['lastname'] ?? '')),
			$address['company'] ?? '',
			$address['address_1'] ?? '',
			$address['address_2'] ?? '',
			$address['city'] ?? '',
			$address['zone'] ?? '',
			$address['postcode'] ?? '',
			$address['country'] ?? ''
		];

		return implode(', ', array_filter(array_map('trim', array_map('strval', $parts))));
	}
}
