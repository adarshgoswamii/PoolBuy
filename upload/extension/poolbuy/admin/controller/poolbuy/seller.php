<?php
namespace Opencart\Admin\Controller\Extension\Poolbuy\Poolbuy;
/**
 * Class Seller
 *
 * Admin CRUD for PoolBuy seller profiles.
 *
 * @package Opencart\Admin\Controller\Extension\Poolbuy\Poolbuy
 */
class Seller extends \Opencart\System\Engine\Controller {
	/**
	 * Route of this controller, used for links and permission checks.
	 */
	private const ROUTE = 'extension/poolbuy/poolbuy/seller';

	/**
	 * Indian GSTIN: 2 digit state code, 5 letter PAN prefix, 4 digits, 1 letter,
	 * 1 alphanumeric entity code, the literal Z, then a checksum character.
	 */
	private const GSTIN_PATTERN = '/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/';

	/**
	 * Index
	 *
	 * @return void
	 */
	public function index(): void {
		$this->load->language('extension/poolbuy/poolbuy/seller');

		$this->document->setTitle($this->language->get('heading_title'));

		$data['breadcrumbs'] = $this->breadcrumbs();

		$data['add'] = $this->url->link(self::ROUTE . '.form', 'user_token=' . $this->session->data['user_token']);
		$data['delete'] = $this->url->link(self::ROUTE . '.delete', 'user_token=' . $this->session->data['user_token']);

		$data['list'] = $this->getList();

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/poolbuy/poolbuy/seller_list', $data));
	}

	/**
	 * List
	 *
	 * Ajax endpoint used when filtering, sorting or paginating.
	 *
	 * @return void
	 */
	public function list(): void {
		$this->load->language('extension/poolbuy/poolbuy/seller');

		$this->response->setOutput($this->getList());
	}

	/**
	 * Get List
	 *
	 * @return string
	 */
	private function getList(): string {
		$filter_name = isset($this->request->get['filter_name']) ? (string)$this->request->get['filter_name'] : '';
		$filter_gst_verified = isset($this->request->get['filter_gst_verified']) ? (string)$this->request->get['filter_gst_verified'] : '';
		$filter_status = isset($this->request->get['filter_status']) ? (string)$this->request->get['filter_status'] : '';

		$sort = isset($this->request->get['sort']) ? (string)$this->request->get['sort'] : 'name';
		$order = (isset($this->request->get['order']) && strtoupper((string)$this->request->get['order']) === 'DESC') ? 'DESC' : 'ASC';
		$page = max(1, (int)($this->request->get['page'] ?? 1));

		$limit = 10;

		$filter_data = [
			'filter_name'         => $filter_name,
			'filter_gst_verified' => $filter_gst_verified,
			'filter_status'       => $filter_status,
			'sort'                => $sort,
			'order'               => $order,
			'start'               => ($page - 1) * $limit,
			'limit'               => $limit
		];

		$this->load->model('extension/poolbuy/poolbuy/seller');

		$data['sellers'] = [];

		$results = $this->model_extension_poolbuy_poolbuy_seller->getSellers($filter_data);

		foreach ($results as $result) {
			$data['sellers'][] = [
				'seller_id'       => (int)$result['seller_id'],
				'name'            => $result['name'],
				'location'        => $result['location'],
				'gst_number'      => $result['gst_number'],
				'gst_verified'    => (bool)$result['gst_verified'],
				'verified_seller' => (bool)$result['verified_seller'],
				'rating'          => number_format((float)$result['rating'], 1),
				'rating_count'    => (int)$result['rating_count'],
				'status'          => (bool)$result['status'],
				'pool_total'      => $this->model_extension_poolbuy_poolbuy_seller->getTotalPoolsBySellerId((int)$result['seller_id']),
				'edit'            => $this->url->link(self::ROUTE . '.form', 'user_token=' . $this->session->data['user_token'] . '&seller_id=' . (int)$result['seller_id'])
			];
		}

		$seller_total = $this->model_extension_poolbuy_poolbuy_seller->getTotalSellers($filter_data);

		// Preserve the active filters across sort links and pagination
		$params = [
			'filter_name'         => $filter_name,
			'filter_gst_verified' => $filter_gst_verified,
			'filter_status'       => $filter_status
		];

		$url = '';

		foreach ($params as $key => $value) {
			if ($value !== '') {
				$url .= '&' . $key . '=' . urlencode($value);
			}
		}

		$data['sort'] = $sort;
		$data['order'] = $order;

		$reverse = ($order === 'ASC') ? 'DESC' : 'ASC';

		foreach (['name', 'location', 'rating', 'status'] as $column) {
			$data['sort_' . $column] = $this->url->link(self::ROUTE . '.list', 'user_token=' . $this->session->data['user_token'] . '&sort=' . $column . '&order=' . (($sort === $column) ? $reverse : 'ASC') . $url);
		}

		$data['pagination'] = $this->load->controller('common/pagination', [
			'total' => $seller_total,
			'page'  => $page,
			'limit' => $limit,
			'url'   => $this->url->link(self::ROUTE . '.list', 'user_token=' . $this->session->data['user_token'] . '&sort=' . $sort . '&order=' . $order . $url . '&page={page}')
		]);

		$data['results'] = sprintf($this->language->get('text_pagination'), $seller_total ? (($page - 1) * $limit) + 1 : 0, ((($page - 1) * $limit) > ($seller_total - $limit)) ? $seller_total : ((($page - 1) * $limit) + $limit), $seller_total, (int)ceil($seller_total / $limit));

		$data['filter_name'] = $filter_name;
		$data['filter_gst_verified'] = $filter_gst_verified;
		$data['filter_status'] = $filter_status;

		$data['user_token'] = $this->session->data['user_token'];
		$data['delete'] = $this->url->link(self::ROUTE . '.delete', 'user_token=' . $this->session->data['user_token']);

		return $this->load->view('extension/poolbuy/poolbuy/seller_list_body', $data);
	}

