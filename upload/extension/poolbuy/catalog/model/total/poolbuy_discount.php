<?php
namespace Opencart\Catalog\Model\Extension\Poolbuy\Total;
/**
 * Class PoolbuyDiscount
 *
 * Order total extension for a PoolBuy promotion discount.
 *
 * OpenCart's order history routine walks every total on an order and calls
 * confirm() on the matching total extension model, so this class must exist or
 * addHistory() fails and a discounted order would be left without a status.
 *
 * The amount is settled when the promotion is claimed - at join for a buyer's own
 * code, or at pool close for a pool-level promotion - not here, because it depends
 * on the pool's final unit price rather than on the cart. This model therefore only
 * satisfies the confirm() contract, exactly as the fee and GST lines do.
 *
 * @package Opencart\Catalog\Model\Extension\Poolbuy\Total
 */
class PoolbuyDiscount extends \Opencart\System\Engine\Model {
	/**
	 * Confirm
	 *
	 * Fraud/verification hook. Returning 0 means "no objection, do not override the
	 * order status", which is the correct behaviour for a passive discount line.
	 *
	 * @param array<string, mixed> $order_info
	 * @param array<string, mixed> $order_total
	 *
	 * @return int an order status id to force, or 0 for no change
	 */
	public function confirm(array $order_info, array $order_total): int {
		return 0;
	}
}
