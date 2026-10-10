<?php
namespace Opencart\Catalog\Model\Extension\Poolbuy\Poolbuy;

use Opencart\System\Library\Extension\Poolbuy\GatewayFactory;
use Opencart\System\Library\Extension\Poolbuy\PoolCalculator;
use Opencart\System\Library\Extension\Poolbuy\PoolLifecycle;

/**
 * Class Lifecycle
 *
 * Moves pools through their lifecycle, applies retroactive pricing, and converts
 * successful commitments into real OpenCart orders.
 *
 * Every method here is IDEMPOTENT. The cron that drives them may be run twice by
 * an over-eager scheduler, or re-run after a failure, and doing so must not
 * double-price a buyer or create a second order for the same commitment. That is
 * enforced by only ever selecting rows still in the status the step expects, and
 * by refusing to convert a reservation that already carries an order_id.
 *
 * @package Opencart\Catalog\Model\Extension\Poolbuy\Poolbuy
 */
class Lifecycle extends \Opencart\System\Engine\Model {
	/**
	 * Run
	 *
	 * Executes the whole lifecycle in dependency order and returns a summary of
	 * what changed, so the cron can log something meaningful.
	 *
	 * @return array<string, mixed>
	 */
	public function run(): array {
		$summary = [
			'activated' => $this->activateDuePools(),
			'reached'   => $this->markReachedPools(),
			'expired'   => $this->expirePools(),
			'closed'    => $this->closeReachedPools(),
			'fulfilled' => [],
			'orders'    => 0,
			'repriced'  => 0
		];

		$fulfilment = $this->fulfilClosedPools();

		$summary['fulfilled'] = $fulfilment['pools'];
		$summary['orders'] = $fulfilment['orders'];
		$summary['repriced'] = $fulfilment['repriced'];

		return $summary;
	}

	/**
	 * Activate Due Pools
	 *
	 * Publishes drafts whose start date has arrived.
	 *
	 * @return array<int, string> references of pools activated
	 */
	public function activateDuePools(): array {
		$query = $this->db->query("SELECT `pool_id`, `reference` FROM `" . DB_PREFIX . "poolbuy_pool` WHERE `status` = 'draft' AND `date_start` <= NOW() AND `date_end` > NOW()");

		$changed = [];

		foreach ($query->rows as $row) {
			$this->setStatus((int)$row['pool_id'], PoolLifecycle::ACTIVE, ['from' => 'draft']);

			$changed[] = (string)$row['reference'];
		}

		return $changed;
	}

	/**
	 * Mark Reached Pools
	 *
	 * An active pool whose committed volume has met its MOQ becomes a live deal.
	 * Repricing happens here, so buyers see the improved price as soon as the
	 * target is hit rather than only at close.
	 *
	 * @return array<int, string>
	 */
	public function markReachedPools(): array {
		$query = $this->db->query("SELECT `pool_id`, `reference`, `moq_target` FROM `" . DB_PREFIX . "poolbuy_pool` WHERE `status` = 'active' AND `moq_target` > 0 AND `reserved_qty` >= `moq_target`");

		$changed = [];

		foreach ($query->rows as $row) {
			$pool_id = (int)$row['pool_id'];

			$this->repricePool($pool_id);
			$this->setStatus($pool_id, PoolLifecycle::REACHED, ['reserved_qty' => $this->currentReserved($pool_id)]);
			$this->notifyParticipants($pool_id, 'reached');

			$changed[] = (string)$row['reference'];
		}

		return $changed;
	}

	/**
	 * Expire Pools
	 *
	 * An active pool that ran out of time without meeting its MOQ expires, and
	 * every commitment is released so no buyer is left bound to a deal that will
	 * never happen.
	 *
	 * @return array<int, string>
	 */
	public function expirePools(): array {
		$query = $this->db->query("SELECT `pool_id`, `reference`, `reserved_qty`, `moq_target` FROM `" . DB_PREFIX . "poolbuy_pool` WHERE `status` = 'active' AND `date_end` <= NOW() AND (`moq_target` <= 0 OR `reserved_qty` < `moq_target`)");

		$changed = [];

		foreach ($query->rows as $row) {
			$pool_id = (int)$row['pool_id'];

			$released = $this->releaseReservations($pool_id);

			// Refund anyone who had already paid into this pool before it expired.
			// Unpaid commitments are a no-op: nobody was charged.
			$refunded = $this->refundPoolPayments($pool_id);

			$this->setStatus($pool_id, PoolLifecycle::EXPIRED, [
				'reserved_qty' => (int)$row['reserved_qty'],
				'moq_target'   => (int)$row['moq_target'],
				'released'     => $released,
				'refunded'     => $refunded
			]);

			$this->recalculateReserved($pool_id);
			$this->notifyParticipants($pool_id, 'expired');

			$changed[] = (string)$row['reference'];
		}

		return $changed;
	}

	/**
	 * Close Reached Pools
	 *
	 * A pool that met its MOQ and has now passed its end date stops accepting
	 * change and is ready for fulfilment.
	 *
	 * @return array<int, string>
	 */
	public function closeReachedPools(): array {
		$query = $this->db->query("SELECT `pool_id`, `reference` FROM `" . DB_PREFIX . "poolbuy_pool` WHERE `status` = 'reached' AND `date_end` <= NOW()");

		$changed = [];

		foreach ($query->rows as $row) {
			$pool_id = (int)$row['pool_id'];

			// Reprice once more in case commitments changed after the pool was marked
			// reached, so the final figure reflects the final volume.
			$this->repricePool($pool_id);
			$this->setStatus($pool_id, PoolLifecycle::CLOSED, []);

			$changed[] = (string)$row['reference'];
		}

		return $changed;
	}

