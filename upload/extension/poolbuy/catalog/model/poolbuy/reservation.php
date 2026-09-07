<?php
namespace Opencart\Catalog\Model\Extension\Poolbuy\Poolbuy;
/**
 * Class Reservation
 *
 * Buyer commitments. Reservations are deliberately not cart items: they only
 * become oc_order rows once a pool closes successfully, which is what makes
 * retroactive repricing and pool expiry safe.
 *
 * @package Opencart\Catalog\Model\Extension\Poolbuy\Poolbuy
 */
class Reservation extends \Opencart\System\Engine\Model {
	/**
	 * Statuses in which a reservation still holds units in the pool.
	 */
	private const HOLDING = "'pending', 'confirmed', 'converted'";

	/**
	 * Add Reservation
	 *
	 * Creates a commitment atomically.
	 *
	 * Concurrency is the whole point of this method. Two buyers racing for the
	 * last units of a pool must not both succeed, so the pool row is locked with
	 * SELECT ... FOR UPDATE, the authoritative reserved total is recomputed from
	 * the reservation rows themselves (never trusting the cached column), and the
	 * quantity is re-checked inside the same transaction. On any failure the
	 * transaction is rolled back and nothing is left half written.
	 *
	 * @param int                  $pool_id
	 * @param int                  $customer_id
	 * @param int                  $quantity
	 * @param array<string, mixed> $data        address_id, shipping_method, unit_price
	 *
	 * @return array{success: bool, error: string, reservation_id: int, reference: string, reserved_qty: int}
	 */
	public function addReservation(int $pool_id, int $customer_id, int $quantity, array $data = []): array {
		$result = [
			'success'        => false,
			'error'          => '',
			'reservation_id' => 0,
			'reference'      => '',
			'reserved_qty'   => 0
		];

		if ($pool_id <= 0 || $customer_id <= 0 || $quantity <= 0) {
			$result['error'] = 'invalid';

			return $result;
		}

		$this->db->query("START TRANSACTION");

		try {
			// Lock the pool row for the duration of the transaction
			$pool = $this->db->query("SELECT * FROM `" . DB_PREFIX . "poolbuy_pool` WHERE `pool_id` = '" . (int)$pool_id . "' FOR UPDATE");

			if (!$pool->num_rows) {
				$this->db->query("ROLLBACK");

				$result['error'] = 'not_found';

				return $result;
			}

			$pool_info = $pool->row;

			// Only an active pool inside its window accepts commitments
			if ((string)$pool_info['status'] !== 'active' || strtotime((string)$pool_info['date_end']) <= time()) {
				$this->db->query("ROLLBACK");

				$result['error'] = 'closed';

				return $result;
			}

			// Authoritative total, recomputed from the rows rather than the cache
			$sum = $this->db->query("SELECT COALESCE(SUM(`quantity`), 0) AS `total` FROM `" . DB_PREFIX . "poolbuy_reservation` WHERE `pool_id` = '" . (int)$pool_id . "' AND `status` IN (" . self::HOLDING . ")");

			$reserved = (int)$sum->row['total'];
			$moq = (int)$pool_info['moq_target'];
			$available = max(0, $moq - $reserved);

			if ($available <= 0) {
				$this->db->query("ROLLBACK");

				$result['error'] = 'full';

				return $result;
			}

			if ($quantity > $available) {
				$this->db->query("ROLLBACK");

				$result['error'] = 'too_many';
				$result['reserved_qty'] = $reserved;

				return $result;
			}

			$min = max(1, (int)$pool_info['min_qty_per_buyer']);
			$max = max(0, (int)$pool_info['max_qty_per_buyer']);

			if ($quantity < $min) {
				$this->db->query("ROLLBACK");

				$result['error'] = 'below_min';

				return $result;
			}

			if ($max > 0 && $quantity > $max) {
				$this->db->query("ROLLBACK");

				$result['error'] = 'above_max';

				return $result;
			}

			// One active commitment per buyer per pool. The database also enforces
			// this via a unique key on the generated active_customer_id column, so
			// this check is the friendly message rather than the guarantee.
			$existing = $this->db->query("SELECT `reservation_id` FROM `" . DB_PREFIX . "poolbuy_reservation` WHERE `pool_id` = '" . (int)$pool_id . "' AND `customer_id` = '" . (int)$customer_id . "' AND `status` IN ('pending', 'confirmed')");

			if ($existing->num_rows) {
				$this->db->query("ROLLBACK");

				$result['error'] = 'already_joined';

				return $result;
			}

			$reference = $this->generateReference();

			$unit_price = round((float)($data['unit_price'] ?? 0), 4);
			$shipping_method = isset($data['shipping_method']) && is_array($data['shipping_method']) ? $data['shipping_method'] : [];

			$sql = "INSERT INTO `" . DB_PREFIX . "poolbuy_reservation` SET";
			$sql .= " `pool_id` = '" . (int)$pool_id . "',";
			$sql .= " `customer_id` = '" . (int)$customer_id . "',";
			$sql .= " `quantity` = '" . (int)$quantity . "',";
			$sql .= " `unit_price_locked` = '" . $unit_price . "',";
			$sql .= " `status` = 'confirmed',";
			$sql .= " `address_id` = '" . (int)($data['address_id'] ?? 0) . "',";
			$sql .= " `shipping_method` = '" . $this->db->escape((string)json_encode($shipping_method)) . "',";
			$sql .= " `shipping_cost` = '" . round((float)($data['shipping_cost'] ?? 0), 4) . "',";
			$sql .= " `reference` = '" . $this->db->escape($reference) . "',";
			$sql .= " `date_added` = NOW(), `date_modified` = NOW()";

			$this->db->query($sql);

			$reservation_id = $this->db->getLastId();

			// Refresh the cached total from the rows, so the cache can never drift
			$new_total = $reserved + $quantity;

			$this->db->query("UPDATE `" . DB_PREFIX . "poolbuy_pool` SET `reserved_qty` = '" . (int)$new_total . "', `date_modified` = NOW() WHERE `pool_id` = '" . (int)$pool_id . "'");

			// Audit trail. No identifying detail is stored in the payload beyond the
			// customer id column itself, which the storefront never exposes.
			$this->db->query("INSERT INTO `" . DB_PREFIX . "poolbuy_pool_event` SET `pool_id` = '" . (int)$pool_id . "', `customer_id` = '" . (int)$customer_id . "', `type` = 'reservation_added', `payload` = '" . $this->db->escape((string)json_encode(['quantity' => $quantity, 'reference' => $reference, 'reserved_qty' => $new_total])) . "', `date_added` = NOW()");

			$this->db->query("COMMIT");

			$result['success'] = true;
			$result['reservation_id'] = $reservation_id;
			$result['reference'] = $reference;
			$result['reserved_qty'] = $new_total;

			return $result;
		} catch (\Throwable $e) {
			$this->db->query("ROLLBACK");

			$this->log->write('PoolBuy reservation failed: ' . $e->getMessage());

			$result['error'] = 'exception';

			return $result;
		}
	}

