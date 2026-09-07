# Verifies the PoolBuy lifecycle cron: state transitions, retroactive repricing,
# reservation-to-order conversion, idempotency, and that the HTTP trigger is
# token protected.
#
# Usage: powershell -File tools\poolbuy\verify_lifecycle.ps1

$ErrorActionPreference = 'Stop'
. "$PSScriptRoot\admin.ps1"

$base = 'http://localhost'
$failures = @()

function Assert([bool] $Condition, [string] $Message) {
    if ($Condition) { Write-Host "  PASS  $Message" -ForegroundColor Green }
    else { Write-Host "  FAIL  $Message" -ForegroundColor Red; $script:failures += $Message }
}

$token = Get-OcSqlScalar "SELECT value FROM oc_setting WHERE code='module_poolbuy' AND oc_setting.``key``='module_poolbuy_cron_token';"

Write-Host "`n[1] HTTP trigger is token protected"
# A 403 makes Invoke-WebRequest throw, so the status code is read from the
# exception rather than from a response body.
function Get-CronStatus([string] $Url) {
    try {
        $r = Invoke-WebRequest -Uri $Url -UseBasicParsing -TimeoutSec 60
        return [int]$r.StatusCode
    } catch {
        if ($_.Exception.Response) { return [int]$_.Exception.Response.StatusCode.value__ }
        return -1
    }
}

Assert ((Get-CronStatus "$base/index.php?route=extension/poolbuy/cron/poolbuy.run") -eq 403) 'request with NO token is refused with 403'
Assert ((Get-CronStatus "$base/index.php?route=extension/poolbuy/cron/poolbuy.run&token=wrong") -eq 403) 'request with a WRONG token is refused with 403'

Write-Host "`n[2] Build a pool that has met its MOQ, with buyers priced at the OLD tier"
# PB-88308 Medical Grade Vials: MOQ 400, tiers 20-199@980 / 200-399@930 / 400+@890
$poolId = Get-OcSqlInt "SELECT pool_id FROM oc_poolbuy_pool WHERE reference='PB-88308';"
Invoke-OcSql "DELETE FROM oc_poolbuy_reservation WHERE pool_id=$poolId;"
Invoke-OcSql "DELETE FROM oc_poolbuy_pool_event WHERE pool_id=$poolId;"

# Three buyers who each joined when only the 980 tier was unlocked
$b1 = Get-OcSqlInt "SELECT customer_id FROM oc_customer WHERE email='buyer@poolbuy.test';"
$b2 = Get-OcSqlInt "SELECT customer_id FROM oc_customer WHERE email='race1@poolbuy.test';"
$b3 = Get-OcSqlInt "SELECT customer_id FROM oc_customer WHERE email='race2@poolbuy.test';"
foreach ($pair in @(@($b1,150,'TX-LIFE-1'), @($b2,150,'TX-LIFE-2'), @($b3,100,'TX-LIFE-3'))) {
  $cid = $pair[0]; $qty = $pair[1]; $ref = $pair[2]
  $addr = Get-OcSqlInt "SELECT address_id FROM oc_address WHERE customer_id=$cid ORDER BY address_id LIMIT 1;"
  Invoke-OcSql "INSERT INTO oc_poolbuy_reservation (pool_id, customer_id, quantity, unit_price_locked, status, address_id, reference, date_added, date_modified) VALUES ($poolId, $cid, $qty, 980.0000, 'confirmed', $addr, '$ref', NOW(), NOW());"
}
# End date already passed, so the cron should reach -> close -> fulfil in one run
Invoke-OcSql "UPDATE oc_poolbuy_pool SET status='active', reserved_qty=400, date_end = NOW() - INTERVAL 1 HOUR WHERE pool_id=$poolId;"

$moq = Get-OcSqlInt "SELECT moq_target FROM oc_poolbuy_pool WHERE pool_id=$poolId;"
Write-Host "  pool $poolId at 400 of $moq, all three buyers locked at 980.00, end date in the past"
$ordersBefore = Get-OcSqlInt "SELECT COUNT(*) FROM oc_order;"

