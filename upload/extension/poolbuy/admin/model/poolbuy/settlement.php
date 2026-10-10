<?php
namespace Opencart\Admin\Model\Extension\Poolbuy\Poolbuy;

/**
 * Class Settlement
 *
 * Read models for platform-wide payment and settlement monitoring, plus the one
 * write an operator needs: marking a seller settlement as paid out.
 *
 * Money is never summed across currencies. Totals are grouped by currency, because
 * adding 1,000 INR to 1,000 USD produces a number that means nothing. This mirrors
 * the rule the seller portal already follows for its own settlement totals.
 *
 * @package Opencart\Admin\Model\Extension\Poolbuy\Poolbuy
 */
class Settlement extends \Opencart\System\Engine\Model {
	/**
	 * Get Payments
	 *
	 * @param array<string, mixed> $data filters, sort, start, limit
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function getPayments(array $data = []): array {
		$sql = "SELECT pay.*, p.`reference` AS `pool_reference`, p.`title` AS `pool_title`, s.`name` AS `seller_name`";
		$sql .= " FROM `" . DB_PREFIX . "poolbuy_payment` pay";
		$sql .= " LEFT JOIN `" . DB_PREFIX . "poolbuy_reservation` r ON (pay.`reservation_id` = r.`reservation_id`)";
		$sql .= " LEFT JOIN `" . DB_PREFIX . "poolbuy_pool` p ON (r.`pool_id` = p.`pool_id`)";
		$sql .= " LEFT JOIN `" . DB_PREFIX . "poolbuy_seller` s ON (p.`seller_id` = s.`seller_id`)";
		$sql .= " WHERE 1";

		$sql .= $this->paymentFilters($data);

		$sorts = ['payment_id', 'order_id', 'amount', 'status', 'date_added'];
		$sort = in_array((string)($data['sort'] ?? ''), $sorts, true) ? (string)$data['sort'] : 'date_added';
		$order = (strtoupper((string)($data['order'] ?? 'DESC')) === 'ASC') ? 'ASC' : 'DESC';

		$sql .= " ORDER BY pay.`" . $sort . "` " . $order . ", pay.`payment_id` DESC";

		$start = max(0, (int)($data['start'] ?? 0));
		$limit = (int)($data['limit'] ?? 10);

		if ($limit < 1) {
			$limit = 10;
		}

		$sql .= " LIMIT " . $start . ", " . $limit;

		return $this->db->query($sql)->rows;
	}

	/**
	 * Get Total Payments
	 *
	 * @param array<string, mixed> $data
	 *
	 * @return int
	 */
	public function getTotalPayments(array $data = []): int {
		$sql = "SELECT COUNT(*) AS `total` FROM `" . DB_PREFIX . "poolbuy_payment` pay";
		$sql .= " LEFT JOIN `" . DB_PREFIX . "poolbuy_reservation` r ON (pay.`reservation_id` = r.`reservation_id`)";
		$sql .= " LEFT JOIN `" . DB_PREFIX . "poolbuy_pool` p ON (r.`pool_id` = p.`pool_id`)";
		$sql .= " WHERE 1";
		$sql .= $this->paymentFilters($data);

		return (int)$this->db->query($sql)->row['total'];
	}

	/**
	 * Payment Filters
	 *
	 * @param array<string, mixed> $data
	 *
	 * @return string
	 */
	private function paymentFilters(array $data): string {
		$sql = '';

		$status = (string)($data['filter_status'] ?? '');

		if (in_array($status, ['pending', 'paid', 'failed', 'refunded'], true)) {
			$sql .= " AND pay.`status` = '" . $this->db->escape($status) . "'";
		}

		if (!empty($data['filter_order_id'])) {
			$sql .= " AND pay.`order_id` = '" . (int)$data['filter_order_id'] . "'";
		}

		if (!empty($data['filter_reference'])) {
			$sql .= " AND p.`reference` LIKE '" . $this->db->escape((string)$data['filter_reference']) . "%'";
		}

		return $sql;
	}