	/**
	 * Fulfil Closed Pools
	 *
	 * Converts each confirmed commitment on a closed pool into a real OpenCart
	 * order, then marks the pool fulfilled.
	 *
	 * @return array{pools: array<int, string>, orders: int, repriced: int}
	 */
	public function fulfilClosedPools(): array {
		$query = $this->db->query("SELECT `pool_id`, `reference` FROM `" . DB_PREFIX . "poolbuy_pool` WHERE `status` = 'closed'");

		$pools = [];
		$orders = 0;
		$repriced = 0;

		foreach ($query->rows as $row) {
			$pool_id = (int)$row['pool_id'];

			$repriced += count($this->repricePool($pool_id));

			// A pool-level promotion applies to every participant. It is resolved
			// here, after the final reprice, so the discount is taken against the
			// price buyers actually ended up paying.
			$this->applyPoolPromotion($pool_id);

			// Freight is quoted for the pool and only divisible once the final
			// volume is known, so it is allocated here - after the last reprice and
			// before any order is raised, so every order carries its buyer's share.
			$this->allocatePoolFreight($pool_id);

			$created = $this->convertPoolReservations($pool_id);

			$orders += $created;

			// Only declare the pool fulfilled once nothing is left awaiting an order,
			// so a partial failure leaves the pool in 'closed' to be retried.
			$outstanding = $this->db->query("SELECT COUNT(*) AS `total` FROM `" . DB_PREFIX . "poolbuy_reservation` WHERE `pool_id` = '" . $pool_id . "' AND `status` = 'confirmed'");

			if (!(int)$outstanding->row['total']) {
				$this->setStatus($pool_id, PoolLifecycle::FULFILLED, ['orders_created' => $created]);
				$this->writeSettlement($pool_id);
				$this->notifyParticipants($pool_id, 'fulfilled');

				$pools[] = (string)$row['reference'];
			}
		}

		return ['pools' => $pools, 'orders' => $orders, 'repriced' => $repriced];
	}

	/**
	 * Reprice Pool
	 *
	 * Applies retroactive pricing: every participant moves to the best tier the
	 * pool's final volume unlocked. Honours the pool's own retro_pricing flag.
	 *
	 * @param int $pool_id
	 *
	 * @return array<int, array<string, mixed>> the changes applied
	 */
	public function repricePool(int $pool_id): array {
		$pool = $this->db->query("SELECT `retro_pricing`, `reserved_qty` FROM `" . DB_PREFIX . "poolbuy_pool` WHERE `pool_id` = '" . (int)$pool_id . "'");

		if (!$pool->num_rows) {
			return [];
		}

		$tiers = $this->db->query("SELECT `min_qty`, `max_qty`, `price` FROM `" . DB_PREFIX . "poolbuy_pool_tier` WHERE `pool_id` = '" . (int)$pool_id . "' ORDER BY `min_qty` ASC")->rows;

		$reservations = $this->db->query("SELECT `reservation_id`, `quantity`, `unit_price_locked` FROM `" . DB_PREFIX . "poolbuy_reservation` WHERE `pool_id` = '" . (int)$pool_id . "' AND `status` IN ('pending', 'confirmed')")->rows;

		$reserved = $this->currentReserved($pool_id);

		$changes = PoolCalculator::repriceReservations($reservations, $tiers, $reserved, (bool)$pool->row['retro_pricing']);

		foreach ($changes as $change) {
			$this->db->query("UPDATE `" . DB_PREFIX . "poolbuy_reservation` SET `unit_price_locked` = '" . (float)$change['new_unit_price'] . "', `date_modified` = NOW() WHERE `reservation_id` = '" . (int)$change['reservation_id'] . "'");
		}

		if ($changes) {
			$this->addEvent($pool_id, 'repriced', [
				'reserved_qty' => $reserved,
				'changed'      => count($changes),
				'new_price'    => $changes[0]['new_unit_price']
			]);
		}

		return $changes;
	}

	/**
	 * Allocate Pool Freight
	 *
	 * Divides the pool's quoted freight across its participants in proportion to
	 * the units each committed, and writes each share onto the reservation so the
	 * order raised from it carries the right freight line.
	 *
	 * Two properties matter here.
	 *
	 * The allocation is computed over the pool's FINAL participant set - every
	 * reservation that is confirmed or already converted - rather than only the
	 * rows still awaiting an order. If a previous run invoiced some buyers and
	 * then failed, recomputing over the remainder would hand them an inflated
	 * share. Including the already-converted rows keeps every share identical on
	 * a retry.
	 *
	 * The write is then restricted to rows that have NOT yet been invoiced
	 * (`order_id` = 0), the same guard convertPoolReservations() uses, so a
	 * second cron run cannot alter freight on an order that already exists.
	 *
	 * @param int $pool_id
	 *
	 * @return array<int, float> reservation_id => allocated share
	 */
	public function allocatePoolFreight(int $pool_id): array {
		$pool = $this->db->query("SELECT `freight_total` FROM `" . DB_PREFIX . "poolbuy_pool` WHERE `pool_id` = '" . (int)$pool_id . "'");

		if (!$pool->num_rows) {
			return [];
		}

		$freight_total = (float)$pool->row['freight_total'];

		// The participant set that freight is divided between.
		$reservations = $this->db->query("SELECT `reservation_id`, `quantity`, `order_id` FROM `" . DB_PREFIX . "poolbuy_reservation` WHERE `pool_id` = '" . (int)$pool_id . "' AND `status` IN ('confirmed', 'converted') ORDER BY `reservation_id` ASC")->rows;

		if (!$reservations) {
			return [];
		}

		$shares = PoolCalculator::allocateFreight($reservations, $freight_total);

		$written = 0;

		foreach ($reservations as $reservation) {
			$reservation_id = (int)$reservation['reservation_id'];

			// Already invoiced: its order is fixed, so leave the row alone.
			if ((int)$reservation['order_id'] !== 0) {
				continue;
			}

			$share = (float)($shares[$reservation_id] ?? 0);

			$this->db->query("UPDATE `" . DB_PREFIX . "poolbuy_reservation` SET `shipping_cost` = '" . $share . "', `date_modified` = NOW() WHERE `reservation_id` = '" . $reservation_id . "' AND `order_id` = '0'");

			$written++;
		}

		if ($freight_total > 0 && $written > 0) {
			$this->addEvent($pool_id, 'freight_allocated', [
				'freight_total' => $freight_total,
				'participants'  => count($shares),
				'allocated_to'  => $written
			]);
		}

		return $shares;
	}

