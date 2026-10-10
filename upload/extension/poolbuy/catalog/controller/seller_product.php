<?php
namespace Opencart\Catalog\Controller\Extension\Poolbuy;

/**
 * Class Seller Product
 *
 * Seller catalogue management: the seller's own products.
 *
 * Route: index.php?route=extension/poolbuy/seller_product
 *
 * Identity is resolved ONCE per request from the signed-in customer account to a
 * seller profile, and that resolved seller_id is the only one ever used. Nothing in
 * the request can influence which seller's catalogue is touched, so a crafted
 * product_id belonging to someone else simply finds nothing.
 *
 * Deliberately server-rendered POST rather than a JSON/fetch form, matching the
 * seller application and the join flow: it survives a refresh and works with
 * JavaScript unavailable.
 *
 * @package Opencart\Catalog\Controller\Extension\Poolbuy
 */
class SellerProduct extends \Opencart\System\Engine\Controller {
	private const LIMIT = 20;

	/**
	 * Allowed image uploads. The extension is checked against this list AND the file
	 * is sniffed with getimagesize, because a client-supplied MIME type is not
	 * evidence of anything.
	 */
	private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];
	private const IMAGE_MAX_BYTES = 2097152;

	/**
	 * Index
	 *
	 * The seller's own catalogue.
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
		$this->load->model('tool/image');

		$page = max(1, (int)($this->request->get['page'] ?? 1));

		$products = $this->model_extension_poolbuy_poolbuy_seller->getOwnProducts($seller_id, [
			'start' => ($page - 1) * self::LIMIT,
			'limit' => self::LIMIT
		]);

		$total = $this->model_extension_poolbuy_poolbuy_seller->getTotalOwnProducts($seller_id);

		$symbol = (string)$this->config->get('module_poolbuy_currency_symbol');

		$rows = [];

		foreach ($products as $product) {
			$rows[] = [
				'product_id' => (int)$product['product_id'],
				'name'       => (string)$product['name'],
				'model'      => (string)$product['model'],
				'price'      => $symbol . number_format((float)$product['price'], 2),
				'quantity'   => (int)$product['quantity'],
				'status'     => (bool)$product['status'],
				'thumb'      => $this->thumb((string)$product['image'], 80, 80),
				'edit'       => $this->url->link('extension/poolbuy/seller_product.form', 'language=' . $language . '&product_id=' . (int)$product['product_id'])
			];
		}

		$data['products'] = $rows;
		$data['heading_title'] = $this->language->get('heading_title');
		$data['is_approved'] = (bool)$seller['status'];
		$data['seller_name'] = (string)$seller['name'];
		$data['pb_active'] = 'products';

		$data['add'] = $this->url->link('extension/poolbuy/seller_product.form', 'language=' . $language);
		$data['pools'] = $this->url->link('extension/poolbuy/seller', 'language=' . $language);
		$data['orders'] = $this->url->link('extension/poolbuy/seller_order', 'language=' . $language);
		$data['products_link'] = $this->url->link('extension/poolbuy/seller_product', 'language=' . $language);
		$data['marketplace'] = $this->url->link('extension/poolbuy/pool', 'language=' . $language);
		$data['tabs'] = $this->tabs('products');

		$data['pagination'] = $this->load->controller('common/pagination', [
			'total' => $total,
			'page'  => $page,
			'limit' => self::LIMIT,
			'url'   => $this->url->link('extension/poolbuy/seller_product', 'language=' . $language . '&page={page}')
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
			['text' => $this->language->get('heading_title'), 'href' => $data['products_link']]
		];

		$data += $this->flash();
		$data += $this->chrome();

		$this->response->setOutput($this->load->view('extension/poolbuy/poolbuy/seller_products', $data));
	}

	/**
	 * Form
	 *
	 * Add or edit one of the seller's own products.
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

		$this->load->model('extension/poolbuy/poolbuy/seller');

		$product_id = (int)($this->request->get['product_id'] ?? 0);

		$product = [];

		if ($product_id) {
			$product = $this->model_extension_poolbuy_poolbuy_seller->getOwnProduct($product_id, $seller_id);

			// Not this seller's product: say it cannot be found rather than that it
			// belongs to somebody else.
			if (!$product) {
				$this->session->data['poolbuy_error'] = $this->language->get('error_product_missing');

				$this->response->redirect($this->url->link('extension/poolbuy/seller_product', 'language=' . $language));

				return;
			}
		}

		$this->renderForm($seller, $product_id, [
			'name'        => (string)($product['name'] ?? ''),
			'description' => (string)($product['description'] ?? ''),
			'model'       => (string)($product['model'] ?? ''),
			'price'       => $product ? number_format((float)$product['price'], 2, '.', '') : '',
			'quantity'    => (string)($product['quantity'] ?? '0'),
			'status'      => (bool)($product['status'] ?? false),
			'image'       => (string)($product['image'] ?? '')
		], []);
	}

	/**
	 * Save
	 *
	 * @return void
	 */
	public function save(): void {
		$seller = $this->resolveSeller();

		if (!$seller) {
			return;
		}

		$language = (string)$this->config->get('config_language');
		$seller_id = (int)$seller['seller_id'];

		$this->load->model('extension/poolbuy/poolbuy/seller');

		$product_id = (int)($this->request->get['product_id'] ?? $this->request->post['product_id'] ?? 0);

		// Editing: prove ownership before anything else is considered.
		if ($product_id && !$this->model_extension_poolbuy_poolbuy_seller->getOwnProduct($product_id, $seller_id)) {
			$this->session->data['poolbuy_error'] = $this->language->get('error_product_missing');

			$this->response->redirect($this->url->link('extension/poolbuy/seller_product', 'language=' . $language));

			return;
		}

		$post = [
			'name'        => trim((string)($this->request->post['name'] ?? '')),
			'description' => trim((string)($this->request->post['description'] ?? '')),
			'model'       => trim((string)($this->request->post['model'] ?? '')),
			'price'       => trim((string)($this->request->post['price'] ?? '')),
			'quantity'    => trim((string)($this->request->post['quantity'] ?? '0')),
			'status'      => !empty($this->request->post['status']),
			'image'       => ''
		];

		$errors = $this->validateProduct($post, (bool)$seller['status']);

		// An unapproved seller may draft products but never enable them, exactly as
		// they may draft pools but never publish.
		if (empty($seller['status'])) {
			$post['status'] = false;
		}

		$image = '';

		if (!empty($this->request->files['image']['name'])) {
			$image = $this->handleUpload($seller_id, $errors);
		}

		if ($errors) {
			$this->renderForm($seller, $product_id, $post, $errors);

			return;
		}

		$fields = [
			'name'        => $post['name'],
			'description' => $post['description'],
			'model'       => $post['model'],
			'price'       => (float)$post['price'],
			'quantity'    => (int)$post['quantity'],
			'status'      => $post['status'] ? 1 : 0,
			'image'       => $image
		];

		if ($product_id) {
			$this->model_extension_poolbuy_poolbuy_seller->editProduct($product_id, $seller_id, $fields);
			$this->session->data['poolbuy_success'] = $this->language->get('text_saved');
		} else {
			$this->model_extension_poolbuy_poolbuy_seller->addProduct($seller_id, $fields);
			$this->session->data['poolbuy_success'] = $this->language->get('text_added');
		}

		$this->response->redirect($this->url->link('extension/poolbuy/seller_product', 'language=' . $language));
	}

	/**
	 * Validate Product
	 *
	 * @param array<string, mixed> $post
	 * @param bool                 $approved
	 *
	 * @return array<string, string> field => message
	 */
	private function validateProduct(array $post, bool $approved): array {
		$errors = [];

		$name = (string)$post['name'];

		if (oc_strlen($name) < 3 || oc_strlen($name) > 255) {
			$errors['name'] = $this->language->get('error_name');
		}

		if (oc_strlen((string)$post['model']) > 64) {
			$errors['model'] = $this->language->get('error_model');
		}

		$price = (string)$post['price'];

		if ($price === '' || !is_numeric($price) || (float)$price < 0) {
			$errors['price'] = $this->language->get('error_price');
		}

		$quantity = (string)$post['quantity'];

		if ($quantity === '' || !ctype_digit($quantity)) {
			$errors['quantity'] = $this->language->get('error_quantity');
		}

		if (oc_strlen((string)$post['description']) > 20000) {
			$errors['description'] = $this->language->get('error_description');
		}

		if (!empty($post['status']) && !$approved) {
			$errors['status'] = $this->language->get('error_not_approved');
		}

		return $errors;
	}

	/**
	 * Handle Upload
	 *
	 * Stores a primary product image. Nothing the browser claims is trusted: the
	 * extension must be in the allow list, getimagesize must agree that the file is
	 * really an image, and the stored name is generated rather than taken from the
	 * upload.
	 *
	 * @param int                   $seller_id
	 * @param array<string, string> $errors    appended to on failure
	 *
	 * @return string the stored path relative to image/, or '' when nothing was stored
	 */
	private function handleUpload(int $seller_id, array &$errors): string {
		$file = $this->request->files['image'];

		if (!empty($file['error']) || !is_uploaded_file((string)$file['tmp_name'])) {
			$errors['image'] = $this->language->get('error_upload');

			return '';
		}

		if ((int)$file['size'] > self::IMAGE_MAX_BYTES) {
			$errors['image'] = $this->language->get('error_image_size');

			return '';
		}

		$extension = strtolower((string)pathinfo((string)$file['name'], PATHINFO_EXTENSION));

		if (!in_array($extension, self::IMAGE_EXTENSIONS, true)) {
			$errors['image'] = $this->language->get('error_image_type');

			return '';
		}

		// Sniff the actual bytes. A .png that is not an image fails here.
		$info = @getimagesize((string)$file['tmp_name']);

		if (!$info || empty($info['mime']) || !in_array((string)$info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)) {
			$errors['image'] = $this->language->get('error_image_type');

			return '';
		}

		$relative = 'catalog/poolbuy/seller_' . $seller_id;
		$directory = DIR_IMAGE . $relative;

		if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
			$errors['image'] = $this->language->get('error_upload');

			return '';
		}

		$filename = bin2hex(random_bytes(16)) . '.' . $extension;

		if (!move_uploaded_file((string)$file['tmp_name'], $directory . '/' . $filename)) {
			$errors['image'] = $this->language->get('error_upload');

			return '';
		}

		return $relative . '/' . $filename;
	}

	/**
	 * Render Form
	 *
	 * @param array<string, mixed>  $seller
	 * @param int                   $product_id
	 * @param array<string, mixed>  $post
	 * @param array<string, string> $errors
	 *
	 * @return void
	 */
	private function renderForm(array $seller, int $product_id, array $post, array $errors): void {
		$language = (string)$this->config->get('config_language');

		$this->load->model('tool/image');

		$data = $post;

		$data['errors'] = $errors;
		$data['product_id'] = $product_id;
		$data['is_approved'] = (bool)$seller['status'];
		$data['pb_active'] = 'products';
		$data['text_form'] = $product_id ? $this->language->get('text_edit_product') : $this->language->get('text_add_product');
		$data['heading_title'] = $this->language->get('heading_title');
		$data['thumb'] = $this->thumb((string)($post['image'] ?? ''), 160, 160);

		$data['action'] = $this->url->link('extension/poolbuy/seller_product.save', 'language=' . $language . ($product_id ? '&product_id=' . $product_id : ''));
		$data['back'] = $this->url->link('extension/poolbuy/seller_product', 'language=' . $language);
		$data['pools'] = $this->url->link('extension/poolbuy/seller', 'language=' . $language);
		$data['orders'] = $this->url->link('extension/poolbuy/seller_order', 'language=' . $language);
		$data['products_link'] = $data['back'];
		$data['marketplace'] = $this->url->link('extension/poolbuy/pool', 'language=' . $language);
		$data['tabs'] = $this->tabs('products');

		$data['breadcrumbs'] = [
			['text' => $this->language->get('text_home'), 'href' => $this->url->link('common/home', 'language=' . $language)],
			['text' => $this->language->get('text_portal'), 'href' => $data['pools']],
			['text' => $this->language->get('heading_title'), 'href' => $data['back']],
			['text' => $data['text_form'], 'href' => $data['action']]
		];

		$this->document->setTitle($data['text_form']);

		$data += $this->chrome();

		$this->response->setOutput($this->load->view('extension/poolbuy/poolbuy/seller_product_form', $data));
	}

	/**
	 * Resolve Seller
	 *
	 * The single tenancy gate for this controller. Mirrors the resolver in the main
	 * seller portal controller.
	 *
	 * @return array<string, mixed> empty when access was denied and a response is already sent
	 */
	private function resolveSeller(): array {
		$this->load->language('extension/poolbuy/poolbuy/seller_product');

		$language = (string)$this->config->get('config_language');

		if (!$this->config->get('module_poolbuy_status')) {
			$this->response->setOutput($this->language->get('error_unavailable'));

			return [];
		}

		if (!$this->customer->isLogged()) {
			$this->session->data['redirect'] = $this->url->link('extension/poolbuy/seller_product', 'language=' . $language);

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
	 * Tabs
	 *
	 * Renders the portal tab strip. Rendered here through load->view() rather than a
	 * Twig {% include %}, because OpenCart's Twig loader does not resolve
	 * extension-relative include paths.
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
		$this->load->model('tool/image');

		if ($image && is_file(DIR_IMAGE . $image)) {
			return $this->model_tool_image->resize($image, $width, $height);
		}

		return $this->model_tool_image->resize('no_image.png', $width, $height);
	}
}
