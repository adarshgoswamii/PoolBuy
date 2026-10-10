# Provisions everything a QA tester needs beyond the standard demo seed, and
# prints a handover summary.
#
# The standard seed (seed_all.ps1) produces ten ACTIVE pools at varying fill
# levels. Testing the full commercial cycle also needs pools that have already
# reached MOQ, expired, and been fulfilled. Those states are created here on
# DEDICATED pools (PB-QA-*) so they never collide with the fixtures the verify
# suites manipulate.
#
# Idempotent: existing QA pools are driven back to their intended state rather
# than duplicated.
#
# Usage: powershell -File tools\poolbuy\seed_qa.ps1

$ErrorActionPreference = 'Stop'
. "$PSScriptRoot\admin.ps1"

$base = 'http://localhost'
$tick = [char]96

function Step([string] $Message) { Write-Host "`n=== $Message ===" -ForegroundColor Cyan }

$admin = Connect-OcAdmin

Step 'Confirming PoolBuy is enabled and payable'
function Get-Setting([string] $Key) {
    return Get-OcSqlScalar "SELECT value FROM oc_setting WHERE code='module_poolbuy' AND ${tick}key${tick}='$Key';"
}
function Set-Setting([string] $Key, [string] $Value) {
    Invoke-OcSql "DELETE FROM oc_setting WHERE store_id=0 AND code='module_poolbuy' AND ${tick}key${tick}='$Key';"
    Invoke-OcSql "INSERT INTO oc_setting (store_id, code, ${tick}key${tick}, value, serialized) VALUES (0, 'module_poolbuy', '$Key', '$Value', 0);"
}

if ((Get-Setting 'module_poolbuy_status') -ne '1') { Set-Setting 'module_poolbuy_status' '1' }
if ((Get-Setting 'module_poolbuy_gateway') -ne 'mock') { Set-Setting 'module_poolbuy_gateway' 'mock' }
if ([string]::IsNullOrWhiteSpace((Get-Setting 'module_poolbuy_razorpay_webhook_secret'))) {
    Set-Setting 'module_poolbuy_razorpay_webhook_secret' 'whsec_poolbuy_dev'
}

Write-Host "  module_poolbuy_status  = $(Get-Setting 'module_poolbuy_status')"
Write-Host "  gateway                = $(Get-Setting 'module_poolbuy_gateway')  (no external credentials required)"
Write-Host "  webhook secret         = set"
Write-Host "  cron rows              = $(Get-OcSqlInt "SELECT COUNT(*) FROM oc_cron WHERE code='poolbuy';")"

# ---------------------------------------------------------------- QA pools
# Each entry is created ACTIVE, then driven into its target state below.
$qaPools = @(
    @{ ref='PB-QA-NEARMOQ';   title='QA - Near MOQ (1 unit short)';        moq=100; fill=99;  days=2;  target='active' }
    @{ ref='PB-QA-REACHED';   title='QA - MOQ reached, awaiting close';    moq=100; fill=100; days=2;  target='reached' }
    @{ ref='PB-QA-EXPIRED';   title='QA - Expired below MOQ';              moq=100; fill=30;  days=-1; target='expired' }
    @{ ref='PB-QA-FULFILLED'; title='QA - Fulfilled with paid orders';     moq=100; fill=100; days=-1; target='fulfilled' }
)