	/**
	 * Apply Pool Promotion
	 *
	 * Applies the pool's own promotion, if it has one, to every commitment that has
	 * not yet been invoiced.
	 *
	 * A pool-level promotion is the operator's or seller's decision and applies to
	 * everybody, so it OVERRIDES whatever buyer code a participant may have entered
	 * earlier - the precedence rule the join flow already tells buyers about. Any
	 * superseded buyer redemption is handed back so a limited code is not consumed
	 * by a commitment that ended up discounted by something else.
	 *
	 * Idempotent: the unique key on (promotion_id, reservation_id) means a second
	 * run records nothing new, and rows already carrying this promotion are skipped.
	 *
	 * @param int $pool_id
	 *
	 * @return int commitments discounted
	 */
	public function applyPoolPromotion(int $pool_id): int {
		$promotion = $this->db->query("SELECT * FROM `" . DB_PREFIX . "poolbuy_promotion` WHERE `scope` = 'pool' AND `pool_id` = '" . (int)$pool_id . "' AND `status` = '1' ORDER BY `promotion_id` ASC LIMIT 1");

		if (!$promotion->num_rows) {
			return 0;
		}

		$promotion_info = $promotion->row;
		$promotion_id = (int)$promotion_info['promotion_id'];

		// Only rows still awaiting an order; an invoiced order is not rewritten.
		$reservations = $this->db->query("SELECT `reservation_id`, `customer_id`, `quantity`, `unit_price_locked`, `promotion_id` FROM `" . DB_PREFIX . "poolbuy_reservation` WHERE `pool_id` = '" . (int)$pool_id . "' AND `status` = 'confirmed' AND `order_id` = '0'")->rows;

		$applied = 0;

		foreach ($reservations as $reservation) {
			$reservation_id = (int)$reservation['reservation_id'];
			$quantity = (int)$reservation['quantity'];
			$subtotal = round($quantity * (float)$reservation['unit_price_locked'], PoolCalculator::SCALE);

			$resolved = PoolCalculator::resolveDiscount($promotion_info, $quantity, $subtotal);

			if (!$resolved['valid']) {
				continue;
			}

			// Already carrying this promotion: nothing to do.
			if ((int)$reservation['promotion_id'] === $promotion_id) {
				continue;
			}

			// Supersede any buyer-entered code and return its redemption.
			$previous = (int)$reservation['promotion_id'];

			if ($previous > 0 && $previous !== $promotion_id) {
				$this->db->query("UPDATE `" . DB_PREFIX . "poolbuy_promotion` SET `redemptions_used` = GREATEST(0, `redemptions_used` - 1), `date_modified` = NOW() WHERE `promotion_id` = '" . $previous . "'");
				$this->db->query("DELETE FROM `" . DB_PREFIX . "poolbuy_promotion_redemption` WHERE `reservation_id` = '" . $reservation_id . "' AND `promotion_id` = '" . $previous . "'");
			}

			$this->db->query("UPDATE `" . DB_PREFIX . "poolbuy_reservation` SET `promotion_id` = '" . $promotion_id . "', `discount_total` = '" . (float)$resolved['discount'] . "', `date_modified` = NOW() WHERE `reservation_id` = '" . $reservation_id . "' AND `order_id` = '0'");

			// INSERT IGNORE leans on the unique key to make a re-run a no-op.
			$this->db->query("INSERT IGNORE INTO `" . DB_PREFIX . "poolbuy_promotion_redemption` SET `promotion_id` = '" . $promotion_id . "', `reservation_id` = '" . $reservation_id . "', `customer_id` = '" . (int)$reservation['customer_id'] . "', `discount_amount` = '" . (float)$resolved['discount'] . "', `date_added` = NOW()");

			$applied++;
		}

		if ($applied > 0) {
			$this->db->query("UPDATE `" . DB_PREFIX . "poolbuy_promotion` SET `redemptions_used` = `redemptions_used` + " . (int)$applied . ", `date_modified` = NOW() WHERE `promotion_id` = '" . $promotion_id . "'");

			$this->addEvent($pool_id, 'promotion_applied', [
				'promotion_id' => $promotion_id,
				'code'         => (string)$promotion_info['code'],
				'applied_to'   => $applied
			]);
		}

		return $applied;
	}

	/**
	 * Convert Pool Reservations
	 *
	 * Creates one OpenCart order per confirmed commitment. Each conversion is
	 * wrapped in its own transaction so a single bad row cannot take down the
	 * whole batch, and a reservation that already has an order_id is skipped.
	 *
	 * @param int $pool_id
	 *
	 * @return int number of orders created
	 */
	public function convertPoolReservations(int $pool_id): int {
		$sql = "SELECT r.*, p.`title`, p.`reference` AS `pool_reference`, p.`product_id`, p.`unit_label`, p.`currency_code`";
		$sql .= " FROM `" . DB_PREFIX . "poolbuy_reservation` r";
		$sql .= " LEFT JOIN `" . DB_PREFIX . "poolbuy_pool` p ON (r.`pool_id` = p.`pool_id`)";
		$sql .= " WHERE r.`pool_id` = '" . (int)$pool_id . "' AND r.`status` = 'confirmed' AND r.`order_id` = '0'";

		$reservations = $this->db->query($sql)->rows;

		$created = 0;

		foreach ($reservations as $reservation) {
			if ($this->convertReservation($reservation)) {
				$created++;
			}
		}

		return $created;
	}

