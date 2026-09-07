<?php
namespace Opencart\Catalog\Controller\Extension\Poolbuy;

use Opencart\System\Library\Extension\Poolbuy\PoolPresenter;

/**
 * Class Pool
 *
 * The marketplace: a filterable feed of live buying pools.
 *
 * Route: index.php?route=extension/poolbuy/pool
 *
 * @package Opencart\Catalog\Controller\Extension\Poolbuy
 */
class Pool extends \Opencart\System\Engine\Controller {
	/**
	 * How many pools appear per page.
	 */
	private const LIMIT = 12;

	/**
	 * Index
	 *
	 * @return void
	 */
	public function index(): void {
		$this->load->language('extension/poolbuy/poolbuy/marketplace');

		$this->document->setTitle($this->language->get('heading_title'));

		$language = (string)$this->config->get('config_language');

		$data['breadcrumbs'] = [
			[
				'text' => $this->language->get('text_home'),
				'href' => $this->url->link('common/home', 'language=' . $language)
			],
			[
				'text' => $this->language->get('heading_title'),
				'href' => $this->url->link('extension/poolbuy/pool', 'language=' . $language)
			]
		];

		$data['heading_title'] = $this->language->get('heading_title');
		$data['pb_active'] = 'marketplace';

		// Sidebar categories, with a live pool count each
		$this->load->model('extension/poolbuy/poolbuy/pool');

		$filter = $this->filterFromRequest();

		$data['categories'] = [];

		foreach ($this->model_extension_poolbuy_poolbuy_pool->getCategoryPoolCounts(12) as $category) {
			$data['categories'][] = [
				'category_id' => (int)$category['category_id'],
				'name'        => $category['name'],
				'pool_total'  => (int)$category['pool_total'],
				'active'      => (int)$category['category_id'] === (int)$filter['filter_category_id'],
				'href'        => $this->link(['filter_category_id' => (int)$category['category_id']])
			];
		}

		$data['locations'] = [];

		foreach ($this->model_extension_poolbuy_poolbuy_pool->getLocations() as $location) {
			$data['locations'][] = [
				'name'   => $location,
				'active' => $location === $filter['filter_location']
			];
		}

		$data['all_categories_href'] = $this->link(['filter_category_id' => '']);

		// Active filter chips let a shopper see and undo each constraint
		$data['chips'] = $this->chips($filter);
		$data['clear_href'] = $this->url->link('extension/poolbuy/pool', 'language=' . $language);

		$data['filter'] = $filter;
		$data['action'] = $this->url->link('extension/poolbuy/pool', 'language=' . $language);

		$data['sorts'] = [];

		foreach (['progress', 'ending', 'newest', 'moq', 'name'] as $sort) {
			$data['sorts'][] = [
				'value'    => $sort,
				'text'     => $this->language->get('text_sort_' . $sort),
				'selected' => $sort === $filter['sort']
			];
		}

		$data['list'] = $this->getList($filter);

		$data['column_left'] = $this->load->controller('common/column_left');
		$data['column_right'] = $this->load->controller('common/column_right');
		$data['content_top'] = $this->load->controller('common/content_top');
		$data['content_bottom'] = $this->load->controller('common/content_bottom');
		$data['footer'] = $this->load->controller('common/footer');
		$data['header'] = $this->load->controller('common/header');

		$this->response->setOutput($this->load->view('extension/poolbuy/poolbuy/marketplace', $data));
	}

	/**
	 * List
	 *
	 * Ajax endpoint so filtering and paging do not reload the whole page.
	 *
	 * @return void
	 */
	public function list(): void {
		$this->load->language('extension/poolbuy/poolbuy/marketplace');

		$this->load->model('extension/poolbuy/poolbuy/pool');

		$this->response->setOutput($this->getList($this->filterFromRequest()));
	}

