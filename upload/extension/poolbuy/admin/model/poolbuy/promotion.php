<?php
namespace Opencart\Admin\Model\Extension\Poolbuy\Poolbuy;
/**
 * Class Promotion
 *
 * Admin CRUD for PoolBuy discount codes.
 *
 * `redemptions_used` is deliberately NOT writable from here. It is a ledger
 * maintained by the storefront as codes are actually claimed and released, and
 * letting an operator type over it would let a code be handed out more times than
 * its own ceiling allows.
 *
 * @package Opencart\Admin\Model\Extension\Poolbuy\Poolbuy
 */
class Promotion extends \Opencart\System\Engine\Model {
	/**
	 * Add Promotion
	 *
	 * @param array<string, mixed> $data
	 *
	 * @return int
	 */
	public function addPromotion(array $data): int {
		$this->db->query("INSERT INTO `" . DB_PREFIX . "poolbuy_promotion` SET " . $this->buildAssignments($data) . ", `date_added` = NOW(), `date_modified` = NOW()");

		return (int)$this->db->getLastId();
	}

	/**
	 * Edit Promotion
	 *
	 * @param int                  $promotion_id
	 * @param array<string, mixed> $data
	 *
	 * @return void
	 */
	public function editPromotion(int $promotion_id, array $data): void {
		$this->db->query("UPDATE `" . DB_PREFIX . "poolbuy_promotion` SET " . $this->buildAssignments($data) . ", `date_modified` = NOW() WHERE `promotion_id` = '" . (int)$promotion_id . "'");
	}

	/**
	 * Delete Promotion
	 *
	 * The redemption ledger goes with it, since it has no meaning once the code is
	 * gone. Commitments keep their recorded discount_total: the money was already
	 * charged and an invoice must not silently change.
	 *
	 * @param int $promotion_id
	 *
	 * @return void
	 */
	public function deletePromotion(int $promotion_id): void {
		$this->db->query("DELETE FROM `" . DB_PREFIX . "poolbuy_promotion_redemption` WHERE `promotion_id` = '" . (int)$promotion_id . "'");
		$this->db->query("DELETE FROM `" . DB_PREFIX . "poolbuy_promotion` WHERE `promotion_id` = '" . (int)$promotion_id . "'");
	}

	/**
	 * Get Promotion
	 *
	 * @param int $promotion_id
	 *
	 * @return array<string, mixed>|null
	 */
	public function getPromotion(int $promotion_id): ?array {
		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "poolbuy_promotion` WHERE `promotion_id` = '" . (int)$promotion_id . "'");

