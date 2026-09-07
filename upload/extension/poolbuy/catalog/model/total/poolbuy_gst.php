<?php
namespace Opencart\Catalog\Model\Extension\Poolbuy\Total;
/**
 * Class PoolbuyGst
 *
 * Order total extension for GST on pool orders.
 *
 * See PoolbuyFee for why this class exists: OpenCart's addHistory() loads a total
 * extension model for every total on an order and calls confirm() on it.
 *
 * The GST amount is computed by PoolCalculator when the reservation is converted,
 * against the pool's settled unit price, so nothing is calculated here.
 *
 * @package Opencart\Catalog\Model\Extension\Poolbuy\Total
 */
class PoolbuyGst extends \Opencart\System\Engine\Model {
	/**
	 * Confirm
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
