<?php
namespace Opencart\Catalog\Model\Extension\Poolbuy\Poolbuy;
/**
 * Class Payment
 *
 * Reads and mutates poolbuy_payment rows. All mutations are conditional and
 * idempotent: marking a pending payment paid only affects a row still pending,
 * so a buyer return and a gateway webhook racing each other settle it exactly
 * once. The order status change is likewise driven off whether the payment row
 * actually transitioned.
 *
 * @package Opencart\Catalog\Model\Extension\Poolbuy\Poolbuy
 */
class Payment extends \Opencart\System\Engine\Model {
	/**
	 * Get By Gateway Ref
	 *
	 * @param string $gateway_ref
	 *
	 * @return array<string, mixed>|null
	 */
	public function getByGatewayRef(string $gateway_ref): ?array {
		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "poolbuy_payment` WHERE `gateway_ref` = '" . $this->db->escape($gateway_ref) . "' LIMIT 1");

		return $query->num_rows ? $query->row : null;
	}

	/**
	 * Get By Order
	 *
	 * @param int $order_id
	 *
	 * @return array<string, mixed>|null
	 */
	public function getByOrder(int $order_id): ?array {
		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "poolbuy_payment` WHERE `order_id` = '" . (int)$order_id . "' LIMIT 1");

		return $query->num_rows ? $query->row : null;
	}

	/**
	 * Owns Order
	 *
	 * Confirms the given customer owns the order behind a payment, so a buyer can
	 * never pay - or read the status of - an order that is not theirs.
	 *
	 * @param int $order_id
	 * @param int $customer_id
	 *
	 * @return bool
	 */
	public function ownsOrder(int $order_id, int $customer_id): bool {
		$query = $this->db->query("SELECT `order_id` FROM `" . DB_PREFIX . "order` WHERE `order_id` = '" . (int)$order_id . "' AND `customer_id` = '" . (int)$customer_id . "'");

		return (bool)$query->num_rows;
	}

	/**
	 * Mark Paid
	 *
	 * Transitions a pending payment to paid. Idempotent: returns true only when
	 * this call is the one that actually moved the row from pending, so callers
	 * can safely fire side effects (order history, notifications) exactly once.
	 *
	 * @param int $payment_id
	 *
	 * @return bool true if this call settled the payment
	 */
	public function markPaid(int $payment_id): bool {
		$this->db->query("UPDATE `" . DB_PREFIX . "poolbuy_payment` SET `status` = 'paid', `date_modified` = NOW() WHERE `payment_id` = '" . (int)$payment_id . "' AND `status` = 'pending'");

		return (bool)$this->db->countAffected();
	}

	/**
	 * Mark Failed
	 *
	 * @param int $payment_id
	 *
	 * @return bool
	 */
	public function markFailed(int $payment_id): bool {
		$this->db->query("UPDATE `" . DB_PREFIX . "poolbuy_payment` SET `status` = 'failed', `date_modified` = NOW() WHERE `payment_id` = '" . (int)$payment_id . "' AND `status` = 'pending'");

		return (bool)$this->db->countAffected();
	}
}