	/**
	 * Form
	 *
	 * @return void
	 */
	public function form(): void {
		$this->load->language('extension/poolbuy/poolbuy/seller');

		$this->document->setTitle($this->language->get('heading_title'));

		$seller_id = (int)($this->request->get['seller_id'] ?? 0);

		$data['breadcrumbs'] = $this->breadcrumbs();

		$data['breadcrumbs'][] = [
			'text' => $seller_id ? $this->language->get('text_edit') : $this->language->get('text_add'),
			'href' => $this->url->link(self::ROUTE . '.form', 'user_token=' . $this->session->data['user_token'] . ($seller_id ? '&seller_id=' . $seller_id : ''))
		];

		$data['text_form'] = $seller_id ? $this->language->get('text_edit') : $this->language->get('text_add');

		$data['save'] = $this->url->link(self::ROUTE . '.save', 'user_token=' . $this->session->data['user_token']);
		$data['back'] = $this->url->link(self::ROUTE, 'user_token=' . $this->session->data['user_token']);

		$seller_info = [];

		if ($seller_id) {
			$this->load->model('extension/poolbuy/poolbuy/seller');

			$seller_info = $this->model_extension_poolbuy_poolbuy_seller->getSeller($seller_id);
		}

		$defaults = [
			'name'            => '',
			'slug'            => '',
			'gst_number'      => '',
			'gst_verified'    => 0,
			'verified_seller' => 0,
			'rating'          => '0.0',
			'rating_count'    => 0,
			'description'     => '',
			'logo'            => '',
			'location'        => '',
			'customer_id'     => 0,
			'status'          => 1
		];

		foreach ($defaults as $key => $default) {
			$data[$key] = $seller_info[$key] ?? $default;
		}

		$data['seller_id'] = $seller_id;

		// The linked storefront account that may sign in to the seller portal.
		$data['customer_name'] = '';

		if (!empty($data['customer_id'])) {
			$this->load->model('customer/customer');

			$customer_info = $this->model_customer_customer->getCustomer((int)$data['customer_id']);

			if ($customer_info) {
				$data['customer_name'] = trim($customer_info['firstname'] . ' ' . $customer_info['lastname']) . ' (' . $customer_info['email'] . ')';
			}
		}

		// Factory gallery images
		$data['images'] = [];

		if (!empty($seller_info['images'])) {
			$decoded = json_decode((string)$seller_info['images'], true);

			if (is_array($decoded)) {
				$data['images'] = array_values(array_filter(array_map('strval', $decoded)));
			}
		}

		// Thumbnails for the logo and each gallery image
		$this->load->model('tool/image');

		$placeholder = $this->model_tool_image->resize('no_image.png', 100, 100);

		$data['placeholder'] = $placeholder;
		$data['logo_thumb'] = ($data['logo'] && is_file(DIR_IMAGE . html_entity_decode((string)$data['logo'], ENT_QUOTES, 'UTF-8'))) ? $this->model_tool_image->resize((string)$data['logo'], 100, 100) : $placeholder;

		$data['image_rows'] = [];

		foreach ($data['images'] as $image) {
			$data['image_rows'][] = [
				'image' => $image,
				'thumb' => is_file(DIR_IMAGE . html_entity_decode($image, ENT_QUOTES, 'UTF-8')) ? $this->model_tool_image->resize($image, 100, 100) : $placeholder
			];
		}

		// Assigned products
		$data['products'] = [];

		if ($seller_id) {
			foreach ($this->model_extension_poolbuy_poolbuy_seller->getProducts($seller_id) as $product) {
				$data['products'][] = [
					'product_id' => (int)$product['product_id'],
					'name'       => $product['name']
				];
			}
		}

		$data['user_token'] = $this->session->data['user_token'];

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/poolbuy/poolbuy/seller_form', $data));
	}