	/**
	 * Convert Reservation
	 *
	 * @param array<string, mixed> $reservation
	 *
	 * @return bool
	 */
	private function convertReservation(array $reservation): bool {
		$reservation_id = (int)$reservation['reservation_id'];
		$customer_id = (int)$reservation['customer_id'];
		$quantity = (int)$reservation['quantity'];
		$unit_price = (float)$reservation['unit_price_locked'];

		// A commitment with no settled price must not become an invoice.
		if ($quantity <= 0 || $unit_price <= 0) {
			$this->log->write('PoolBuy: skipping reservation ' . $reservation_id . ' because it has no settled price.');

			return false;
		}

		$customer = $this->db->query("SELECT * FROM `" . DB_PREFIX . "customer` WHERE `customer_id` = '" . $customer_id . "'");

		if (!$customer->num_rows) {
			return false;
		}

		$customer_info = $customer->row;

		$address = $this->resolveAddress((int)$reservation['address_id'], $customer_id);

		$this->load->model('checkout/order');
		$this->load->model('catalog/product');

		$product_info = $this->model_catalog_product->getProduct((int)$reservation['product_id']);

		$gst_rate = (float)$this->config->get('module_poolbuy_gst_rate');
		$fee_rate = (float)$this->config->get('module_poolbuy_platform_fee');

		// The discount was resolved and recorded when the promotion was claimed -
		// either the buyer's own code at join, or the pool-level promotion applied
		// at close. It is read from the row rather than recomputed so the invoice
		// matches exactly what the buyer was shown and what was redeemed.
		$discount = round(max(0.0, (float)($reservation['discount_total'] ?? 0)), PoolCalculator::SCALE);

		$breakdown = PoolCalculator::priceBreakdown($quantity, $unit_price, $gst_rate, $fee_rate, (float)$reservation['shipping_cost'], $discount);

		$currency_code = (string)($reservation['currency_code'] ?: $this->config->get('config_currency'));

		$store_name = (string)$this->config->get('config_name');

		$order_data = [
			'subscription_id'   => 0,
			'invoice_prefix'    => (string)$this->config->get('config_invoice_prefix'),
			'store_id'          => (int)$this->config->get('config_store_id'),
			'store_name'        => $store_name,
			'store_url'         => (string)$this->config->get('config_url'),
			'customer_id'       => $customer_id,
			'customer_group_id' => (int)$customer_info['customer_group_id'],
			'firstname'         => (string)$customer_info['firstname'],
			'lastname'          => (string)$customer_info['lastname'],
			'email'             => (string)$customer_info['email'],
			'telephone'         => (string)$customer_info['telephone'],
			'custom_field'      => [],

			'payment_address_id'     => $address['address_id'],
			'payment_firstname'      => $address['firstname'],
			'payment_lastname'       => $address['lastname'],
			'payment_company'        => $address['company'],
			'payment_address_1'      => $address['address_1'],
			'payment_address_2'      => $address['address_2'],
			'payment_city'           => $address['city'],
			'payment_postcode'       => $address['postcode'],
			'payment_country'        => $address['country'],
			'payment_country_id'     => $address['country_id'],
			'payment_zone'           => $address['zone'],
			'payment_zone_id'        => $address['zone_id'],
			'payment_address_format' => '',
			'payment_custom_field'   => [],
			// Reserve-now-pay-later: the order is raised unpaid and a real gateway
			// can be attached later without changing any of this.
			'payment_method' => ['name' => 'Pool invoice', 'code' => 'poolbuy.invoice'],

			'shipping_address_id'     => $address['address_id'],
			'shipping_firstname'      => $address['firstname'],
			'shipping_lastname'       => $address['lastname'],
			'shipping_company'        => $address['company'],
			'shipping_address_1'      => $address['address_1'],
			'shipping_address_2'      => $address['address_2'],
			'shipping_city'           => $address['city'],
			'shipping_postcode'       => $address['postcode'],
			'shipping_country'        => $address['country'],
			'shipping_country_id'     => $address['country_id'],
			'shipping_zone'           => $address['zone'],
			'shipping_zone_id'        => $address['zone_id'],
			'shipping_address_format' => '',
			'shipping_custom_field'   => [],
			'shipping_method'         => ['name' => 'Direct manufacturer freight', 'code' => 'poolbuy.pool_freight'],

			'comment'         => sprintf('PoolBuy pool %s - commitment %s (%d %s)', (string)$reservation['pool_reference'], (string)$reservation['reference'], $quantity, (string)$reservation['unit_label']),
			'total'           => $breakdown['total'],
			'affiliate_id'    => 0,
			'commission'      => 0,
			'marketing_id'    => 0,
			'tracking'        => '',
			'language_id'     => (int)$this->config->get('config_language_id'),
			'language_code'   => (string)$this->config->get('config_language'),
			'currency_id'     => $this->currencyId($currency_code),
			'currency_code'   => $currency_code,
			'currency_value'  => 1.0,
			'ip'              => '',
			'forwarded_ip'    => '',
			'user_agent'      => 'PoolBuy lifecycle',
			'accept_language' => '',

			'products' => [
				[
					'product_id'   => (int)$reservation['product_id'],
					'master_id'    => 0,
					'name'         => (string)($product_info['name'] ?? $reservation['title']),
					'model'        => (string)($product_info['model'] ?? ''),
					'option'       => [],
					'subscription' => [],
					'download'     => [],
					'quantity'     => $quantity,
					'subtract'     => (bool)($product_info['subtract'] ?? 0),
					'price'        => $unit_price,
					'total'        => $breakdown['subtotal'],
					'tax'          => 0,
					'reward'       => 0
				]
			],

			'totals' => $this->buildTotals($breakdown, $gst_rate, $fee_rate)
		];

		$this->db->query("START TRANSACTION");

		try {
			$order_id = $this->model_checkout_order->addOrder($order_data);

			if (!$order_id) {
				throw new \RuntimeException('addOrder returned no id');
			}

			// Only mark the commitment converted once the order genuinely exists, and
			// only if it is still awaiting conversion, so a concurrent run cannot
			// create a second order for the same commitment.
			$this->db->query("UPDATE `" . DB_PREFIX . "poolbuy_reservation` SET `status` = 'converted', `order_id` = '" . (int)$order_id . "', `date_modified` = NOW() WHERE `reservation_id` = '" . $reservation_id . "' AND `status` = 'confirmed' AND `order_id` = '0'");

			if (!$this->db->countAffected()) {
				throw new \RuntimeException('reservation was already converted');
			}

			$this->db->query("INSERT INTO `" . DB_PREFIX . "poolbuy_pool_event` SET `pool_id` = '" . (int)$reservation['pool_id'] . "', `customer_id` = '" . $customer_id . "', `type` = 'reservation_converted', `payload` = '" . $this->db->escape((string)json_encode(['order_id' => $order_id, 'reference' => $reservation['reference'], 'total' => $breakdown['total']])) . "', `date_added` = NOW()");

			// Raise a pending payment for the freshly created order and generate a
			// payable link through the configured gateway. Idempotent: the unique key
			// on poolbuy_payment.order_id means a re-run inserts nothing. Kept inside
			// the same transaction so an order never exists without its payment row.
			$this->issuePayment($order_id, $reservation_id, (float)$breakdown['total'], $currency_code, (string)$customer_info['email'], (string)$customer_info['firstname']);

			$this->db->query("COMMIT");

			// Give the order a real status so it appears in admin Sales rather than
			// sitting as an invisible zero-status record. A pool order is raised
			// unpaid, so the store's configured "order" status (normally Pending) is
			// the right starting point; if the store has none configured we fall back
			// to whatever status actually exists rather than leaving it at 0.
			$this->model_checkout_order->addHistory($order_id, $this->initialOrderStatusId(), $order_data['comment'], false);

			// Tell the buyer their order is ready to pay, with the payment link.
			$this->notifyPaymentRequested($order_id, (string)$customer_info['email'], (string)$customer_info['firstname']);

			return true;
		} catch (\Throwable $e) {
			$this->db->query("ROLLBACK");

			$this->log->write('PoolBuy order conversion failed for reservation ' . $reservation_id . ': ' . $e->getMessage());

			return false;
		}
	}

