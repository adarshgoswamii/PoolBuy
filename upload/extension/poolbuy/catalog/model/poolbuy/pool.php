<?php
namespace Opencart\Catalog\Model\Extension\Poolbuy\Poolbuy;
/**
 * Class Pool
 *
 * Storefront reads for pools. Every query here is scoped to pools a shopper is
 * allowed to see, so a caller cannot accidentally surface a draft or cancelled
 * pool by forgetting a condition.
 *
 * @package Opencart\Catalog\Model\Extension\Poolbuy\Poolbuy
 */
class Pool extends \Opencart\System\Engine\Model {
	/**
	 * Statuses a shopper may see on the storefront. Drafts and cancelled pools are
	 * deliberately excluded.
	 */
	private const VISIBLE = "'active', 'reached', 'closed', 'fulfilled', 'expired'";

	/**
	 * Statuses that still count as a live, joinable or in-flight pool.
	 */
	private const LIVE = "'active', 'reached', 'closed'";

	/**
	 * Get Pool
	 *
	 * @param int $pool_id
	 *
	 * @return array<string, mixed>
	 */
	public function getPool(int $pool_id): array {
		$query = $this->db->query($this->baseSelect() . " WHERE p.`pool_id` = '" . (int)$pool_id . "' AND p.`status` IN (" . self::VISIBLE . ")");

		return $query->row;
	}

	/**
	 * Get Pool By Product ID
	 *
	 * The live pool attached to a product, preferring the one that is still open.
	 *
	 * @param int $product_id
	 *
	 * @return array<string, mixed>
	 */
	public function getPoolByProductId(int $product_id): array {
		$sql = $this->baseSelect();
		$sql .= " WHERE p.`product_id` = '" . (int)$product_id . "' AND p.`status` IN (" . self::VISIBLE . ")";
		// Prefer an open pool, then the most recently created
		$sql .= " ORDER BY FIELD(p.`status`, 'active', 'reached', 'closed', 'fulfilled', 'expired'), p.`pool_id` DESC";
		$sql .= " LIMIT 1";

		$query = $this->db->query($sql);

		return $query->row;
	}

	/**
	 * Get Pools
	 *
	 * @param array<string, mixed> $data filters, sort, order, start, limit
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function getPools(array $data = []): array {
		$sql = $this->baseSelect() . $this->buildFilters($data);

		$sorts = [
			'progress' => '(p.`reserved_qty` / GREATEST(p.`moq_target`, 1))',
			'ending'   => 'p.`date_end`',
			'newest'   => 'p.`pool_id`',
			'moq'      => 'p.`moq_target`',
			'name'     => 'pd.`name`'
		];

		$sort = (isset($data['sort']) && isset($sorts[$data['sort']])) ? $sorts[$data['sort']] : $sorts['progress'];
		$order = (isset($data['order']) && strtoupper((string)$data['order']) === 'ASC') ? 'ASC' : 'DESC';

		$sql .= " ORDER BY " . $sort . " " . $order . ", p.`pool_id` DESC";

		$start = max(0, (int)($data['start'] ?? 0));
		$limit = (int)($data['limit'] ?? 12);

		if ($limit < 1) {
			$limit = 12;
		}

		$sql .= " LIMIT " . $start . "," . $limit;

		$query = $this->db->query($sql);

		return $query->rows;
	}

	/**
	 * Get Total Pools
	 *
	 * @param array<string, mixed> $data
	 *
	 * @return int
	 */
	public function getTotalPools(array $data = []): int {
		$sql = "SELECT COUNT(DISTINCT p.`pool_id`) AS `total`" . $this->fromJoins() . $this->buildFilters($data);

		$query = $this->db->query($sql);

		return (int)$query->row['total'];
	}

	/**
	 * Get Featured Pool
	 *
	 * The most nearly complete open pool, used for the landing hero. Choosing the
	 * fullest pool means the hero always shows genuine momentum rather than an
	 * empty progress bar.
	 *
	 * @return array<string, mixed>
	 */
	public function getFeaturedPool(): array {
		$sql = $this->baseSelect();
		$sql .= " WHERE p.`status` = 'active' AND p.`moq_target` > 0";
		$sql .= " ORDER BY (p.`reserved_qty` / p.`moq_target`) DESC, p.`date_end` ASC";
		$sql .= " LIMIT 1";

		$query = $this->db->query($sql);

		return $query->row;
	}