	/**
	 * Save
	 *
	 * @return void
	 */
	public function save(): void {
		$this->load->language('extension/poolbuy/poolbuy/seller');

		$json = [];

		if (!$this->user->hasPermission('modify', self::ROUTE)) {
			$json['error']['warning'] = $this->language->get('error_permission');
		}

		$required = [
			'seller_id'  => 0,
			'name'       => '',
			'slug'       => '',
			'gst_number' => '',
			'rating'     => 0,
			'location'   => ''
		];

		$post_info = $this->request->post + $required;

		$seller_id = (int)$post_info['seller_id'];
		$name = trim((string)$post_info['name']);
		$gst_number = strtoupper(trim((string)$post_info['gst_number']));

		if (!oc_validate_length($name, 3, 128)) {
			$json['error']['name'] = $this->language->get('error_name');
		}

		// An empty GST number is allowed for a seller that is not GST registered,
		// but anything supplied must be a structurally valid GSTIN.
		if ($gst_number !== '' && !preg_match(self::GSTIN_PATTERN, $gst_number)) {
			$json['error']['gst_number'] = $this->language->get('error_gst_number');
		}

		// Claiming GST verification without a number would put an unearned trust
		// badge on the storefront.
		if (!empty($post_info['gst_verified']) && $gst_number === '') {
			$json['error']['gst_number'] = $this->language->get('error_gst_required');
		}

		$rating = (float)$post_info['rating'];

		if ($rating < 0 || $rating > 5) {
			$json['error']['rating'] = $this->language->get('error_rating');
		}

		if (oc_strlen((string)$post_info['location']) > 128) {
			$json['error']['location'] = $this->language->get('error_location');
		}

		// A linked storefront account grants access to the seller portal, so it must
		// exist and must not already belong to a different seller.
		$customer_id = (int)($post_info['customer_id'] ?? 0);

		if ($customer_id) {
			$this->load->model('customer/customer');

			if (!$this->model_customer_customer->getCustomer($customer_id)) {
				$json['error']['customer'] = $this->language->get('error_customer');
			} else {
				$owner = $this->db->query("SELECT `seller_id` FROM `" . DB_PREFIX . "poolbuy_seller` WHERE `customer_id` = '" . $customer_id . "' AND `seller_id` != '" . $seller_id . "'");

				if ($owner->num_rows) {
					$json['error']['customer'] = $this->language->get('error_customer_taken');
				}
			}
		}

		// Slug: fall back to the name, then guarantee uniqueness
		$slug = $this->slugify((string)$post_info['slug'] !== '' ? (string)$post_info['slug'] : $name);

		if ($slug === '') {
			$json['error']['name'] = $this->language->get('error_name');
		}

		if (!$json) {
			$this->load->model('extension/poolbuy/poolbuy/seller');

			$existing = $this->model_extension_poolbuy_poolbuy_seller->getSellerBySlug($slug);

			if ($existing && (int)$existing['seller_id'] !== $seller_id) {
				$json['error']['slug'] = $this->language->get('error_slug_unique');
			}
		}

		if (!$json) {
			$post_info['name'] = $name;
			$post_info['slug'] = $slug;
			$post_info['gst_number'] = $gst_number;

			if ($seller_id) {
				$this->model_extension_poolbuy_poolbuy_seller->editSeller($seller_id, $post_info);
			} else {
				$json['seller_id'] = $this->model_extension_poolbuy_poolbuy_seller->addSeller($post_info);
			}

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
		$this->load->language('extension/poolbuy/poolbuy/seller');

		$json = [];

		if (!$this->user->hasPermission('modify', self::ROUTE)) {
			$json['error']['warning'] = $this->language->get('error_permission');
		}

		$selected = (isset($this->request->post['selected']) && is_array($this->request->post['selected'])) ? array_map('intval', $this->request->post['selected']) : [];

		if (!$selected) {
			$json['error']['warning'] = $this->language->get('error_selection');
		}

		if (!$json) {
			$this->load->model('extension/poolbuy/poolbuy/seller');

			foreach ($selected as $seller_id) {
				// Refuse to orphan pools from their supplier.
				if ($this->model_extension_poolbuy_poolbuy_seller->getTotalPoolsBySellerId($seller_id) > 0) {
					$seller_info = $this->model_extension_poolbuy_poolbuy_seller->getSeller($seller_id);

					$json['error']['warning'] = sprintf($this->language->get('error_has_pools'), $seller_info['name'] ?? (string)$seller_id);

					break;
				}
			}
		}

		if (!$json) {
			foreach ($selected as $seller_id) {
				$this->model_extension_poolbuy_poolbuy_seller->deleteSeller($seller_id);
			}

			$json['success'] = $this->language->get('text_success_delete');
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	/**
	 * Autocomplete
	 *
	 * Feeds the seller picker used by the pool form.
	 *
	 * @return void
	 */
	public function autocomplete(): void {
		$json = [];

		$filter_name = isset($this->request->get['filter_name']) ? (string)$this->request->get['filter_name'] : '';

		$this->load->model('extension/poolbuy/poolbuy/seller');

		$results = $this->model_extension_poolbuy_poolbuy_seller->getSellers([
			'filter_name'   => $filter_name,
			'filter_status' => 1,
			'start'         => 0,
			'limit'         => 10
		]);

		foreach ($results as $result) {
			$json[] = [
				'seller_id' => (int)$result['seller_id'],
				'name'      => strip_tags(html_entity_decode($result['name'], ENT_QUOTES, 'UTF-8'))
			];
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
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

	/**
	 * Slugify
	 *
	 * @param string $value
	 *
	 * @return string url safe slug
	 */
	private function slugify(string $value): string {
		$slug = strtolower(trim(html_entity_decode($value, ENT_QUOTES, 'UTF-8')));
		$slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';

		return trim($slug, '-');
	}
}
