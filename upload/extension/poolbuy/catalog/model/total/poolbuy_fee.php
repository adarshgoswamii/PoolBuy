<?php
namespace Opencart\Catalog\Model\Extension\Poolbuy\Total;
/**
 * Class PoolbuyFee
 *
 * Order total extension for the PoolBuy marketplace commission.
 *
 * PoolBuy writes two of its own order totals (this fee and GST) onto pool orders.
 * OpenCart's order history routine walks every total on an order and calls
 * confirm() on the matching total extension model, so these classes must exist or
 * addHistory() fails and the order is left without a status.
 *
 * The fee is calculated when the reservation is converted, not here, because the
 * amount depends on the pool's settled unit price rather than on the cart. This
 * model therefore only satisfies the confirm() contract.
 *
 * @package Opencart\Catalog\Model\Extension\Poolbuy\Total
 */
class PoolbuyFee extends \Opencart\System\Engine\Model {
	/**
	 * Confirm
	 *
	 * Fraud/verification hook. Returning 0 means "no objection, do not override the
	 * order status", which is the correct behaviour for a passive charge line.
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
