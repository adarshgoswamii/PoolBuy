<?php
namespace Opencart\Admin\Model\Extension\Poolbuy\Poolbuy;
/**
 * Class Seller
 *
 * Data access for PoolBuy seller profiles. `customer_id` is left at 0 while
 * sellers are administered here; it is populated in phase 2 when sellers gain
 * their own logins.
 *
 * @package Opencart\Admin\Model\Extension\Poolbuy\Poolbuy
 */
class Seller extends \Opencart\System\Engine\Model {
	/**
	 * Add Seller
	 *
	 * @param array<string, mixed> $data
	 *
	 * @return int primary key of the new seller
	 */
	public function addSeller(array $data): int {
		$this->db->query("INSERT INTO `" . DB_PREFIX . "poolbuy_seller` SET " . $this->buildAssignments($data) . ", `date_added` = NOW(), `date_modified` = NOW()");

		$seller_id = $this->db->getLastId();

		$this->setProducts($seller_id, isset($data['product']) && is_array($data['product']) ? $data['product'] : []);

		return $seller_id;
	}

	/**
	 * Edit Seller
	 *
	 * @param int                  $seller_id
	 * @param array<string, mixed> $data
	 *
	 * @return void
	 */
	public function editSeller(int $seller_id, array $data): void {
		$this->db->query("UPDATE `" . DB_PREFIX . "poolbuy_seller` SET " . $this->buildAssignments($data) . ", `date_modified` = NOW() WHERE `seller_id` = '" . (int)$seller_id . "'");

		$this->setProducts($seller_id, isset($data['product']) && is_array($data['product']) ? $data['product'] : []);
	}

	/**
	 * Delete Seller
	 *
	 * @param int $seller_id
	 *
	 * @return void
	 */
	public function deleteSeller(int $seller_id): void {
		$this->db->query("DELETE FROM `" . DB_PREFIX . "poolbuy_seller` WHERE `seller_id` = '" . (int)$seller_id . "'");
		$this->db->query("DELETE FROM `" . DB_PREFIX . "poolbuy_product_seller` WHERE `seller_id` = '" . (int)$seller_id . "'");
	}

	/**
	 * Get Seller
	 *
	 * @param int $seller_id
	 *
	 * @return array<string, mixed> empty array when not found
	 */
	public function getSeller(int $seller_id): array {
		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "poolbuy_seller` WHERE `seller_id` = '" . (int)$seller_id . "'");

