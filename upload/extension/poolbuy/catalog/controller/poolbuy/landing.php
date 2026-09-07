<?php
namespace Opencart\Catalog\Controller\Extension\Poolbuy\Poolbuy;

use Opencart\System\Library\Extension\Poolbuy\PoolPresenter;

/**
 * Class Landing
 *
 * Renders the PoolBuy marketing landing page. Called by common/home when the
 * extension is enabled, and returns a rendered fragment rather than a full
 * response so it composes with OpenCart's layout modules.
 *
 * @package Opencart\Catalog\Controller\Extension\Poolbuy\Poolbuy
 */
class Landing extends \Opencart\System\Engine\Controller {
	/**
	 * Index
	 *
	 * @return string rendered landing markup
	 */
	public function index(): string {
		$this->load->language('extension/poolbuy/poolbuy/landing');

		$this->load->model('extension/poolbuy/poolbuy/pool');
		$this->load->model('tool/image');

		$symbol = (string)$this->config->get('module_poolbuy_currency_symbol');

		$data['marketplace'] = $this->url->link('extension/poolbuy/pool', 'language=' . $this->config->get('config_language'));
		$data['register'] = $this->url->link('account/register', 'language=' . $this->config->get('config_language'));
		$data['contact'] = $this->url->link('information/contact', 'language=' . $this->config->get('config_language'));
		$data['categories_all'] = $this->url->link('product/category', 'language=' . $this->config->get('config_language'));

		// ---- Hero: the fullest open pool, so the hero always shows real momentum
		$data['featured'] = [];

		$featured = $this->model_extension_poolbuy_poolbuy_pool->getFeaturedPool();

		if ($featured) {
			$tiers = $this->model_extension_poolbuy_poolbuy_pool->getTiers((int)$featured['pool_id']);

			$view = PoolPresenter::present($featured, $tiers, ['symbol' => $symbol]);

			$view['hint'] = PoolPresenter::hint($view);
			$view['progress_modifier'] = PoolPresenter::progressModifier($view);
			$view['href'] = $this->url->link('product/product', 'language=' . $this->config->get('config_language') . '&product_id=' . $view['product_id']);
			$view['thumb'] = $this->thumb((string)($featured['product_image'] ?? ''), 160, 160);

			$data['featured'] = $view;
		}

		// ---- Headline statistics, from real counts
		$stats = $this->model_extension_poolbuy_poolbuy_pool->getMarketplaceStatistics();

		$data['stats'] = [
			'active_products' => $this->compact($stats['active_products']),
			'active_sellers'  => $this->compact($stats['active_sellers']),
			'active_pools'    => $this->compact($stats['active_pools']),
			'units_pooled'    => $this->compact($stats['units_pooled']),
			'active_buyers'   => $this->compact($stats['active_buyers'])
		];

		$data['has_pools'] = $stats['active_pools'] > 0;

		// ---- Featured categories with live pool counts
		$data['categories'] = [];

		foreach ($this->model_extension_poolbuy_poolbuy_pool->getCategoryPoolCounts(3) as $category) {
			$data['categories'][] = [
				'name'       => $category['name'],
				'pool_total' => (int)$category['pool_total'],
				'image'      => $this->thumb((string)$category['image'], 640, 400),
				'href'       => $this->url->link('extension/poolbuy/pool', 'language=' . $this->config->get('config_language') . '&filter_category_id=' . (int)$category['category_id'])
			];
		}

		// ---- How it works. Icons are FontAwesome equivalents of the design's
		// Material Symbols, since FontAwesome already ships with OpenCart.
		$data['steps'] = [
			['icon' => 'fa-solid fa-file-arrow-up', 'title' => $this->language->get('text_step_1_title'), 'body' => $this->language->get('text_step_1_body')],
			['icon' => 'fa-solid fa-user-plus',     'title' => $this->language->get('text_step_2_title'), 'body' => $this->language->get('text_step_2_body')],
			['icon' => 'fa-solid fa-circle-check',  'title' => $this->language->get('text_step_3_title'), 'body' => $this->language->get('text_step_3_body')],
			['icon' => 'fa-solid fa-truck',         'title' => $this->language->get('text_step_4_title'), 'body' => $this->language->get('text_step_4_body')]
		];

		return $this->load->view('extension/poolbuy/poolbuy/landing', $data);
	}

	/**
	 * Thumb
	 *
	 * Resizes an image, falling back to OpenCart's placeholder when the file is
	 * missing so a broken path never renders as a broken image.
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

	/**
	 * Compact
	 *
	 * Renders large counts as 1.2K / 3.4M so the stats band stays legible.
	 *
	 * @param int $value
	 *
	 * @return string
	 */
	private function compact(int $value): string {
		if ($value >= 1000000) {
			return rtrim(rtrim(number_format($value / 1000000, 1), '0'), '.') . 'M';
		}

		if ($value >= 1000) {
			return rtrim(rtrim(number_format($value / 1000, 1), '0'), '.') . 'K';
		}

		return (string)$value;
	}
}
