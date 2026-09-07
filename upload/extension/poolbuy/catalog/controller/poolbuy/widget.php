<?php
namespace Opencart\Catalog\Controller\Extension\Poolbuy\Poolbuy;

use Opencart\System\Library\Extension\Poolbuy\PoolCalculator;
use Opencart\System\Library\Extension\Poolbuy\PoolPresenter;

/**
 * Class Widget
 *
 * The pool status widget shown on a product page: live progress, the price tier
 * ladder, the seller card, an anonymised participant feed and logistics terms.
 *
 * Returns a rendered fragment (or an empty string when the product has no pool)
 * so product/product can drop it in without knowing anything about pools.
 *
 * @package Opencart\Catalog\Controller\Extension\Poolbuy\Poolbuy
 */
class Widget extends \Opencart\System\Engine\Controller {
	/**
	 * Index
	 *
	 * @param int $product_id
	 *
	 * @return string rendered widget, or '' when this product has no visible pool
	 */
	public function index(int $product_id): string {
		if ($product_id <= 0) {
			return '';
		}

		$this->load->language('extension/poolbuy/poolbuy/widget');

		$this->load->model('extension/poolbuy/poolbuy/pool');
		$this->load->model('tool/image');

		$pool = $this->model_extension_poolbuy_poolbuy_pool->getPoolByProductId($product_id);

		if (!$pool) {
			return '';
		}

		$pool_id = (int)$pool['pool_id'];
		$language = (string)$this->config->get('config_language');
		$symbol = (string)$this->config->get('module_poolbuy_currency_symbol');

		$tiers = $this->model_extension_poolbuy_poolbuy_pool->getTiers($pool_id);

		$view = PoolPresenter::present($pool, $tiers, ['symbol' => $symbol]);

		$data['pool'] = $view;
		$data['hint'] = PoolPresenter::hint($view);
		$data['progress_modifier'] = PoolPresenter::progressModifier($view);
		$data['status_text'] = $this->language->get('text_status_' . $view['status']);

		$data['join'] = $this->url->link('extension/poolbuy/join', 'language=' . $language . '&pool_id=' . $pool_id);
		$data['buy_all'] = $this->url->link('extension/poolbuy/join', 'language=' . $language . '&pool_id=' . $pool_id . '&quantity=' . $view['remaining']);
		$data['marketplace'] = $this->url->link('extension/poolbuy/pool', 'language=' . $language);
		$data['contact'] = $this->url->link('information/contact', 'language=' . $language);

		// ---- Price tier ladder, decorated with unlocked / active / locked state
		$data['tiers'] = [];

		foreach (PoolCalculator::tierLadder($tiers, $view['reserved_qty']) as $index => $tier) {
			$position = $index + 1;

			if ($tier['max_qty'] === null) {
				$range = sprintf($this->language->get('text_tier_open'), $position, $tier['min_qty']);
			} else {
				$range = sprintf($this->language->get('text_tier_range'), $position, $tier['min_qty'], $tier['max_qty']);
			}

			// A tier the pool has passed is already banked; the best one is active;
			// the next one up is within reach; anything beyond stays locked.
			if ($tier['active']) {
				$state = $this->language->get('text_tier_active');
				$state_class = 'active';
			} elseif ($tier['unlocked']) {
				$state = $this->language->get('text_tier_passed');
				$state_class = 'locked';
			} elseif ($tier['units_needed'] > 0 && $tier['units_needed'] === $view['units_to_next_tier']) {
				$state = sprintf($this->language->get('text_tier_soon'), $tier['units_needed']);
				$state_class = 'soon';
			} else {
				$state = $this->language->get('text_tier_locked');
				$state_class = 'locked';
			}

			$data['tiers'][] = [
				'index'       => $index + 1,
				'range'       => $range,
				'price_text'  => PoolPresenter::money((float)$tier['price'], $symbol),
				'active'      => (bool)$tier['active'],
				'unlocked'    => (bool)$tier['unlocked'],
				'state'       => $state,
				'state_class' => $state_class
			];
		}

		// ---- Seller card
		$gallery = [];

		if (!empty($pool['seller_images'])) {
			$decoded = json_decode((string)$pool['seller_images'], true);

			if (is_array($decoded)) {
				foreach (array_slice($decoded, 0, 3) as $image) {
					$gallery[] = $this->thumb((string)$image, 400, 260);
				}
			}
		}

		$rating = (float)$view['seller_rating'];

		// Whole / half / empty stars. A remainder of 0.75 or more rounds up to a
		// whole star, 0.25 to 0.75 shows a half star, below that shows nothing.
		$full = (int)floor($rating);
		$remainder = $rating - $full;
		$half = false;

		if ($remainder >= 0.75) {
			$full++;
		} elseif ($remainder >= 0.25) {
			$half = true;
		}

		$full = max(0, min(5, $full));
		$empty = max(0, 5 - $full - ($half ? 1 : 0));

		$data['seller'] = [
			'name'         => (string)$view['seller_name'],
			'initials'     => $this->initials((string)$view['seller_name']),
			'location'     => (string)$view['seller_location'],
			'description'  => (string)($pool['seller_description'] ?? ''),
			'rating'       => number_format($rating, 1),
			'rating_count' => (int)$view['seller_rating_count'],
			'stars_full'   => $full,
			'star_half'    => $half,
			'stars_empty'  => $empty,
			'gst_verified' => (bool)$view['gst_verified'],
			'verified'     => (bool)$view['verified_seller'],
			'gallery'      => $gallery
		];

		// ---- Anonymised participant feed. Only quantity and a relative time are
		// exposed; buyer identity is never sent to the browser.
		$data['participants'] = [];

		foreach ($this->model_extension_poolbuy_poolbuy_pool->getRecentParticipants($pool_id, 4) as $participant) {
			$data['participants'][] = [
				'quantity' => (int)$participant['quantity'],
				'label'    => (int)$participant['order_total'] > 0 ? $this->language->get('text_wholesale_partner') : $this->language->get('text_anonymous_buyer'),
				'ago'      => $this->timeAgo((string)$participant['date_added'])
			];
		}

		$data['participant_total'] = $this->model_extension_poolbuy_poolbuy_pool->getTotalParticipants($pool_id);

		// ---- Logistics
		$data['logistics'] = [
			'min_qty'        => $view['min_qty_per_buyer'],
			'unit_label'     => $view['unit_label'],
			'lead_time'      => $view['lead_time'],
			'shipping_terms' => $view['shipping_terms']
		];

		return $this->load->view('extension/poolbuy/poolbuy/pool_widget', $data);
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

	/**
	 * Initials
	 *
	 * Two-letter monogram used as the seller avatar when no logo is set.
	 *
	 * @param string $name
	 *
	 * @return string
	 */
	private function initials(string $name): string {
		$words = preg_split('/\s+/', trim(html_entity_decode($name, ENT_QUOTES, 'UTF-8'))) ?: [];

		$letters = '';

		foreach ($words as $word) {
			if ($word === '' || !ctype_alpha($word[0])) {
				continue;
			}

			$letters .= strtoupper($word[0]);

			if (oc_strlen($letters) >= 2) {
				break;
			}
		}

		return $letters !== '' ? $letters : '?';
	}

	/**
	 * Time Ago
	 *
	 * Relative time such as "2h ago", deliberately coarse so a reservation cannot
	 * be correlated to a precise moment.
	 *
	 * @param string $date
	 *
	 * @return string
	 */
	private function timeAgo(string $date): string {
		$timestamp = strtotime($date);

		if ($timestamp === false) {
			return '';
		}

		$seconds = max(0, time() - $timestamp);

		if ($seconds < 3600) {
			return sprintf($this->language->get('text_minutes_ago'), max(1, (int)floor($seconds / 60)));
		}

		if ($seconds < 86400) {
			return sprintf($this->language->get('text_hours_ago'), (int)floor($seconds / 3600));
		}

		return sprintf($this->language->get('text_days_ago'), (int)floor($seconds / 86400));
	}
}