	/**
	 * Issue Payment
	 *
	 * Records a pending payment for a newly raised order and asks the configured
	 * gateway for a payable link. Idempotent: the unique key on
	 * poolbuy_payment.order_id means a second call for the same order inserts
	 * nothing and leaves the existing row untouched.
	 *
	 * @param int    $order_id
	 * @param int    $reservation_id
	 * @param float  $amount
	 * @param string $currency
	 * @param string $email
	 * @param string $name
	 *
	 * @return void
	 */
	private function issuePayment(int $order_id, int $reservation_id, float $amount, string $currency, string $email, string $name): void {
		$existing = $this->db->query("SELECT `payment_id` FROM `" . DB_PREFIX . "poolbuy_payment` WHERE `order_id` = '" . (int)$order_id . "'");

		if ($existing->num_rows) {
			return;
		}

		$gateway = $this->gateway();

		$link = $gateway->createPaymentLink([
			'order_id'  => $order_id,
			'reference' => 'PB-ORDER-' . $order_id,
			'amount'    => $amount,
			'currency'  => $currency,
			'email'     => $email,
			'name'      => $name
		]);

		$this->db->query(
			"INSERT INTO `" . DB_PREFIX . "poolbuy_payment` SET "
			. "`order_id` = '" . (int)$order_id . "', "
			. "`reservation_id` = '" . (int)$reservation_id . "', "
			. "`gateway` = '" . $this->db->escape($gateway->code()) . "', "
			. "`gateway_ref` = '" . $this->db->escape((string)$link['reference']) . "', "
			. "`link_url` = '" . $this->db->escape((string)$link['url']) . "', "
			. "`amount` = '" . (float)$amount . "', "
			. "`currency` = '" . $this->db->escape($currency) . "', "
			. "`status` = 'pending', `date_added` = NOW(), `date_modified` = NOW()"
		);
	}

	/**
	 * Gateway
	 *
	 * Builds the payment gateway from module settings. Mock unless Razorpay is
	 * selected with both a key and secret present.
	 *
	 * @return \Opencart\System\Library\Extension\Poolbuy\PaymentGatewayInterface
	 */
	private function gateway(): \Opencart\System\Library\Extension\Poolbuy\PaymentGatewayInterface {
		return GatewayFactory::make([
			'gateway'         => (string)$this->config->get('module_poolbuy_gateway'),
			'razorpay_key'    => (string)$this->config->get('module_poolbuy_razorpay_key'),
			'razorpay_secret' => (string)$this->config->get('module_poolbuy_razorpay_secret'),
			'base_url'        => (string)$this->config->get('config_url') . 'index.php?route=extension/poolbuy/payment.pay'
		]);
	}

	/**
	 * Refund Pool Payments
	 *
	 * Refunds every paid payment behind a pool that is being expired or cancelled.
	 * Idempotent: only payments still in 'paid' are touched, and the row is moved
	 * to 'refunded' only once the gateway confirms, so a re-run refunds nothing
	 * twice. Buyers with no paid payment are untouched - nobody was charged.
	 *
	 * @param int $pool_id
	 *
	 * @return int number of payments refunded
	 */
	public function refundPoolPayments(int $pool_id): int {
		$sql = "SELECT pm.`payment_id`, pm.`gateway_ref`, pm.`amount`, pm.`order_id`";
		$sql .= " FROM `" . DB_PREFIX . "poolbuy_payment` pm";
		$sql .= " LEFT JOIN `" . DB_PREFIX . "poolbuy_reservation` r ON (pm.`reservation_id` = r.`reservation_id`)";
		$sql .= " WHERE r.`pool_id` = '" . (int)$pool_id . "' AND pm.`status` = 'paid'";

		$gateway = $this->gateway();

		$refunded = 0;

		foreach ($this->db->query($sql)->rows as $payment) {
			$refund_ref = $gateway->refund((string)$payment['gateway_ref'], (float)$payment['amount'], (string)($payment['currency'] ?? ''));

			if ($refund_ref === null) {
				$this->log->write('PoolBuy refund failed for payment ' . (int)$payment['payment_id'] . ' on pool ' . $pool_id);

				continue;
			}

			// Conditional update: only a still-paid row transitions, so two runs
			// cannot both record a refund for the same payment.
			$this->db->query("UPDATE `" . DB_PREFIX . "poolbuy_payment` SET `status` = 'refunded', `refund_ref` = '" . $this->db->escape($refund_ref) . "', `date_modified` = NOW() WHERE `payment_id` = '" . (int)$payment['payment_id'] . "' AND `status` = 'paid'");

			if ($this->db->countAffected()) {
				$refunded++;

				$this->notifyBuyerPayment((int)$payment['order_id'], 'refund_issued');
			}
		}

		return $refunded;
	}

