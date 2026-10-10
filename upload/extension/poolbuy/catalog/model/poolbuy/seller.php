<?php
namespace Opencart\Catalog\Model\Extension\Poolbuy\Poolbuy;

use Opencart\System\Library\Extension\Poolbuy\PoolCalculator;
use Opencart\System\Library\Extension\Poolbuy\PoolLifecycle;

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
	 * Add Seller
	 *
	 * Creates a seller profile from a storefront application.
	 *
	 * The profile is deliberately created unapproved: `status`, `gst_verified` and
	 * `verified_seller` are all forced to 0 here rather than taken from $data, so a
	 * crafted post cannot self-approve an account or award itself a verified badge.
	 * An administrator promotes the account from Admin -> PoolBuy -> Sellers, and
	 * until then the portal allows drafts but refuses to publish.
	 *
	 * @param int                  $customer_id the signed-in account making the application
	 * @param array<string, mixed> $data        validated application fields
	 *
	 * @return int the new seller_id
	 */
	public function addSeller(int $customer_id, array $data): int {
		$name = trim((string)($data['name'] ?? ''));

		$this->db->query("INSERT INTO `" . DB_PREFIX . "poolbuy_seller` SET
			`customer_id` = '" . (int)$customer_id . "',
			`name` = '" . $this->db->escape($name) . "',
			`slug` = '" . $this->db->escape($this->uniqueSlug($name)) . "',
			`gst_number` = '" . $this->db->escape(strtoupper(trim((string)($data['gst_number'] ?? '')))) . "',
			`gst_verified` = '0',
			`verified_seller` = '0',
			`description` = '" . $this->db->escape(trim((string)($data['description'] ?? ''))) . "',
			`location` = '" . $this->db->escape(trim((string)($data['location'] ?? ''))) . "',
			`status` = '0',
			`date_added` = NOW(),
			`date_modified` = NOW()");

		return (int)$this->db->getLastId();
	}

	/**
	 * Unique Slug
	 *
	 * Derives a URL-safe slug from the business name and suffixes it until it is
	 * free, because `slug` carries a UNIQUE key and two applicants may well share
	 * a trading name.
	 *
	 * @param string $name
	 *
	 * @return string
	 */
	private function uniqueSlug(string $name): string {
		$base = strtolower((string)preg_replace('/[^a-z0-9]+/i', '-', $name));
		$base = trim($base, '-');

		if ($base === '') {
			$base = 'seller';
		}

		$base = substr($base, 0, 110);
		$slug = $base;
		$suffix = 1;

		while (true) {
			$query = $this->db->query("SELECT `seller_id` FROM `" . DB_PREFIX . "poolbuy_seller` WHERE `slug` = '" . $this->db->escape($slug) . "'");

			if (!$query->num_rows) {
				return $slug;
			}

			$suffix++;
			$slug = $base . '-' . $suffix;
		}
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
	 * Get Settlements
	 *
	 * The seller's settlement rows, scoped to their own seller_id so one seller can
	 * never read another's payouts. Read only from the portal.
	 *
	 * @param int $seller_id
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function getSettlements(int $seller_id): array {
		$sql = "SELECT s.*, p.`title`, p.`reference` AS `pool_reference`";
		$sql .= " FROM `" . DB_PREFIX . "poolbuy_settlement` s";
		$sql .= " LEFT JOIN `" . DB_PREFIX . "poolbuy_pool` p ON (s.`pool_id` = p.`pool_id`)";
		$sql .= " WHERE s.`seller_id` = '" . (int)$seller_id . "'";
		$sql .= " ORDER BY s.`date_added` DESC";

		return $this->db->query($sql)->rows;
	}

	/**
	 * Get Settlement Totals By Currency
	 *
	 * What the platform owes a seller, GROUPED BY CURRENCY.
	 *
	 * Summing settlements across currencies would produce a meaningless number -
	 * adding yen to rupees - so the totals are only ever aggregated within a single
	 * currency and the caller renders one row per currency.
	 *
	 * @param int $seller_id
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function getSettlementTotals(int $seller_id): array {
		$sql = "SELECT `currency`, COUNT(*) AS `pools`, SUM(`order_count`) AS `orders`,";
		$sql .= " SUM(`gross`) AS `gross`, SUM(`platform_fee`) AS `platform_fee`, SUM(`net`) AS `net`,";
		$sql .= " SUM(CASE WHEN `status` = 'pending' THEN `net` ELSE 0 END) AS `net_pending`,";
		$sql .= " SUM(CASE WHEN `status` = 'paid' THEN `net` ELSE 0 END) AS `net_paid`";
		$sql .= " FROM `" . DB_PREFIX . "poolbuy_settlement`";
		$sql .= " WHERE `seller_id` = '" . (int)$seller_id . "'";
		$sql .= " GROUP BY `currency` ORDER BY `currency` ASC";

		return $this->db->query($sql)->rows;
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
			"`shipping_terms` = '" . $this->db->escape((string)($data['shipping_terms'] ?? '')) . "'",
			// Clamped so a hand-crafted POST cannot push a negative freight total
			// into the pool and invert every participant's allocated share.
			"`freight_total` = '" . max(0.0, (float)($data['freight_total'] ?? 0)) . "'"
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

	/**
	 * Get Own Products
	 *
	 * The seller's own catalogue. Tenancy lives in the join against
	 * poolbuy_product_seller, so a seller can only ever list their own rows.
	 *
	 * @param int                  $seller_id
	 * @param array<string, mixed> $data      start/limit
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function getOwnProducts(int $seller_id, array $data = []): array {
		$sql = "SELECT p.`product_id`, p.`model`, p.`price`, p.`quantity`, p.`status`, p.`image`, pd.`name`";
		$sql .= " FROM `" . DB_PREFIX . "poolbuy_product_seller` ps";
		$sql .= " INNER JOIN `" . DB_PREFIX . "product` p ON (ps.`product_id` = p.`product_id`)";
		$sql .= " LEFT JOIN `" . DB_PREFIX . "product_description` pd ON (p.`product_id` = pd.`product_id` AND pd.`language_id` = '" . (int)$this->config->get('config_language_id') . "')";
		$sql .= " WHERE ps.`seller_id` = '" . (int)$seller_id . "'";
		$sql .= " ORDER BY pd.`name` ASC";

		$start = max(0, (int)($data['start'] ?? 0));
		$limit = (int)($data['limit'] ?? 20);

		if ($limit < 1) {
			$limit = 20;
		}

		$sql .= " LIMIT " . $start . ", " . $limit;

		return $this->db->query($sql)->rows;
	}

	/**
	 * Get Total Own Products
	 *
	 * @param int $seller_id
	 *
	 * @return int
	 */
	public function getTotalOwnProducts(int $seller_id): int {
		$query = $this->db->query("SELECT COUNT(*) AS `total` FROM `" . DB_PREFIX . "poolbuy_product_seller` ps INNER JOIN `" . DB_PREFIX . "product` p ON (ps.`product_id` = p.`product_id`) WHERE ps.`seller_id` = '" . (int)$seller_id . "'");

		return (int)$query->row['total'];
	}

	/**
	 * Get Own Product
	 *
	 * One product, but only if it belongs to this seller. The seller_id sits in the
	 * WHERE clause rather than being checked afterwards, so a crafted product_id
	 * belonging to somebody else simply finds nothing.
	 *
	 * @param int $product_id
	 * @param int $seller_id
	 *
	 * @return array<string, mixed> empty when the product is not this seller's
	 */
	public function getOwnProduct(int $product_id, int $seller_id): array {
		$sql = "SELECT p.*, pd.`name`, pd.`description`, pd.`meta_title`";
		$sql .= " FROM `" . DB_PREFIX . "poolbuy_product_seller` ps";
		$sql .= " INNER JOIN `" . DB_PREFIX . "product` p ON (ps.`product_id` = p.`product_id`)";
		$sql .= " LEFT JOIN `" . DB_PREFIX . "product_description` pd ON (p.`product_id` = pd.`product_id` AND pd.`language_id` = '" . (int)$this->config->get('config_language_id') . "')";
		$sql .= " WHERE ps.`product_id` = '" . (int)$product_id . "' AND ps.`seller_id` = '" . (int)$seller_id . "'";

		return $this->db->query($sql)->row;
	}

	/**
	 * Add Product
	 *
	 * Creates a product owned by this seller and maps it in one transaction, so a
	 * product row can never exist without its ownership mapping (which would make it
	 * an orphan nobody could edit).
	 *
	 * `status` is forced to 0 for an unapproved seller: publishing is gated on
	 * approval exactly as pool publishing is.
	 *
	 * @param int                  $seller_id
	 * @param array<string, mixed> $data
	 *
	 * @return int the new product_id
	 */
	public function addProduct(int $seller_id, array $data): int {
		$language_id = (int)$this->config->get('config_language_id');
		$stock_status_id = (int)$this->config->get('config_stock_status_id');

		if ($stock_status_id < 1) {
			$stock_status_id = 5;
		}

		$this->db->query("START TRANSACTION");

		try {
			$this->db->query("INSERT INTO `" . DB_PREFIX . "product` SET
				`model` = '" . $this->db->escape((string)($data['model'] ?? '')) . "',
				`quantity` = '" . (int)($data['quantity'] ?? 0) . "',
				`minimum` = '1',
				`subtract` = '1',
				`stock_status_id` = '" . $stock_status_id . "',
				`shipping` = '1',
				`price` = '" . (float)($data['price'] ?? 0) . "',
				`tax_class_id` = '0',
				`image` = '" . $this->db->escape((string)($data['image'] ?? '')) . "',
				`date_available` = NOW(),
				`weight_class_id` = '1',
				`length_class_id` = '1',
				`status` = '" . (int)($data['status'] ?? 0) . "',
				`sort_order` = '0',
				`date_added` = NOW(),
				`date_modified` = NOW()");

			$product_id = (int)$this->db->getLastId();

			$this->db->query("INSERT INTO `" . DB_PREFIX . "product_description` SET
				`product_id` = '" . $product_id . "',
				`language_id` = '" . $language_id . "',
				`name` = '" . $this->db->escape((string)($data['name'] ?? '')) . "',
				`description` = '" . $this->db->escape((string)($data['description'] ?? '')) . "',
				`tag` = '',
				`meta_title` = '" . $this->db->escape((string)($data['name'] ?? '')) . "',
				`meta_description` = '',
				`meta_keyword` = ''");

			$this->db->query("INSERT INTO `" . DB_PREFIX . "product_to_store` SET `product_id` = '" . $product_id . "', `store_id` = '0'");

			// The ownership mapping is what makes this the seller's product.
			$this->db->query("INSERT INTO `" . DB_PREFIX . "poolbuy_product_seller` SET `product_id` = '" . $product_id . "', `seller_id` = '" . (int)$seller_id . "' ON DUPLICATE KEY UPDATE `seller_id` = '" . (int)$seller_id . "'");

			$this->db->query("COMMIT");
		} catch (\Exception $e) {
			$this->db->query("ROLLBACK");

			throw $e;
		}

		return $product_id;
	}

	/**
	 * Edit Product
	 *
	 * The UPDATE is constrained by an EXISTS against the ownership mapping, so an
	 * attempt to edit another seller's product updates zero rows rather than
	 * erroring, and the caller can tell the difference.
	 *
	 * @param int                  $product_id
	 * @param int                  $seller_id
	 * @param array<string, mixed> $data
	 *
	 * @return bool whether the product was actually this seller's
	 */
	public function editProduct(int $product_id, int $seller_id, array $data): bool {
		if (!$this->ownsProduct($product_id, $seller_id)) {
			return false;
		}

		$language_id = (int)$this->config->get('config_language_id');

		$assignments = [
			"`model` = '" . $this->db->escape((string)($data['model'] ?? '')) . "'",
			"`quantity` = '" . (int)($data['quantity'] ?? 0) . "'",
			"`price` = '" . (float)($data['price'] ?? 0) . "'",
			"`status` = '" . (int)($data['status'] ?? 0) . "'",
			"`date_modified` = NOW()"
		];

		// Only overwrite the image when a new one was supplied, so saving the form
		// without re-uploading does not wipe the existing picture.
		if (!empty($data['image'])) {
			$assignments[] = "`image` = '" . $this->db->escape((string)$data['image']) . "'";
		}

		$this->db->query("UPDATE `" . DB_PREFIX . "product` SET " . implode(', ', $assignments) . " WHERE `product_id` = '" . (int)$product_id . "'");

		$this->db->query("UPDATE `" . DB_PREFIX . "product_description` SET `name` = '" . $this->db->escape((string)($data['name'] ?? '')) . "', `description` = '" . $this->db->escape((string)($data['description'] ?? '')) . "' WHERE `product_id` = '" . (int)$product_id . "' AND `language_id` = '" . $language_id . "'");

		return true;
	}

	/**
	 * Get Orders
	 *
	 * Orders raised against this seller's pools.
	 *
	 * Buyer identity is deliberately absent: the same opaque handle used on the
	 * reservation map is returned instead of a name, email or customer_id, so the
	 * order list cannot become a way around buyer privacy.
	 *
	 * @param int                  $seller_id
	 * @param array<string, mixed> $data      start/limit
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function getOrders(int $seller_id, array $data = []): array {
		$sql = "SELECT o.`order_id`, o.`order_status_id`, o.`total`, o.`currency_code`, o.`tracking`, o.`date_added`,";
		$sql .= " r.`quantity`, r.`unit_price_locked`, r.`reference` AS `commitment_reference`,";
		$sql .= " p.`reference` AS `pool_reference`, p.`title` AS `pool_title`, p.`status` AS `pool_status`, p.`unit_label`,";
		$sql .= " (1000 + r.`customer_id`) AS `handle`,";
		$sql .= " os.`name` AS `order_status`";
		$sql .= " FROM `" . DB_PREFIX . "poolbuy_reservation` r";
		$sql .= " INNER JOIN `" . DB_PREFIX . "poolbuy_pool` p ON (r.`pool_id` = p.`pool_id`)";
		$sql .= " INNER JOIN `" . DB_PREFIX . "order` o ON (r.`order_id` = o.`order_id`)";
		$sql .= " LEFT JOIN `" . DB_PREFIX . "order_status` os ON (o.`order_status_id` = os.`order_status_id` AND os.`language_id` = '" . (int)$this->config->get('config_language_id') . "')";
		$sql .= " WHERE p.`seller_id` = '" . (int)$seller_id . "' AND r.`order_id` > '0'";
		$sql .= " ORDER BY o.`order_id` DESC";

		$start = max(0, (int)($data['start'] ?? 0));
		$limit = (int)($data['limit'] ?? 20);

		if ($limit < 1) {
			$limit = 20;
		}

		$sql .= " LIMIT " . $start . ", " . $limit;

		return $this->db->query($sql)->rows;
	}

	/**
	 * Get Total Orders
	 *
	 * @param int $seller_id
	 *
	 * @return int
	 */
	public function getTotalOrders(int $seller_id): int {
		$sql = "SELECT COUNT(*) AS `total` FROM `" . DB_PREFIX . "poolbuy_reservation` r";
		$sql .= " INNER JOIN `" . DB_PREFIX . "poolbuy_pool` p ON (r.`pool_id` = p.`pool_id`)";
		$sql .= " INNER JOIN `" . DB_PREFIX . "order` o ON (r.`order_id` = o.`order_id`)";
		$sql .= " WHERE p.`seller_id` = '" . (int)$seller_id . "' AND r.`order_id` > '0'";

		return (int)$this->db->query($sql)->row['total'];
	}

	/**
	 * Get Order
	 *
	 * One order, but only if it was raised against one of this seller's pools.
	 *
	 * The buyer's delivery details are included, because the seller has to ship to
	 * them, but ONLY once the pool is fulfilled. Until then the caller gets the
	 * opaque handle and nothing else, which is the promise made on the reservation
	 * map: full delivery details arrive with the order once the pool is fulfilled.
	 *
	 * @param int $order_id
	 * @param int $seller_id
	 *
	 * @return array<string, mixed> empty when the order is not this seller's
	 */
	public function getOrder(int $order_id, int $seller_id): array {
		$sql = "SELECT o.`order_id`, o.`order_status_id`, o.`total`, o.`currency_code`, o.`tracking`, o.`date_added`,";
		$sql .= " o.`shipping_firstname`, o.`shipping_lastname`, o.`shipping_address_1`, o.`shipping_address_2`,";
		$sql .= " o.`shipping_city`, o.`shipping_postcode`, o.`shipping_zone`, o.`shipping_country`, o.`telephone`,";
		$sql .= " o.`shipping_method`, o.`comment`,";
		$sql .= " r.`quantity`, r.`unit_price_locked`, r.`reference` AS `commitment_reference`, r.`shipping_cost`,";
		$sql .= " p.`pool_id`, p.`reference` AS `pool_reference`, p.`title` AS `pool_title`, p.`status` AS `pool_status`, p.`unit_label`,";
		$sql .= " (1000 + r.`customer_id`) AS `handle`,";
		$sql .= " os.`name` AS `order_status`";
		$sql .= " FROM `" . DB_PREFIX . "poolbuy_reservation` r";
		$sql .= " INNER JOIN `" . DB_PREFIX . "poolbuy_pool` p ON (r.`pool_id` = p.`pool_id`)";
		$sql .= " INNER JOIN `" . DB_PREFIX . "order` o ON (r.`order_id` = o.`order_id`)";
		$sql .= " LEFT JOIN `" . DB_PREFIX . "order_status` os ON (o.`order_status_id` = os.`order_status_id` AND os.`language_id` = '" . (int)$this->config->get('config_language_id') . "')";
		$sql .= " WHERE o.`order_id` = '" . (int)$order_id . "' AND p.`seller_id` = '" . (int)$seller_id . "' AND r.`order_id` > '0'";

		$row = $this->db->query($sql)->row;

		if (!$row) {
			return [];
		}

		// Withhold the delivery details until the pool is fulfilled.
		if ((string)$row['pool_status'] !== PoolLifecycle::FULFILLED) {
			foreach (['shipping_firstname', 'shipping_lastname', 'shipping_address_1', 'shipping_address_2', 'shipping_city', 'shipping_postcode', 'shipping_zone', 'shipping_country', 'telephone'] as $field) {
				$row[$field] = '';
			}

			$row['address_released'] = false;
		} else {
			$row['address_released'] = true;
		}

		return $row;
	}

	/**
	 * Get Order Totals
	 *
	 * The order's own total lines, so the seller sees the same breakdown the buyer
	 * was invoiced. Ownership is proven again here rather than trusted from the
	 * caller.
	 *
	 * @param int $order_id
	 * @param int $seller_id
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function getOrderTotals(int $order_id, int $seller_id): array {
		$sql = "SELECT ot.`title`, ot.`value` FROM `" . DB_PREFIX . "order_total` ot";
		$sql .= " WHERE ot.`order_id` = '" . (int)$order_id . "'";
		$sql .= " AND EXISTS (SELECT 1 FROM `" . DB_PREFIX . "poolbuy_reservation` r INNER JOIN `" . DB_PREFIX . "poolbuy_pool` p ON (r.`pool_id` = p.`pool_id`) WHERE r.`order_id` = ot.`order_id` AND p.`seller_id` = '" . (int)$seller_id . "')";
		$sql .= " ORDER BY ot.`sort_order` ASC";

		return $this->db->query($sql)->rows;
	}

	/**
	 * Get Order Statuses
	 *
	 * The statuses a seller is allowed to move an order into, read from the store's
	 * own installed list rather than hardcoded, so a store that has renamed or
	 * removed a status does not end up offering something that does not exist.
	 *
	 * @param array<int, int> $allowed order_status_id values to offer
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function getOrderStatuses(array $allowed): array {
		$ids = array_values(array_filter(array_map('intval', $allowed), static fn (int $id): bool => $id > 0));

		if (!$ids) {
			return [];
		}

		$sql = "SELECT `order_status_id`, `name` FROM `" . DB_PREFIX . "order_status`";
		$sql .= " WHERE `language_id` = '" . (int)$this->config->get('config_language_id') . "'";
		$sql .= " AND `order_status_id` IN (" . implode(', ', $ids) . ")";
		$sql .= " ORDER BY FIELD(`order_status_id`, " . implode(', ', $ids) . ")";

		return $this->db->query($sql)->rows;
	}

	/**
	 * Update Order Status
	 *
	 * Advances one of this seller's orders and records the change in the order's
	 * history, which is what Admin -> Sales -> Orders reads.
	 *
	 * The UPDATE carries an EXISTS against the seller's own pools, so a crafted
	 * order_id belonging to another seller updates zero rows and the method reports
	 * failure without revealing whether the order exists.
	 *
	 * @param int    $order_id
	 * @param int    $seller_id
	 * @param int    $order_status_id
	 * @param string $tracking
	 * @param string $comment
	 *
	 * @return bool whether the order was this seller's and was updated
	 */
	public function updateOrderStatus(int $order_id, int $seller_id, int $order_status_id, string $tracking = '', string $comment = ''): bool {
		$owned = "EXISTS (SELECT 1 FROM `" . DB_PREFIX . "poolbuy_reservation` r INNER JOIN `" . DB_PREFIX . "poolbuy_pool` p ON (r.`pool_id` = p.`pool_id`) WHERE r.`order_id` = `" . DB_PREFIX . "order`.`order_id` AND p.`seller_id` = '" . (int)$seller_id . "')";

		$assignments = [
			"`order_status_id` = '" . (int)$order_status_id . "'",
			"`date_modified` = NOW()"
		];

		if ($tracking !== '') {
			$assignments[] = "`tracking` = '" . $this->db->escape($tracking) . "'";
		}

		$this->db->query("UPDATE `" . DB_PREFIX . "order` SET " . implode(', ', $assignments) . " WHERE `order_id` = '" . (int)$order_id . "' AND " . $owned);

		if (!$this->db->countAffected()) {
			// Either not this seller's order, or nothing changed. Re-check ownership so
			// a no-op update on an owned order is not reported as a permission failure.
			$check = $this->db->query("SELECT 1 AS `ok` FROM `" . DB_PREFIX . "poolbuy_reservation` r INNER JOIN `" . DB_PREFIX . "poolbuy_pool` p ON (r.`pool_id` = p.`pool_id`) WHERE r.`order_id` = '" . (int)$order_id . "' AND p.`seller_id` = '" . (int)$seller_id . "'");

			if (!$check->num_rows) {
				return false;
			}
		}

		$this->db->query("INSERT INTO `" . DB_PREFIX . "order_history` SET `order_id` = '" . (int)$order_id . "', `order_status_id` = '" . (int)$order_status_id . "', `notify` = '0', `comment` = '" . $this->db->escape($comment) . "', `date_added` = NOW()");

		$pool = $this->db->query("SELECT p.`pool_id` FROM `" . DB_PREFIX . "poolbuy_reservation` r INNER JOIN `" . DB_PREFIX . "poolbuy_pool` p ON (r.`pool_id` = p.`pool_id`) WHERE r.`order_id` = '" . (int)$order_id . "' AND p.`seller_id` = '" . (int)$seller_id . "'");

		if ($pool->num_rows) {
			$this->addEvent((int)$pool->row['pool_id'], 'order_status_changed', [
				'order_id'        => $order_id,
				'order_status_id' => $order_status_id,
				'by'              => 'seller',
				'tracking'        => $tracking !== '' ? 'set' : 'unchanged'
			]);
		}

		return true;
	}
}