	/**
	 * Get List
	 *
	 * @param array<string, mixed> $filter
	 *
	 * @return string
	 */
	private function getList(array $filter): string {
		$this->load->model('extension/poolbuy/poolbuy/pool');
		$this->load->model('tool/image');

		$language = (string)$this->config->get('config_language');
		$symbol = (string)$this->config->get('module_poolbuy_currency_symbol');

		$page = max(1, (int)$filter['page']);

		$query = $filter + [
			'start' => ($page - 1) * self::LIMIT,
			'limit' => self::LIMIT
		];

		$results = $this->model_extension_poolbuy_poolbuy_pool->getPools($query);
		$total = $this->model_extension_poolbuy_poolbuy_pool->getTotalPools($query);

		$data['pools'] = [];

		foreach ($results as $result) {
			$tiers = $this->model_extension_poolbuy_poolbuy_pool->getTiers((int)$result['pool_id']);

			$view = PoolPresenter::present($result, $tiers, ['symbol' => $symbol]);

			$view['hint'] = PoolPresenter::hint($view);
			$view['progress_modifier'] = PoolPresenter::progressModifier($view);
			$view['thumb'] = $this->thumb((string)($result['product_image'] ?? ''), 600, 440);
			$view['href'] = $this->url->link('product/product', 'language=' . $language . '&product_id=' . $view['product_id']);
			$view['join'] = $this->url->link('extension/poolbuy/join', 'language=' . $language . '&pool_id=' . $view['pool_id']);
			// Buying the whole MOQ is the same reservation path with the remaining
			// units pre-filled, so there is only ever one commitment code path.
			$view['buy_all'] = $this->url->link('extension/poolbuy/join', 'language=' . $language . '&pool_id=' . $view['pool_id'] . '&quantity=' . $view['remaining']);

			$data['pools'][] = $view;
		}

		$data['pagination'] = $this->load->controller('common/pagination', [
			'total' => $total,
			'page'  => $page,
			'limit' => self::LIMIT,
			'url'   => $this->link(['page' => '{page}'], true)
		]);

		$data['results'] = sprintf(
			$this->language->get('text_pagination'),
			$total ? (($page - 1) * self::LIMIT) + 1 : 0,
			((($page - 1) * self::LIMIT) > ($total - self::LIMIT)) ? $total : ((($page - 1) * self::LIMIT) + self::LIMIT),
			$total,
			(int)ceil($total / self::LIMIT)
		);

		$data['total'] = $total;
		$data['has_filters'] = (bool)$this->chips($filter);
		$data['clear_href'] = $this->url->link('extension/poolbuy/pool', 'language=' . $language);

		return $this->load->view('extension/poolbuy/poolbuy/marketplace_list', $data);
	}

	/**
	 * Filter From Request
	 *
	 * Reads and normalises every supported filter, so the rest of the controller
	 * works with typed values rather than raw request data.
	 *
	 * @return array<string, mixed>
	 */
	private function filterFromRequest(): array {
		$get = $this->request->get;

		$sorts = ['progress', 'ending', 'newest', 'moq', 'name'];

		$sort = isset($get['sort']) && in_array((string)$get['sort'], $sorts, true) ? (string)$get['sort'] : 'progress';

		// Ascending makes sense for a deadline or a name; descending for progress
		$order = isset($get['order']) && strtoupper((string)$get['order']) === 'ASC' ? 'ASC' : 'DESC';

		if (in_array($sort, ['ending', 'name'], true) && !isset($get['order'])) {
			$order = 'ASC';
		}

		return [
			'filter_search'       => isset($get['filter_search']) ? trim((string)$get['filter_search']) : '',
			'filter_category_id'  => isset($get['filter_category_id']) ? (int)$get['filter_category_id'] : 0,
			'filter_location'     => isset($get['filter_location']) ? trim((string)$get['filter_location']) : '',
			'filter_moq_min'      => isset($get['filter_moq_min']) && $get['filter_moq_min'] !== '' ? max(0, (int)$get['filter_moq_min']) : 0,
			'filter_moq_max'      => isset($get['filter_moq_max']) && $get['filter_moq_max'] !== '' ? max(0, (int)$get['filter_moq_max']) : 0,
			'filter_available'    => !empty($get['filter_available']) ? 1 : 0,
			'filter_gst_verified' => !empty($get['filter_gst_verified']) ? 1 : 0,
			'sort'                => $sort,
			'order'               => $order,
			'page'                => isset($get['page']) ? max(1, (int)$get['page']) : 1
		];
	}

