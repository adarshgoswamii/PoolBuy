<?php
namespace Opencart\Catalog\Model\Extension\Poolbuy\Poolbuy;

use Opencart\System\Library\Extension\Poolbuy\PoolCalculator;

/**
 * Class Promotion
 *
 * Discount codes for pool commitments.
 *
 * Two scopes exist and they do not mix:
 *
 *   'pool'  - attached to a pool by an operator or its seller. It applies to
 *             EVERY participant automatically when the pool closes; nobody types
 *             it in.
 *   'buyer' - typed in by an individual buyer while joining. It discounts only
 *             that buyer's commitment.
 *
 * A pool-level promotion takes PRECEDENCE. If the pool carries one, a buyer's
 * own code is refused outright rather than silently dropped or stacked, because
 * quietly ignoring a code the buyer entered is the kind of thing that turns into
 * a support complaint and a chargeback.
 *
 * Trust boundary: nothing here believes a posted discount. The caller passes a
 * code and a quantity; the amount is always recomputed from the promotion row.
 *
 * @package Opencart\Catalog\Model\Extension\Poolbuy\Poolbuy
 */
class Promotion extends \Opencart\System\Engine\Model {
	/**
	 * Get By Code
	 *
	 * @param string $code
	 *
	 * @return array<string, mixed>|null
	 */
	public function getByCode(string $code): ?array {
		$code = trim($code);

		if ($code === '') {
			return null;
		}

		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "poolbuy_promotion` WHERE `code` = '" . $this->db->escape($code) . "'");

		return $query->num_rows ? $query->row : null;
	}

	/**
	 * Get Pool Promotion
	 *
	 * The active pool-scoped promotion attached to a pool, if any.
	 *
	 * @param int $pool_id
	 *
	 * @return array<string, mixed>|null
	 */
	public function getPoolPromotion(int $pool_id): ?array {
		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "poolbuy_promotion` WHERE `scope` = 'pool' AND `pool_id` = '" . (int)$pool_id . "' AND `status` = '1' ORDER BY `promotion_id` ASC LIMIT 1");

		return $query->num_rows ? $query->row : null;
	}

	/**
	 * Quote
	 *
	 * Works out what a buyer-entered code is worth against a commitment, without
	 * claiming anything. Used to show the discount before it is committed.
	 *
	 * @param string $code
	 * @param int    $pool_id
	 * @param int    $quantity
	 * @param float  $subtotal
	 *
	 * @return array{valid: bool, reason: string, discount: float, promotion_id: int, code: string}
	 */
	public function quote(string $code, int $pool_id, int $quantity, float $subtotal): array {
		$fail = static fn (string $reason): array => ['valid' => false, 'reason' => $reason, 'discount' => 0.0, 'promotion_id' => 0, 'code' => ''];

		// Pool-level promotions win. Say so explicitly - the buyer typed something
		// and deserves to know why it was not used.
		if ($this->getPoolPromotion($pool_id)) {
			return $fail('pool_promotion_applies');
		}

		$promotion = $this->getByCode($code);

		if (!$promotion) {
			return $fail('not_found');
		}

		// A pool-scoped code is not something a buyer may type in.
		if ((string)$promotion['scope'] !== 'buyer') {
			return $fail('not_a_buyer_code');
		}

		// A buyer code may be pinned to one pool; 0 means it works anywhere.
		if ((int)$promotion['pool_id'] !== 0 && (int)$promotion['pool_id'] !== $pool_id) {
			return $fail('wrong_pool');
		}

		$resolved = PoolCalculator::resolveDiscount($promotion, $quantity, $subtotal);

		if (!$resolved['valid']) {
			return $fail($resolved['reason']);
		}

		return [
			'valid'        => true,
			'reason'       => '',
			'discount'     => $resolved['discount'],
			'promotion_id' => (int)$promotion['promotion_id'],
			'code'         => (string)$promotion['code']
		];
	}

	/**
	 * Claim
	 *
	 * Takes one redemption of a promotion for a specific commitment.
	 *
	 * The counter is moved with a conditional UPDATE that re-checks the ceiling in
	 * the same statement, so two buyers racing for the last redemption cannot both
	 * win - exactly the discipline used for the pool's reserved_qty. The redemption
	 * row's unique key on (promotion_id, reservation_id) then makes a repeat claim
	 * for the same commitment impossible, which is what keeps pool close idempotent.
	 *
	 * @param int   $promotion_id
	 * @param int   $reservation_id
	 * @param int   $customer_id
	 * @param float $discount
	 *
	 * @return bool true when this call took the redemption
	 */
	public function claim(int $promotion_id, int $reservation_id, int $customer_id, float $discount): bool {
		if ($promotion_id <= 0 || $reservation_id <= 0) {
			return false;
		}

		// Already recorded for this commitment: nothing to do, and not an error.
		$existing = $this->db->query("SELECT `redemption_id` FROM `" . DB_PREFIX . "poolbuy_promotion_redemption` WHERE `promotion_id` = '" . (int)$promotion_id . "' AND `reservation_id` = '" . (int)$reservation_id . "'");

		if ($existing->num_rows) {
			return false;
		}

		// Re-check the ceiling inside the write. 0 means unlimited.
		$this->db->query(
			"UPDATE `" . DB_PREFIX . "poolbuy_promotion`
			SET `redemptions_used` = `redemptions_used` + 1, `date_modified` = NOW()
			WHERE `promotion_id` = '" . (int)$promotion_id . "'
			AND `status` = '1'
			AND (`max_redemptions` = 0 OR `redemptions_used` < `max_redemptions`)"
		);

		if (!$this->db->countAffected()) {
			return false;
		}

		$this->db->query(
			"INSERT INTO `" . DB_PREFIX . "poolbuy_promotion_redemption`
			SET `promotion_id` = '" . (int)$promotion_id . "',
				`reservation_id` = '" . (int)$reservation_id . "',
				`customer_id` = '" . (int)$customer_id . "',
				`discount_amount` = '" . (float)$discount . "',
				`date_added` = NOW()"
		);

		return true;
	}

	/**
	 * Release
	 *
	 * Gives a redemption back when the commitment it belonged to is withdrawn or
	 * released, so a cancelled join does not permanently consume a limited code.
	 *
	 * @param int $reservation_id
	 *
	 * @return void
	 */
	public function release(int $reservation_id): void {
		$rows = $this->db->query("SELECT `promotion_id` FROM `" . DB_PREFIX . "poolbuy_promotion_redemption` WHERE `reservation_id` = '" . (int)$reservation_id . "'")->rows;

		foreach ($rows as $row) {
			// Floor at zero so a double release cannot drive the counter negative.
			$this->db->query("UPDATE `" . DB_PREFIX . "poolbuy_promotion` SET `redemptions_used` = GREATEST(0, `redemptions_used` - 1), `date_modified` = NOW() WHERE `promotion_id` = '" . (int)$row['promotion_id'] . "'");
		}

		$this->db->query("DELETE FROM `" . DB_PREFIX . "poolbuy_promotion_redemption` WHERE `reservation_id` = '" . (int)$reservation_id . "'");
	}
}