		return $query->row;
	}

	/**
	 * Get Seller By Slug
	 *
	 * @param string $slug
	 *
	 * @return array<string, mixed>
	 */
	public function getSellerBySlug(string $slug): array {
		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "poolbuy_seller` WHERE `slug` = '" . $this->db->escape($slug) . "'");

		return $query->row;
	}

	/**
	 * Get Sellers
	 *
	 * @param array<string, mixed> $data filter_name, filter_gst_verified, filter_status, sort, order, start, limit
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function getSellers(array $data = []): array {
		$sql = "SELECT * FROM `" . DB_PREFIX . "poolbuy_seller`";

		$sql .= $this->buildFilters($data);

		$sorts = ['name', 'location', 'rating', 'status', 'date_added'];

		$sort = (isset($data['sort']) && in_array($data['sort'], $sorts, true)) ? $data['sort'] : 'name';

		$sql .= " ORDER BY `" . $sort . "`";
		$sql .= (isset($data['order']) && strtoupper((string)$data['order']) === 'DESC') ? ' DESC' : ' ASC';

		if (isset($data['start']) || isset($data['limit'])) {
			$start = max(0, (int)($data['start'] ?? 0));
			$limit = (int)($data['limit'] ?? 10);

			if ($limit < 1) {
				$limit = 10;
			}

			$sql .= " LIMIT " . $start . "," . $limit;
		}

		$query = $this->db->query($sql);

		return $query->rows;
	}

	/**
	 * Get Total Sellers
	 *
	 * @param array<string, mixed> $data
	 *
	 * @return int
	 */
	public function getTotalSellers(array $data = []): int {
		$query = $this->db->query("SELECT COUNT(*) AS `total` FROM `" . DB_PREFIX . "poolbuy_seller`" . $this->buildFilters($data));

		return (int)$query->row['total'];
	}

	/**
	 * Set Products
	 *
	 * Replaces the seller's product assignments. A product belongs to at most one
	 * seller, so assigning it here removes any previous owner.
	 *
	 * @param int             $seller_id
	 * @param array<int, int> $product_ids
	 *
	 * @return void
	 */
	public function setProducts(int $seller_id, array $product_ids): void {
		$this->db->query("DELETE FROM `" . DB_PREFIX . "poolbuy_product_seller` WHERE `seller_id` = '" . (int)$seller_id . "'");

		foreach (array_unique(array_map('intval', $product_ids)) as $product_id) {
			if ($product_id <= 0) {
				continue;
			}

			$this->db->query("REPLACE INTO `" . DB_PREFIX . "poolbuy_product_seller` SET `product_id` = '" . (int)$product_id . "', `seller_id` = '" . (int)$seller_id . "'");
		}
	}

	/**
	 * Get Products
	 *
	 * @param int $seller_id
	 *
	 * @return array<int, array<string, mixed>> product_id and name pairs
	 */
	public function getProducts(int $seller_id): array {
		$query = $this->db->query("SELECT ps.`product_id`, pd.`name` FROM `" . DB_PREFIX . "poolbuy_product_seller` ps LEFT JOIN `" . DB_PREFIX . "product_description` pd ON (ps.`product_id` = pd.`product_id` AND pd.`language_id` = '" . (int)$this->config->get('config_language_id') . "') WHERE ps.`seller_id` = '" . (int)$seller_id . "' ORDER BY pd.`name` ASC");

		return $query->rows;
	}

	/**
	 * Get Seller By Product ID
	 *
	 * @param int $product_id
	 *
	 * @return array<string, mixed>
	 */
	public function getSellerByProductId(int $product_id): array {
		$query = $this->db->query("SELECT s.* FROM `" . DB_PREFIX . "poolbuy_product_seller` ps LEFT JOIN `" . DB_PREFIX . "poolbuy_seller` s ON (ps.`seller_id` = s.`seller_id`) WHERE ps.`product_id` = '" . (int)$product_id . "'");

		return $query->row;
	}

	/**
	 * Get Total Pools By Seller ID
	 *
	 * Used to block deletion of a seller that still has pools attached, so pools
	 * can never end up orphaned from their supplier.
	 *
	 * @param int $seller_id
	 *
	 * @return int
	 */
	public function getTotalPoolsBySellerId(int $seller_id): int {
		$query = $this->db->query("SELECT COUNT(*) AS `total` FROM `" . DB_PREFIX . "poolbuy_pool` WHERE `seller_id` = '" . (int)$seller_id . "'");

		return (int)$query->row['total'];
	}

	/**
	 * Build Filters
	 *
	 * @param array<string, mixed> $data
	 *
	 * @return string SQL WHERE clause, or an empty string
	 */
	private function buildFilters(array $data): string {
		$conditions = [];

		if (!empty($data['filter_name'])) {
			$conditions[] = "`name` LIKE '" . $this->db->escape('%' . (string)$data['filter_name'] . '%') . "'";
		}

		if (isset($data['filter_gst_verified']) && $data['filter_gst_verified'] !== '') {
			$conditions[] = "`gst_verified` = '" . (int)$data['filter_gst_verified'] . "'";
		}

		if (isset($data['filter_status']) && $data['filter_status'] !== '') {
			$conditions[] = "`status` = '" . (int)$data['filter_status'] . "'";
		}

		return $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
	}

	/**
	 * Build Assignments
	 *
	 * Every value is escaped or cast here, so callers cannot smuggle SQL through a
	 * seller field.
	 *
	 * @param array<string, mixed> $data
	 *
	 * @return string SQL SET assignment list
	 */
	private function buildAssignments(array $data): string {
		$images = (isset($data['images']) && is_array($data['images'])) ? array_values(array_filter(array_map('strval', $data['images']))) : [];

		$assignments = [
			"`customer_id` = '" . (int)($data['customer_id'] ?? 0) . "'",
			"`name` = '" . $this->db->escape((string)($data['name'] ?? '')) . "'",
			"`slug` = '" . $this->db->escape((string)($data['slug'] ?? '')) . "'",
			"`gst_number` = '" . $this->db->escape(strtoupper((string)($data['gst_number'] ?? ''))) . "'",
			"`gst_verified` = '" . (int)!empty($data['gst_verified']) . "'",
			"`verified_seller` = '" . (int)!empty($data['verified_seller']) . "'",
			"`rating` = '" . (float)($data['rating'] ?? 0) . "'",
			"`rating_count` = '" . (int)($data['rating_count'] ?? 0) . "'",
			"`description` = '" . $this->db->escape((string)($data['description'] ?? '')) . "'",
			"`logo` = '" . $this->db->escape((string)($data['logo'] ?? '')) . "'",
			"`location` = '" . $this->db->escape((string)($data['location'] ?? '')) . "'",
			"`images` = '" . $this->db->escape((string)json_encode($images)) . "'",
			"`status` = '" . (int)!empty($data['status']) . "'"
		];

		return implode(', ', $assignments);
	}
}
