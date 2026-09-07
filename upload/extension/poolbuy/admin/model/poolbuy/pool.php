<?php
namespace Opencart\Admin\Model\Extension\Poolbuy\Poolbuy;
/**
 * Class Pool
 *
 * Data access for pools and their price tiers.
 *
 * @package Opencart\Admin\Model\Extension\Poolbuy\Poolbuy
 */
class Pool extends \Opencart\System\Engine\Model {
	/**
	 * Add Pool
	 *
	 * @param array<string, mixed> $data
	 *
	 * @return int
	 */
	public function addPool(array $data): int {
		$this->db->query("INSERT INTO `" . DB_PREFIX . "poolbuy_pool` SET " . $this->buildAssignments($data) . ", `reserved_qty` = '0', `date_added` = NOW(), `date_modified` = NOW()");

		$pool_id = $this->db->getLastId();

		$this->setTiers($pool_id, isset($data['pool_tier']) && is_array($data['pool_tier']) ? $data['pool_tier'] : []);

		$this->addEvent($pool_id, 'pool_created', ['status' => (string)($data['status'] ?? 'draft')]);

		return $pool_id;
	}

	/**
	 * Edit Pool
	 *
	 * `reserved_qty` is deliberately not writable here - it is a derived value
	 * maintained only by reservation activity, so an admin edit can never
	 * fabricate pool progress.
	 *
	 * @param int                  $pool_id
	 * @param array<string, mixed> $data
	 *
	 * @return void
	 */
	public function editPool(int $pool_id, array $data): void {
		$before = $this->getPool($pool_id);

		$this->db->query("UPDATE `" . DB_PREFIX . "poolbuy_pool` SET " . $this->buildAssignments($data) . ", `date_modified` = NOW() WHERE `pool_id` = '" . (int)$pool_id . "'");

		$this->setTiers($pool_id, isset($data['pool_tier']) && is_array($data['pool_tier']) ? $data['pool_tier'] : []);

		$new_status = (string)($data['status'] ?? '');

		if ($before && $new_status !== '' && $new_status !== (string)$before['status']) {
			$this->addEvent($pool_id, 'status_changed', [
				'from' => (string)$before['status'],
				'to'   => $new_status
			]);
		}
	}

	/**
	 * Delete Pool
	 *
	 * @param int $pool_id
	 *
	 * @return void
	 */
	public function deletePool(int $pool_id): void {
		$this->db->query("DELETE FROM `" . DB_PREFIX . "poolbuy_pool` WHERE `pool_id` = '" . (int)$pool_id . "'");
		$this->db->query("DELETE FROM `" . DB_PREFIX . "poolbuy_pool_tier` WHERE `pool_id` = '" . (int)$pool_id . "'");
		$this->db->query("DELETE FROM `" . DB_PREFIX . "poolbuy_reservation` WHERE `pool_id` = '" . (int)$pool_id . "'");
		$this->db->query("DELETE FROM `" . DB_PREFIX . "poolbuy_pool_event` WHERE `pool_id` = '" . (int)$pool_id . "'");
	}

	/**
	 * Get Pool
	 *
	 * @param int $pool_id
	 *
	 * @return array<string, mixed>
	 */
	public function getPool(int $pool_id): array {
		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "poolbuy_pool` WHERE `pool_id` = '" . (int)$pool_id . "'");

