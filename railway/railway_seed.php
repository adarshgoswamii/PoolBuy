<?php
/**
 * Headless PoolBuy seeder for cloud deploys (Railway).
 *
 * Mirrors tools/poolbuy/seed_*.ps1 but runs inside the container in PHP:
 *   1. registers the extension package row (oc_extension_install)
 *   2. logs into admin over localhost, installs the poolbuy module (creates tables,
 *      permissions, settings), configures settings
 *   3. seeds sellers, then pools (with tiers) + synthetic reservations
 *   4. registers the cron row
 *
 * Idempotent: safe to run repeatedly. Reads DB creds from the same env the
 * entrypoint used. Talks to the admin API at http://127.0.0.1:$PORT.
 *
 * Usage (from entrypoint): php /var/www/html/railway_seed.php
 */

$DB_HOST = getenv('MYSQLHOST') ?: (getenv('DB_HOSTNAME') ?: 'mysql');
$DB_PORT = (int)(getenv('MYSQLPORT') ?: (getenv('DB_PORT') ?: 3306));
$DB_USER = getenv('MYSQLUSER') ?: (getenv('DB_USERNAME') ?: 'root');
$DB_PASS = getenv('MYSQLPASSWORD') ?: (getenv('DB_PASSWORD') ?: 'opencart');
$DB_NAME = getenv('MYSQLDATABASE') ?: (getenv('DB_DATABASE') ?: 'railway');
$PORT    = (int)(getenv('PORT') ?: 80);
$BASE    = "http://127.0.0.1:$PORT";

function db() {
    global $DB_HOST, $DB_PORT, $DB_USER, $DB_PASS, $DB_NAME;
    static $c = null;
    if ($c === null) {
        $c = @mysqli_connect($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT);
        if (!$c) { fwrite(STDERR, "seed: DB connect failed\n"); exit(1); }
    }
    return $c;
}
function q($sql) { $r = mysqli_query(db(), $sql); if ($r === false) { fwrite(STDERR, 'seed SQL error: ' . mysqli_error(db()) . "\n"); } return $r; }
function scalar($sql) { $r = q($sql); if (!$r) return null; $row = mysqli_fetch_row($r); return $row ? $row[0] : null; }
function esc($v) { return mysqli_real_escape_string(db(), (string)$v); }

// --- Guard: only seed once ---
$hasSellerTable = scalar("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='" . esc($GLOBALS['DB_NAME']) . "' AND table_name='oc_poolbuy_seller'");
$sellerCount = $hasSellerTable ? (int)scalar("SELECT COUNT(*) FROM oc_poolbuy_seller") : 0;
if ($sellerCount >= 5) { echo "seed: PoolBuy already seeded ($sellerCount sellers)\n"; exit(0); }

// --- 1. Register extension package (idempotent) ---
q("INSERT INTO oc_extension_install (extension_id, extension_download_id, name, description, code, version, author, link, status, date_added)
   SELECT 0,0,'PoolBuy Wholesale Marketplace','B2B wholesale marketplace with collective pool buying.','poolbuy','1.0.0','PoolBuy','',1,NOW()
   FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM oc_extension_install WHERE code='poolbuy')");

// The oc_extension row is what makes OpenCart 4 resolve extension/poolbuy/* routes
// and load the extension's autoloader paths. Without it the storefront route 404s.
q("INSERT INTO oc_extension (extension, type, code)
   SELECT 'poolbuy','module','poolbuy'
   FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM oc_extension WHERE extension='poolbuy' AND code='poolbuy')");

// --- Admin login handshake ---
$cookie = tempnam(sys_get_temp_dir(), 'occk');
function http($url, $post = null, $cookie = null) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_USERAGENT => 'PoolBuy-Seeder/1.0',
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_COOKIEFILE => $cookie,
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    }
    $body = curl_exec($ch);
    curl_close($ch);
    return (string)$body;
}

$loginPage = http("$BASE/admin/index.php?route=common/login", null, $cookie);
if (!preg_match('/login_token=([0-9a-zA-Z]+)/', $loginPage, $lm)) {
    fwrite(STDERR, "seed: could not read login_token\n");
    exit(1);
}
$loginToken = $lm[1];
$loginResp = http("$BASE/admin/index.php?route=common/login.login&login_token=$loginToken", http_build_query([
    'username' => 'admin', 'password' => 'admin',
]), $cookie);
if (!preg_match('/user_token=([0-9a-f]+)/', $loginResp, $tm)) {
    fwrite(STDERR, "seed: admin login failed: " . substr($loginResp, 0, 300) . "\n");
    exit(1);
}
$token = $tm[1];
echo "seed: admin logged in\n";

