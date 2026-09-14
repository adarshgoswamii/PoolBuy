<?php
namespace Opencart\Catalog\Controller\Extension\Poolbuy;

use Opencart\System\Library\Extension\Poolbuy\GatewayFactory;

/**
 * Class Payment
 *
 * Buyer-facing payment surface for pool orders raised at close, plus the gateway
 * webhook. Pool orders are created unpaid; this controller is how they get paid.
 *
 * Routes:
 *   extension/poolbuy/payment.link     redirect a logged-in buyer to their link
 *   extension/poolbuy/payment.pay      the mock hosted page (mock gateway only)
 *   extension/poolbuy/payment.return   buyer returns from the gateway
 *   extension/poolbuy/payment.webhook  gateway server-to-server confirmation
 *
 * Trust boundaries:
 *   - The buyer routes resolve ownership from the signed-in customer against the
 *     order behind the payment, so nobody can pay or inspect another's order.
 *   - The webhook authenticates by gateway signature and fails closed on a blank
 *     secret, mirroring the cron endpoint's rule.
 *
 * @package Opencart\Catalog\Controller\Extension\Poolbuy
 */
class Payment extends \Opencart\System\Engine\Controller {
	/**
	 * Link
	 *
	 * Sends a logged-in buyer to the payment link for one of their own pool
	 * orders. A missing or foreign order simply bounces to the dashboard.
	 *
	 * @return void
	 */
	public function link(): void {
		$language = (string)$this->config->get('config_language');

		$this->load->language('extension/poolbuy/poolbuy/account');

		if (!$this->config->get('module_poolbuy_status')) {
			$this->response->redirect($this->url->link('common/home', 'language=' . $language));

			return;
		}

		if (!$this->requireLogin()) {
			return;
		}

		$this->load->model('extension/poolbuy/poolbuy/payment');

		$order_id = (int)($this->request->get['order_id'] ?? 0);
		$customer_id = (int)$this->customer->getId();

		$payment = $this->model_extension_poolbuy_poolbuy_payment->getByOrder($order_id);

		// Unknown order, or an order belonging to somebody else. Both are reported
		// identically so this route cannot be used to probe which orders exist.
		if (!$payment || !$this->model_extension_poolbuy_poolbuy_payment->ownsOrder($order_id, $customer_id)) {
			$this->bounce('error', 'error_payment_missing');

			return;
		}

		$status = (string)$payment['status'];

		if ($status === 'paid') {
			$this->bounce('success', 'text_payment_already');

			return;
		}

		if ($status === 'refunded') {
			$this->bounce('success', 'text_payment_refunded_notice');

			return;
		}

		if ($status === 'failed') {
			$this->bounce('error', 'error_payment_failed');

			return;
		}

		// Pending, but the gateway never returned a payable link (for example it was
		// unreachable when the order was raised). Say so plainly rather than
		// pretending the payment does not exist.
		if ((string)$payment['link_url'] === '') {
			$this->bounce('error', 'error_payment_unavailable');

			return;
		}

		$this->response->redirect((string)$payment['link_url']);
	}