	/**
	 * Cancel Reservation
	 *
	 * Releases a buyer's own commitment and recomputes the pool total. Scoped by
	 * customer id so one buyer can never cancel another's reservation.
	 *
	 * @param int $reservation_id
	 * @param int $customer_id
	 *
	 * @return bool
	 */
	public function cancelReservation(int $reservation_id, int $customer_id): bool {
		$query = $this->db->query("SELECT r.*, p.`status` AS `pool_status` FROM `" . DB_PREFIX . "poolbuy_reservation` r LEFT JOIN `" . DB_PREFIX . "poolbuy_pool` p ON (r.`pool_id` = p.`pool_id`) WHERE r.`reservation_id` = '" . (int)$reservation_id . "' AND r.`customer_id` = '" . (int)$customer_id . "'");

		if (!$query->num_rows) {
			return false;
		}

		$reservation = $query->row;

		// A commitment can only be withdrawn while the pool is still filling. Once
		// the MOQ is reached the deal is being prepared and buyers are bound.
		if ((string)$reservation['pool_status'] !== 'active' || (string)$reservation['status'] !== 'confirmed') {
			return false;
		}

		$pool_id = (int)$reservation['pool_id'];

		$this->db->query("START TRANSACTION");

		try {
			$this->db->query("UPDATE `" . DB_PREFIX . "poolbuy_reservation` SET `status` = 'cancelled', `date_modified` = NOW() WHERE `reservation_id` = '" . (int)$reservation_id . "' AND `customer_id` = '" . (int)$customer_id . "'");

			$sum = $this->db->query("SELECT COALESCE(SUM(`quantity`), 0) AS `total` FROM `" . DB_PREFIX . "poolbuy_reservation` WHERE `pool_id` = '" . (int)$pool_id . "' AND `status` IN (" . self::HOLDING . ")");

			$this->db->query("UPDATE `" . DB_PREFIX . "poolbuy_pool` SET `reserved_qty` = '" . (int)$sum->row['total'] . "', `date_modified` = NOW() WHERE `pool_id` = '" . (int)$pool_id . "'");

			$this->db->query("INSERT INTO `" . DB_PREFIX . "poolbuy_pool_event` SET `pool_id` = '" . (int)$pool_id . "', `customer_id` = '" . (int)$customer_id . "', `type` = 'reservation_cancelled', `payload` = '" . $this->db->escape((string)json_encode(['quantity' => (int)$reservation['quantity']])) . "', `date_added` = NOW()");

			$this->db->query("COMMIT");

			return true;
		} catch (\Throwable $e) {
			$this->db->query("ROLLBACK");

			$this->log->write('PoolBuy cancellation failed: ' . $e->getMessage());

			return false;
		}
	}