	/**
	 * Write Settlement
	 *
	 * Records what the seller is owed for a fulfilled pool: the gross of the pool's
	 * order subtotals, the platform fee retained, and the net payable. Idempotent
	 * via the unique key on poolbuy_settlement.pool_id, so a re-run of fulfilment
	 * writes nothing new.
	 *
	 * Gross is taken from the order line subtotals of the pool's converted
	 * reservations, so it reconciles exactly with what buyers were invoiced.
	 *
	 * @param int $pool_id
	 *
	 * @return void
	 */
	public function writeSettlement(int $pool_id): void {
		$existing = $this->db->query("SELECT `settlement_id` FROM `" . DB_PREFIX . "poolbuy_settlement` WHERE `pool_id` = '" . (int)$pool_id . "'");

		if ($existing->num_rows) {
			return;
		}

		$pool = $this->db->query("SELECT `seller_id`, `currency_code` FROM `" . DB_PREFIX . "poolbuy_pool` WHERE `pool_id` = '" . (int)$pool_id . "'");

		if (!$pool->num_rows) {
			return;
		}

		// Gross from the order line subtotals of this pool's converted commitments.
		$sql = "SELECT COALESCE(SUM(op.`total`), 0) AS `gross`, COUNT(DISTINCT r.`order_id`) AS `orders`";
		$sql .= " FROM `" . DB_PREFIX . "poolbuy_reservation` r";
		$sql .= " LEFT JOIN `" . DB_PREFIX . "order_product` op ON (op.`order_id` = r.`order_id`)";
		$sql .= " WHERE r.`pool_id` = '" . (int)$pool_id . "' AND r.`status` = 'converted' AND r.`order_id` > 0";

		$totals = $this->db->query($sql)->row;

		$gross = round((float)$totals['gross'], 4);
		$fee_rate = (float)$this->config->get('module_poolbuy_platform_fee');
		$platform_fee = round($gross * (max(0.0, $fee_rate) / 100), 4);
		$net = round($gross - $platform_fee, 4);

		$currency = (string)($pool->row['currency_code'] ?: $this->config->get('config_currency'));

		$this->db->query(
			"INSERT INTO `" . DB_PREFIX . "poolbuy_settlement` SET "
			. "`seller_id` = '" . (int)$pool->row['seller_id'] . "', "
			. "`pool_id` = '" . (int)$pool_id . "', "
			. "`gross` = '" . $gross . "', "
			. "`platform_fee` = '" . $platform_fee . "', "
			. "`net` = '" . $net . "', "
			. "`currency` = '" . $this->db->escape($currency) . "', "
			. "`order_count` = '" . (int)$totals['orders'] . "', "
			. "`status` = 'pending', `date_added` = NOW(), `date_modified` = NOW() "
			. "ON DUPLICATE KEY UPDATE `pool_id` = `pool_id`"
		);

		$this->addEvent($pool_id, 'settlement_recorded', [
			'gross'        => $gross,
			'platform_fee' => $platform_fee,
			'net'          => $net,
			'order_count'  => (int)$totals['orders']
		]);
	}

	/**
	 * Notify Payment Requested
	 *
	 * Emails the buyer that their pool order is ready to pay, including the payment
	 * link stored on the payment row. Failure-tolerant like every other send here.
	 *
	 * @param int    $order_id
	 * @param string $email
	 * @param string $firstname
	 *
	 * @return void
	 */
	private function notifyPaymentRequested(int $order_id, string $email, string $firstname): void {
		if (!$this->config->get('config_mail_engine')) {
			return;
		}

		if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
			return;
		}

		$payment = $this->db->query("SELECT `link_url` FROM `" . DB_PREFIX . "poolbuy_payment` WHERE `order_id` = '" . (int)$order_id . "'");

		$link = $payment->num_rows ? (string)$payment->row['link_url'] : '';

		$this->load->language('extension/poolbuy/poolbuy/mail');

		$mail_option = [
			'parameter'     => $this->config->get('config_mail_parameter'),
			'smtp_hostname' => $this->config->get('config_mail_smtp_hostname'),
			'smtp_username' => $this->config->get('config_mail_smtp_username'),
			'smtp_password' => html_entity_decode((string)$this->config->get('config_mail_smtp_password'), ENT_QUOTES, 'UTF-8'),
			'smtp_port'     => $this->config->get('config_mail_smtp_port'),
			'smtp_timeout'  => $this->config->get('config_mail_smtp_timeout')
		];