	/**
	 * Pay
	 *
	 * The mock gateway's stand-in for a hosted checkout page. It exists so the
	 * whole pay flow works end to end with no external provider: it settles the
	 * payment immediately and returns the buyer to their dashboard. Only reachable
	 * when the mock gateway is active AND the buyer owns the order.
	 *
	 * @return void
	 */
	public function pay(): void {
		$language = (string)$this->config->get('config_language');

		$this->load->language('extension/poolbuy/poolbuy/account');

		if (!$this->config->get('module_poolbuy_status')) {
			$this->response->redirect($this->url->link('common/home', 'language=' . $language));

			return;
		}

		if (!$this->requireLogin()) {
			return;
		}

		$this->load->model('extension/poolbuy/poolbuy/payment');

		$order_id = (int)($this->request->get['order_id'] ?? 0);
		$customer_id = (int)$this->customer->getId();

		$payment = $this->model_extension_poolbuy_poolbuy_payment->getByOrder($order_id);

		// Unknown order or one owned by another customer: identical response, so the
		// route reveals nothing about other people's orders.
		if (!$payment || !$this->model_extension_poolbuy_poolbuy_payment->ownsOrder($order_id, $customer_id)) {
			$this->bounce('error', 'error_payment_missing');

			return;
		}

		// Only the mock gateway serves its own hosted page; a real gateway hosts one.
		if ((string)$payment['gateway'] !== 'mock') {
			$this->bounce('error', 'error_payment_unavailable');

			return;
		}

		$status = (string)$payment['status'];

		if ($status === 'refunded') {
			$this->bounce('success', 'text_payment_refunded_notice');

			return;
		}

		if ($status === 'failed') {
			$this->bounce('error', 'error_payment_failed');

			return;
		}

		if ($this->model_extension_poolbuy_poolbuy_payment->markPaid((int)$payment['payment_id'])) {
			$this->onPaid($payment);

			$this->bounce('success', 'text_payment_success', '&filter_status=completed');

			return;
		}

		// markPaid() only moves a pending row, so getting here means it was already
		// settled by a concurrent webhook or a second click.
		$this->bounce('success', 'text_payment_already', '&filter_status=completed');
	}

	/**
	 * Return
	 *
	 * Where a real gateway sends the buyer after checkout. The buyer's return is
	 * never trusted to settle the payment - that is the webhook's job - so this
	 * only reports current status and returns them to the dashboard.
	 *
	 * @return void
	 */
	public function return(): void {
		$language = (string)$this->config->get('config_language');

		$this->load->language('extension/poolbuy/poolbuy/account');

		if (!$this->customer->isLogged()) {
			// Nothing to report to a visitor we cannot identify; send them to login
			// rather than leaving a blank page.
			$this->session->data['redirect'] = $this->url->link('extension/poolbuy/account', 'language=' . $language);

			$this->response->redirect($this->url->link('account/login', 'language=' . $language));

			return;
		}

		// A gateway that cancels or fails typically says so on the return URL. Honour
		// that hint for messaging only - the authoritative status still comes from
		// the payment row, which only the webhook may change.
		$outcome = strtolower((string)($this->request->get['outcome'] ?? ''));

		$this->load->model('extension/poolbuy/poolbuy/payment');

		$order_id = (int)($this->request->get['order_id'] ?? 0);
		$customer_id = (int)$this->customer->getId();

		$payment = $order_id > 0 ? $this->model_extension_poolbuy_poolbuy_payment->getByOrder($order_id) : null;
		$owns = $payment && $this->model_extension_poolbuy_poolbuy_payment->ownsOrder($order_id, $customer_id);

		if ($payment && $owns) {
			$status = (string)$payment['status'];

			if ($status === 'paid') {
				$this->bounce('success', 'text_payment_success', '&filter_status=completed');

				return;
			}

			if ($status === 'refunded') {
				$this->bounce('success', 'text_payment_refunded_notice', '&filter_status=completed');

				return;
			}

			if ($status === 'failed' || $outcome === 'failed') {
				$this->bounce('error', 'error_payment_failed', '&filter_status=completed');

				return;
			}

			if ($outcome === 'cancelled') {
				$this->bounce('error', 'error_payment_cancelled', '&filter_status=completed');

				return;
			}

			// Still pending: the webhook has not landed yet. Say we are confirming
			// rather than claiming success we cannot vouch for.
			$this->bounce('success', 'text_payment_return', '&filter_status=completed');

			return;
		}

		if ($outcome === 'cancelled') {
			$this->bounce('error', 'error_payment_cancelled', '&filter_status=completed');

			return;
		}

		if ($outcome === 'failed') {
			$this->bounce('error', 'error_payment_failed', '&filter_status=completed');

			return;
		}

		$this->bounce('success', 'text_payment_return', '&filter_status=completed');
	}