Write-Host "`n[3] Run the cron"
$run = Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/cron/poolbuy.run&token=$token" -UseBasicParsing -TimeoutSec 120
Write-Host "  response: $($run.Content)"
Assert ($run.Content -match '"success":true') 'cron ran successfully with a valid token'

Write-Host "`n[4] Retroactive repricing applied"
$prices = docker compose exec -T -e MYSQL_PWD=opencart mysql mysql -uroot opencart -N -B -e "SELECT DISTINCT unit_price_locked FROM oc_poolbuy_reservation WHERE pool_id=$poolId;"
$distinct = @($prices | Where-Object { $_ -match '^\d' } | ForEach-Object { $_.Trim() })
Write-Host "  distinct locked prices now: $($distinct -join ', ')"
Assert ($distinct.Count -eq 1 -and [decimal]$distinct[0] -eq 890) 'all three buyers repriced from 980.00 down to the 890.00 tier the pool unlocked'

Write-Host "`n[5] Pool reached fulfilled status"
$status = Get-OcSqlScalar "SELECT status FROM oc_poolbuy_pool WHERE pool_id=$poolId;"
Assert ($status -eq 'fulfilled') "pool status is now 'fulfilled' (got $status)"

Write-Host "`n[6] Real OpenCart orders were created"
$ordersAfter = Get-OcSqlInt "SELECT COUNT(*) FROM oc_order;"
$created = $ordersAfter - $ordersBefore
Assert ($created -eq 3) "3 orders created (got $created)"

$converted = Get-OcSqlInt "SELECT COUNT(*) FROM oc_poolbuy_reservation WHERE pool_id=$poolId AND status='converted' AND order_id > 0;"
Assert ($converted -eq 3) "all 3 commitments marked converted with an order_id (got $converted)"

Write-Host "`n[7] Order totals reconcile to the repriced figures"
# 150 x 890 = 133,500 subtotal ; GST 18% = 24,030 ; fee 2% = 2,670 ; total 160,200
$orderId = Get-OcSqlInt "SELECT order_id FROM oc_poolbuy_reservation WHERE pool_id=$poolId AND quantity=150 LIMIT 1;"
docker compose exec -T -e MYSQL_PWD=opencart mysql mysql -uroot opencart -e "SELECT o.order_id, o.total, o.currency_code, o.order_status_id FROM oc_order o WHERE o.order_id=$orderId; SELECT code, title, value FROM oc_order_total WHERE order_id=$orderId ORDER BY sort_order;"
$total = Get-OcSqlScalar "SELECT total FROM oc_order WHERE order_id=$orderId;"
Assert ([math]::Abs([decimal]$total - 160200) -lt 0.01) "order total is 160,200.00 as computed (got $total)"

$lineTotal = Get-OcSqlScalar "SELECT total FROM oc_order_product WHERE order_id=$orderId LIMIT 1;"
Assert ([math]::Abs([decimal]$lineTotal - 133500) -lt 0.01) "order line subtotal is 133,500.00 (got $lineTotal)"

$linePrice = Get-OcSqlScalar "SELECT price FROM oc_order_product WHERE order_id=$orderId LIMIT 1;"
Assert ([math]::Abs([decimal]$linePrice - 890) -lt 0.01) "order line unit price is the repriced 890.00 (got $linePrice)"

$statusId = Get-OcSqlInt "SELECT order_status_id FROM oc_order WHERE order_id=$orderId;"
Assert ($statusId -gt 0) "order has a real status so it appears in admin Sales (status_id $statusId)"