function adminPost($route, $fields, $token, $cookie) {
    global $BASE;
    return http("$BASE/admin/index.php?route=$route&user_token=$token", $fields, $cookie);
}

// --- 2. Install poolbuy schema directly (deterministic; avoids HTTP install-hook
//        permission/timing flakiness) ---
$suffix = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
$schema = [];
$schema[] = "CREATE TABLE IF NOT EXISTS `oc_poolbuy_seller` (
    `seller_id` int(11) NOT NULL AUTO_INCREMENT, `customer_id` int(11) NOT NULL DEFAULT '0',
    `name` varchar(128) NOT NULL DEFAULT '', `slug` varchar(128) NOT NULL DEFAULT '',
    `gst_number` varchar(32) NOT NULL DEFAULT '', `gst_verified` tinyint(1) NOT NULL DEFAULT '0',
    `verified_seller` tinyint(1) NOT NULL DEFAULT '0', `rating` decimal(3,2) NOT NULL DEFAULT '0.00',
    `rating_count` int(11) NOT NULL DEFAULT '0', `description` text, `logo` varchar(255) NOT NULL DEFAULT '',
    `location` varchar(128) NOT NULL DEFAULT '', `images` text, `status` tinyint(1) NOT NULL DEFAULT '1',
    `date_added` datetime NOT NULL, `date_modified` datetime NOT NULL,
    PRIMARY KEY (`seller_id`), UNIQUE KEY `uk_poolbuy_seller_slug` (`slug`),
    KEY `idx_poolbuy_seller_customer` (`customer_id`), KEY `idx_poolbuy_seller_status` (`status`)
)$suffix";
$schema[] = "CREATE TABLE IF NOT EXISTS `oc_poolbuy_product_seller` (
    `product_id` int(11) NOT NULL, `seller_id` int(11) NOT NULL DEFAULT '0',
    PRIMARY KEY (`product_id`), KEY `idx_poolbuy_product_seller_seller` (`seller_id`)
)$suffix";
$schema[] = "CREATE TABLE IF NOT EXISTS `oc_poolbuy_pool` (
    `pool_id` int(11) NOT NULL AUTO_INCREMENT, `product_id` int(11) NOT NULL DEFAULT '0',
    `seller_id` int(11) NOT NULL DEFAULT '0', `title` varchar(255) NOT NULL DEFAULT '',
    `reference` varchar(32) NOT NULL DEFAULT '', `moq_target` int(11) NOT NULL DEFAULT '0',
    `reserved_qty` int(11) NOT NULL DEFAULT '0', `unit_label` varchar(32) NOT NULL DEFAULT 'Units',
    `currency_code` varchar(3) NOT NULL DEFAULT '',
    `status` enum('draft','active','reached','closed','expired','fulfilled','cancelled') NOT NULL DEFAULT 'draft',
    `date_start` datetime NOT NULL, `date_end` datetime NOT NULL,
    `retro_pricing` tinyint(1) NOT NULL DEFAULT '1', `allow_full_moq_buy` tinyint(1) NOT NULL DEFAULT '1',
    `min_qty_per_buyer` int(11) NOT NULL DEFAULT '1', `max_qty_per_buyer` int(11) NOT NULL DEFAULT '0',
    `lead_time` varchar(64) NOT NULL DEFAULT '', `shipping_terms` varchar(64) NOT NULL DEFAULT '',
    `date_added` datetime NOT NULL, `date_modified` datetime NOT NULL,
    PRIMARY KEY (`pool_id`), UNIQUE KEY `uk_poolbuy_pool_reference` (`reference`),
    KEY `idx_poolbuy_pool_product` (`product_id`), KEY `idx_poolbuy_pool_seller` (`seller_id`),
    KEY `idx_poolbuy_pool_status` (`status`), KEY `idx_poolbuy_pool_date_end` (`date_end`),
    KEY `idx_poolbuy_pool_status_end` (`status`, `date_end`)
)$suffix";
$schema[] = "CREATE TABLE IF NOT EXISTS `oc_poolbuy_pool_tier` (
    `tier_id` int(11) NOT NULL AUTO_INCREMENT, `pool_id` int(11) NOT NULL DEFAULT '0',
    `min_qty` int(11) NOT NULL DEFAULT '0', `max_qty` int(11) DEFAULT NULL,
    `price` decimal(15,4) NOT NULL DEFAULT '0.0000', `sort_order` int(3) NOT NULL DEFAULT '0',
    PRIMARY KEY (`tier_id`), KEY `idx_poolbuy_tier_pool` (`pool_id`),
    KEY `idx_poolbuy_tier_pool_min` (`pool_id`, `min_qty`)
)$suffix";
$schema[] = "CREATE TABLE IF NOT EXISTS `oc_poolbuy_reservation` (
    `reservation_id` int(11) NOT NULL AUTO_INCREMENT, `pool_id` int(11) NOT NULL DEFAULT '0',
    `customer_id` int(11) NOT NULL DEFAULT '0', `quantity` int(11) NOT NULL DEFAULT '0',
    `unit_price_locked` decimal(15,4) NOT NULL DEFAULT '0.0000',
    `status` enum('pending','confirmed','cancelled','converted','released') NOT NULL DEFAULT 'pending',
    `order_id` int(11) NOT NULL DEFAULT '0', `address_id` int(11) NOT NULL DEFAULT '0',
    `shipping_method` text, `shipping_cost` decimal(15,4) NOT NULL DEFAULT '0.0000',
    `reference` varchar(32) NOT NULL DEFAULT '', `date_added` datetime NOT NULL, `date_modified` datetime NOT NULL,
    `active_customer_id` int(11) GENERATED ALWAYS AS (CASE WHEN `status` IN ('pending','confirmed') THEN `customer_id` ELSE NULL END) STORED,
    PRIMARY KEY (`reservation_id`), UNIQUE KEY `uk_poolbuy_reservation_reference` (`reference`),
    UNIQUE KEY `uk_poolbuy_reservation_active` (`pool_id`, `active_customer_id`),
    KEY `idx_poolbuy_reservation_pool` (`pool_id`), KEY `idx_poolbuy_reservation_customer` (`customer_id`),
    KEY `idx_poolbuy_reservation_status` (`status`), KEY `idx_poolbuy_reservation_order` (`order_id`)
)$suffix";
$schema[] = "CREATE TABLE IF NOT EXISTS `oc_poolbuy_pool_event` (
    `event_id` int(11) NOT NULL AUTO_INCREMENT, `pool_id` int(11) NOT NULL DEFAULT '0',
    `customer_id` int(11) NOT NULL DEFAULT '0', `type` varchar(32) NOT NULL DEFAULT '', `payload` text,
    `date_added` datetime NOT NULL, PRIMARY KEY (`event_id`),
    KEY `idx_poolbuy_event_pool` (`pool_id`), KEY `idx_poolbuy_event_date` (`date_added`),
    KEY `idx_poolbuy_event_pool_type` (`pool_id`, `type`)
)$suffix";
foreach ($schema as $ddl) { q($ddl); }
echo "seed: schema created (" . (int)scalar("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='" . esc($GLOBALS['DB_NAME']) . "' AND table_name LIKE 'oc_poolbuy%'") . " tables)\n";

