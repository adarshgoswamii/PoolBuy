<?php
namespace Opencart\Catalog\Model\Extension\Poolbuy\Poolbuy;
/**
 * Class Review
 *
 * Buyer reviews of completed pool purchases.
 *
 * ELIGIBILITY IS THE WHOLE POINT. A rating is only worth reading if the person
 * who left it actually bought the thing, so a review requires all of:
 *
 *   - a reservation owned by this customer
 *   - that reservation converted into a real order
 *   - the pool fulfilled
 *   - the payment for that order settled
 *
 * Anything less is refused, with a reason. The check is performed server side
 * from the reservation id and the signed-in customer id; nothing about
 * eligibility is ever taken from the submitted form.
 *
 * Reviews are created 'pending' and are invisible on the storefront until an
 * operator approves them.
 *
 * @package Opencart\Catalog\Model\Extension\Poolbuy\Poolbuy
 */
class Review extends \Opencart\System\Engine\Model {
	/**
	 * Ratings are a 1-5 star scale.
	 */
	public const MIN_RATING = 1;
	public const MAX_RATING = 5;

	/**
	 * Can Review
	 *
	 * Decides whether this customer may review this commitment, and says why not.
	 *
	 * @param int $reservation_id
	 * @param int $customer_id
	 *
	 * @return array{allowed: bool, reason: string, reservation: array<string, mixed>|null}
	 */
	public function canReview(int $reservation_id, int $customer_id): array {
		$deny = static fn (string $reason): array => ['allowed' => false, 'reason' => $reason, 'reservation' => null];

		if ($reservation_id <= 0 || $customer_id <= 0) {
			return $deny('not_found');
		}

		$sql = "SELECT r.`reservation_id`, r.`pool_id`, r.`customer_id`, r.`status` AS `reservation_status`, r.`order_id`,";
		$sql .= " p.`seller_id`, p.`product_id`, p.`status` AS `pool_status`, p.`reference` AS `pool_reference`, p.`title`,";
		$sql .= " pay.`status` AS `payment_status`";
		$sql .= " FROM `" . DB_PREFIX . "poolbuy_reservation` r";
		$sql .= " LEFT JOIN `" . DB_PREFIX . "poolbuy_pool` p ON (r.`pool_id` = p.`pool_id`)";
		$sql .= " LEFT JOIN `" . DB_PREFIX . "poolbuy_payment` pay ON (pay.`order_id` = r.`order_id`)";
		$sql .= " WHERE r.`reservation_id` = '" . (int)$reservation_id . "'";

		$query = $this->db->query($sql);

		if (!$query->num_rows) {
			return $deny('not_found');
		}

		$row = $query->row;

		// Ownership. Reported as not_found so this cannot be used to discover which
		// commitments exist or who owns them.
		if ((int)$row['customer_id'] !== $customer_id) {
			return $deny('not_found');
		}

		if ((string)$row['reservation_status'] !== 'converted' || (int)$row['order_id'] <= 0) {
			return $deny('not_purchased');
		}

		if ((string)$row['pool_status'] !== 'fulfilled') {
			return $deny('not_fulfilled');
		}

		if ((string)($row['payment_status'] ?? '') !== 'paid') {
			return $deny('not_paid');
		}

		// One review per purchase, enforced by a unique key as well as here.
		$existing = $this->db->query("SELECT `review_id` FROM `" . DB_PREFIX . "poolbuy_review` WHERE `reservation_id` = '" . (int)$reservation_id . "'");

		if ($existing->num_rows) {
			return $deny('already_reviewed');
		}

		return ['allowed' => true, 'reason' => '', 'reservation' => $row];
	}

	/**
	 * Add Review
	 *
	 * Records a review after re-checking eligibility. Returns the outcome rather
	 * than throwing, so the caller can report a specific reason.
	 *
	 * @param int    $reservation_id
	 * @param int    $customer_id
	 * @param int    $rating
	 * @param string $title
	 * @param string $text
	 *
	 * @return array{success: bool, reason: string, review_id: int}
	 */
	public function addReview(int $reservation_id, int $customer_id, int $rating, string $title, string $text): array {
		// Re-checked here, not trusted from whatever rendered the form.
		$eligibility = $this->canReview($reservation_id, $customer_id);

		if (!$eligibility['allowed']) {
			return ['success' => false, 'reason' => $eligibility['reason'], 'review_id' => 0];
		}

		$reservation = $eligibility['reservation'];

		// Clamped server side. A posted 0, 99 or -3 becomes a legal rating rather
		// than corrupting every average that reads this column.
		$rating = (int)max(self::MIN_RATING, min(self::MAX_RATING, $rating));

		$title = trim($title);
		$text = trim($text);

		if ($text === '') {
			return ['success' => false, 'reason' => 'empty_text', 'review_id' => 0];
		}

		$this->db->query(
			"INSERT INTO `" . DB_PREFIX . "poolbuy_review`
			SET `pool_id` = '" . (int)$reservation['pool_id'] . "',
				`seller_id` = '" . (int)$reservation['seller_id'] . "',
				`product_id` = '" . (int)$reservation['product_id'] . "',
				`customer_id` = '" . (int)$customer_id . "',
				`reservation_id` = '" . (int)$reservation_id . "',
				`rating` = '" . $rating . "',
				`title` = '" . $this->db->escape(oc_substr($title, 0, 128)) . "',
				`text` = '" . $this->db->escape(oc_substr($text, 0, 5000)) . "',
				`status` = 'pending',
				`date_added` = NOW(), `date_modified` = NOW()"
		);

		return ['success' => true, 'reason' => '', 'review_id' => (int)$this->db->getLastId()];
	}