		return $query->num_rows ? $query->row : null;
	}

	/**
	 * Get Promotion By Code
	 *
	 * @param string $code
	 *
	 * @return array<string, mixed>|null
	 */
	public function getPromotionByCode(string $code): ?array {
		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "poolbuy_promotion` WHERE `code` = '" . $this->db->escape($code) . "'");

		return $query->num_rows ? $query->row : null;
	}

	/**
	 * Get Promotions
	 *
	 * @param array<string, mixed> $data
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function getPromotions(array $data = []): array {
		$sql = "SELECT pr.*, p.`reference` AS `pool_reference` FROM `" . DB_PREFIX . "poolbuy_promotion` pr";
		$sql .= " LEFT JOIN `" . DB_PREFIX . "poolbuy_pool` p ON (pr.`pool_id` = p.`pool_id`)";
		$sql .= $this->buildFilters($data);

		$allowed = ['code', 'name', 'scope', 'type', 'value', 'redemptions_used', 'status'];
		$sort = (isset($data['sort']) && in_array((string)$data['sort'], $allowed, true)) ? (string)$data['sort'] : 'code';
		$order = (isset($data['order']) && strtoupper((string)$data['order']) === 'DESC') ? 'DESC' : 'ASC';

		$sql .= " ORDER BY pr.`" . $sort . "` " . $order;

		$start = max(0, (int)($data['start'] ?? 0));
		$limit = max(1, (int)($data['limit'] ?? 10));

		$sql .= " LIMIT " . $start . "," . $limit;

		return $this->db->query($sql)->rows;
	}

	/**
	 * Get Total Promotions
	 *
	 * @param array<string, mixed> $data
	 *
	 * @return int
	 */
	public function getTotalPromotions(array $data = []): int {
		$sql = "SELECT COUNT(*) AS `total` FROM `" . DB_PREFIX . "poolbuy_promotion` pr" . $this->buildFilters($data);

		return (int)$this->db->query($sql)->row['total'];
	}

	/**
	 * Get Redemptions
	 *
	 * The audit view for one code: who used it, on which commitment, for how much.
	 *
	 * @param int $promotion_id
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function getRedemptions(int $promotion_id): array {
		$sql = "SELECT rd.*, r.`reference` AS `reservation_reference`, r.`pool_id`, p.`reference` AS `pool_reference`";
		$sql .= " FROM `" . DB_PREFIX . "poolbuy_promotion_redemption` rd";
		$sql .= " LEFT JOIN `" . DB_PREFIX . "poolbuy_reservation` r ON (rd.`reservation_id` = r.`reservation_id`)";
		$sql .= " LEFT JOIN `" . DB_PREFIX . "poolbuy_pool` p ON (r.`pool_id` = p.`pool_id`)";
		$sql .= " WHERE rd.`promotion_id` = '" . (int)$promotion_id . "'";
		$sql .= " ORDER BY rd.`date_added` DESC LIMIT 100";

		return $this->db->query($sql)->rows;
	}

	/**
	 * Build Filters
	 *
	 * @param array<string, mixed> $data
	 *
	 * @return string
	 */
	private function buildFilters(array $data): string {
		$sql = " WHERE 1";

		if (!empty($data['filter_code'])) {
			$sql .= " AND pr.`code` LIKE '" . $this->db->escape((string)$data['filter_code']) . "%'";
		}

		if (!empty($data['filter_scope']) && in_array((string)$data['filter_scope'], ['pool', 'buyer'], true)) {
			$sql .= " AND pr.`scope` = '" . $this->db->escape((string)$data['filter_scope']) . "'";
		}

		if (isset($data['filter_status']) && $data['filter_status'] !== '') {
			$sql .= " AND pr.`status` = '" . (int)!empty($data['filter_status']) . "'";
		}

		return $sql;
	}

	/**
	 * Build Assignments
	 *
	 * @param array<string, mixed> $data
	 *
	 * @return string
	 */
	private function buildAssignments(array $data): string {
		$scope = (string)($data['scope'] ?? 'buyer') === 'pool' ? 'pool' : 'buyer';
		$type = (string)($data['type'] ?? 'percent') === 'fixed' ? 'fixed' : 'percent';

		// A percentage above 100 is meaningless, so it is stored clamped rather than
		// relying on every reader to defend against it.
		$value = max(0.0, (float)($data['value'] ?? 0));

		if ($type === 'percent') {
			$value = min(100.0, $value);
		}

		$assignments = [
			"`code` = '" . $this->db->escape(strtoupper(trim((string)($data['code'] ?? '')))) . "'",
			"`name` = '" . $this->db->escape((string)($data['name'] ?? '')) . "'",
			"`scope` = '" . $scope . "'",
			"`pool_id` = '" . max(0, (int)($data['pool_id'] ?? 0)) . "'",
			"`type` = '" . $type . "'",
			"`value` = '" . $value . "'",
			"`min_qty` = '" . max(0, (int)($data['min_qty'] ?? 0)) . "'",
			"`max_redemptions` = '" . max(0, (int)($data['max_redemptions'] ?? 0)) . "'",
			"`status` = '" . (int)!empty($data['status']) . "'"
		];

		// A blank date means "no bound" and is stored as NULL, not as a zero date.
		foreach (['date_start', 'date_end'] as $field) {
			$value = trim((string)($data[$field] ?? ''));

			$assignments[] = $value === ''
				? "`" . $field . "` = NULL"
				: "`" . $field . "` = '" . $this->db->escape($value) . "'";
		}

		return implode(', ', $assignments);
	}
}