	/**
	 * Get Reservation By Pool And Customer
	 *
	 * @param int $pool_id
	 * @param int $customer_id
	 *
	 * @return array<string, mixed>
	 */
	public function getActiveReservation(int $pool_id, int $customer_id): array {
		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "poolbuy_reservation` WHERE `pool_id` = '" . (int)$pool_id . "' AND `customer_id` = '" . (int)$customer_id . "' AND `status` IN ('pending', 'confirmed')");

		return $query->row;
	}

	/**
	 * Get Reservation
	 *
	 * Always scoped by customer so a crafted reservation_id cannot read another
	 * buyer's commitment.
	 *
	 * @param int $reservation_id
	 * @param int $customer_id
	 *
	 * @return array<string, mixed>
	 */
	public function getReservation(int $reservation_id, int $customer_id): array {
		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "poolbuy_reservation` WHERE `reservation_id` = '" . (int)$reservation_id . "' AND `customer_id` = '" . (int)$customer_id . "'");

		return $query->row;
	}

	/**
	 * Get Reservations By Customer
	 *
	 * @param int                  $customer_id
	 * @param array<string, mixed> $data        filter_status, start, limit
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function getReservationsByCustomer(int $customer_id, array $data = []): array {
		$sql = "SELECT r.*, p.`title`, p.`reference` AS `pool_reference`, p.`moq_target`, p.`reserved_qty`,";
		$sql .= " p.`status` AS `pool_status`, p.`unit_label`, p.`date_end`, p.`product_id`, p.`allow_full_moq_buy`,";
		$sql .= " p.`min_qty_per_buyer`, p.`max_qty_per_buyer`, p.`retro_pricing`,";
		$sql .= " pd.`name` AS `product_name`, pr.`image` AS `product_image`, pr.`price` AS `retail_price`,";
		$sql .= " s.`name` AS `seller_name`, s.`location` AS `seller_location`, s.`gst_verified`, s.`verified_seller`";
		$sql .= " FROM `" . DB_PREFIX . "poolbuy_reservation` r";
		$sql .= " LEFT JOIN `" . DB_PREFIX . "poolbuy_pool` p ON (r.`pool_id` = p.`pool_id`)";
		$sql .= " LEFT JOIN `" . DB_PREFIX . "product` pr ON (p.`product_id` = pr.`product_id`)";
		$sql .= " LEFT JOIN `" . DB_PREFIX . "product_description` pd ON (p.`product_id` = pd.`product_id` AND pd.`language_id` = '" . (int)$this->config->get('config_language_id') . "')";
		$sql .= " LEFT JOIN `" . DB_PREFIX . "poolbuy_seller` s ON (p.`seller_id` = s.`seller_id`)";
		$sql .= " WHERE r.`customer_id` = '" . (int)$customer_id . "'";

		if (!empty($data['filter_status'])) {
			if ($data['filter_status'] === 'active') {
				$sql .= " AND r.`status` IN ('pending', 'confirmed') AND p.`status` IN ('active', 'reached', 'closed')";
			} elseif ($data['filter_status'] === 'completed') {
				$sql .= " AND (r.`status` = 'converted' OR p.`status` = 'fulfilled')";
			} elseif ($data['filter_status'] === 'cancelled') {
				$sql .= " AND (r.`status` IN ('cancelled', 'released') OR p.`status` IN ('expired', 'cancelled'))";
			}
		}

		$sql .= " ORDER BY r.`date_added` DESC";

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
	 * Get Total Reservations By Customer
	 *
	 * @param int                  $customer_id
	 * @param array<string, mixed> $data
	 *
	 * @return int
	 */
	public function getTotalReservationsByCustomer(int $customer_id, array $data = []): int {
		$sql = "SELECT COUNT(*) AS `total` FROM `" . DB_PREFIX . "poolbuy_reservation` r";
		$sql .= " LEFT JOIN `" . DB_PREFIX . "poolbuy_pool` p ON (r.`pool_id` = p.`pool_id`)";
		$sql .= " WHERE r.`customer_id` = '" . (int)$customer_id . "'";

		if (!empty($data['filter_status'])) {
			if ($data['filter_status'] === 'active') {
				$sql .= " AND r.`status` IN ('pending', 'confirmed') AND p.`status` IN ('active', 'reached', 'closed')";
			} elseif ($data['filter_status'] === 'completed') {
				$sql .= " AND (r.`status` = 'converted' OR p.`status` = 'fulfilled')";
			} elseif ($data['filter_status'] === 'cancelled') {
				$sql .= " AND (r.`status` IN ('cancelled', 'released') OR p.`status` IN ('expired', 'cancelled'))";
			}
		}

		$query = $this->db->query($sql);

		return (int)$query->row['total'];
	}

	/**
	 * Get Customer Statistics
	 *
	 * Figures for the buyer dashboard, all scoped to one customer.
	 *
	 * @param int $customer_id
	 *
	 * @return array<string, mixed>
	 */
	public function getCustomerStatistics(int $customer_id): array {
		$active = $this->db->query("SELECT COUNT(*) AS `total` FROM `" . DB_PREFIX . "poolbuy_reservation` r LEFT JOIN `" . DB_PREFIX . "poolbuy_pool` p ON (r.`pool_id` = p.`pool_id`) WHERE r.`customer_id` = '" . (int)$customer_id . "' AND r.`status` IN ('pending', 'confirmed') AND p.`status` IN ('active', 'reached', 'closed')");

		$week = $this->db->query("SELECT COUNT(*) AS `total` FROM `" . DB_PREFIX . "poolbuy_reservation` WHERE `customer_id` = '" . (int)$customer_id . "' AND `status` IN ('pending', 'confirmed', 'converted') AND `date_added` >= (NOW() - INTERVAL 7 DAY)");

		$completed = $this->db->query("SELECT COUNT(*) AS `total` FROM `" . DB_PREFIX . "poolbuy_reservation` r LEFT JOIN `" . DB_PREFIX . "poolbuy_pool` p ON (r.`pool_id` = p.`pool_id`) WHERE r.`customer_id` = '" . (int)$customer_id . "' AND (r.`status` = 'converted' OR p.`status` = 'fulfilled')");

		$failed = $this->db->query("SELECT COUNT(*) AS `total` FROM `" . DB_PREFIX . "poolbuy_reservation` r LEFT JOIN `" . DB_PREFIX . "poolbuy_pool` p ON (r.`pool_id` = p.`pool_id`) WHERE r.`customer_id` = '" . (int)$customer_id . "' AND (r.`status` IN ('cancelled', 'released') OR p.`status` IN ('expired', 'cancelled'))");

		// Spend and savings only count commitments with a locked price, since a
		// reservation still waiting on repricing has no final figure yet.
		$spend = $this->db->query("SELECT COALESCE(SUM(r.`quantity` * r.`unit_price_locked`), 0) AS `total`, COALESCE(SUM(r.`quantity` * GREATEST(pr.`price` - r.`unit_price_locked`, 0)), 0) AS `saving` FROM `" . DB_PREFIX . "poolbuy_reservation` r LEFT JOIN `" . DB_PREFIX . "poolbuy_pool` p ON (r.`pool_id` = p.`pool_id`) LEFT JOIN `" . DB_PREFIX . "product` pr ON (p.`product_id` = pr.`product_id`) WHERE r.`customer_id` = '" . (int)$customer_id . "' AND r.`status` IN ('pending', 'confirmed', 'converted') AND YEAR(r.`date_added`) = YEAR(NOW())");

		$completed_total = (int)$completed->row['total'];
		$failed_total = (int)$failed->row['total'];
		$settled = $completed_total + $failed_total;

		return [
			'active_pools'     => (int)$active->row['total'],
			'joined_this_week' => (int)$week->row['total'],
			'completed_pools'  => $completed_total,
			'fulfilment_rate'  => $settled > 0 ? round(($completed_total / $settled) * 100, 1) : 0.0,
			'spend_ytd'        => (float)$spend->row['total'],
			'saving_ytd'       => (float)$spend->row['saving']
		];
	}

	/**
	 * Generate Reference
	 *
	 * A human quotable transaction reference. Retried on the astronomically
	 * unlikely collision because the column is unique.
	 *
	 * @return string
	 */
	private function generateReference(): string {
		for ($attempt = 0; $attempt < 5; $attempt++) {
			$reference = 'TX-' . strtoupper(bin2hex(random_bytes(4)));

			$query = $this->db->query("SELECT `reservation_id` FROM `" . DB_PREFIX . "poolbuy_reservation` WHERE `reference` = '" . $this->db->escape($reference) . "'");

			if (!$query->num_rows) {
				return $reference;
			}
		}

		return 'TX-' . strtoupper(bin2hex(random_bytes(8)));
	}
}
