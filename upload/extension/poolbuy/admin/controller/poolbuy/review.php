<?php
namespace Opencart\Admin\Controller\Extension\Poolbuy\Poolbuy;
/**
 * Class Review
 *
 * Moderation queue for PoolBuy buyer reviews.
 *
 * Operators approve or reject; they do not author or edit review text, because a
 * review that an operator can rewrite is not evidence of anything. Approving or
 * rejecting recomputes the seller's published rating from approved rows.
 *
 * @package Opencart\Admin\Controller\Extension\Poolbuy\Poolbuy
 */
class Review extends \Opencart\System\Engine\Controller {
	/**
	 * Route of this controller, used for links and permission checks.
	 */
	private const ROUTE = 'extension/poolbuy/poolbuy/review';

	/**
	 * Index
	 *
	 * @return void
	 */
	public function index(): void {
		$this->load->language('extension/poolbuy/poolbuy/review');

		$this->document->setTitle($this->language->get('heading_title'));

		$data['breadcrumbs'] = $this->breadcrumbs();
		$data['delete'] = $this->url->link(self::ROUTE . '.delete', 'user_token=' . $this->session->data['user_token']);
		$data['list'] = $this->getList();

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/poolbuy/poolbuy/review_list', $data));
	}

	/**
	 * List
	 *
	 * @return void
	 */
	public function list(): void {
		$this->load->language('extension/poolbuy/poolbuy/review');

		$this->response->setOutput($this->getList());
	}

	/**
	 * Get List
	 *
	 * @return string
	 */
	private function getList(): string {
		$filter_status = isset($this->request->get['filter_status']) ? (string)$this->request->get['filter_status'] : '';
		$filter_rating = isset($this->request->get['filter_rating']) ? (string)$this->request->get['filter_rating'] : '';

		$sort = isset($this->request->get['sort']) ? (string)$this->request->get['sort'] : 'date_added';
		$order = (isset($this->request->get['order']) && strtoupper((string)$this->request->get['order']) === 'ASC') ? 'ASC' : 'DESC';
		$page = max(1, (int)($this->request->get['page'] ?? 1));

		$limit = 10;

		$filter_data = [
			'filter_status' => $filter_status,
			'filter_rating' => $filter_rating,
			'sort'          => $sort,
			'order'         => $order,
			'start'         => ($page - 1) * $limit,
			'limit'         => $limit
		];

		$this->load->model('extension/poolbuy/poolbuy/review');

		$data['reviews'] = [];

		foreach ($this->model_extension_poolbuy_poolbuy_review->getReviews($filter_data) as $row) {
			$data['reviews'][] = [
				'review_id' => (int)$row['review_id'],
				'rating'    => (int)$row['rating'],
				'title'     => (string)$row['title'],
				// Trimmed for the list; the full text is on the detail screen.
				'excerpt'        => oc_substr(trim((string)$row['text']), 0, 160),
				'seller_name'    => (string)($row['seller_name'] ?? ''),
				'product_name'   => (string)($row['product_name'] ?? ''),
				'pool_reference' => (string)($row['pool_reference'] ?? ''),
				// Buyers are shown to operators as an opaque handle, the same
				// convention the seller portal uses.
				'buyer_handle' => sprintf($this->language->get('text_buyer_handle'), (int)$row['customer_id']),
				'status'       => (string)$row['status'],
				'status_text'  => $this->language->get('text_status_' . $row['status']),
				'date_added'   => date($this->language->get('date_format_short'), strtotime((string)$row['date_added'])),
				'approve'      => $this->url->link(self::ROUTE . '.approve', 'user_token=' . $this->session->data['user_token'] . '&review_id=' . (int)$row['review_id']),
				'reject'       => $this->url->link(self::ROUTE . '.reject', 'user_token=' . $this->session->data['user_token'] . '&review_id=' . (int)$row['review_id'])
			];
		}

		$total = $this->model_extension_poolbuy_poolbuy_review->getTotalReviews($filter_data);

		$url = '';

		foreach (['filter_status' => $filter_status, 'filter_rating' => $filter_rating] as $key => $value) {
			if ($value !== '') {
				$url .= '&' . $key . '=' . urlencode($value);
			}
		}

		$data['sort'] = $sort;
		$data['order'] = $order;

		$reverse = ($order === 'ASC') ? 'DESC' : 'ASC';

		foreach (['rating', 'status', 'date_added'] as $column) {
			$data['sort_' . $column] = $this->url->link(self::ROUTE . '.list', 'user_token=' . $this->session->data['user_token'] . '&sort=' . $column . '&order=' . (($sort === $column) ? $reverse : 'DESC') . $url);
		}

		$data['pagination'] = $this->load->controller('common/pagination', [
			'total' => $total,
			'page'  => $page,
			'limit' => $limit,
			'url'   => $this->url->link(self::ROUTE . '.list', 'user_token=' . $this->session->data['user_token'] . $url . '&page={page}')
		]);

		$data['results'] = sprintf($this->language->get('text_pagination'), $total ? (($page - 1) * $limit) + 1 : 0, min($page * $limit, $total), $total, (int)ceil($total / $limit));

		$data['pending_total'] = $this->model_extension_poolbuy_poolbuy_review->getPendingTotal();
		$data['filter_status'] = $filter_status;
		$data['filter_rating'] = $filter_rating;
		$data['user_token'] = $this->session->data['user_token'];

		return $this->load->view('extension/poolbuy/poolbuy/review_list_body', $data);
	}

	/**
	 * Approve
	 *
	 * @return void
	 */
	public function approve(): void {
		$this->moderate('approved');
	}

	/**
	 * Reject
	 *
	 * @return void
	 */
	public function reject(): void {
		$this->moderate('rejected');
	}

	/**
	 * Delete
	 *
	 * @return void
	 */
	public function delete(): void {
		$this->load->language('extension/poolbuy/poolbuy/review');

		$json = [];

		if (!$this->user->hasPermission('modify', self::ROUTE)) {
			$json['error']['warning'] = $this->language->get('error_permission');
		}

		$selected = $this->request->post['selected'] ?? [];

		if (!is_array($selected) || !$selected) {
			$json['error']['warning'] = $this->language->get('error_selection');
		}

		if (!$json) {
			$this->load->model('extension/poolbuy/poolbuy/review');

			foreach ($selected as $review_id) {
				$this->model_extension_poolbuy_poolbuy_review->deleteReview((int)$review_id);
			}

			$json['success'] = $this->language->get('text_success');
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput((string)json_encode($json));
	}

	/**
	 * Moderate
	 *
	 * @param string $status
	 *
	 * @return void
	 */
	private function moderate(string $status): void {
		$this->load->language('extension/poolbuy/poolbuy/review');

		$json = [];

		if (!$this->user->hasPermission('modify', self::ROUTE)) {
			$json['error']['warning'] = $this->language->get('error_permission');
		}

		$review_id = (int)($this->request->get['review_id'] ?? $this->request->post['review_id'] ?? 0);

		if (!$json) {
			$this->load->model('extension/poolbuy/poolbuy/review');

			if (!$this->model_extension_poolbuy_poolbuy_review->setStatus($review_id, $status)) {
				$json['error']['warning'] = $this->language->get('error_not_found');
			} else {
				$json['success'] = $this->language->get('text_' . $status);
			}
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput((string)json_encode($json));
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