// Grant the admin user group access+modify on every poolbuy admin route so the
// save endpoints below are authorised.
$routes = ['extension/poolbuy/module/poolbuy','extension/poolbuy/poolbuy/seller','extension/poolbuy/poolbuy/pool','extension/poolbuy/poolbuy/reservation'];
$permRow = scalar("SELECT permission FROM oc_user_group WHERE user_group_id=1");
$perm = json_decode((string)$permRow, true);
if (is_array($perm)) {
    foreach (['access','modify'] as $k) {
        if (!isset($perm[$k]) || !is_array($perm[$k])) $perm[$k] = [];
        foreach ($routes as $rt) { if (!in_array($rt, $perm[$k], true)) $perm[$k][] = $rt; }
    }
    q("UPDATE oc_user_group SET permission='" . esc(json_encode($perm)) . "' WHERE user_group_id=1");
    echo "seed: admin permissions granted\n";
}

// Settings row (module_poolbuy group)
$cronToken = 'poolbuy-cloud-cron-token';
$settings = [
    'module_poolbuy_status' => '1', 'module_poolbuy_currency_code' => 'INR',
    'module_poolbuy_currency_symbol' => '₹', 'module_poolbuy_gst_rate' => '18',
    'module_poolbuy_platform_fee' => '2', 'module_poolbuy_pool_duration' => '7',
    'module_poolbuy_retro_pricing' => '1', 'module_poolbuy_cron_token' => $cronToken,
];
q("DELETE FROM oc_setting WHERE code='module_poolbuy'");
foreach ($settings as $key => $val) {
    q("INSERT INTO oc_setting (store_id, code, `key`, value, serialized) VALUES (0, 'module_poolbuy', '" . esc($key) . "', '" . esc($val) . "', 0)");
}
echo "seed: settings saved\n";