	/**
	 * Webhook
	 *
	 * Server-to-server confirmation from the gateway. Authenticated by signature;
	 * fails closed when the configured webhook secret is blank. Idempotent: a
	 * replayed webhook confirms nothing twice.
	 *
	 * @return void
	 */
	public function webhook(): void {
		$this->response->addHeader('Content-Type: application/json');

		if (!$this->config->get('module_poolbuy_status')) {
			$this->respond(404, ['error' => 'not_found']);

			return;
		}

		// Rate limited before signature verification, so an unauthenticated caller
		// cannot use this endpoint to brute force signatures without cost.
		$this->load->model('extension/poolbuy/poolbuy/rate_limit');

		$gate = $this->model_extension_poolbuy_poolbuy_rate_limit->check('payment.webhook');

		if (!$gate['allowed']) {
			$this->response->addHeader('Retry-After: ' . (int)$gate['retry_after']);
			$this->respond(429, ['error' => 'rate_limited', 'retry_after' => (int)$gate['retry_after']]);

			return;
		}

		$secret = (string)$this->config->get('module_poolbuy_razorpay_webhook_secret');

		// Fail closed: without a configured secret no webhook can ever authenticate.
		if ($secret === '') {
			$this->respond(403, ['error' => 'forbidden']);

			return;
		}

		$payload = (string)file_get_contents('php://input');
		$signature = (string)($this->request->server['HTTP_X_POOLBUY_SIGNATURE'] ?? $this->request->server['HTTP_X_RAZORPAY_SIGNATURE'] ?? '');

		$gateway = $this->gateway();

		if (!$gateway->verifyWebhookSignature($payload, $signature, $secret)) {
			$this->respond(403, ['error' => 'bad_signature']);

			return;
		}

		$event = $gateway->parseWebhook($payload);

		if ($event === null) {
			$this->respond(422, ['error' => 'unrecognised']);

			return;
		}

		$this->load->model('extension/poolbuy/poolbuy/payment');

		$payment = $this->model_extension_poolbuy_poolbuy_payment->getByGatewayRef((string)$event['reference']);

		if (!$payment) {
			$this->respond(404, ['error' => 'unknown_reference']);

			return;
		}

		if ((string)$event['status'] === 'paid') {
			if ($this->model_extension_poolbuy_poolbuy_payment->markPaid((int)$payment['payment_id'])) {
				$this->onPaid($payment);
			}

			$this->respond(200, ['status' => 'ok']);

			return;
		}

		$this->model_extension_poolbuy_poolbuy_payment->markFailed((int)$payment['payment_id']);

		$this->respond(200, ['status' => 'ok']);
	}

	/**
	 * Require Login
	 *
	 * Sends an anonymous visitor to login, remembering where they were heading.
	 *
	 * @return bool true when the caller may continue
	 */
	private function requireLogin(): bool {
		if ($this->customer->isLogged()) {
			return true;
		}

		$language = (string)$this->config->get('config_language');

		$this->session->data['redirect'] = $this->url->link('extension/poolbuy/account', 'language=' . $language);

		$this->response->redirect($this->url->link('account/login', 'language=' . $language));

		return false;
	}

	/**
	 * Bounce
	 *
	 * Every buyer-facing failure and success path ends the same way: put one
	 * message in the session flash the dashboard already renders, then redirect
	 * there. Centralising it is what makes the messaging consistent - no path can
	 * end in a blank page, a raw JSON body, or a silent redirect.
	 *
	 * @param string $type  error|success
	 * @param string $key   language key for the message
	 * @param string $extra extra query string appended to the dashboard link
	 *
	 * @return void
	 */
	private function bounce(string $type, string $key, string $extra = ''): void {
		$language = (string)$this->config->get('config_language');

		$slot = $type === 'error' ? 'poolbuy_error' : 'poolbuy_success';

		$this->session->data[$slot] = $this->language->get($key);

		$this->response->redirect($this->url->link('extension/poolbuy/account', 'language=' . $language . $extra));
	}