		try {
			$mail = new \Opencart\System\Library\Mail((string)$this->config->get('config_mail_engine'), $mail_option);

			$mail->setTo($email);
			$mail->setFrom((string)$this->config->get('config_email'));
			$mail->setSender((string)$this->config->get('config_name'));
			$mail->setSubject(sprintf($this->language->get('subject_payment_requested'), $order_id));
			$mail->setText(sprintf($this->language->get('body_payment_requested'), $firstname, $order_id, $link));
			$mail->send();
		} catch (\Throwable $e) {
			$this->log->write('PoolBuy payment_requested notification failed for order ' . $order_id . ': ' . $e->getMessage());
		}
	}

	/**
	 * Notify Buyer Payment
	 *
	 * Emails the buyer behind an order about a payment event (payment_requested,
	 * payment_received, refund_issued), reusing the same failure-tolerant
	 * transport as notifyParticipants(). Never throws.
	 *
	 * @param int    $order_id
	 * @param string $event
	 *
	 * @return void
	 */
	public function notifyBuyerPayment(int $order_id, string $event): void {
		if (!$this->config->get('config_mail_engine')) {
			return;
		}

		$this->load->language('extension/poolbuy/poolbuy/mail');

		$query = $this->db->query("SELECT `email`, `firstname` FROM `" . DB_PREFIX . "order` WHERE `order_id` = '" . (int)$order_id . "'");

		if (!$query->num_rows || empty($query->row['email']) || !filter_var($query->row['email'], FILTER_VALIDATE_EMAIL)) {
			return;
		}

		$mail_option = [
			'parameter'     => $this->config->get('config_mail_parameter'),
			'smtp_hostname' => $this->config->get('config_mail_smtp_hostname'),
			'smtp_username' => $this->config->get('config_mail_smtp_username'),
			'smtp_password' => html_entity_decode((string)$this->config->get('config_mail_smtp_password'), ENT_QUOTES, 'UTF-8'),
			'smtp_port'     => $this->config->get('config_mail_smtp_port'),
			'smtp_timeout'  => $this->config->get('config_mail_smtp_timeout')
		];

		try {
			$mail = new \Opencart\System\Library\Mail((string)$this->config->get('config_mail_engine'), $mail_option);

			$mail->setTo((string)$query->row['email']);
			$mail->setFrom((string)$this->config->get('config_email'));
			$mail->setSender((string)$this->config->get('config_name'));
			$mail->setSubject(sprintf($this->language->get('subject_' . $event), (int)$order_id));
			$mail->setText(sprintf($this->language->get('body_' . $event), (string)$query->row['firstname'], (int)$order_id));
			$mail->send();
		} catch (\Throwable $e) {
			$this->log->write('PoolBuy payment notification failed for order ' . $order_id . ': ' . $e->getMessage());
		}
	}

	/**
	 * Initial Order Status ID
	 *
	 * The status a newly raised pool order should start in.
	 *
	 * @return int
	 */
	private function initialOrderStatusId(): int {
		foreach (['config_order_status_id', 'config_processing_status_id'] as $key) {
			$status_id = (int)$this->config->get($key);

			if ($status_id > 0) {
				return $status_id;
			}
		}

		// Nothing configured: use the lowest real status the store still has, so the
		// order is at least visible and actionable in admin Sales.
		$query = $this->db->query("SELECT `order_status_id` FROM `" . DB_PREFIX . "order_status` WHERE `language_id` = '" . (int)$this->config->get('config_language_id') . "' ORDER BY `order_status_id` ASC LIMIT 1");

		return $query->num_rows ? (int)$query->row['order_status_id'] : 1;
	}

	/**
	 * Build Totals
	 *
	 * Order totals in the conventional OpenCart order: sub total, then charges,
	 * then the grand total.
	 *
	 * @param array<string, mixed> $breakdown
	 * @param float                $gst_rate
	 * @param float                $fee_rate
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function buildTotals(array $breakdown, float $gst_rate, float $fee_rate): array {
		$totals = [];
		$sort = 1;

		$totals[] = ['extension' => 'opencart', 'code' => 'sub_total', 'title' => 'Sub-Total', 'value' => $breakdown['subtotal'], 'sort_order' => $sort++];

		// A promotion discount sits immediately after the sub-total and BEFORE the
		// fee and GST lines, because both of those are charged on the discounted
		// base. Carried as a negative value, which is how OpenCart represents a
		// reduction in an order total line.
		if (!empty($breakdown['discount']) && $breakdown['discount'] > 0) {
			$totals[] = ['extension' => 'poolbuy', 'code' => 'poolbuy_discount', 'title' => 'Discount', 'value' => -$breakdown['discount'], 'sort_order' => $sort++];
		}

		if ($breakdown['shipping'] > 0) {
			$totals[] = ['extension' => 'opencart', 'code' => 'shipping', 'title' => 'Freight', 'value' => $breakdown['shipping'], 'sort_order' => $sort++];
		}

		if ($breakdown['platform_fee'] > 0) {
			$totals[] = ['extension' => 'poolbuy', 'code' => 'poolbuy_fee', 'title' => sprintf('Platform Fee (%s%%)', $fee_rate), 'value' => $breakdown['platform_fee'], 'sort_order' => $sort++];
		}

		if ($breakdown['gst'] > 0) {
			$totals[] = ['extension' => 'poolbuy', 'code' => 'poolbuy_gst', 'title' => sprintf('GST (%s%%)', $gst_rate), 'value' => $breakdown['gst'], 'sort_order' => $sort++];
		}

		$totals[] = ['extension' => 'opencart', 'code' => 'total', 'title' => 'Total', 'value' => $breakdown['total'], 'sort_order' => $sort];

		return $totals;
	}

	/**
	 * Resolve Address
	 *
	 * The buyer's chosen destination, with country and zone names resolved. Falls
	 * back to any address the customer owns, then to blanks, so a missing address
	 * degrades rather than aborting the order.
	 *
	 * @param int $address_id
	 * @param int $customer_id
	 *
	 * @return array<string, mixed>
	 */
	private function resolveAddress(int $address_id, int $customer_id): array {
		// In OpenCart 4.1 country and zone NAMES live in language-scoped description
		// tables; oc_country and oc_zone themselves hold only codes and status.
		$language_id = (int)$this->config->get('config_language_id');

		$sql = "SELECT a.*, cd.`name` AS `country`, zd.`name` AS `zone` FROM `" . DB_PREFIX . "address` a";
		$sql .= " LEFT JOIN `" . DB_PREFIX . "country_description` cd ON (a.`country_id` = cd.`country_id` AND cd.`language_id` = '" . $language_id . "')";
		$sql .= " LEFT JOIN `" . DB_PREFIX . "zone_description` zd ON (a.`zone_id` = zd.`zone_id` AND zd.`language_id` = '" . $language_id . "')";
		$sql .= " WHERE a.`customer_id` = '" . (int)$customer_id . "'";

		if ($address_id > 0) {
			$sql .= " AND a.`address_id` = '" . (int)$address_id . "'";
		}

		$sql .= " ORDER BY a.`default` DESC, a.`address_id` ASC LIMIT 1";

		$query = $this->db->query($sql);

		if (!$query->num_rows) {
			return [
				'address_id' => 0, 'firstname' => '', 'lastname' => '', 'company' => '',
				'address_1'  => '', 'address_2' => '', 'city' => '', 'postcode' => '',
				'country'    => '', 'country_id' => 0, 'zone' => '', 'zone_id' => 0
			];
		}

		$row = $query->row;

		return [
			'address_id' => (int)$row['address_id'],
			'firstname'  => (string)$row['firstname'],
			'lastname'   => (string)$row['lastname'],
			'company'    => (string)$row['company'],
			'address_1'  => (string)$row['address_1'],
			'address_2'  => (string)$row['address_2'],
			'city'       => (string)$row['city'],
			'postcode'   => (string)$row['postcode'],
			'country'    => (string)($row['country'] ?? ''),
			'country_id' => (int)$row['country_id'],
			'zone'       => (string)($row['zone'] ?? ''),
			'zone_id'    => (int)$row['zone_id']
		];
	}

	/**
	 * Currency ID
	 *
	 * @param string $code
	 *
	 * @return int
	 */
	private function currencyId(string $code): int {
		$query = $this->db->query("SELECT `currency_id` FROM `" . DB_PREFIX . "currency` WHERE `code` = '" . $this->db->escape($code) . "'");

		return $query->num_rows ? (int)$query->row['currency_id'] : (int)$this->config->get('config_currency_id');
	}

	/**
	 * Notify Participants
	 *
	 * Emails everyone holding a commitment on a pool. Failures are logged but
	 * never abort the lifecycle: a mail outage must not stop pools progressing.
	 *
	 * @param int    $pool_id
	 * @param string $event   reached|expired|fulfilled
	 *
	 * @return int number of messages sent
	 */
	public function notifyParticipants(int $pool_id, string $event): int {
		if (!$this->config->get('config_mail_engine')) {
			return 0;
		}

		$this->load->language('extension/poolbuy/poolbuy/mail');

		$sql = "SELECT r.`reference`, r.`quantity`, r.`unit_price_locked`, c.`email`, c.`firstname`, p.`title`, p.`reference` AS `pool_reference`, p.`unit_label`";
		$sql .= " FROM `" . DB_PREFIX . "poolbuy_reservation` r";
		$sql .= " LEFT JOIN `" . DB_PREFIX . "customer` c ON (r.`customer_id` = c.`customer_id`)";
		$sql .= " LEFT JOIN `" . DB_PREFIX . "poolbuy_pool` p ON (r.`pool_id` = p.`pool_id`)";
		$sql .= " WHERE r.`pool_id` = '" . (int)$pool_id . "' AND r.`status` IN ('pending', 'confirmed', 'converted', 'released')";

		$sent = 0;

		// Mail transport settings are passed through the constructor's option array;
		// the Mail library exposes no public properties for them.
		$mail_option = [
			'parameter'     => $this->config->get('config_mail_parameter'),
			'smtp_hostname' => $this->config->get('config_mail_smtp_hostname'),
			'smtp_username' => $this->config->get('config_mail_smtp_username'),
			'smtp_password' => html_entity_decode((string)$this->config->get('config_mail_smtp_password'), ENT_QUOTES, 'UTF-8'),
			'smtp_port'     => $this->config->get('config_mail_smtp_port'),
			'smtp_timeout'  => $this->config->get('config_mail_smtp_timeout')
		];

		foreach ($this->db->query($sql)->rows as $row) {
			if (empty($row['email']) || !filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
				continue;
			}

			$subject = sprintf($this->language->get('subject_' . $event), (string)$row['pool_reference']);

			$body = sprintf(
				$this->language->get('body_' . $event),
				(string)$row['firstname'],
				(string)($row['title'] ?: $row['pool_reference']),
				(int)$row['quantity'],
				(string)$row['unit_label'],
				(string)$row['reference']
			);

			try {
				$mail = new \Opencart\System\Library\Mail((string)$this->config->get('config_mail_engine'), $mail_option);

				$mail->setTo((string)$row['email']);
				$mail->setFrom((string)$this->config->get('config_email'));
				$mail->setSender((string)$this->config->get('config_name'));
				$mail->setSubject($subject);
				$mail->setText($body);
				$mail->send();

				$sent++;
			} catch (\Throwable $e) {
				$this->log->write('PoolBuy notification failed for pool ' . $pool_id . ': ' . $e->getMessage());
			}
		}

		return $sent;
	}

	/**
	 * Set Status
	 *
	 * @param int                  $pool_id
	 * @param string               $status
	 * @param array<string, mixed> $payload
	 *
	 * @return void
	 */
	private function setStatus(int $pool_id, string $status, array $payload): void {
		$this->db->query("UPDATE `" . DB_PREFIX . "poolbuy_pool` SET `status` = '" . $this->db->escape($status) . "', `date_modified` = NOW() WHERE `pool_id` = '" . (int)$pool_id . "'");

		$this->addEvent($pool_id, 'pool_' . $status, $payload);
	}

	/**
	 * Release Reservations
	 *
	 * @param int $pool_id
	 *
	 * @return int
	 */
	private function releaseReservations(int $pool_id): int {
		$this->db->query("UPDATE `" . DB_PREFIX . "poolbuy_reservation` SET `status` = 'released', `date_modified` = NOW() WHERE `pool_id` = '" . (int)$pool_id . "' AND `status` IN ('pending', 'confirmed')");

		return (int)$this->db->countAffected();
	}

	/**
	 * Current Reserved
	 *
	 * @param int $pool_id
	 *
	 * @return int
	 */
	private function currentReserved(int $pool_id): int {
		$query = $this->db->query("SELECT COALESCE(SUM(`quantity`), 0) AS `total` FROM `" . DB_PREFIX . "poolbuy_reservation` WHERE `pool_id` = '" . (int)$pool_id . "' AND `status` IN ('pending', 'confirmed', 'converted')");

		return (int)$query->row['total'];
	}

	/**
	 * Recalculate Reserved
	 *
	 * @param int $pool_id
	 *
	 * @return void
	 */
	private function recalculateReserved(int $pool_id): void {
		$this->db->query("UPDATE `" . DB_PREFIX . "poolbuy_pool` SET `reserved_qty` = '" . $this->currentReserved($pool_id) . "' WHERE `pool_id` = '" . (int)$pool_id . "'");
	}

	/**
	 * Add Event
	 *
	 * @param int                  $pool_id
	 * @param string               $type
	 * @param array<string, mixed> $payload
	 *
	 * @return void
	 */
	private function addEvent(int $pool_id, string $type, array $payload): void {
		$this->db->query("INSERT INTO `" . DB_PREFIX . "poolbuy_pool_event` SET `pool_id` = '" . (int)$pool_id . "', `customer_id` = '0', `type` = '" . $this->db->escape($type) . "', `payload` = '" . $this->db->escape((string)json_encode($payload)) . "', `date_added` = NOW()");
	}
}