Write-Host "`n[8] IDEMPOTENCY: running the cron again changes nothing"
$run2 = Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/cron/poolbuy.run&token=$token" -UseBasicParsing -TimeoutSec 120
$ordersAfter2 = Get-OcSqlInt "SELECT COUNT(*) FROM oc_order;"
Assert ($ordersAfter2 -eq $ordersAfter) "no duplicate orders on a second run (still $ordersAfter2)"
$status2 = Get-OcSqlScalar "SELECT status FROM oc_poolbuy_pool WHERE pool_id=$poolId;"
Assert ($status2 -eq 'fulfilled') 'pool status unchanged on a second run'
$prices2 = docker compose exec -T -e MYSQL_PWD=opencart mysql mysql -uroot opencart -N -B -e "SELECT DISTINCT unit_price_locked FROM oc_poolbuy_reservation WHERE pool_id=$poolId;"
$distinct2 = @($prices2 | Where-Object { $_ -match '^\d' } | ForEach-Object { $_.Trim() })
Assert ($distinct2.Count -eq 1 -and [decimal]$distinct2[0] -eq 890) 'prices unchanged on a second run (no double repricing)'

Write-Host "`n[9] Expiry path releases commitments"
# PB-88307 Copper Conductors: 90 of 300, force its end date into the past
$expId = Get-OcSqlInt "SELECT pool_id FROM oc_poolbuy_pool WHERE reference='PB-88307';"
# Reset the fixture completely, not just the pool status. A previous run leaves the
# commitments in 'released', which would make $heldBefore 0 and let the assertion
# below pass vacuously.
Invoke-OcSql "UPDATE oc_poolbuy_reservation SET status='confirmed' WHERE pool_id=$expId AND status='released';"
Invoke-OcSql "UPDATE oc_poolbuy_pool SET status='active', date_end = NOW() - INTERVAL 1 HOUR WHERE pool_id=$expId;"
Invoke-OcSql "UPDATE oc_poolbuy_pool p SET reserved_qty=(SELECT COALESCE(SUM(quantity),0) FROM oc_poolbuy_reservation r WHERE r.pool_id=p.pool_id AND r.status IN ('pending','confirmed','converted')) WHERE p.pool_id=$expId;"
$heldBefore = Get-OcSqlInt "SELECT COUNT(*) FROM oc_poolbuy_reservation WHERE pool_id=$expId AND status='confirmed';"
Assert ($heldBefore -gt 0) "the expiry fixture starts with $heldBefore held commitments"
Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/cron/poolbuy.run&token=$token" -UseBasicParsing -TimeoutSec 120 | Out-Null
$expStatus = Get-OcSqlScalar "SELECT status FROM oc_poolbuy_pool WHERE pool_id=$expId;"
$released = Get-OcSqlInt "SELECT COUNT(*) FROM oc_poolbuy_reservation WHERE pool_id=$expId AND status='released';"
$reserved = Get-OcSqlInt "SELECT reserved_qty FROM oc_poolbuy_pool WHERE pool_id=$expId;"
Assert ($expStatus -eq 'expired') "unfilled pool expired (got $expStatus)"
Assert ($released -eq $heldBefore -and $released -gt 0) "all $heldBefore commitments released (got $released)"
Assert ($reserved -eq 0) "reserved_qty recomputed to 0 (got $reserved)"

Write-Host "`n[10] Audit trail written"
docker compose exec -T -e MYSQL_PWD=opencart mysql mysql -uroot opencart -e "SELECT type, COUNT(*) AS n FROM oc_poolbuy_pool_event WHERE pool_id IN ($poolId,$expId) GROUP BY type ORDER BY type;"
$events = Get-OcSqlInt "SELECT COUNT(*) FROM oc_poolbuy_pool_event WHERE pool_id=$poolId AND type IN ('repriced','pool_reached','pool_closed','pool_fulfilled','reservation_converted');"
Assert ($events -ge 5) "lifecycle events logged (found $events)"

Write-Host ""
if ($failures.Count -eq 0) {
    Write-Host "ALL LIFECYCLE CHECKS PASSED" -ForegroundColor Green
    exit 0
} else {
    Write-Host "$($failures.Count) CHECK(S) FAILED" -ForegroundColor Red
    exit 1
}