// --- 4. Sellers ---
$sellers = [
    ['Titan Pumps Global Mfg.','29ABCDE1234F1Z5','4.8','240','Main Warehouse, NJ','Industrial submersible and drainage pump manufacturer. ISO 9001 certified.'],
    ['AquaGlobal Industrial Ltd.','27AAAAA0000A1Z5','4.5','88','Bangalore, KA','Commercial filtration systems for industrial and municipal use.'],
    ['ChemBulk Logistics','24BBBBB1111B1Z5','4.6','132','Surat, GJ','Bulk industrial chemicals and water treatment compounds.'],
    ['BrightTech Solutions','06CCCCC2222C1Z5','4.3','54','Gurugram, HR','Smart LED lighting and IoT control systems.'],
    ['VoltaGlobal Industrial','33DDDDD3333D1Z5','4.7','196','Chennai, TN','Enterprise grade lithium-ion cells and battery packs.'],
];
foreach ($sellers as $s) {
    $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($s[0])), '-');
    if ((int)scalar("SELECT COUNT(*) FROM oc_poolbuy_seller WHERE slug='" . esc($slug) . "'") > 0) continue;
    adminPost('extension/poolbuy/poolbuy/seller.save', http_build_query([
        'seller_id' => 0, 'name' => $s[0], 'slug' => $slug, 'gst_number' => $s[1],
        'gst_verified' => 1, 'verified_seller' => 1, 'rating' => $s[2], 'rating_count' => $s[3],
        'location' => $s[4], 'description' => $s[5], 'status' => 1,
    ]), $token, $cookie);
}
echo "seed: " . (int)scalar("SELECT COUNT(*) FROM oc_poolbuy_seller") . " sellers\n";

// --- Product ids from the demo catalogue ---
$productIds = [];
$r = q("SELECT product_id FROM oc_product WHERE status=1 AND date_available <= NOW() ORDER BY product_id LIMIT 12");
if ($r) while ($row = mysqli_fetch_row($r)) $productIds[] = (int)$row[0];
if (count($productIds) < 6) { echo "seed: not enough products (" . count($productIds) . "), skipping pools\n"; exit(0); }

