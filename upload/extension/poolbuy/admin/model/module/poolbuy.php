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
		'poolbuy_pool_event'
	];

	/**
	 * Install
	 *
	 * Creates every PoolBuy table. Safe to re-run: each statement uses
	 * IF NOT EXISTS so a partially installed schema is completed rather
	 * than erroring.
	 *
	 * @return void
	 */
	public function install(): void {
		foreach ($this->getSchema() as $sql) {
			$this->db->query($sql);
		}
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