	/**
	 * On Paid
	 *
	 * Side effects that must happen exactly once when a payment settles: advance
	 * the order to the store's configured paid status, and notify the buyer.
	 * Called only by the code path that actually transitioned the payment.
	 *
	 * @param array<string, mixed> $payment
	 *
	 * @return void
	 */
	private function onPaid(array $payment): void {
		$order_id = (int)$payment['order_id'];

		$this->load->model('checkout/order');

		$status_id = (int)$this->config->get('config_complete_status_id');

		if ($status_id <= 0) {
			$status_id = (int)$this->config->get('config_processing_status_id');
		}

		if ($status_id > 0) {
			$this->model_checkout_order->addHistory($order_id, $status_id, 'PoolBuy payment received', true);
		}

		$this->notifyBuyer($order_id, 'payment_received');
	}

	/**
	 * Notify Buyer
	 *
	 * Emails the buyer behind an order about a payment event, reusing the same
	 * failure-tolerant transport as the lifecycle. Never throws.
	 *
	 * @param int    $order_id
	 * @param string $event
	 *
	 * @return void
	 */
	private function notifyBuyer(int $order_id, string $event): void {
		if (!$this->config->get('config_mail_engine')) {
			return;
		}

		$this->load->language('extension/poolbuy/poolbuy/mail');

		$query = $this->db->query("SELECT `email`, `firstname` FROM `" . DB_PREFIX . "order` WHERE `order_id` = '" . (int)$order_id . "'");

		if (!$query->num_rows || empty($query->row['email']) || !filter_var($query->row['email'], FILTER_VALIDATE_EMAIL)) {
			return;
		}

		$mail_option = [
			'parameter'     => $this->config->get('config_mail_parameter'),
			'smtp_hostname' => $this->config->get('config_mail_smtp_hostname'),
			'smtp_username' => $this->config->get('config_mail_smtp_username'),
			'smtp_password' => html_entity_decode((string)$this->config->get('config_mail_smtp_password'), ENT_QUOTES, 'UTF-8'),
			'smtp_port'     => $this->config->get('config_mail_smtp_port'),
			'smtp_timeout'  => $this->config->get('config_mail_smtp_timeout')
		];

		try {
			$mail = new \Opencart\System\Library\Mail((string)$this->config->get('config_mail_engine'), $mail_option);

			$mail->setTo((string)$query->row['email']);
			$mail->setFrom((string)$this->config->get('config_email'));
			$mail->setSender((string)$this->config->get('config_name'));
			$mail->setSubject(sprintf($this->language->get('subject_' . $event), (string)$order_id));
			$mail->setText(sprintf($this->language->get('body_' . $event), (string)$query->row['firstname'], (int)$order_id));
			$mail->send();
		} catch (\Throwable $e) {
			$this->log->write('PoolBuy payment notification failed for order ' . $order_id . ': ' . $e->getMessage());
		}
	}

	/**
	 * Gateway
	 *
	 * @return \Opencart\System\Library\Extension\Poolbuy\PaymentGatewayInterface
	 */
	private function gateway(): \Opencart\System\Library\Extension\Poolbuy\PaymentGatewayInterface {
		return GatewayFactory::make([
			'gateway'         => (string)$this->config->get('module_poolbuy_gateway'),
			'razorpay_key'    => (string)$this->config->get('module_poolbuy_razorpay_key'),
			'razorpay_secret' => (string)$this->config->get('module_poolbuy_razorpay_secret'),
			'base_url'        => (string)$this->config->get('config_url') . 'index.php?route=extension/poolbuy/payment.pay'
		]);
	}

	/**
	 * Respond
	 *
	 * @param int                  $code
	 * @param array<string, mixed> $body
	 *
	 * @return void
	 */
	private function respond(int $code, array $body): void {
		$this->response->addHeader($this->request->server['SERVER_PROTOCOL'] . ' ' . $code);
		$this->response->setOutput((string)json_encode($body));
	}
}
