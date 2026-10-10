<?php
namespace Opencart\Admin\Model\Extension\Poolbuy\Poolbuy;
/**
 * Class Review
 *
 * Moderation for PoolBuy buyer reviews.
 *
 * Reviews arrive 'pending' and are invisible on the storefront until approved,
 * mirroring OpenCart's own product review moderation.
 *
 * The seller's rating is never stored as an operator-typed number: it is
 * RECOMPUTED from approved rows whenever moderation changes. That keeps the badge
 * on the storefront honest - it is always the average of reviews that real buyers
 * left and an operator published, and it cannot drift from them.
 *
 * @package Opencart\Admin\Model\Extension\Poolbuy\Poolbuy
 */
class Review extends \Opencart\System\Engine\Model {
	/**
	 * Get Review
	 *
	 * @param int $review_id
	 *
	 * @return array<string, mixed>|null
	 */
	public function getReview(int $review_id): ?array {
		$sql = "SELECT rv.*, s.`name` AS `seller_name`, p.`reference` AS `pool_reference`, p.`title` AS `pool_title`,";
		$sql .= " c.`firstname`, c.`lastname`, c.`email`, r.`reference` AS `reservation_reference`";
		$sql .= " FROM `" . DB_PREFIX . "poolbuy_review` rv";
		$sql .= " LEFT JOIN `" . DB_PREFIX . "poolbuy_seller` s ON (rv.`seller_id` = s.`seller_id`)";
		$sql .= " LEFT JOIN `" . DB_PREFIX . "poolbuy_pool` p ON (rv.`pool_id` = p.`pool_id`)";
		$sql .= " LEFT JOIN `" . DB_PREFIX . "customer` c ON (rv.`customer_id` = c.`customer_id`)";
		$sql .= " LEFT JOIN `" . DB_PREFIX . "poolbuy_reservation` r ON (rv.`reservation_id` = r.`reservation_id`)";
		$sql .= " WHERE rv.`review_id` = '" . (int)$review_id . "'";

		$query = $this->db->query($sql);

		return $query->num_rows ? $query->row : null;
	}

	/**
	 * Get Reviews
	 *
	 * @param array<string, mixed> $data
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function getReviews(array $data = []): array {
		$sql = "SELECT rv.`review_id`, rv.`rating`, rv.`title`, rv.`text`, rv.`status`, rv.`date_added`, rv.`customer_id`,";
		$sql .= " s.`name` AS `seller_name`, p.`reference` AS `pool_reference`, pd.`name` AS `product_name`";
		$sql .= " FROM `" . DB_PREFIX . "poolbuy_review` rv";
		$sql .= " LEFT JOIN `" . DB_PREFIX . "poolbuy_seller` s ON (rv.`seller_id` = s.`seller_id`)";
		$sql .= " LEFT JOIN `" . DB_PREFIX . "poolbuy_pool` p ON (rv.`pool_id` = p.`pool_id`)";
		$sql .= " LEFT JOIN `" . DB_PREFIX . "product_description` pd ON (rv.`product_id` = pd.`product_id` AND pd.`language_id` = '" . (int)$this->config->get('config_language_id') . "')";
		$sql .= $this->buildFilters($data);

		$allowed = ['rating', 'status', 'date_added'];
		$sort = (isset($data['sort']) && in_array((string)$data['sort'], $allowed, true)) ? (string)$data['sort'] : 'date_added';
		$order = (isset($data['order']) && strtoupper((string)$data['order']) === 'ASC') ? 'ASC' : 'DESC';

		$sql .= " ORDER BY rv.`" . $sort . "` " . $order;
		$sql .= " LIMIT " . max(0, (int)($data['start'] ?? 0)) . "," . max(1, (int)($data['limit'] ?? 10));

		return $this->db->query($sql)->rows;
	}

	/**
	 * Get Total Reviews
	 *
	 * @param array<string, mixed> $data
	 *
	 * @return int
	 */
	public function getTotalReviews(array $data = []): int {
		$sql = "SELECT COUNT(*) AS `total` FROM `" . DB_PREFIX . "poolbuy_review` rv" . $this->buildFilters($data);

		return (int)$this->db->query($sql)->row['total'];
	}

	/**
	 * Get Pending Total
	 *
	 * @return int
	 */
	public function getPendingTotal(): int {
		$query = $this->db->query("SELECT COUNT(*) AS `total` FROM `" . DB_PREFIX . "poolbuy_review` WHERE `status` = 'pending'");

		return (int)$query->row['total'];
	}

	/**
	 * Set Status
	 *
	 * Moderates one review and recomputes the affected seller's rating.
	 *
	 * @param int    $review_id
	 * @param string $status    pending|approved|rejected
	 *
	 * @return bool
	 */
	public function setStatus(int $review_id, string $status): bool {
		if (!in_array($status, ['pending', 'approved', 'rejected'], true)) {
			return false;
		}

		$review = $this->getReview($review_id);

		if (!$review) {
			return false;
		}

		$this->db->query("UPDATE `" . DB_PREFIX . "poolbuy_review` SET `status` = '" . $this->db->escape($status) . "', `date_modified` = NOW() WHERE `review_id` = '" . (int)$review_id . "'");

		// Whatever the transition was - published, unpublished or returned to the
		// queue - the seller's aggregate has to follow it.
		$this->recomputeSellerRating((int)$review['seller_id']);

		return true;
	}

	/**
	 * Delete Review
	 *
	 * @param int $review_id
	 *
	 * @return void
	 */
	public function deleteReview(int $review_id): void {
		$review = $this->getReview($review_id);

		$this->db->query("DELETE FROM `" . DB_PREFIX . "poolbuy_review` WHERE `review_id` = '" . (int)$review_id . "'");

		if ($review) {
			$this->recomputeSellerRating((int)$review['seller_id']);
		}
	}

	/**
	 * Recompute Seller Rating
	 *
	 * Rewrites poolbuy_seller.rating and rating_count from APPROVED reviews only.
	 *
	 * A seller with no approved reviews goes to 0.00 / 0 rather than keeping a
	 * stale figure, so removing the last review cannot leave a rating behind that
	 * nothing supports.
	 *
	 * @param int $seller_id
	 *
	 * @return array{count: int, average: float}
	 */
	public function recomputeSellerRating(int $seller_id): array {
		if ($seller_id <= 0) {
			return ['count' => 0, 'average' => 0.0];
		}

		$query = $this->db->query("SELECT COUNT(*) AS `total`, COALESCE(AVG(`rating`), 0) AS `average` FROM `" . DB_PREFIX . "poolbuy_review` WHERE `seller_id` = '" . (int)$seller_id . "' AND `status` = 'approved'");

		$count = (int)$query->row['total'];
		$average = round((float)$query->row['average'], 2);

		$this->db->query("UPDATE `" . DB_PREFIX . "poolbuy_seller` SET `rating` = '" . $average . "', `rating_count` = '" . $count . "', `date_modified` = NOW() WHERE `seller_id` = '" . (int)$seller_id . "'");

		return ['count' => $count, 'average' => $average];
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

		if (!empty($data['filter_status']) && in_array((string)$data['filter_status'], ['pending', 'approved', 'rejected'], true)) {
			$sql .= " AND rv.`status` = '" . $this->db->escape((string)$data['filter_status']) . "'";
		}

		if (!empty($data['filter_rating'])) {
			$sql .= " AND rv.`rating` = '" . (int)$data['filter_rating'] . "'";
		}

		return $sql;
	}
}