	/**
	 * Chips
	 *
	 * Human readable labels for the filters currently applied, each with a link
	 * that removes just that one.
	 *
	 * @param array<string, mixed> $filter
	 *
	 * @return array<int, array<string, string>>
	 */
	private function chips(array $filter): array {
		$chips = [];

		if ($filter['filter_search'] !== '') {
			$chips[] = ['text' => '"' . $filter['filter_search'] . '"', 'href' => $this->link(['filter_search' => ''])];
		}

		if ($filter['filter_category_id']) {
			$this->load->model('catalog/category');

			$category = $this->model_catalog_category->getCategory((int)$filter['filter_category_id']);

			if ($category) {
				$chips[] = ['text' => $category['name'], 'href' => $this->link(['filter_category_id' => ''])];
			}
		}

		if ($filter['filter_location'] !== '') {
			$chips[] = ['text' => $filter['filter_location'], 'href' => $this->link(['filter_location' => ''])];
		}

		if ($filter['filter_moq_min']) {
			$chips[] = ['text' => sprintf($this->language->get('text_chip_moq_min'), $filter['filter_moq_min']), 'href' => $this->link(['filter_moq_min' => ''])];
		}

		if ($filter['filter_moq_max']) {
			$chips[] = ['text' => sprintf($this->language->get('text_chip_moq_max'), $filter['filter_moq_max']), 'href' => $this->link(['filter_moq_max' => ''])];
		}

		if ($filter['filter_available']) {
			$chips[] = ['text' => $this->language->get('text_filter_available'), 'href' => $this->link(['filter_available' => ''])];
		}

		if ($filter['filter_gst_verified']) {
			$chips[] = ['text' => $this->language->get('text_filter_gst'), 'href' => $this->link(['filter_gst_verified' => ''])];
		}

		return $chips;
	}

	/**
	 * Link
	 *
	 * Builds a marketplace URL preserving the current filters, with the given
	 * overrides applied. Passing an empty string removes a filter.
	 *
	 * @param array<string, mixed> $overrides
	 * @param bool                 $ajax      build a link to the .list endpoint instead
	 *
	 * @return string
	 */
	private function link(array $overrides = [], bool $ajax = false): string {
		$current = $this->filterFromRequest();

		// Changing any filter must return to page one, otherwise a shopper can land
		// on an empty page of a smaller result set.
		if (!isset($overrides['page'])) {
			$current['page'] = 1;
		}

		$params = array_merge($current, $overrides);

		$defaults = [
			'filter_search'       => '',
			'filter_category_id'  => 0,
			'filter_location'     => '',
			'filter_moq_min'      => 0,
			'filter_moq_max'      => 0,
			'filter_available'    => 0,
			'filter_gst_verified' => 0,
			'sort'                => 'progress',
			'page'                => 1
		];

		$query = 'language=' . $this->config->get('config_language');

		foreach ($params as $key => $value) {
			if ($key === 'order') {
				continue;
			}

			// Omit anything left at its default so URLs stay short and shareable
			if (array_key_exists($key, $defaults) && (string)$value === (string)$defaults[$key]) {
				continue;
			}

			if ($value === '' || $value === null) {
				continue;
			}

			$query .= '&' . $key . '=' . ($value === '{page}' ? '{page}' : urlencode((string)$value));
		}

		return $this->url->link('extension/poolbuy/pool' . ($ajax ? '.list' : ''), $query);
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
