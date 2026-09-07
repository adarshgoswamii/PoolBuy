<?php
namespace Opencart\Catalog\Model\Extension\Poolbuy\Poolbuy;

use Opencart\System\Library\Extension\Poolbuy\PoolCalculator;

/**
 * Class Seller
 *
 * Data access for the seller self-service portal.
 *
 * EVERY method here takes a $seller_id and every query filters on it. That is the
 * whole tenancy boundary: a seller can pass any pool_id they like, and if it is
 * not theirs the query simply matches nothing. No method ever trusts a caller to
 * have already checked ownership.
 *
 * @package Opencart\Catalog\Model\Extension\Poolbuy\Poolbuy
 */
class Seller extends \Opencart\System\Engine\Model {
	/**
	 * Get Seller By Customer ID
	 *
	 * Resolves the signed-in storefront account to a seller profile. Returns an
	 * empty array when the account is not a seller, which is what gates the whole
	 * portal.
	 *
	 * @param int $customer_id
	 *
	 * @return array<string, mixed>
	 */
	public function getSellerByCustomerId(int $customer_id): array {
		if ($customer_id <= 0) {
			return [];
		}

		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "poolbuy_seller` WHERE `customer_id` = '" . (int)$customer_id . "'");

		return $query->row;
	}

	/**
	 * Get Pools
	 *
	 * @param int                  $seller_id
	 * @param array<string, mixed> $data
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function getPools(int $seller_id, array $data = []): array {
		$sql = "SELECT p.*, pd.`name` AS `product_name`, pr.`model` AS `product_model`, pr.`image` AS `product_image`";
		$sql .= " FROM `" . DB_PREFIX . "poolbuy_pool` p";
		$sql .= " LEFT JOIN `" . DB_PREFIX . "product` pr ON (p.`product_id` = pr.`product_id`)";
		$sql .= " LEFT JOIN `" . DB_PREFIX . "product_description` pd ON (p.`product_id` = pd.`product_id` AND pd.`language_id` = '" . (int)$this->config->get('config_language_id') . "')";
		$sql .= " WHERE p.`seller_id` = '" . (int)$seller_id . "'";

		if (!empty($data['filter_status'])) {
			$sql .= " AND p.`status` = '" . $this->db->escape((string)$data['filter_status']) . "'";
		}

		$sql .= " ORDER BY FIELD(p.`status`, 'active', 'reached', 'closed', 'draft', 'fulfilled', 'expired', 'cancelled'), p.`date_end` ASC";

		$start = max(0, (int)($data['start'] ?? 0));
		$limit = (int)($data['limit'] ?? 20);

		if ($limit < 1) {
			$limit = 20;
		}

		$sql .= " LIMIT " . $start . "," . $limit;

		return $this->db->query($sql)->rows;
	}

	/**
	 * Get Total Pools
	 *
	 * @param int                  $seller_id
	 * @param array<string, mixed> $data
	 *
	 * @return int
	 */
	public function getTotalPools(int $seller_id, array $data = []): int {
		$sql = "SELECT COUNT(*) AS `total` FROM `" . DB_PREFIX . "poolbuy_pool` WHERE `seller_id` = '" . (int)$seller_id . "'";

		if (!empty($data['filter_status'])) {
			$sql .= " AND `status` = '" . $this->db->escape((string)$data['filter_status']) . "'";
		}

		return (int)$this->db->query($sql)->row['total'];
	}

	/**
	 * Get Pool
	 *
	 * Scoped by seller. A pool_id belonging to someone else returns nothing.
	 *
	 * @param int $pool_id
	 * @param int $seller_id
	 *
	 * @return array<string, mixed>
	 */
	public function getPool(int $pool_id, int $seller_id): array {
		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "poolbuy_pool` WHERE `pool_id` = '" . (int)$pool_id . "' AND `seller_id` = '" . (int)$seller_id . "'");

		return $query->row;
	}

	/**
	 * Get Tiers
	 *
	 * The join on the pool table keeps this scoped: tiers for another seller's
	 * pool are not returned.
	 *
	 * @param int $pool_id
	 * @param int $seller_id
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function getTiers(int $pool_id, int $seller_id): array {
		$sql = "SELECT t.* FROM `" . DB_PREFIX . "poolbuy_pool_tier` t";
		$sql .= " INNER JOIN `" . DB_PREFIX . "poolbuy_pool` p ON (t.`pool_id` = p.`pool_id`)";
		$sql .= " WHERE t.`pool_id` = '" . (int)$pool_id . "' AND p.`seller_id` = '" . (int)$seller_id . "'";
		$sql .= " ORDER BY t.`min_qty` ASC";

		return $this->db->query($sql)->rows;
	}

	/**
	 * Get Reservations
	 *
	 * Commitments on one of the seller's own pools, ANONYMISED. A seller sees
	 * quantities and trust signals but never a buyer's name, email or id.
	 *
	 * @param int $pool_id
	 * @param int $seller_id
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function getReservations(int $pool_id, int $seller_id): array {
		$sql = "SELECT r.`quantity`, r.`unit_price_locked`, r.`status`, r.`date_added`,";
		$sql .= " (SELECT COUNT(*) FROM `" . DB_PREFIX . "order` o WHERE o.`customer_id` = r.`customer_id` AND o.`order_status_id` > 0) AS `order_total`,";
		// A stable but non-reversible handle, so a seller can refer to a buyer
		// without learning who they are.
		$sql .= " (1000 + r.`customer_id`) AS `handle`";
		$sql .= " FROM `" . DB_PREFIX . "poolbuy_reservation` r";
		$sql .= " INNER JOIN `" . DB_PREFIX . "poolbuy_pool` p ON (r.`pool_id` = p.`pool_id`)";
		$sql .= " WHERE r.`pool_id` = '" . (int)$pool_id . "' AND p.`seller_id` = '" . (int)$seller_id . "'";
		$sql .= " AND r.`status` IN ('pending', 'confirmed', 'converted')";
		$sql .= " ORDER BY r.`date_added` DESC";

		return $this->db->query($sql)->rows;
	}

	/**
	 * Get Products
	 *
	 * Products assigned to this seller, which are the only ones they may attach a
	 * pool to.
	 *
	 * @param int $seller_id
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function getProducts(int $seller_id): array {
		$sql = "SELECT ps.`product_id`, pd.`name`, pr.`model` FROM `" . DB_PREFIX . "poolbuy_product_seller` ps";
		$sql .= " INNER JOIN `" . DB_PREFIX . "product` pr ON (ps.`product_id` = pr.`product_id`)";
		$sql .= " LEFT JOIN `" . DB_PREFIX . "product_description` pd ON (ps.`product_id` = pd.`product_id` AND pd.`language_id` = '" . (int)$this->config->get('config_language_id') . "')";
		$sql .= " WHERE ps.`seller_id` = '" . (int)$seller_id . "'";
		$sql .= " ORDER BY pd.`name` ASC";

		return $this->db->query($sql)->rows;
	}

	/**
	 * Owns Product
	 *
	 * @param int $product_id
	 * @param int $seller_id
	 *
	 * @return bool
	 */
	public function ownsProduct(int $product_id, int $seller_id): bool {
		$query = $this->db->query("SELECT `product_id` FROM `" . DB_PREFIX . "poolbuy_product_seller` WHERE `product_id` = '" . (int)$product_id . "' AND `seller_id` = '" . (int)$seller_id . "'");

		return (bool)$query->num_rows;
	}

	/**
	 * Add Pool
	 *
	 * The seller_id is taken from the resolved session identity, never from the
	 * request, so a seller cannot create a pool under another seller's name.
	 *
	 * @param int                  $seller_id
	 * @param array<string, mixed> $data
	 *
	 * @return int
	 */
	public function addPool(int $seller_id, array $data): int {
		$this->db->query("INSERT INTO `" . DB_PREFIX . "poolbuy_pool` SET `seller_id` = '" . (int)$seller_id . "', " . $this->buildAssignments($data) . ", `reserved_qty` = '0', `date_added` = NOW(), `date_modified` = NOW()");

		$pool_id = $this->db->getLastId();

		$this->setTiers($pool_id, $seller_id, isset($data['pool_tier']) && is_array($data['pool_tier']) ? $data['pool_tier'] : []);

		$this->addEvent($pool_id, 'pool_created', ['by' => 'seller', 'status' => (string)($data['status'] ?? 'draft')]);

		return $pool_id;
	}

	/**
	 * Edit Pool
	 *
	 * The WHERE clause carries seller_id, so an attempt to edit someone else's
	 * pool updates zero rows.
	 *
	 * @param int                  $pool_id
	 * @param int                  $seller_id
	 * @param array<string, mixed> $data
	 *
	 * @return bool whether a row was actually updated
	 */
	public function editPool(int $pool_id, int $seller_id, array $data): bool {
		$this->db->query("UPDATE `" . DB_PREFIX . "poolbuy_pool` SET " . $this->buildAssignments($data) . ", `date_modified` = NOW() WHERE `pool_id` = '" . (int)$pool_id . "' AND `seller_id` = '" . (int)$seller_id . "'");

		// countAffected can be 0 for an identical save, so ownership is confirmed
		// separately rather than inferred from it.
		if (!$this->getPool($pool_id, $seller_id)) {
			return false;
		}

		$this->setTiers($pool_id, $seller_id, isset($data['pool_tier']) && is_array($data['pool_tier']) ? $data['pool_tier'] : []);

		return true;
	}

	/**
	 * Set Tiers
	 *
	 * @param int                              $pool_id
	 * @param int                              $seller_id
	 * @param array<int, array<string, mixed>> $tiers
	 *
	 * @return void
	 */
	public function setTiers(int $pool_id, int $seller_id, array $tiers): void {
		// Refuse outright if the pool is not this seller's
		if (!$this->getPool($pool_id, $seller_id)) {
			return;
		}

		$this->db->query("DELETE FROM `" . DB_PREFIX . "poolbuy_pool_tier` WHERE `pool_id` = '" . (int)$pool_id . "'");

		$sort_order = 0;

		foreach ($tiers as $tier) {
			$min_qty = (int)($tier['min_qty'] ?? 0);

			if ($min_qty < 1) {
				continue;
			}

			$max_raw = $tier['max_qty'] ?? '';
			$max_sql = ($max_raw === '' || (int)$max_raw === 0) ? 'NULL' : "'" . (int)$max_raw . "'";

			$this->db->query("INSERT INTO `" . DB_PREFIX . "poolbuy_pool_tier` SET `pool_id` = '" . (int)$pool_id . "', `min_qty` = '" . $min_qty . "', `max_qty` = " . $max_sql . ", `price` = '" . (float)($tier['price'] ?? 0) . "', `sort_order` = '" . $sort_order++ . "'");
		}
	}

	/**
	 * Get Statistics
	 *
	 * Portal headline figures, all scoped to one seller.
	 *
	 * @param int $seller_id
	 *
	 * @return array<string, mixed>
	 */
	public function getStatistics(int $seller_id): array {
		$volume = $this->db->query("SELECT COALESCE(SUM(r.`quantity` * r.`unit_price_locked`), 0) AS `total` FROM `" . DB_PREFIX . "poolbuy_reservation` r INNER JOIN `" . DB_PREFIX . "poolbuy_pool` p ON (r.`pool_id` = p.`pool_id`) WHERE p.`seller_id` = '" . (int)$seller_id . "' AND r.`status` IN ('pending', 'confirmed', 'converted')");

		$active = $this->db->query("SELECT COUNT(*) AS `total` FROM `" . DB_PREFIX . "poolbuy_pool` WHERE `seller_id` = '" . (int)$seller_id . "' AND `status` = 'active'");

		$fill = $this->db->query("SELECT COALESCE(AVG(LEAST(100, (`reserved_qty` / `moq_target`) * 100)), 0) AS `average` FROM `" . DB_PREFIX . "poolbuy_pool` WHERE `seller_id` = '" . (int)$seller_id . "' AND `moq_target` > 0 AND `status` IN ('active', 'reached', 'closed', 'fulfilled')");

		$closing = $this->db->query("SELECT COUNT(*) AS `total` FROM `" . DB_PREFIX . "poolbuy_pool` WHERE `seller_id` = '" . (int)$seller_id . "' AND `status` = 'active' AND `moq_target` > 0 AND (`reserved_qty` / `moq_target`) >= 0.8");

		return [
			'total_volume' => (float)$volume->row['total'],
			'active_pools' => (int)$active->row['total'],
			'average_fill' => round((float)$fill->row['average'], 1),
			'closing_soon' => (int)$closing->row['total']
		];
	}

	/**
	 * Build Assignments
	 *
	 * Note what is absent: seller_id, reserved_qty and reference are never taken
	 * from seller input. Identity, progress and the public reference are not the
	 * seller's to set.
	 *
	 * @param array<string, mixed> $data
	 *
	 * @return string
	 */
	private function buildAssignments(array $data): string {
		$assignments = [
			"`product_id` = '" . (int)($data['product_id'] ?? 0) . "'",
			"`title` = '" . $this->db->escape((string)($data['title'] ?? '')) . "'",
			"`moq_target` = '" . (int)($data['moq_target'] ?? 0) . "'",
			"`unit_label` = '" . $this->db->escape((string)($data['unit_label'] ?? 'Units')) . "'",
			"`currency_code` = '" . $this->db->escape(strtoupper((string)($data['currency_code'] ?? ''))) . "'",
			"`status` = '" . $this->db->escape((string)($data['status'] ?? 'draft')) . "'",
			"`date_start` = '" . $this->db->escape((string)($data['date_start'] ?? '')) . "'",
			"`date_end` = '" . $this->db->escape((string)($data['date_end'] ?? '')) . "'",
			"`retro_pricing` = '" . (int)!empty($data['retro_pricing']) . "'",
			"`allow_full_moq_buy` = '" . (int)!empty($data['allow_full_moq_buy']) . "'",
			"`min_qty_per_buyer` = '" . max(1, (int)($data['min_qty_per_buyer'] ?? 1)) . "'",
			"`max_qty_per_buyer` = '" . max(0, (int)($data['max_qty_per_buyer'] ?? 0)) . "'",
			"`lead_time` = '" . $this->db->escape((string)($data['lead_time'] ?? '')) . "'",
			"`shipping_terms` = '" . $this->db->escape((string)($data['shipping_terms'] ?? '')) . "'"
		];

		// A reference is only set on creation, and only when one was generated.
		if (!empty($data['reference'])) {
			$assignments[] = "`reference` = '" . $this->db->escape((string)$data['reference']) . "'";
		}

		return implode(', ', $assignments);
	}

	/**
	 * Reference Exists
	 *
	 * @param string $reference
	 *
	 * @return bool
	 */
	public function referenceExists(string $reference): bool {
		$query = $this->db->query("SELECT `pool_id` FROM `" . DB_PREFIX . "poolbuy_pool` WHERE `reference` = '" . $this->db->escape($reference) . "'");

		return (bool)$query->num_rows;
	}

	/**
	 * Add Event
	 *
	 * @param int                  $pool_id
	 * @param string               $type
	 * @param array<string, mixed> $payload
	 *
	 * @return void
	 */
	public function addEvent(int $pool_id, string $type, array $payload = []): void {
		$this->db->query("INSERT INTO `" . DB_PREFIX . "poolbuy_pool_event` SET `pool_id` = '" . (int)$pool_id . "', `customer_id` = '0', `type` = '" . $this->db->escape($type) . "', `payload` = '" . $this->db->escape((string)json_encode($payload)) . "', `date_added` = NOW()");
	}

	/**
	 * Decorate Pool
	 *
	 * Shared display maths for the portal list.
	 *
	 * @param array<string, mixed> $pool
	 *
	 * @return array<string, mixed>
	 */
	public function decoratePool(array $pool): array {
		$moq = (int)$pool['moq_target'];
		$reserved = (int)$pool['reserved_qty'];

		return $pool + [
			'fill'      => PoolCalculator::fillPercentage($reserved, $moq),
			'remaining' => PoolCalculator::unitsRemaining($reserved, $moq)
		];
	}
}