	/**
	 * Get Payment Totals
	 *
	 * Value by status, grouped by currency. This is the revenue picture an operator
	 * needs: how much is collected, how much is still outstanding, how much has been
	 * refunded, per currency.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function getPaymentTotals(): array {
		$sql = "SELECT `currency`, `status`, COUNT(*) AS `count`, COALESCE(SUM(`amount`), 0) AS `value`";
		$sql .= " FROM `" . DB_PREFIX . "poolbuy_payment`";
		$sql .= " GROUP BY `currency`, `status`";
		$sql .= " ORDER BY `currency` ASC, `status` ASC";

		return $this->db->query($sql)->rows;
	}

	/**
	 * Get Settlements
	 *
	 * @param array<string, mixed> $data
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function getSettlements(array $data = []): array {
		$sql = "SELECT st.*, p.`reference` AS `pool_reference`, p.`title` AS `pool_title`, s.`name` AS `seller_name`";
		$sql .= " FROM `" . DB_PREFIX . "poolbuy_settlement` st";
		$sql .= " LEFT JOIN `" . DB_PREFIX . "poolbuy_pool` p ON (st.`pool_id` = p.`pool_id`)";
		$sql .= " LEFT JOIN `" . DB_PREFIX . "poolbuy_seller` s ON (st.`seller_id` = s.`seller_id`)";
		$sql .= " WHERE 1";
		$sql .= $this->settlementFilters($data);

		$sql .= " ORDER BY st.`date_added` DESC, st.`settlement_id` DESC";

		$start = max(0, (int)($data['start'] ?? 0));
		$limit = (int)($data['limit'] ?? 10);

		if ($limit < 1) {
			$limit = 10;
		}

		$sql .= " LIMIT " . $start . ", " . $limit;

		return $this->db->query($sql)->rows;
	}

	/**
	 * Get Total Settlements
	 *
	 * @param array<string, mixed> $data
	 *
	 * @return int
	 */
	public function getTotalSettlements(array $data = []): int {
		$sql = "SELECT COUNT(*) AS `total` FROM `" . DB_PREFIX . "poolbuy_settlement` st";
		$sql .= " WHERE 1";
		$sql .= $this->settlementFilters($data);

		return (int)$this->db->query($sql)->row['total'];
	}

	/**
	 * Settlement Filters
	 *
	 * @param array<string, mixed> $data
	 *
	 * @return string
	 */
	private function settlementFilters(array $data): string {
		$sql = '';

		$status = (string)($data['filter_settlement_status'] ?? '');

		if (in_array($status, ['pending', 'paid'], true)) {
			$sql .= " AND st.`status` = '" . $this->db->escape($status) . "'";
		}

		if (!empty($data['filter_seller_id'])) {
			$sql .= " AND st.`seller_id` = '" . (int)$data['filter_seller_id'] . "'";
		}

		return $sql;
	}

	/**
	 * Get Settlement Totals
	 *
	 * Payable and paid, per currency, so an operator can see the payout liability.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function getSettlementTotals(): array {
		$sql = "SELECT `currency`, `status`, COUNT(*) AS `count`,";
		$sql .= " COALESCE(SUM(`gross`), 0) AS `gross`,";
		$sql .= " COALESCE(SUM(`platform_fee`), 0) AS `platform_fee`,";
		$sql .= " COALESCE(SUM(`net`), 0) AS `net`";
		$sql .= " FROM `" . DB_PREFIX . "poolbuy_settlement`";
		$sql .= " GROUP BY `currency`, `status`";
		$sql .= " ORDER BY `currency` ASC, `status` ASC";

		return $this->db->query($sql)->rows;
	}

	/**
	 * Mark Paid
	 *
	 * Records that a seller settlement has been paid out.
	 *
	 * The WHERE clause carries the current status, so marking an already-paid
	 * settlement affects zero rows rather than silently rewriting the timestamp and
	 * making a double payout look like a fresh one.
	 *
	 * @param int $settlement_id
	 *
	 * @return bool whether a pending settlement was actually transitioned
	 */
	public function markPaid(int $settlement_id): bool {
		$this->db->query("UPDATE `" . DB_PREFIX . "poolbuy_settlement` SET `status` = 'paid', `date_modified` = NOW() WHERE `settlement_id` = '" . (int)$settlement_id . "' AND `status` = 'pending'");

		if (!$this->db->countAffected()) {
			return false;
		}

		// Leave an audit trail on the pool, the same way pool state changes do.
		$row = $this->db->query("SELECT `pool_id`, `seller_id`, `net`, `currency` FROM `" . DB_PREFIX . "poolbuy_settlement` WHERE `settlement_id` = '" . (int)$settlement_id . "'");

		if ($row->num_rows && (int)$row->row['pool_id'] > 0) {
			$payload = json_encode([
				'settlement_id' => $settlement_id,
				'seller_id'     => (int)$row->row['seller_id'],
				'net'           => (float)$row->row['net'],
				'currency'      => (string)$row->row['currency'],
				'by'            => 'admin'
			]);

			$this->db->query("INSERT INTO `" . DB_PREFIX . "poolbuy_pool_event` SET `pool_id` = '" . (int)$row->row['pool_id'] . "', `customer_id` = '0', `type` = 'settlement_paid', `payload` = '" . $this->db->escape((string)$payload) . "', `date_added` = NOW()");
		}

		return true;
	}

	/**
	 * Get Pending Settlement Count
	 *
	 * @return int
	 */
	public function getPendingSettlementCount(): int {
		return (int)$this->db->query("SELECT COUNT(*) AS `total` FROM `" . DB_PREFIX . "poolbuy_settlement` WHERE `status` = 'pending'")->row['total'];
	}
}