Step 'Creating QA pools'
$productIds = @(docker compose exec -T -e MYSQL_PWD=opencart mysql mysql -uroot opencart -N -B -e `
    "SELECT p.product_id FROM oc_product p WHERE p.status=1 AND p.date_available <= NOW() ORDER BY p.product_id LIMIT 12;" |
    Where-Object { $_ -match '^\d+$' } | ForEach-Object { [int]$_.Trim() })

if ($productIds.Count -eq 0) { throw 'No enabled products found to attach QA pools to.' }

# Every QA pool belongs to seller 1, which has a real storefront login, so the
# seller portal can be exercised against them.
$sellerId = 1
$i = 0

foreach ($pool in $qaPools) {
    $existing = Get-OcSqlInt "SELECT COUNT(*) FROM oc_poolbuy_pool WHERE reference='$($pool.ref)';"
    $productId = $productIds[$i % $productIds.Count]
    $i++

    if ($existing -eq 0) {
        $start = (Get-Date).AddDays(-3).ToString('yyyy-MM-dd HH:mm:ss')
        $end   = (Get-Date).AddDays([int]$pool.days).ToString('yyyy-MM-dd HH:mm:ss')

        $body = "pool_id=0&status=active" +
            "&product_id=$productId&seller_id=$sellerId" +
            "&title=" + [uri]::EscapeDataString($pool.title) +
            "&reference=$($pool.ref)" +
            "&moq_target=$($pool.moq)&unit_label=Units&currency_code=INR" +
            "&date_start=" + [uri]::EscapeDataString($start) +
            "&date_end=" + [uri]::EscapeDataString($end) +
            "&min_qty_per_buyer=5&max_qty_per_buyer=0&retro_pricing=1&allow_full_moq_buy=1" +
            "&lead_time=" + [uri]::EscapeDataString('10-14 Days') +
            "&shipping_terms=" + [uri]::EscapeDataString('FOB QA') +
            "&pool_tier%5B0%5D%5Bmin_qty%5D=5&pool_tier%5B0%5D%5Bmax_qty%5D=49&pool_tier%5B0%5D%5Bprice%5D=500" +
            "&pool_tier%5B1%5D%5Bmin_qty%5D=50&pool_tier%5B1%5D%5Bmax_qty%5D=99&pool_tier%5B1%5D%5Bprice%5D=450" +
            "&pool_tier%5B2%5D%5Bmin_qty%5D=100&pool_tier%5B2%5D%5Bmax_qty%5D=&pool_tier%5B2%5D%5Bprice%5D=400"

        $uri = "$($admin.BaseUrl)/admin/index.php?route=extension/poolbuy/poolbuy/pool.save&user_token=$($admin.UserToken)"
        $response = Invoke-WebRequest -Uri $uri -Method POST -Body $body -ContentType 'application/x-www-form-urlencoded' -WebSession $admin.Session -UseBasicParsing

        if ($response.Content -notmatch '"success"') {
            Write-Host "  FAILED  $($pool.ref): $($response.Content)" -ForegroundColor Red
            exit 1
        }

        Write-Host "  created $($pool.ref)" -ForegroundColor Green
    } else {
        Write-Host "  reusing $($pool.ref)" -ForegroundColor DarkGray
    }

    $poolId = Get-OcSqlInt "SELECT pool_id FROM oc_poolbuy_pool WHERE reference='$($pool.ref)';"

    # Reset to a clean, active baseline so the script is repeatable.
    Invoke-OcSql "DELETE pm FROM oc_poolbuy_payment pm JOIN oc_poolbuy_reservation r ON pm.reservation_id=r.reservation_id WHERE r.pool_id=$poolId;"
    Invoke-OcSql "DELETE FROM oc_poolbuy_reservation WHERE pool_id=$poolId;"
    Invoke-OcSql "DELETE FROM oc_poolbuy_settlement WHERE pool_id=$poolId;"
    Invoke-OcSql "DELETE FROM oc_poolbuy_pool_event WHERE pool_id=$poolId;"

    $end = (Get-Date).AddDays([int]$pool.days).ToString('yyyy-MM-dd HH:mm:ss')
    Invoke-OcSql "UPDATE oc_poolbuy_pool SET status='active', date_end='$end' WHERE pool_id=$poolId;"

    # Two buyers share the fill so the seller's commitment list is not trivial.
    if ([int]$pool.fill -gt 0) {
        $b1 = Get-OcSqlInt "SELECT customer_id FROM oc_customer WHERE email='buyer@poolbuy.test';"
        $b2 = Get-OcSqlInt "SELECT customer_id FROM oc_customer WHERE email='demo@poolbuy.test';"
        $q1 = [math]::Ceiling([int]$pool.fill / 2)
        $q2 = [int]$pool.fill - $q1

        $a1 = Get-OcSqlInt "SELECT address_id FROM oc_address WHERE customer_id=$b1 ORDER BY address_id LIMIT 1;"
        Invoke-OcSql "INSERT INTO oc_poolbuy_reservation (pool_id, customer_id, quantity, unit_price_locked, status, address_id, reference, date_added, date_modified) VALUES ($poolId, $b1, $q1, 500.0000, 'confirmed', $a1, 'QA-$($pool.ref)-1', NOW(), NOW());"

        if ($q2 -gt 0 -and $b2 -gt 0) {
            $a2 = Get-OcSqlInt "SELECT address_id FROM oc_address WHERE customer_id=$b2 ORDER BY address_id LIMIT 1;"
            Invoke-OcSql "INSERT INTO oc_poolbuy_reservation (pool_id, customer_id, quantity, unit_price_locked, status, address_id, reference, date_added, date_modified) VALUES ($poolId, $b2, $q2, 500.0000, 'confirmed', $a2, 'QA-$($pool.ref)-2', NOW(), NOW());"
        }
    }

    Invoke-OcSql "UPDATE oc_poolbuy_pool p SET reserved_qty=(SELECT COALESCE(SUM(quantity),0) FROM oc_poolbuy_reservation r WHERE r.pool_id=p.pool_id AND r.status IN ('pending','confirmed','converted')) WHERE p.pool_id=$poolId;"
}

Step 'Driving the QA pools into their target states'
# The lifecycle itself performs every transition, so these states are reached the
# same way production would reach them rather than by writing statuses by hand.
$cronToken = Get-Setting 'module_poolbuy_cron_token'
if ([string]::IsNullOrWhiteSpace($cronToken)) { throw 'No cron token configured; run seed_all.ps1 first.' }

Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/cron/poolbuy.run&token=$cronToken" -UseBasicParsing -TimeoutSec 180 | Out-Null

# The fulfilled pool's orders are then paid through the mock gateway so the
# settlement figures a tester sees are real.
$fulfilledId = Get-OcSqlInt "SELECT pool_id FROM oc_poolbuy_pool WHERE reference='PB-QA-FULFILLED';"
$orders = @(docker compose exec -T -e MYSQL_PWD=opencart mysql mysql -uroot opencart -N -B -e `
    "SELECT order_id FROM oc_poolbuy_reservation WHERE pool_id=$fulfilledId AND order_id > 0;" |
    Where-Object { $_ -match '^\d+$' } | ForEach-Object { [int]$_.Trim() })