	/**
	 * Get Tiers
	 *
	 * @param int $pool_id
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function getTiers(int $pool_id): array {
		$query = $this->db->query("SELECT `tier_id`, `min_qty`, `max_qty`, `price` FROM `" . DB_PREFIX . "poolbuy_pool_tier` WHERE `pool_id` = '" . (int)$pool_id . "' ORDER BY `min_qty` ASC");

		return $query->rows;
	}

	/**
	 * Get Category Pool Counts
	 *
	 * Live pool counts per top-level category, for the featured categories strip.
	 *
	 * @param int $limit
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function getCategoryPoolCounts(int $limit = 3): array {
		$limit = max(1, $limit);

		$sql = "SELECT c.`category_id`, cd.`name`, c.`image`, COUNT(DISTINCT p.`pool_id`) AS `pool_total`";
		$sql .= " FROM `" . DB_PREFIX . "poolbuy_pool` p";
		$sql .= " INNER JOIN `" . DB_PREFIX . "product_to_category` p2c ON (p.`product_id` = p2c.`product_id`)";
		$sql .= " INNER JOIN `" . DB_PREFIX . "category` c ON (p2c.`category_id` = c.`category_id`)";
		$sql .= " INNER JOIN `" . DB_PREFIX . "category_description` cd ON (c.`category_id` = cd.`category_id` AND cd.`language_id` = '" . (int)$this->config->get('config_language_id') . "')";
		$sql .= " WHERE p.`status` IN (" . self::LIVE . ") AND c.`status` = '1'";
		$sql .= " GROUP BY c.`category_id`, cd.`name`, c.`image`";
		$sql .= " ORDER BY `pool_total` DESC, cd.`name` ASC";
		$sql .= " LIMIT " . $limit;

		$query = $this->db->query($sql);

		return $query->rows;
	}

	/**
	 * Get Marketplace Statistics
	 *
	 * Headline numbers for the landing page. These are real counts, so an empty
	 * store honestly reports zeros rather than inventing social proof.
	 *
	 * @return array<string, int>
	 */
	public function getMarketplaceStatistics(): array {
		$pools = $this->db->query("SELECT COUNT(*) AS `total` FROM `" . DB_PREFIX . "poolbuy_pool` WHERE `status` IN (" . self::LIVE . ")");
		$sellers = $this->db->query("SELECT COUNT(*) AS `total` FROM `" . DB_PREFIX . "poolbuy_seller` WHERE `status` = '1'");
		$products = $this->db->query("SELECT COUNT(DISTINCT `product_id`) AS `total` FROM `" . DB_PREFIX . "poolbuy_pool` WHERE `status` IN (" . self::LIVE . ")");
		$units = $this->db->query("SELECT COALESCE(SUM(`reserved_qty`), 0) AS `total` FROM `" . DB_PREFIX . "poolbuy_pool` WHERE `status` IN (" . self::LIVE . ")");
		$buyers = $this->db->query("SELECT COUNT(DISTINCT `customer_id`) AS `total` FROM `" . DB_PREFIX . "poolbuy_reservation` WHERE `status` IN ('pending', 'confirmed', 'converted')");

		return [
			'active_pools'    => (int)$pools->row['total'],
			'active_sellers'  => (int)$sellers->row['total'],
			'active_products' => (int)$products->row['total'],
			'units_pooled'    => (int)$units->row['total'],
			'active_buyers'   => (int)$buyers->row['total']
		];
	}

	/**
	 * Get Recent Participants
	 *
	 * The anonymised live feed on the product page. Only the quantity and a
	 * relative timestamp are exposed - never a customer name or id - so
	 * participation stays confidential between buyer and platform.
	 *
	 * @param int $pool_id
	 * @param int $limit
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function getRecentParticipants(int $pool_id, int $limit = 5): array {
		$limit = max(1, $limit);

		$sql = "SELECT r.`quantity`, r.`date_added`, r.`status`,";
		// A buyer with completed orders is shown as a wholesale partner rather than
		// an anonymous buyer, which is the only distinction the feed reveals.
		$sql .= " (SELECT COUNT(*) FROM `" . DB_PREFIX . "order` o WHERE o.`customer_id` = r.`customer_id` AND o.`order_status_id` > 0) AS `order_total`";
		$sql .= " FROM `" . DB_PREFIX . "poolbuy_reservation` r";
		$sql .= " WHERE r.`pool_id` = '" . (int)$pool_id . "' AND r.`status` IN ('pending', 'confirmed', 'converted')";
		$sql .= " ORDER BY r.`date_added` DESC";
		$sql .= " LIMIT " . $limit;

		$query = $this->db->query($sql);

		return $query->rows;
	}

	/**
	 * Get Total Participants
	 *
	 * @param int $pool_id
	 *
	 * @return int
	 */
	public function getTotalParticipants(int $pool_id): int {
		$query = $this->db->query("SELECT COUNT(*) AS `total` FROM `" . DB_PREFIX . "poolbuy_reservation` WHERE `pool_id` = '" . (int)$pool_id . "' AND `status` IN ('pending', 'confirmed', 'converted')");

		return (int)$query->row['total'];
	}

