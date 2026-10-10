# Verifies pool freight: the estimate a buyer sees while joining, and the
# allocation actually written when the pool closes.
#
# The property that matters is EXACTNESS. Freight is quoted once for the pool and
# divided between participants, so the shares must sum to the quoted figure to
# the paisa - anything else either overcharges buyers or leaves the platform
# short. Naive per-buyer rounding does not achieve this, which is why the
# allocator uses the largest-remainder method.
#
# Requires: tools\poolbuy\seed_all.ps1
#
# Usage: powershell -File tools\poolbuy\verify_freight.ps1

$ErrorActionPreference = 'Stop'
. "$PSScriptRoot\admin.ps1"

$base     = 'http://localhost'
$email    = 'buyer@poolbuy.test'
$password = 'PoolBuy123!'

$failures = @()

function Assert([bool] $Condition, [string] $Message) {
    if ($Condition) {
        Write-Host "  PASS  $Message" -ForegroundColor Green
    } else {
        Write-Host "  FAIL  $Message" -ForegroundColor Red
        $script:failures += $Message
    }
}

function Connect-Buyer {
    $session = New-Object Microsoft.PowerShell.Commands.WebRequestSession

    $page = Invoke-WebRequest -Uri "$base/index.php?route=account/login" -WebSession $session -UseBasicParsing
    if ($page.Content -notmatch 'login_token=([0-9a-zA-Z]+)') {
        throw 'Could not read login_token from the login form.'
    }
    $token = $Matches[1]

    $response = Invoke-WebRequest -Uri "$base/index.php?route=account/login.login&login_token=$token" `
        -Method POST -Body @{ email = $email; password = $password } -WebSession $session -UseBasicParsing

    if ($response.Content -notmatch 'customer_token') {
        throw "Buyer login failed: $($response.Content)"
    }

    return $session
}

function Get-CronToken {
    # `key` is a MySQL reserved word; $OcTick from admin.ps1 supplies the backtick
    # that cannot be written literally inside a double-quoted PowerShell string.
    return (Get-OcSqlScalar "SELECT value FROM oc_setting WHERE code='module_poolbuy' AND ${OcTick}key${OcTick}='module_poolbuy_cron_token' LIMIT 1;")
}

function Invoke-Cron {
    $token = Get-CronToken
    return (Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/cron/poolbuy.run&token=$token" -UseBasicParsing -TimeoutSec 180).Content
}

# ---------------------------------------------------------------------------
# [1] The buyer-facing estimate
# ---------------------------------------------------------------------------
Write-Host "`n[1] Freight estimate in the join flow"

$poolId = Get-OcSqlInt "SELECT pool_id FROM oc_poolbuy_pool WHERE reference='PB-88303';"
$customerId = Get-OcSqlInt "SELECT customer_id FROM oc_customer WHERE email='$email';"

# Clean slate, and give this pool a known freight quote.
Invoke-OcSql "DELETE FROM oc_poolbuy_reservation WHERE pool_id=$poolId AND customer_id=$customerId;"
Invoke-OcSql "UPDATE oc_poolbuy_pool SET reserved_qty = (SELECT COALESCE(SUM(quantity),0) FROM oc_poolbuy_reservation WHERE pool_id=$poolId AND status IN ('pending','confirmed','converted')) WHERE pool_id=$poolId;"
Invoke-OcSql "UPDATE oc_poolbuy_pool SET freight_total='12000.0000' WHERE pool_id=$poolId;"

$reserved = Get-OcSqlInt "SELECT reserved_qty FROM oc_poolbuy_pool WHERE pool_id=$poolId;"
$session = Connect-Buyer
$addressId = Get-OcSqlInt "SELECT address_id FROM oc_address WHERE customer_id=$customerId LIMIT 1;"

$step3 = Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/join&pool_id=$poolId" `
    -Method POST -Body "step=2&quantity=20" -ContentType 'application/x-www-form-urlencoded' `
    -WebSession $session -UseBasicParsing

Assert ($step3.Content -match 'Estimated freight for your 20 units') 'the join flow shows an estimated freight figure'
Assert ($step3.Content -match 'per unit at the pool') 'the per-unit basis is shown'
Assert ($step3.Content -match 'Freight quoted for the whole pool') 'the pool-level freight quote is disclosed'
Assert ($step3.Content -match 'An estimate, not a charge') 'the figure is labelled an estimate, not a charge'
Assert ($step3.Content -match 'Freight is quoted when the pool closes') 'the quoted-at-close caveat is still present'

# 12,000 over (reserved + 20) units. The estimate must fall as the pool fills,
# which is the whole point of sharing one freight charge.
$projected = $reserved + 20
$expectedPerUnit = [math]::Round(12000 / $projected, 2)
Write-Host "      (reserved $reserved + 20 = $projected units -> about $expectedPerUnit per unit)"

$step3Bigger = Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/join&pool_id=$poolId" `
    -Method POST -Body "step=2&quantity=60" -ContentType 'application/x-www-form-urlencoded' `
    -WebSession $session -UseBasicParsing

# The language string reads "<amount> per unit at the pool's current volume", so
# the figure sits BEFORE the phrase.
if ($step3.Content -match '([\d,]+\.\d{2})\s*per unit at the pool') { $small = [decimal](($Matches[1]) -replace ',','') } else { $small = 0 }
if ($step3Bigger.Content -match '([\d,]+\.\d{2})\s*per unit at the pool') { $big = [decimal](($Matches[1]) -replace ',','') } else { $big = 0 }

# Committing more units spreads the same charge further, so the per-unit share drops.
Assert ($small -gt 0 -and $big -gt 0 -and $big -lt $small) "per-unit freight falls as more units join ($small -> $big)"

# ---------------------------------------------------------------------------
# [2] A pool with no freight says nothing misleading
# ---------------------------------------------------------------------------
Write-Host "`n[2] A pool with no freight quote"

Invoke-OcSql "UPDATE oc_poolbuy_pool SET freight_total='0.0000' WHERE pool_id=$poolId;"

$noFreight = Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/join&pool_id=$poolId" `
    -Method POST -Body "step=2&quantity=20" -ContentType 'application/x-www-form-urlencoded' `
    -WebSession $session -UseBasicParsing

Assert (-not ($noFreight.Content -match 'Estimated freight for your')) 'no freight estimate is invented when none is quoted'
Assert ($noFreight.Content -match 'Freight is quoted when the pool closes') 'the honest caveat still shows'

Invoke-OcSql "DELETE FROM oc_poolbuy_reservation WHERE pool_id=$poolId AND customer_id=$customerId;"
Invoke-OcSql "UPDATE oc_poolbuy_pool SET reserved_qty = (SELECT COALESCE(SUM(quantity),0) FROM oc_poolbuy_reservation WHERE pool_id=$poolId AND status IN ('pending','confirmed','converted')) WHERE pool_id=$poolId;"

# ---------------------------------------------------------------------------
# [3] Allocation at close sums EXACTLY to the quoted total
# ---------------------------------------------------------------------------
Write-Host "`n[3] Allocation at pool close"

# Build a dedicated pool so the assertion is about freight only. Three unequal
# commitments over a total that does not divide evenly by any of them.
$sellerId = Get-OcSqlInt "SELECT seller_id FROM oc_poolbuy_seller ORDER BY seller_id LIMIT 1;"
$productId = Get-OcSqlInt "SELECT product_id FROM oc_poolbuy_product_seller WHERE seller_id=$sellerId LIMIT 1;"
if ($productId -le 0) { $productId = Get-OcSqlInt "SELECT product_id FROM oc_product ORDER BY product_id LIMIT 1;" }

Invoke-OcSql "DELETE FROM oc_poolbuy_reservation WHERE pool_id IN (SELECT pool_id FROM oc_poolbuy_pool WHERE reference='PB-FREIGHT-1');"
Invoke-OcSql "DELETE FROM oc_poolbuy_pool_tier WHERE pool_id IN (SELECT pool_id FROM oc_poolbuy_pool WHERE reference='PB-FREIGHT-1');"
Invoke-OcSql "DELETE FROM oc_poolbuy_pool_event WHERE pool_id IN (SELECT pool_id FROM oc_poolbuy_pool WHERE reference='PB-FREIGHT-1');"
Invoke-OcSql "DELETE FROM oc_poolbuy_settlement WHERE pool_id IN (SELECT pool_id FROM oc_poolbuy_pool WHERE reference='PB-FREIGHT-1');"
Invoke-OcSql "DELETE FROM oc_poolbuy_pool WHERE reference='PB-FREIGHT-1';"

# 100.00 across 3 buyers is deliberately indivisible.
Invoke-OcSql @"
INSERT INTO oc_poolbuy_pool
(product_id, seller_id, title, reference, moq_target, reserved_qty, unit_label, currency_code, status,
 date_start, date_end, retro_pricing, allow_full_moq_buy, min_qty_per_buyer, max_qty_per_buyer,
 lead_time, shipping_terms, freight_total, date_added, date_modified)
VALUES
($productId, $sellerId, 'Freight allocation pool', 'PB-FREIGHT-1', 30, 30, 'Units', 'INR', 'closed',
 DATE_SUB(NOW(), INTERVAL 5 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY), 1, 1, 1, 0,
 '10 Days', 'FOB Test', '100.0000', NOW(), NOW());
"@

$fPool = Get-OcSqlInt "SELECT pool_id FROM oc_poolbuy_pool WHERE reference='PB-FREIGHT-1';"
Invoke-OcSql "INSERT INTO oc_poolbuy_pool_tier (pool_id, min_qty, max_qty, price, sort_order) VALUES ($fPool, 1, NULL, '500.0000', 1);"

# Three equal commitments of 10 - the hardest case for rounding.
$buyers = Get-OcSqlScalar "SELECT GROUP_CONCAT(customer_id) FROM (SELECT customer_id FROM oc_customer ORDER BY customer_id LIMIT 3) x;"
$buyerList = $buyers -split ','

$i = 0
foreach ($b in $buyerList) {
    $i++
    $addr = Get-OcSqlInt "SELECT COALESCE((SELECT address_id FROM oc_address WHERE customer_id=$b LIMIT 1), 0);"
    Invoke-OcSql "INSERT INTO oc_poolbuy_reservation (pool_id, customer_id, quantity, unit_price_locked, status, order_id, address_id, shipping_cost, reference, date_added, date_modified) VALUES ($fPool, $b, 10, '500.0000', 'confirmed', 0, $addr, '0.0000', 'TX-FR-$i', NOW(), NOW());"
}

Write-Host "      pool PB-FREIGHT-1: freight 100.00 over three commitments of 10 units"

$cron1 = Invoke-Cron
Assert ($cron1 -match 'PB-FREIGHT-1') 'the cron fulfilled the freight pool'

$sum = Get-OcSqlScalar "SELECT COALESCE(SUM(shipping_cost),0) FROM oc_poolbuy_reservation WHERE pool_id=$fPool;"
Assert ([decimal]$sum -eq [decimal]'100.0000') "allocated shares sum EXACTLY to the 100.00 quoted (got $sum)"

$shares = Get-OcSqlScalar "SELECT GROUP_CONCAT(shipping_cost ORDER BY shipping_cost) FROM oc_poolbuy_reservation WHERE pool_id=$fPool;"
Write-Host "      shares: $shares"
Assert ($shares -eq '33.3333,33.3333,33.3334') "the indivisible remainder went to exactly one buyer ($shares)"

$negative = Get-OcSqlInt "SELECT COUNT(*) FROM oc_poolbuy_reservation WHERE pool_id=$fPool AND shipping_cost < 0;"
Assert ($negative -eq 0) 'no buyer received a negative freight share'

# ---------------------------------------------------------------------------
# [4] Every order carries its buyer's share
# ---------------------------------------------------------------------------
Write-Host "`n[4] Freight reaches the orders"

$freightLines = Get-OcSqlInt "SELECT COUNT(*) FROM oc_order_total WHERE code='shipping' AND order_id IN (SELECT order_id FROM oc_poolbuy_reservation WHERE pool_id=$fPool AND order_id>0);"
Assert ($freightLines -eq 3) "all three orders carry a freight line (found $freightLines)"

$orderFreight = Get-OcSqlScalar "SELECT COALESCE(SUM(value),0) FROM oc_order_total WHERE code='shipping' AND order_id IN (SELECT order_id FROM oc_poolbuy_reservation WHERE pool_id=$fPool AND order_id>0);"
Assert ([decimal]$orderFreight -eq [decimal]'100.0000') "freight on the orders also sums to exactly 100.00 (got $orderFreight)"

# The order total must equal the sum of its own lines, freight included.
$mismatch = Get-OcSqlInt @"
SELECT COUNT(*) FROM (
  SELECT o.order_id,
         ROUND(o.total, 4) AS declared,
         ROUND(SUM(CASE WHEN ot.code <> 'total' THEN ot.value ELSE 0 END), 4) AS parts
  FROM oc_order o
  JOIN oc_order_total ot ON ot.order_id = o.order_id
  WHERE o.order_id IN (SELECT order_id FROM oc_poolbuy_reservation WHERE pool_id=$fPool AND order_id>0)
  GROUP BY o.order_id
) x WHERE declared <> parts;
"@
Assert ($mismatch -eq 0) 'every order total equals the sum of its lines, freight included'

# ---------------------------------------------------------------------------
# [5] Re-running the cron does not double-charge
# ---------------------------------------------------------------------------
Write-Host "`n[5] Idempotency"

$ordersBefore = Get-OcSqlInt "SELECT COUNT(DISTINCT order_id) FROM oc_poolbuy_reservation WHERE pool_id=$fPool AND order_id>0;"
$cron2 = Invoke-Cron
$ordersAfter = Get-OcSqlInt "SELECT COUNT(DISTINCT order_id) FROM oc_poolbuy_reservation WHERE pool_id=$fPool AND order_id>0;"
$sumAfter = Get-OcSqlScalar "SELECT COALESCE(SUM(shipping_cost),0) FROM oc_poolbuy_reservation WHERE pool_id=$fPool;"

Assert ($ordersAfter -eq $ordersBefore) "a second cron run created no extra orders ($ordersBefore -> $ordersAfter)"
Assert ([decimal]$sumAfter -eq [decimal]'100.0000') "freight is still exactly 100.00 after a re-run (got $sumAfter)"

$orderFreight2 = Get-OcSqlScalar "SELECT COALESCE(SUM(value),0) FROM oc_order_total WHERE code='shipping' AND order_id IN (SELECT order_id FROM oc_poolbuy_reservation WHERE pool_id=$fPool AND order_id>0);"
Assert ([decimal]$orderFreight2 -eq [decimal]'100.0000') "order freight was not double-charged (got $orderFreight2)"

# ---------------------------------------------------------------------------
# [6] The allocation is audited
# ---------------------------------------------------------------------------
Write-Host "`n[6] Audit trail"

$events = Get-OcSqlInt "SELECT COUNT(*) FROM oc_poolbuy_pool_event WHERE pool_id=$fPool AND type='freight_allocated';"
Assert ($events -ge 1) "a freight_allocated event was recorded (found $events)"

# ---------------------------------------------------------------------------
# [7] A zero-freight pool allocates nothing
# ---------------------------------------------------------------------------
Write-Host "`n[7] Zero-freight pool"

Invoke-OcSql "DELETE FROM oc_poolbuy_reservation WHERE pool_id IN (SELECT pool_id FROM oc_poolbuy_pool WHERE reference='PB-FREIGHT-0');"
Invoke-OcSql "DELETE FROM oc_poolbuy_pool_tier WHERE pool_id IN (SELECT pool_id FROM oc_poolbuy_pool WHERE reference='PB-FREIGHT-0');"
Invoke-OcSql "DELETE FROM oc_poolbuy_pool_event WHERE pool_id IN (SELECT pool_id FROM oc_poolbuy_pool WHERE reference='PB-FREIGHT-0');"
Invoke-OcSql "DELETE FROM oc_poolbuy_settlement WHERE pool_id IN (SELECT pool_id FROM oc_poolbuy_pool WHERE reference='PB-FREIGHT-0');"
Invoke-OcSql "DELETE FROM oc_poolbuy_pool WHERE reference='PB-FREIGHT-0';"

Invoke-OcSql @"
INSERT INTO oc_poolbuy_pool
(product_id, seller_id, title, reference, moq_target, reserved_qty, unit_label, currency_code, status,
 date_start, date_end, retro_pricing, allow_full_moq_buy, min_qty_per_buyer, max_qty_per_buyer,
 lead_time, shipping_terms, freight_total, date_added, date_modified)
VALUES
($productId, $sellerId, 'No freight pool', 'PB-FREIGHT-0', 10, 10, 'Units', 'INR', 'closed',
 DATE_SUB(NOW(), INTERVAL 5 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY), 1, 1, 1, 0,
 '10 Days', 'FOB Test', '0.0000', NOW(), NOW());
"@

$zPool = Get-OcSqlInt "SELECT pool_id FROM oc_poolbuy_pool WHERE reference='PB-FREIGHT-0';"
Invoke-OcSql "INSERT INTO oc_poolbuy_pool_tier (pool_id, min_qty, max_qty, price, sort_order) VALUES ($zPool, 1, NULL, '250.0000', 1);"

$firstBuyer = $buyerList[0]
$addr0 = Get-OcSqlInt "SELECT COALESCE((SELECT address_id FROM oc_address WHERE customer_id=$firstBuyer LIMIT 1), 0);"
Invoke-OcSql "INSERT INTO oc_poolbuy_reservation (pool_id, customer_id, quantity, unit_price_locked, status, order_id, address_id, shipping_cost, reference, date_added, date_modified) VALUES ($zPool, $firstBuyer, 10, '250.0000', 'confirmed', 0, $addr0, '0.0000', 'TX-FR-0', NOW(), NOW());"

Invoke-Cron | Out-Null

$zeroSum = Get-OcSqlScalar "SELECT COALESCE(SUM(shipping_cost),0) FROM oc_poolbuy_reservation WHERE pool_id=$zPool;"
Assert ([decimal]$zeroSum -eq [decimal]'0.0000') "a pool with no freight allocates nothing (got $zeroSum)"

$zeroLines = Get-OcSqlInt "SELECT COUNT(*) FROM oc_order_total WHERE code='shipping' AND order_id IN (SELECT order_id FROM oc_poolbuy_reservation WHERE pool_id=$zPool AND order_id>0);"
Assert ($zeroLines -eq 0) 'no freight line is added to an order that carries no freight'

# ---------------------------------------------------------------------------
# Clean up the pools this suite created
# ---------------------------------------------------------------------------
foreach ($ref in @('PB-FREIGHT-1', 'PB-FREIGHT-0')) {
    $id = Get-OcSqlInt "SELECT COALESCE((SELECT pool_id FROM oc_poolbuy_pool WHERE reference='$ref'), 0);"
    if ($id -gt 0) {
        Invoke-OcSql "DELETE FROM oc_order_total WHERE order_id IN (SELECT order_id FROM oc_poolbuy_reservation WHERE pool_id=$id AND order_id>0);"
        Invoke-OcSql "DELETE FROM oc_order_product WHERE order_id IN (SELECT order_id FROM oc_poolbuy_reservation WHERE pool_id=$id AND order_id>0);"
        Invoke-OcSql "DELETE FROM oc_order_history WHERE order_id IN (SELECT order_id FROM oc_poolbuy_reservation WHERE pool_id=$id AND order_id>0);"
        Invoke-OcSql "DELETE FROM oc_order WHERE order_id IN (SELECT order_id FROM oc_poolbuy_reservation WHERE pool_id=$id AND order_id>0);"
        Invoke-OcSql "DELETE FROM oc_poolbuy_payment WHERE reservation_id IN (SELECT reservation_id FROM oc_poolbuy_reservation WHERE pool_id=$id);"
        Invoke-OcSql "DELETE FROM oc_poolbuy_reservation WHERE pool_id=$id;"
        Invoke-OcSql "DELETE FROM oc_poolbuy_pool_tier WHERE pool_id=$id;"
        Invoke-OcSql "DELETE FROM oc_poolbuy_pool_event WHERE pool_id=$id;"
        Invoke-OcSql "DELETE FROM oc_poolbuy_settlement WHERE pool_id=$id;"
        Invoke-OcSql "DELETE FROM oc_poolbuy_pool WHERE pool_id=$id;"
    }
}

Write-Host ""
if ($failures.Count -eq 0) {
    Write-Host "ALL FREIGHT CHECKS PASSED" -ForegroundColor Green
    exit 0
} else {
    Write-Host "$($failures.Count) CHECK(S) FAILED" -ForegroundColor Red
    exit 1
}