foreach ($orderId in $orders) {
    Invoke-OcSql "UPDATE oc_poolbuy_payment SET status='paid', date_modified=NOW() WHERE order_id=$orderId AND status='pending';"
}
Write-Host "  marked $($orders.Count) QA order payment(s) paid"

Step 'QA pool state spread'
docker compose exec -T -e MYSQL_PWD=opencart mysql mysql -uroot opencart -e `
    "SELECT reference, status, reserved_qty, moq_target, DATE_FORMAT(date_end,'%Y-%m-%d %H:%i') AS ends FROM oc_poolbuy_pool ORDER BY (reference LIKE 'PB-QA%') DESC, reference;"

Step 'Handover summary'
Write-Host @"

  TEST CREDENTIALS
    Admin       $base/admin/                    admin / admin
    Buyer       buyer@poolbuy.test              PoolBuy123!
    Buyer 2     demo@poolbuy.test               PoolBuy123!
    Seller A    sellera@poolbuy.test            PoolBuy123!   (Titan Pumps, owns every PB-QA pool)
    Seller B    sellerb@poolbuy.test            PoolBuy123!   (ChemBulk)

  KEY SURFACES
    Landing         $base/
    Marketplace     $base/index.php?route=extension/poolbuy/pool
    Buyer dashboard $base/index.php?route=extension/poolbuy/account
    Seller portal   $base/index.php?route=extension/poolbuy/seller
    Admin pools     $base/admin/index.php?route=extension/poolbuy/poolbuy/pool

  PAYMENTS
    Gateway is 'mock', so no Stripe/Razorpay/PayPal account is needed.
    A buyer pays from the dashboard via "Pay now"; the mock gateway settles
    immediately and the order advances to a paid status.

  POOL STATES TO EXERCISE
    PB-QA-NEARMOQ    active, 99/100    - joining the last unit should reach MOQ
    PB-QA-REACHED    reached           - locked, repriced, no longer joinable
    PB-QA-EXPIRED    expired           - commitments released, nobody charged
    PB-QA-FULFILLED  fulfilled         - real orders raised, payments paid, settlement written

  Run the lifecycle by hand at any time:
    $base/index.php?route=extension/poolbuy/cron/poolbuy.run&token=$cronToken
"@

Write-Host "QA SEED COMPLETE" -ForegroundColor Green
