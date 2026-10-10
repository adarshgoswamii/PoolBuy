<?php
namespace Opencart\Catalog\Controller\Extension\Poolbuy;

/**
 * Class Review
 *
 * Buyer review submission.
 *
 * Routes:
 *   extension/poolbuy/review.add   POST a review for one of your own purchases
 *
 * Everything about whether a review is permitted is decided server side from the
 * signed-in customer and the reservation id. The form contributes only the rating
 * and the words; it cannot assert that the buyer is eligible.
 *
 * @package Opencart\Catalog\Controller\Extension\Poolbuy
 */
class Review extends \Opencart\System\Engine\Controller {
	/**
	 * Add
	 *
	 * @return void
	 */
	public function add(): void {
		$this->load->language('extension/poolbuy/poolbuy/account');

		$language = (string)$this->config->get('config_language');

		if (!$this->config->get('module_poolbuy_status')) {
			$this->response->redirect($this->url->link('common/home', 'language=' . $language));

			return;
		}

		if (!$this->customer->isLogged()) {
			$this->session->data['redirect'] = $this->url->link('extension/poolbuy/account', 'language=' . $language);

			$this->response->redirect($this->url->link('account/login', 'language=' . $language));

			return;
		}

		// A review changes state, so only accept it as a POST. A GET would make it
		// forgeable from an image tag or a link.
		if (strtoupper((string)($this->request->server['REQUEST_METHOD'] ?? '')) !== 'POST') {
			$this->bounce('error', 'error_review_method');

			return;
		}

		$customer_id = (int)$this->customer->getId();
		$reservation_id = (int)($this->request->post['reservation_id'] ?? 0);
		$rating = (int)($this->request->post['rating'] ?? 0);
		$title = (string)($this->request->post['title'] ?? '');
		$text = (string)($this->request->post['text'] ?? '');

		$this->load->model('extension/poolbuy/poolbuy/review');

		$result = $this->model_extension_poolbuy_poolbuy_review->addReview($reservation_id, $customer_id, $rating, $title, $text);

		if ($result['success']) {
			$this->bounce('success', 'text_review_submitted');

			return;
		}

		// One message per refusal reason.
		$key = 'error_review_' . $result['reason'];
		$message = $this->language->get($key);

		$this->bounce('error', $message !== $key ? $key : 'error_review_not_found');
	}

	/**
	 * Bounce
	 *
	 * Puts one message in the flash the dashboard already renders and returns the
	 * buyer there, so no path ends in a blank page or a raw response.
	 *
	 * @param string $type error|success
	 * @param string $key  language key
	 *
	 * @return void
	 */
	private function bounce(string $type, string $key): void {
		$language = (string)$this->config->get('config_language');

		$slot = $type === 'error' ? 'poolbuy_error' : 'poolbuy_success';

		$this->session->data[$slot] = $this->language->get($key);

		$this->response->redirect($this->url->link('extension/poolbuy/account', 'language=' . $language));
	}
}