	/**
	 * Get Reviewable Commitments
	 *
	 * The customer's own purchases that are eligible for a review and do not have
	 * one yet. Drives the review prompts on the dashboard.
	 *
	 * @param int $customer_id
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function getReviewable(int $customer_id): array {
		$sql = "SELECT r.`reservation_id`, r.`pool_id`, r.`reference`, p.`title`, p.`reference` AS `pool_reference`, p.`product_id`, p.`seller_id`";
		$sql .= " FROM `" . DB_PREFIX . "poolbuy_reservation` r";
		$sql .= " INNER JOIN `" . DB_PREFIX . "poolbuy_pool` p ON (r.`pool_id` = p.`pool_id`)";
		$sql .= " INNER JOIN `" . DB_PREFIX . "poolbuy_payment` pay ON (pay.`order_id` = r.`order_id`)";
		$sql .= " LEFT JOIN `" . DB_PREFIX . "poolbuy_review` rv ON (rv.`reservation_id` = r.`reservation_id`)";
		$sql .= " WHERE r.`customer_id` = '" . (int)$customer_id . "'";
		$sql .= " AND r.`status` = 'converted' AND r.`order_id` > 0";
		$sql .= " AND p.`status` = 'fulfilled'";
		$sql .= " AND pay.`status` = 'paid'";
		$sql .= " AND rv.`review_id` IS NULL";
		$sql .= " ORDER BY r.`date_modified` DESC";

		return $this->db->query($sql)->rows;
	}

	/**
	 * Get Own Reviews
	 *
	 * The customer's own reviews, including ones still awaiting moderation, so they
	 * can see that a submission was received.
	 *
	 * @param int $customer_id
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function getOwnReviews(int $customer_id): array {
		$sql = "SELECT rv.*, p.`title`, p.`reference` AS `pool_reference`";
		$sql .= " FROM `" . DB_PREFIX . "poolbuy_review` rv";
		$sql .= " LEFT JOIN `" . DB_PREFIX . "poolbuy_pool` p ON (rv.`pool_id` = p.`pool_id`)";
		$sql .= " WHERE rv.`customer_id` = '" . (int)$customer_id . "'";
		$sql .= " ORDER BY rv.`date_added` DESC";

		return $this->db->query($sql)->rows;
	}

	/**
	 * Get Approved By Seller
	 *
	 * Public reviews for a seller. Only approved rows are ever returned.
	 *
	 * @param int $seller_id
	 * @param int $limit
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function getApprovedBySeller(int $seller_id, int $limit = 20): array {
		$sql = "SELECT rv.`review_id`, rv.`rating`, rv.`title`, rv.`text`, rv.`date_added`, rv.`customer_id`, rv.`pool_id`,";
		$sql .= " p.`reference` AS `pool_reference`, p.`title` AS `pool_title`";
		$sql .= " FROM `" . DB_PREFIX . "poolbuy_review` rv";
		$sql .= " LEFT JOIN `" . DB_PREFIX . "poolbuy_pool` p ON (rv.`pool_id` = p.`pool_id`)";
		$sql .= " WHERE rv.`seller_id` = '" . (int)$seller_id . "' AND rv.`status` = 'approved'";
		$sql .= " ORDER BY rv.`date_added` DESC LIMIT " . max(1, $limit);

		return $this->db->query($sql)->rows;
	}

	/**
	 * Get Approved By Product
	 *
	 * @param int $product_id
	 * @param int $limit
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function getApprovedByProduct(int $product_id, int $limit = 20): array {
		$sql = "SELECT rv.`review_id`, rv.`rating`, rv.`title`, rv.`text`, rv.`date_added`, rv.`customer_id`, rv.`pool_id`,";
		$sql .= " p.`reference` AS `pool_reference`, p.`title` AS `pool_title`";
		$sql .= " FROM `" . DB_PREFIX . "poolbuy_review` rv";
		$sql .= " LEFT JOIN `" . DB_PREFIX . "poolbuy_pool` p ON (rv.`pool_id` = p.`pool_id`)";
		$sql .= " WHERE rv.`product_id` = '" . (int)$product_id . "' AND rv.`status` = 'approved'";
		$sql .= " ORDER BY rv.`date_added` DESC LIMIT " . max(1, $limit);

		return $this->db->query($sql)->rows;
	}

	/**
	 * Get Product Summary
	 *
	 * Approved rating count and average for one product. Used on the product page,
	 * where the average must describe the reviews actually listed beneath it - a
	 * seller-wide figure there would contradict the list.
	 *
	 * @param int $product_id
	 *
	 * @return array{count: int, average: float}
	 */
	public function getProductSummary(int $product_id): array {
		$query = $this->db->query("SELECT COUNT(*) AS `total`, COALESCE(AVG(`rating`), 0) AS `average` FROM `" . DB_PREFIX . "poolbuy_review` WHERE `product_id` = '" . (int)$product_id . "' AND `status` = 'approved'");

		return [
			'count'   => (int)$query->row['total'],
			'average' => round((float)$query->row['average'], 2)
		];
	}

	/**
	 * Get Summary
	 *
	 * Approved rating count and average for a seller.
	 *
	 * @param int $seller_id
	 *
	 * @return array{count: int, average: float}
	 */
	public function getSummary(int $seller_id): array {
		$query = $this->db->query("SELECT COUNT(*) AS `total`, COALESCE(AVG(`rating`), 0) AS `average` FROM `" . DB_PREFIX . "poolbuy_review` WHERE `seller_id` = '" . (int)$seller_id . "' AND `status` = 'approved'");

		return [
			'count'   => (int)$query->row['total'],
			'average' => round((float)$query->row['average'], 2)
		];
	}
}