// --- 5. Pools + tiers + reservations ---
$pools = [
    ['PB-99201','Titan-X Industrial Grade 5HP Submersible Drainage Pump',1,200,'Units',150,3,'15-20 Days','FOB Shanghai',[[10,49,1550],[50,149,1380],[150,'',1250]]],
    ['PB-99210','Industrial Grade Filtration Units - Q4 Procurement',2,500,'Units',410,1,'10-14 Days','FOB Nhava Sheva',[[10,49,1250],[50,99,1100],[100,'',950]]],
    ['PB-88301','EliteFlow Filtration Pro-7',2,200,'Units',150,5,'12-18 Days','FOB Mundra',[[10,99,165],[100,199,152],[200,'',145]]],
    ['PB-88302','CrystalPure Chlorine Tablets (25kg)',3,500,'Buckets',420,2,'7-10 Days','Ex Works Surat',[[25,199,94],[200,499,88],[500,'',82]]],
    ['PB-88303','LumiPool RGB Smart LED Kits',4,100,'Kits',12,12,'20-25 Days','FOB Shenzhen',[[5,49,240],[50,99,225],[100,'',210]]],
    ['PB-88304','Li-Ion Enterprise Cells (Batch 42)',5,1000,'Units',780,1,'18-22 Days','FOB Chennai',[[50,499,64],[500,999,58],[1000,'',52]]],
    ['PB-88305','Brushed Al-Chassis (Model X-5)',1,500,'Units',420,3,'14-18 Days','FOB Mumbai',[[25,249,310],[250,499,288],[500,'',265]]],
    ['PB-88306','Ceramic Mosaic XL',3,200,'Boxes',195,2,'9-12 Days','Ex Works Morbi',[[10,99,1180],[100,199,1090],[200,'',990]]],
    ['PB-88307','Copper Conductors (200m)',5,300,'Reels',90,9,'11-15 Days','FOB Chennai',[[10,99,1450],[100,199,1340],[200,'',1240]]],
    ['PB-88308','Medical Grade Vials (Case)',4,400,'Cases',0,15,'16-20 Days','FOB Gurugram',[[20,199,980],[200,399,930],[400,'',890]]],
];
$i = 0;
foreach ($pools as $p) {
    [$ref,$title,$seller,$moq,$unit,$fill,$days,$lead,$terms,$tiers] = $p;
    if ((int)scalar("SELECT COUNT(*) FROM oc_poolbuy_pool WHERE reference='" . esc($ref) . "'") > 0) { $i++; continue; }
    $productId = $productIds[$i % count($productIds)];
    $i++;
    $start = date('Y-m-d H:i:s', time() - 2 * 86400);
    $end   = date('Y-m-d H:i:s', time() + $days * 86400);
    $fields = [
        'pool_id' => 0, 'status' => 'active', 'product_id' => $productId, 'seller_id' => $seller,
        'title' => $title, 'reference' => $ref, 'moq_target' => $moq, 'unit_label' => $unit,
        'currency_code' => 'INR', 'date_start' => $start, 'date_end' => $end,
        'min_qty_per_buyer' => 10, 'max_qty_per_buyer' => 0, 'retro_pricing' => 1, 'allow_full_moq_buy' => 1,
        'lead_time' => $lead, 'shipping_terms' => $terms,
    ];
    foreach ($tiers as $t => $tier) {
        $fields["pool_tier[$t][min_qty]"] = $tier[0];
        $fields["pool_tier[$t][max_qty]"] = $tier[1];
        $fields["pool_tier[$t][price]"] = $tier[2];
    }
    adminPost('extension/poolbuy/poolbuy/pool.save', http_build_query($fields), $token, $cookie);

    if ($fill > 0) {
        $poolId = (int)scalar("SELECT pool_id FROM oc_poolbuy_pool WHERE reference='" . esc($ref) . "'");
        if ($poolId) {
            $half = (int)floor($fill * 0.5); $third = (int)floor($fill * 0.3); $rest = $fill - $half - $third;
            $n = 0;
            foreach ([$half, $third, $rest] as $qty) {
                if ($qty <= 0) continue;
                $email = "poolbuyer$n@example.com";
                $buyerId = (int)scalar("SELECT customer_id FROM oc_customer WHERE email='" . esc($email) . "'");
                if ($buyerId === 0) {
                    q("INSERT INTO oc_customer (customer_group_id, store_id, language_id, firstname, lastname, email, password, telephone, custom_field, newsletter, ip, status, safe, commenter, token, code, date_added) VALUES (1,0,1,'Pool','Buyer $n','" . esc($email) . "','x','','',0,'127.0.0.1',1,0,0,'','',NOW())");
                    $buyerId = (int)scalar("SELECT customer_id FROM oc_customer WHERE email='" . esc($email) . "'");
                }
                $txref = "TX-" . substr($ref, 3) . "-$n";
                q("INSERT INTO oc_poolbuy_reservation (pool_id, customer_id, quantity, unit_price_locked, status, reference, date_added, date_modified) VALUES ($poolId, $buyerId, $qty, 0, 'confirmed', '" . esc($txref) . "', NOW() - INTERVAL " . ($n*5) . " HOUR, NOW())");
                $n++;
            }
            q("UPDATE oc_poolbuy_pool SET reserved_qty = (SELECT COALESCE(SUM(quantity),0) FROM oc_poolbuy_reservation WHERE pool_id=$poolId AND status IN ('pending','confirmed','converted')) WHERE pool_id=$poolId");
        }
    }
}
echo "seed: " . (int)scalar("SELECT COUNT(*) FROM oc_poolbuy_pool") . " pools\n";

// --- 6. Cron row ---
q("INSERT INTO oc_cron (code, description, cycle, action, status, date_added, date_modified)
   SELECT 'poolbuy','PoolBuy lifecycle','hour','extension/poolbuy/cron/poolbuy',1,NOW(),'2020-01-01 00:00:00'
   FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM oc_cron WHERE code='poolbuy')");

// --- 7. Demo buyer + seller storefront accounts ---
// (Kept minimal; passwords set via OpenCart's password hashing is complex headless,
//  so demo buyer login is optional — the marketplace itself is public.)

echo "seed: PoolBuy seed complete\n";
