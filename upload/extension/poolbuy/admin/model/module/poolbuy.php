<?php
namespace Opencart\Admin\Model\Extension\Poolbuy\Module;
/**
 * Class Poolbuy
 *
 * Owns the PoolBuy database schema. Called by the module controller's
 * install()/uninstall() hooks, which OpenCart triggers from
 * Extensions > Modules > PoolBuy.
 *
 * @package Opencart\Admin\Model\Extension\Poolbuy\Module
 */
class Poolbuy extends \Opencart\System\Engine\Model {
	/**
	 * Table names owned by this extension, in creation order.
	 *
	 * Uninstall drops them in reverse so child tables go first.
	 *
	 * @var array<int, string>
	 */
	private array $tables = [
		'poolbuy_seller',
		'poolbuy_product_seller',
		'poolbuy_pool',
		'poolbuy_pool_tier',
		'poolbuy_reservation',
		'poolbuy_pool_event',
		'poolbuy_payment',
		'poolbuy_settlement',
		'poolbuy_promotion',
		'poolbuy_promotion_redemption',
		'poolbuy_review',
		'poolbuy_rate_limit'
	];

	/**
	 * Install
	 *
	 * Creates every PoolBuy table. Safe to re-run: each statement uses
	 * IF NOT EXISTS so a partially installed schema is completed rather
	 * than erroring.
	 *
	 * Because IF NOT EXISTS leaves an EXISTING table untouched, creating the
	 * tables alone is not enough when the extension is reinstalled over an older
	 * database - new columns added in a later version would be silently skipped
	 * and every query against them would fail. migrate() closes that gap, so a
	 * single install path works for both a fresh database and an upgrade.
	 *
	 * @return void
	 */
	public function install(): void {
		foreach ($this->getSchema() as $sql) {
			$this->db->query($sql);
		}

		$this->migrate();
	}

	/**
	 * Migrate
	 *
	 * Brings an already-installed schema up to date by adding any column that a
	 * newer version of the extension introduced. Each entry is applied only when
	 * the column is genuinely absent, so this is idempotent and safe to run on
	 * every install.
	 *
	 * @return void
	 */
	public function migrate(): void {
		$columns = [
			// Pool-level freight, split across participants when the pool closes.
			'poolbuy_pool' => [
				'freight_total' => "ADD COLUMN `freight_total` decimal(15,4) NOT NULL DEFAULT '0.0000' AFTER `shipping_terms`"
			],
			// Which promotion (if any) discounted this commitment, and by how much.
			'poolbuy_reservation' => [
				'promotion_id'   => "ADD COLUMN `promotion_id` int(11) NOT NULL DEFAULT '0' AFTER `shipping_cost`",
				'discount_total' => "ADD COLUMN `discount_total` decimal(15,4) NOT NULL DEFAULT '0.0000' AFTER `promotion_id`"
			]
		];

		foreach ($columns as $table => $additions) {
			foreach ($additions as $column => $ddl) {
				if (!$this->hasColumn($table, $column)) {
					$this->db->query("ALTER TABLE `" . DB_PREFIX . $table . "` " . $ddl);
				}
			}
		}
	}

	/**
	 * Has Column
	 *
	 * @param string $table  unprefixed table name
	 * @param string $column
	 *
	 * @return bool
	 */
	private function hasColumn(string $table, string $column): bool {
		$query = $this->db->query(
			"SELECT COUNT(*) AS `total` FROM `INFORMATION_SCHEMA`.`COLUMNS`
			WHERE `TABLE_SCHEMA` = DATABASE()
			AND `TABLE_NAME` = '" . $this->db->escape(DB_PREFIX . $table) . "'
			AND `COLUMN_NAME` = '" . $this->db->escape($column) . "'"
		);

		return (int)$query->row['total'] > 0;
	}