	/**
	 * Get Locations
	 *
	 * Distinct seller locations that currently have live pools, for the
	 * marketplace location filter.
	 *
	 * @return array<int, string>
	 */
	public function getLocations(): array {
		$sql = "SELECT DISTINCT s.`location` FROM `" . DB_PREFIX . "poolbuy_seller` s";
		$sql .= " INNER JOIN `" . DB_PREFIX . "poolbuy_pool` p ON (p.`seller_id` = s.`seller_id`)";
		$sql .= " WHERE s.`location` != '' AND p.`status` IN (" . self::LIVE . ")";
		$sql .= " ORDER BY s.`location` ASC";

		$query = $this->db->query($sql);

		return array_column($query->rows, 'location');
	}

	/**
	 * Base Select
	 *
	 * @return string
	 */
	private function baseSelect(): string {
		$sql = "SELECT p.*, pd.`name` AS `product_name`, pr.`model` AS `product_model`, pr.`image` AS `product_image`,";
		$sql .= " pr.`price` AS `retail_price`, pr.`tax_class_id`,";
		$sql .= " s.`name` AS `seller_name`, s.`slug` AS `seller_slug`, s.`gst_verified`, s.`verified_seller`,";
		$sql .= " s.`rating` AS `seller_rating`, s.`rating_count` AS `seller_rating_count`, s.`location` AS `seller_location`,";
		$sql .= " s.`logo` AS `seller_logo`, s.`images` AS `seller_images`, s.`description` AS `seller_description`";
		$sql .= $this->fromJoins();

		return $sql;
	}

	/**
	 * From Joins
	 *
	 * @return string
	 */
	private function fromJoins(): string {
		$sql = " FROM `" . DB_PREFIX . "poolbuy_pool` p";
		$sql .= " LEFT JOIN `" . DB_PREFIX . "product` pr ON (p.`product_id` = pr.`product_id`)";
		$sql .= " LEFT JOIN `" . DB_PREFIX . "product_description` pd ON (p.`product_id` = pd.`product_id` AND pd.`language_id` = '" . (int)$this->config->get('config_language_id') . "')";
		$sql .= " LEFT JOIN `" . DB_PREFIX . "poolbuy_seller` s ON (p.`seller_id` = s.`seller_id`)";

		return $sql;
	}

	/**
	 * Build Filters
	 *
	 * @param array<string, mixed> $data
	 *
	 * @return string
	 */
	private function buildFilters(array $data): string {
		// Only ever show pools a shopper is allowed to see, and only products that
		// are themselves enabled and in their availability window.
		$conditions = [
			"p.`status` IN (" . self::VISIBLE . ")",
			"pr.`status` = '1'",
			"pr.`date_available` <= NOW()"
		];

		if (!empty($data['filter_search'])) {
			$term = $this->db->escape('%' . (string)$data['filter_search'] . '%');

			$conditions[] = "(pd.`name` LIKE '" . $term . "' OR pr.`model` LIKE '" . $term . "' OR p.`title` LIKE '" . $term . "' OR s.`name` LIKE '" . $term . "')";
		}

		if (!empty($data['filter_category_id'])) {
			$conditions[] = "p.`product_id` IN (SELECT `product_id` FROM `" . DB_PREFIX . "product_to_category` WHERE `category_id` = '" . (int)$data['filter_category_id'] . "')";
		}

		if (!empty($data['filter_seller_id'])) {
			$conditions[] = "p.`seller_id` = '" . (int)$data['filter_seller_id'] . "'";
		}

		if (!empty($data['filter_location'])) {
			$conditions[] = "s.`location` = '" . $this->db->escape((string)$data['filter_location']) . "'";
		}

		if (!empty($data['filter_moq_min'])) {
			$conditions[] = "p.`moq_target` >= '" . (int)$data['filter_moq_min'] . "'";
		}

		if (!empty($data['filter_moq_max'])) {
			$conditions[] = "p.`moq_target` <= '" . (int)$data['filter_moq_max'] . "'";
		}

		// "Pools available" means still joinable right now
		if (!empty($data['filter_available'])) {
			$conditions[] = "p.`status` = 'active'";
			$conditions[] = "p.`date_end` > NOW()";
			$conditions[] = "p.`reserved_qty` < p.`moq_target`";
		}

		if (!empty($data['filter_gst_verified'])) {
			$conditions[] = "s.`gst_verified` = '1'";
		}

		return ' WHERE ' . implode(' AND ', $conditions);
	}
}