		return $query->row;
	}

	/**
	 * Get Pool By Reference
	 *
	 * @param string $reference
	 *
	 * @return array<string, mixed>
	 */
	public function getPoolByReference(string $reference): array {
		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "poolbuy_pool` WHERE `reference` = '" . $this->db->escape($reference) . "'");

		return $query->row;
	}

	/**
	 * Get Pools
	 *
	 * @param array<string, mixed> $data
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function getPools(array $data = []): array {
		$sql = "SELECT p.*, pd.`name` AS `product_name`, s.`name` AS `seller_name`, pr.`model` AS `product_model`";
		$sql .= " FROM `" . DB_PREFIX . "poolbuy_pool` p";
		$sql .= " LEFT JOIN `" . DB_PREFIX . "product` pr ON (p.`product_id` = pr.`product_id`)";
		$sql .= " LEFT JOIN `" . DB_PREFIX . "product_description` pd ON (p.`product_id` = pd.`product_id` AND pd.`language_id` = '" . (int)$this->config->get('config_language_id') . "')";
		$sql .= " LEFT JOIN `" . DB_PREFIX . "poolbuy_seller` s ON (p.`seller_id` = s.`seller_id`)";

		$sql .= $this->buildFilters($data);

		$sorts = [
			'title'        => 'p.`title`',
			'product_name' => 'pd.`name`',
			'seller_name'  => 's.`name`',
			'moq_target'   => 'p.`moq_target`',
			'reserved_qty' => 'p.`reserved_qty`',
			'status'       => 'p.`status`',
			'date_end'     => 'p.`date_end`'
		];

		$sort = (isset($data['sort']) && isset($sorts[$data['sort']])) ? $sorts[$data['sort']] : 'p.`date_end`';

		$sql .= " ORDER BY " . $sort;
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
	 * Get Total Pools
	 *
	 * @param array<string, mixed> $data
	 *
	 * @return int
	 */
	public function getTotalPools(array $data = []): int {
		$sql = "SELECT COUNT(*) AS `total` FROM `" . DB_PREFIX . "poolbuy_pool` p";
		$sql .= " LEFT JOIN `" . DB_PREFIX . "product_description` pd ON (p.`product_id` = pd.`product_id` AND pd.`language_id` = '" . (int)$this->config->get('config_language_id') . "')";
		$sql .= " LEFT JOIN `" . DB_PREFIX . "poolbuy_seller` s ON (p.`seller_id` = s.`seller_id`)";
		$sql .= $this->buildFilters($data);

		$query = $this->db->query($sql);

		return (int)$query->row['total'];
	}

	/**
	 * Set Tiers
	 *
	 * Replaces the pool's price ladder wholesale. An empty `max_qty` is stored as
	 * NULL so the top tier is genuinely open ended.
	 *
	 * @param int                              $pool_id
	 * @param array<int, array<string, mixed>> $tiers
	 *
	 * @return void
	 */
	public function setTiers(int $pool_id, array $tiers): void {
		$this->db->query("DELETE FROM `" . DB_PREFIX . "poolbuy_pool_tier` WHERE `pool_id` = '" . (int)$pool_id . "'");

		$sort_order = 0;

		foreach ($tiers as $tier) {
			$min_qty = (int)($tier['min_qty'] ?? 0);

			if ($min_qty < 1) {
				continue;
			}

			// A blank or zero ceiling means the top, open-ended tier. The null
			// coalesce above already rules out null, so '' and 0 are the only
			// "no ceiling" forms left to check.
			$max_raw = $tier['max_qty'] ?? '';
			$max_sql = ($max_raw === '' || (int)$max_raw === 0) ? 'NULL' : "'" . (int)$max_raw . "'";

			$this->db->query("INSERT INTO `" . DB_PREFIX . "poolbuy_pool_tier` SET `pool_id` = '" . (int)$pool_id . "', `min_qty` = '" . $min_qty . "', `max_qty` = " . $max_sql . ", `price` = '" . (float)($tier['price'] ?? 0) . "', `sort_order` = '" . $sort_order++ . "'");
		}
	}

	/**
	 * Get Tiers
	 *
	 * @param int $pool_id
	 *
	 * @return array<int, array<string, mixed>> ordered by ascending minimum quantity
	 */
	public function getTiers(int $pool_id): array {
		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "poolbuy_pool_tier` WHERE `pool_id` = '" . (int)$pool_id . "' ORDER BY `min_qty` ASC");

		return $query->rows;
	}

	/**
	 * Get Reservations
	 *
	 * @param int $pool_id
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function getReservations(int $pool_id): array {
		$sql = "SELECT r.*, CONCAT(c.`firstname`, ' ', c.`lastname`) AS `customer_name`";
		$sql .= " FROM `" . DB_PREFIX . "poolbuy_reservation` r";
		$sql .= " LEFT JOIN `" . DB_PREFIX . "customer` c ON (r.`customer_id` = c.`customer_id`)";
		$sql .= " WHERE r.`pool_id` = '" . (int)$pool_id . "'";
		$sql .= " ORDER BY r.`date_added` DESC";

		$query = $this->db->query($sql);

		return $query->rows;
	}

	/**
	 * Recalculate Reserved Quantity
	 *
	 * Recomputes the cached total from the reservations that are actually live.
	 * Used after administrative edits so the cache can never drift from reality.
	 *
	 * @param int $pool_id
	 *
	 * @return int the recalculated total
	 */
	public function recalculateReservedQty(int $pool_id): int {
		$query = $this->db->query("SELECT COALESCE(SUM(`quantity`), 0) AS `total` FROM `" . DB_PREFIX . "poolbuy_reservation` WHERE `pool_id` = '" . (int)$pool_id . "' AND `status` IN ('pending', 'confirmed', 'converted')");

		$total = (int)$query->row['total'];

		$this->db->query("UPDATE `" . DB_PREFIX . "poolbuy_pool` SET `reserved_qty` = '" . $total . "' WHERE `pool_id` = '" . (int)$pool_id . "'");

		return $total;
	}

	/**
	 * Set Status
	 *
	 * Moves a pool to a new status without touching any other field. Used by the
	 * close-early action and by the lifecycle cron.
	 *
	 * @param int    $pool_id
	 * @param string $status
	 *
	 * @return void
	 */
	public function setStatus(int $pool_id, string $status): void {
		$this->db->query("UPDATE `" . DB_PREFIX . "poolbuy_pool` SET `status` = '" . $this->db->escape($status) . "', `date_modified` = NOW() WHERE `pool_id` = '" . (int)$pool_id . "'");
	}

	/**
	 * Release Reservations
	 *
	 * Marks every live commitment on a pool as released. Used when a pool closes
	 * without meeting its MOQ, so buyers are not left holding a commitment that
	 * will never be fulfilled.
	 *
	 * @param int $pool_id
	 *
	 * @return int number of reservations released
	 */
	public function releaseReservations(int $pool_id): int {
		$this->db->query("UPDATE `" . DB_PREFIX . "poolbuy_reservation` SET `status` = 'released', `date_modified` = NOW() WHERE `pool_id` = '" . (int)$pool_id . "' AND `status` IN ('pending', 'confirmed')");

		return (int)$this->db->countAffected();
	}

	/**
	 * Add Event
	 *
	 * Appends to the pool's audit trail.
	 *
	 * @param int                  $pool_id
	 * @param string               $type
	 * @param array<string, mixed> $payload
	 * @param int                  $customer_id
	 *
	 * @return void
	 */
	public function addEvent(int $pool_id, string $type, array $payload = [], int $customer_id = 0): void {
		$this->db->query("INSERT INTO `" . DB_PREFIX . "poolbuy_pool_event` SET `pool_id` = '" . (int)$pool_id . "', `customer_id` = '" . (int)$customer_id . "', `type` = '" . $this->db->escape($type) . "', `payload` = '" . $this->db->escape((string)json_encode($payload)) . "', `date_added` = NOW()");
	}

	/**
	 * Get Events
	 *
	 * @param int $pool_id
	 * @param int $limit
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function getEvents(int $pool_id, int $limit = 20): array {
		$limit = max(1, $limit);

		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "poolbuy_pool_event` WHERE `pool_id` = '" . (int)$pool_id . "' ORDER BY `date_added` DESC, `event_id` DESC LIMIT " . $limit);

		return $query->rows;
	}

	/**
	 * Get Statistics
	 *
	 * Feeds the three stat cards above the pool list.
	 *
	 * @return array<string, mixed>
	 */
	public function getStatistics(): array {
		$volume = $this->db->query("SELECT COALESCE(SUM(r.`quantity` * r.`unit_price_locked`), 0) AS `total` FROM `" . DB_PREFIX . "poolbuy_reservation` r WHERE r.`status` IN ('pending', 'confirmed', 'converted')");

		$active = $this->db->query("SELECT COUNT(*) AS `total` FROM `" . DB_PREFIX . "poolbuy_pool` WHERE `status` = 'active'");

		// Average fill across pools that have a meaningful target
		$fill = $this->db->query("SELECT COALESCE(AVG(LEAST(100, (`reserved_qty` / `moq_target`) * 100)), 0) AS `average` FROM `" . DB_PREFIX . "poolbuy_pool` WHERE `moq_target` > 0 AND `status` IN ('active', 'reached', 'closed', 'fulfilled')");

		// Pools within 20% of their MOQ, i.e. the ones worth preparing stock for
		$closing = $this->db->query("SELECT COUNT(*) AS `total` FROM `" . DB_PREFIX . "poolbuy_pool` WHERE `status` = 'active' AND `moq_target` > 0 AND (`reserved_qty` / `moq_target`) >= 0.8");

		return [
			'total_volume' => (float)$volume->row['total'],
			'active_pools' => (int)$active->row['total'],
			'average_fill' => round((float)$fill->row['average'], 1),
			'closing_soon' => (int)$closing->row['total']
		];
	}

	/**
	 * Build Filters
	 *
	 * @param array<string, mixed> $data
	 *
	 * @return string
	 */
	private function buildFilters(array $data): string {
		$conditions = [];

		if (!empty($data['filter_product'])) {
			$conditions[] = "pd.`name` LIKE '" . $this->db->escape('%' . (string)$data['filter_product'] . '%') . "'";
		}

		if (!empty($data['filter_status'])) {
			$conditions[] = "p.`status` = '" . $this->db->escape((string)$data['filter_status']) . "'";
		}

		if (!empty($data['filter_seller_id'])) {
			$conditions[] = "p.`seller_id` = '" . (int)$data['filter_seller_id'] . "'";
		}

		return $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
	}

	/**
	 * Build Assignments
	 *
	 * @param array<string, mixed> $data
	 *
	 * @return string
	 */
	private function buildAssignments(array $data): string {
		$assignments = [
			"`product_id` = '" . (int)($data['product_id'] ?? 0) . "'",
			"`seller_id` = '" . (int)($data['seller_id'] ?? 0) . "'",
			"`title` = '" . $this->db->escape((string)($data['title'] ?? '')) . "'",
			"`reference` = '" . $this->db->escape((string)($data['reference'] ?? '')) . "'",
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

		return implode(', ', $assignments);
	}
}