	/**
	 * Uninstall
	 *
	 * Drops every PoolBuy table in reverse dependency order so that no
	 * orphan tables are left behind.
	 *
	 * @return void
	 */
	public function uninstall(): void {
		foreach (array_reverse($this->tables) as $table) {
			$this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . $table . "`");
		}
	}

	/**
	 * Get Schema
	 *
	 * The full DDL for the extension. Kept in one place so install and the
	 * automated schema test read from the same source of truth.
	 *
	 * @return array<int, string> CREATE TABLE statements
	 */
	public function getSchema(): array {
		$suffix = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

		$schema = [];

		// Seller profiles. `customer_id` stays 0 while sellers are admin-managed and
		// is populated in phase 2 when sellers get their own logins.
		$schema[] = "CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "poolbuy_seller` (
			`seller_id` int(11) NOT NULL AUTO_INCREMENT,
			`customer_id` int(11) NOT NULL DEFAULT '0',
			`name` varchar(128) NOT NULL DEFAULT '',
			`slug` varchar(128) NOT NULL DEFAULT '',
			`gst_number` varchar(32) NOT NULL DEFAULT '',
			`gst_verified` tinyint(1) NOT NULL DEFAULT '0',
			`verified_seller` tinyint(1) NOT NULL DEFAULT '0',
			`rating` decimal(3,2) NOT NULL DEFAULT '0.00',
			`rating_count` int(11) NOT NULL DEFAULT '0',
			`description` text,
			`logo` varchar(255) NOT NULL DEFAULT '',
			`location` varchar(128) NOT NULL DEFAULT '',
			`images` text,
			`status` tinyint(1) NOT NULL DEFAULT '1',
			`date_added` datetime NOT NULL,
			`date_modified` datetime NOT NULL,
			PRIMARY KEY (`seller_id`),
			UNIQUE KEY `uk_poolbuy_seller_slug` (`slug`),
			KEY `idx_poolbuy_seller_customer` (`customer_id`),
			KEY `idx_poolbuy_seller_status` (`status`)
		)" . $suffix;

		// Maps an ordinary OpenCart product to the seller that supplies it. Kept as a
		// separate table rather than a column on oc_product so the extension never
		// alters a core schema, which keeps OpenCart upgrades clean. One seller per
		// product, hence product_id as the primary key.
		$schema[] = "CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "poolbuy_product_seller` (
			`product_id` int(11) NOT NULL,
			`seller_id` int(11) NOT NULL DEFAULT '0',
			PRIMARY KEY (`product_id`),
			KEY `idx_poolbuy_product_seller_seller` (`seller_id`)
		)" . $suffix;

		// A pool attaches tiered, MOQ-gated pricing to an ordinary OpenCart product.
		// `reserved_qty` is a denormalised cache of the sum of active reservations; it
		// is only ever mutated through conditional UPDATEs so concurrent joins cannot
		// oversubscribe the pool.
		$schema[] = "CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "poolbuy_pool` (
			`pool_id` int(11) NOT NULL AUTO_INCREMENT,
			`product_id` int(11) NOT NULL DEFAULT '0',
			`seller_id` int(11) NOT NULL DEFAULT '0',
			`title` varchar(255) NOT NULL DEFAULT '',
			`reference` varchar(32) NOT NULL DEFAULT '',
			`moq_target` int(11) NOT NULL DEFAULT '0',
			`reserved_qty` int(11) NOT NULL DEFAULT '0',
			`unit_label` varchar(32) NOT NULL DEFAULT 'Units',
			`currency_code` varchar(3) NOT NULL DEFAULT '',
			`status` enum('draft','active','reached','closed','expired','fulfilled','cancelled') NOT NULL DEFAULT 'draft',
			`date_start` datetime NOT NULL,
			`date_end` datetime NOT NULL,
			`retro_pricing` tinyint(1) NOT NULL DEFAULT '1',
			`allow_full_moq_buy` tinyint(1) NOT NULL DEFAULT '1',
			`min_qty_per_buyer` int(11) NOT NULL DEFAULT '1',
			`max_qty_per_buyer` int(11) NOT NULL DEFAULT '0',
			`lead_time` varchar(64) NOT NULL DEFAULT '',
			`shipping_terms` varchar(64) NOT NULL DEFAULT '',
			`freight_total` decimal(15,4) NOT NULL DEFAULT '0.0000',
			`date_added` datetime NOT NULL,
			`date_modified` datetime NOT NULL,
			PRIMARY KEY (`pool_id`),
			UNIQUE KEY `uk_poolbuy_pool_reference` (`reference`),
			KEY `idx_poolbuy_pool_product` (`product_id`),
			KEY `idx_poolbuy_pool_seller` (`seller_id`),
			KEY `idx_poolbuy_pool_status` (`status`),
			KEY `idx_poolbuy_pool_date_end` (`date_end`),
			KEY `idx_poolbuy_pool_status_end` (`status`, `date_end`)
		)" . $suffix;

		// Price ladder. `max_qty` NULL means the top, open-ended tier.
		$schema[] = "CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "poolbuy_pool_tier` (
			`tier_id` int(11) NOT NULL AUTO_INCREMENT,
			`pool_id` int(11) NOT NULL DEFAULT '0',
			`min_qty` int(11) NOT NULL DEFAULT '0',
			`max_qty` int(11) DEFAULT NULL,
			`price` decimal(15,4) NOT NULL DEFAULT '0.0000',
			`sort_order` int(3) NOT NULL DEFAULT '0',
			PRIMARY KEY (`tier_id`),
			KEY `idx_poolbuy_tier_pool` (`pool_id`),
			KEY `idx_poolbuy_tier_pool_min` (`pool_id`, `min_qty`)
		)" . $suffix;

		// Buyer commitments. These are deliberately NOT cart items: they only become
		// oc_order rows once the pool closes successfully, which is what makes
		// retroactive repricing and pool expiry safe.
		//
		// `active_customer_id` is a generated column that mirrors `customer_id` only
		// while the reservation is live. Combined with the unique key below, the
		// database itself guarantees one active reservation per buyer per pool -
		// duplicate submissions cannot slip through a race between check and insert.
		$schema[] = "CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "poolbuy_reservation` (
			`reservation_id` int(11) NOT NULL AUTO_INCREMENT,
			`pool_id` int(11) NOT NULL DEFAULT '0',
			`customer_id` int(11) NOT NULL DEFAULT '0',
			`quantity` int(11) NOT NULL DEFAULT '0',
			`unit_price_locked` decimal(15,4) NOT NULL DEFAULT '0.0000',
			`status` enum('pending','confirmed','cancelled','converted','released') NOT NULL DEFAULT 'pending',
			`order_id` int(11) NOT NULL DEFAULT '0',
			`address_id` int(11) NOT NULL DEFAULT '0',
			`shipping_method` text,
			`shipping_cost` decimal(15,4) NOT NULL DEFAULT '0.0000',
			`promotion_id` int(11) NOT NULL DEFAULT '0',
			`discount_total` decimal(15,4) NOT NULL DEFAULT '0.0000',
			`reference` varchar(32) NOT NULL DEFAULT '',
			`date_added` datetime NOT NULL,
			`date_modified` datetime NOT NULL,
			`active_customer_id` int(11) GENERATED ALWAYS AS (CASE WHEN `status` IN ('pending','confirmed') THEN `customer_id` ELSE NULL END) STORED,
			PRIMARY KEY (`reservation_id`),
			UNIQUE KEY `uk_poolbuy_reservation_reference` (`reference`),
			UNIQUE KEY `uk_poolbuy_reservation_active` (`pool_id`, `active_customer_id`),
			KEY `idx_poolbuy_reservation_pool` (`pool_id`),
			KEY `idx_poolbuy_reservation_customer` (`customer_id`),
			KEY `idx_poolbuy_reservation_status` (`status`),
			KEY `idx_poolbuy_reservation_order` (`order_id`)
		)" . $suffix;

		// Append-only audit trail. Doubles as the source for the anonymised
		// "Recent Participants" live feed on the product page.
		$schema[] = "CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "poolbuy_pool_event` (
			`event_id` int(11) NOT NULL AUTO_INCREMENT,
			`pool_id` int(11) NOT NULL DEFAULT '0',
			`customer_id` int(11) NOT NULL DEFAULT '0',
			`type` varchar(32) NOT NULL DEFAULT '',
			`payload` text,
			`date_added` datetime NOT NULL,
			PRIMARY KEY (`event_id`),
			KEY `idx_poolbuy_event_pool` (`pool_id`),
			KEY `idx_poolbuy_event_date` (`date_added`),
			KEY `idx_poolbuy_event_pool_type` (`pool_id`, `type`)
		)" . $suffix;

		// One payment per pool order. Created pending when the order is raised at
		// pool close; moved to paid by the buyer paying or a gateway webhook, and to
		// refunded when a pool the buyer paid into is later cancelled or expired.
		// `gateway_ref` is the gateway's own handle, which a webhook quotes back.
		$schema[] = "CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "poolbuy_payment` (
			`payment_id` int(11) NOT NULL AUTO_INCREMENT,
			`order_id` int(11) NOT NULL DEFAULT '0',
			`reservation_id` int(11) NOT NULL DEFAULT '0',
			`gateway` varchar(32) NOT NULL DEFAULT '',
			`gateway_ref` varchar(191) NOT NULL DEFAULT '',
			`refund_ref` varchar(191) NOT NULL DEFAULT '',
			`link_url` text,
			`amount` decimal(15,4) NOT NULL DEFAULT '0.0000',
			`currency` varchar(3) NOT NULL DEFAULT '',
			`status` enum('pending','paid','failed','refunded') NOT NULL DEFAULT 'pending',
			`date_added` datetime NOT NULL,
			`date_modified` datetime NOT NULL,
			PRIMARY KEY (`payment_id`),
			UNIQUE KEY `uk_poolbuy_payment_order` (`order_id`),
			KEY `idx_poolbuy_payment_reservation` (`reservation_id`),
			KEY `idx_poolbuy_payment_gateway_ref` (`gateway_ref`),
			KEY `idx_poolbuy_payment_status` (`status`)
		)" . $suffix;

		// One settlement row per seller per fulfilled pool: the gross of paid order
		// subtotals, the platform fee retained, and the net owed to the seller. Read
		// only from the portal and admin; written once at fulfilment.
		$schema[] = "CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "poolbuy_settlement` (
			`settlement_id` int(11) NOT NULL AUTO_INCREMENT,
			`seller_id` int(11) NOT NULL DEFAULT '0',
			`pool_id` int(11) NOT NULL DEFAULT '0',
			`gross` decimal(15,4) NOT NULL DEFAULT '0.0000',
			`platform_fee` decimal(15,4) NOT NULL DEFAULT '0.0000',
			`net` decimal(15,4) NOT NULL DEFAULT '0.0000',
			`currency` varchar(3) NOT NULL DEFAULT '',
			`order_count` int(11) NOT NULL DEFAULT '0',
			`status` enum('pending','paid') NOT NULL DEFAULT 'pending',
			`date_added` datetime NOT NULL,
			`date_modified` datetime NOT NULL,
			PRIMARY KEY (`settlement_id`),
			UNIQUE KEY `uk_poolbuy_settlement_pool` (`pool_id`),
			KEY `idx_poolbuy_settlement_seller` (`seller_id`)
		)" . $suffix;

		// Discount codes. `scope` decides who may use one:
		//   'pool'  - attached to a pool and applied to EVERY participant at close
		//   'buyer' - typed in by an individual buyer at join, discounting only them
		//
		// `pool_id` 0 on a buyer-scoped code means it works on any pool. A
		// pool-scoped row must name its pool. `max_redemptions` 0 means unlimited;
		// `redemptions_used` is only ever moved by a conditional UPDATE so two
		// buyers cannot both claim the last redemption.
		$schema[] = "CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "poolbuy_promotion` (
			`promotion_id` int(11) NOT NULL AUTO_INCREMENT,
			`code` varchar(64) NOT NULL DEFAULT '',
			`name` varchar(128) NOT NULL DEFAULT '',
			`scope` enum('pool','buyer') NOT NULL DEFAULT 'buyer',
			`pool_id` int(11) NOT NULL DEFAULT '0',
			`type` enum('percent','fixed') NOT NULL DEFAULT 'percent',
			`value` decimal(15,4) NOT NULL DEFAULT '0.0000',
			`min_qty` int(11) NOT NULL DEFAULT '0',
			`max_redemptions` int(11) NOT NULL DEFAULT '0',
			`redemptions_used` int(11) NOT NULL DEFAULT '0',
			`date_start` datetime DEFAULT NULL,
			`date_end` datetime DEFAULT NULL,
			`status` tinyint(1) NOT NULL DEFAULT '1',
			`date_added` datetime NOT NULL,
			`date_modified` datetime NOT NULL,
			PRIMARY KEY (`promotion_id`),
			UNIQUE KEY `uk_poolbuy_promotion_code` (`code`),
			KEY `idx_poolbuy_promotion_scope` (`scope`),
			KEY `idx_poolbuy_promotion_pool` (`pool_id`),
			KEY `idx_poolbuy_promotion_status` (`status`)
		)" . $suffix;

		// One row per promotion actually applied to a commitment. The unique key on
		// (promotion_id, reservation_id) makes re-applying the same code to the same
		// commitment impossible, which is what keeps pool close idempotent: a second
		// cron run cannot discount the same buyer twice.
		$schema[] = "CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "poolbuy_promotion_redemption` (
			`redemption_id` int(11) NOT NULL AUTO_INCREMENT,
			`promotion_id` int(11) NOT NULL DEFAULT '0',
			`reservation_id` int(11) NOT NULL DEFAULT '0',
			`customer_id` int(11) NOT NULL DEFAULT '0',
			`discount_amount` decimal(15,4) NOT NULL DEFAULT '0.0000',
			`date_added` datetime NOT NULL,
			PRIMARY KEY (`redemption_id`),
			UNIQUE KEY `uk_poolbuy_redemption_unique` (`promotion_id`, `reservation_id`),
			KEY `idx_poolbuy_redemption_reservation` (`reservation_id`),
			KEY `idx_poolbuy_redemption_customer` (`customer_id`)
		)" . $suffix;

		// Buyer reviews of a completed pool purchase.
		//
		// A review is anchored to a RESERVATION, not merely to a product or seller,
		// and the unique key on reservation_id is what makes "one review per actual
		// purchase" a database guarantee rather than a convention. Combined with the
		// eligibility check in the storefront model - which requires the commitment
		// to be converted, the pool fulfilled and the payment settled - this is what
		// stops the ratings being gamed by people who never bought anything.
		//
		// Reviews default to 'pending' and are invisible until an operator approves
		// them, mirroring OpenCart's own product review moderation.
		$schema[] = "CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "poolbuy_review` (
			`review_id` int(11) NOT NULL AUTO_INCREMENT,
			`pool_id` int(11) NOT NULL DEFAULT '0',
			`seller_id` int(11) NOT NULL DEFAULT '0',
			`product_id` int(11) NOT NULL DEFAULT '0',
			`customer_id` int(11) NOT NULL DEFAULT '0',
			`reservation_id` int(11) NOT NULL DEFAULT '0',
			`rating` tinyint(1) NOT NULL DEFAULT '0',
			`title` varchar(128) NOT NULL DEFAULT '',
			`text` text,
			`status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
			`date_added` datetime NOT NULL,
			`date_modified` datetime NOT NULL,
			PRIMARY KEY (`review_id`),
			UNIQUE KEY `uk_poolbuy_review_reservation` (`reservation_id`),
			KEY `idx_poolbuy_review_seller` (`seller_id`),
			KEY `idx_poolbuy_review_product` (`product_id`),
			KEY `idx_poolbuy_review_customer` (`customer_id`),
			KEY `idx_poolbuy_review_status` (`status`),
			KEY `idx_poolbuy_review_seller_status` (`seller_id`, `status`)
		)" . $suffix;

		// Fixed-window request counters for the API rate limiter. One row per
		// (endpoint, caller, window), so the table stays small and is pruned by the
		// lifecycle cron rather than growing per request.
		$schema[] = "CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "poolbuy_rate_limit` (
			`bucket` char(64) NOT NULL,
			`hits` int(11) NOT NULL DEFAULT '0',
			`window_start` int(11) NOT NULL DEFAULT '0',
			PRIMARY KEY (`bucket`),
			KEY `idx_poolbuy_rate_limit_window` (`window_start`)
		)" . $suffix;

		return $schema;
	}

	/**
	 * Get Tables
	 *
	 * @return array<int, string> unprefixed table names owned by this extension
	 */
	public function getTables(): array {
		return $this->tables;
	}
}
