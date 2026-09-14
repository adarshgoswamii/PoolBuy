# Verifies PoolBuy promotions: buyer codes, pool-wide promotions, the precedence
# rule between them, and the properties that stop a discount becoming a liability.
#
# The things that matter here are:
#   - a discount can never invert an order or exceed the goods value
#   - a limited code cannot be redeemed more times than its own ceiling, even
#     under concurrency
#   - a pool-wide promotion outranks a buyer code, and says so rather than
#     silently ignoring it
#   - the admin screens are authorised and injection safe
#
# Requires: tools\poolbuy\seed_all.ps1
#
# Usage: powershell -File tools\poolbuy\verify_promotions.ps1

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

function Connect-Buyer([string] $Email = $email) {
    $session = New-Object Microsoft.PowerShell.Commands.WebRequestSession

    $page = Invoke-WebRequest -Uri "$base/index.php?route=account/login" -WebSession $session -UseBasicParsing
    if ($page.Content -notmatch 'login_token=([0-9a-zA-Z]+)') { throw 'no login_token' }
    $token = $Matches[1]

    $response = Invoke-WebRequest -Uri "$base/index.php?route=account/login.login&login_token=$token" `
        -Method POST -Body @{ email = $Email; password = $password } -WebSession $session -UseBasicParsing

    if ($response.Content -notmatch 'customer_token') { throw "login failed for $Email" }

    return $session
}

function Join-Step([object] $Session, [int] $PoolId, [string] $Body) {
    return (Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/join&pool_id=$PoolId" `
        -Method POST -Body $Body -ContentType 'application/x-www-form-urlencoded' `
        -WebSession $Session -UseBasicParsing).Content
}

function Get-CronToken {
    return (Get-OcSqlScalar "SELECT value FROM oc_setting WHERE code='module_poolbuy' AND ${OcTick}key${OcTick}='module_poolbuy_cron_token' LIMIT 1;")
}

function Invoke-Cron {
    $token = Get-CronToken
    return (Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/cron/poolbuy.run&token=$token" -UseBasicParsing -TimeoutSec 180).Content
}

# Fresh, known codes for this run.
Invoke-OcSql "DELETE FROM oc_poolbuy_promotion WHERE code IN ('VPCT10','VPFIX500','VPONCE','VPGONE','VPSOON','VPBIG','VPPOOL20');"
Invoke-OcSql @"
INSERT INTO oc_poolbuy_promotion (code,name,scope,pool_id,type,value,min_qty,max_redemptions,redemptions_used,date_start,date_end,status,date_added,date_modified) VALUES
('VPCT10','Ten percent','buyer',0,'percent','10.0000',0,0,0,NULL,NULL,1,NOW(),NOW()),
('VPFIX500','Five hundred off','buyer',0,'fixed','500.0000',0,0,0,NULL,NULL,1,NOW(),NOW()),
('VPONCE','Single redemption','buyer',0,'percent','25.0000',0,1,0,NULL,NULL,1,NOW(),NOW()),
('VPGONE','Expired','buyer',0,'percent','50.0000',0,0,0,NULL,'2020-01-01 00:00:00',1,NOW(),NOW()),
('VPSOON','Not started','buyer',0,'percent','50.0000',0,0,0,'2090-01-01 00:00:00',NULL,1,NOW(),NOW()),
('VPBIG','Needs 500 units','buyer',0,'percent','30.0000',500,0,0,NULL,NULL,1,NOW(),NOW());
"@

$poolId = Get-OcSqlInt "SELECT pool_id FROM oc_poolbuy_pool WHERE reference='PB-88303';"
$customerId = Get-OcSqlInt "SELECT customer_id FROM oc_customer WHERE email='$email';"
$addressId = Get-OcSqlInt "SELECT address_id FROM oc_address WHERE customer_id=$customerId LIMIT 1;"

function Reset-Pool {
    Invoke-OcSql "DELETE FROM oc_poolbuy_promotion_redemption WHERE reservation_id IN (SELECT reservation_id FROM oc_poolbuy_reservation WHERE pool_id=$poolId);"
    Invoke-OcSql "DELETE FROM oc_poolbuy_reservation WHERE pool_id=$poolId AND customer_id=$customerId;"
    Invoke-OcSql "UPDATE oc_poolbuy_pool SET reserved_qty = (SELECT COALESCE(SUM(quantity),0) FROM oc_poolbuy_reservation WHERE pool_id=$poolId AND status IN ('pending','confirmed','converted')), status='active', freight_total='0.0000' WHERE pool_id=$poolId;"
}

Reset-Pool

# ---------------------------------------------------------------------------
Write-Host "`n[1] A buyer code discounts the commitment, and tax follows the discount"

$session = Connect-Buyer
$page = Join-Step $session $poolId 'step=1&quantity=20&promo_code=VPCT10'

Assert ($page -match 'Discount applied') 'a valid code reports itself as applied'

# The invariant: GST and the platform fee must be charged on the DISCOUNTED base,
# not the gross subtotal. Parse the rendered rows and check the arithmetic.
function Get-Money([string] $Html, [string] $Label) {
    if ($Html -match "<span>$Label[^<]*</span>\s*<strong[^>]*>[^0-9]*([\d,]+\.\d{2})</strong>") {
        return [decimal](($Matches[1]) -replace ',', '')
    }
    return [decimal]0
}

$subtotal = Get-Money $page 'Subtotal'
$discount = Get-Money $page 'Discount'
$gst      = Get-Money $page 'GST / Tax'
$fee      = Get-Money $page 'Platform Fee'
$total    = Get-Money $page 'Estimated Total'

Write-Host "      subtotal=$subtotal discount=$discount gst=$gst fee=$fee total=$total"

Assert ($subtotal -gt 0 -and $discount -gt 0) 'the breakdown renders a subtotal and a discount'
Assert ($discount -eq [math]::Round($subtotal * 0.10, 2)) "the discount is 10% of the subtotal ($discount)"

$discounted = $subtotal - $discount
Assert ($gst -eq [math]::Round($discounted * 0.18, 2)) "GST is charged on the discounted base, not the gross ($gst)"
Assert ($fee -eq [math]::Round($discounted * 0.02, 2)) "the platform fee is charged on the discounted base ($fee)"
Assert ($total -eq ($discounted + $gst + $fee)) "the total is the sum of its discounted parts ($total)"

# ---------------------------------------------------------------------------
Write-Host "`n[2] Every rejection reason is reported specifically"

$cases = @{
    'VPGONE'  = 'has expired'
    'VPSOON'  = 'not valid yet'
    'VPBIG'   = 'needs a larger commitment'
    'NOSUCH'  = 'was not recognised'
}

foreach ($code in $cases.Keys) {
    $out = Join-Step $session $poolId "step=1&quantity=20&promo_code=$code"
    Assert ($out -match $cases[$code]) "$code is refused with its own reason ('$($cases[$code])')"
    Assert (-not ($out -match 'Discount applied')) "$code does not apply a discount"
}

# ---------------------------------------------------------------------------
Write-Host "`n[3] A fixed code cannot exceed the goods value"

# Force a tiny commitment so a 500 fixed discount would overshoot.
$minQty = Get-OcSqlInt "SELECT min_qty_per_buyer FROM oc_poolbuy_pool WHERE pool_id=$poolId;"
$unit = Get-OcSqlScalar "SELECT price FROM oc_poolbuy_pool_tier WHERE pool_id=$poolId ORDER BY min_qty ASC LIMIT 1;"

$small = Join-Step $session $poolId "step=1&quantity=$minQty&promo_code=VPFIX500"
$sSub = Get-Money $small 'Subtotal'
$sDisc = Get-Money $small 'Discount'
$sTotal = Get-Money $small 'Estimated Total'

Write-Host "      subtotal=$sSub discount=$sDisc total=$sTotal"
Assert ($sDisc -le $sSub) "the discount never exceeds the subtotal ($sDisc <= $sSub)"
Assert ($sTotal -ge 0) "the total is never negative ($sTotal)"

# ---------------------------------------------------------------------------
Write-Host "`n[4] Committing claims exactly one redemption"

Reset-Pool

$used0 = Get-OcSqlInt "SELECT redemptions_used FROM oc_poolbuy_promotion WHERE code='VPCT10';"
$confirm = Join-Step $session $poolId "step=3&quantity=20&promo_code=VPCT10&address_id=$addressId"

Assert ($confirm -match 'Reservation confirmed') 'the commitment was accepted'

$used1 = Get-OcSqlInt "SELECT redemptions_used FROM oc_poolbuy_promotion WHERE code='VPCT10';"
Assert ($used1 -eq ($used0 + 1)) "redemptions_used moved by exactly one ($used0 -> $used1)"

$stored = Get-OcSqlScalar "SELECT discount_total FROM oc_poolbuy_reservation WHERE pool_id=$poolId AND customer_id=$customerId AND status='confirmed';"
Assert ([decimal]$stored -gt 0) "the discount was recorded on the commitment ($stored)"

$rows = Get-OcSqlInt "SELECT COUNT(*) FROM oc_poolbuy_promotion_redemption rd JOIN oc_poolbuy_reservation r ON r.reservation_id=rd.reservation_id WHERE r.pool_id=$poolId AND r.customer_id=$customerId;"
Assert ($rows -eq 1) "exactly one redemption row exists (found $rows)"

# ---------------------------------------------------------------------------
Write-Host "`n[5] Withdrawing gives the redemption back"

$resId = Get-OcSqlInt "SELECT reservation_id FROM oc_poolbuy_reservation WHERE pool_id=$poolId AND customer_id=$customerId AND status='confirmed' LIMIT 1;"
# The withdraw route takes reservation_id on the query string.
$cancel = Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/account.cancel&reservation_id=$resId" `
    -WebSession $session -UseBasicParsing -MaximumRedirection 5

$used2 = Get-OcSqlInt "SELECT redemptions_used FROM oc_poolbuy_promotion WHERE code='VPCT10';"
Assert ($used2 -eq $used0) "withdrawing returned the redemption ($used1 -> $used2)"

$orphans = Get-OcSqlInt "SELECT COUNT(*) FROM oc_poolbuy_promotion_redemption WHERE reservation_id=$resId;"
Assert ($orphans -eq 0) 'the redemption row was removed with the commitment'

# ---------------------------------------------------------------------------
Write-Host "`n[6] A single-redemption code cannot be claimed twice, even concurrently"

Reset-Pool
Invoke-OcSql "UPDATE oc_poolbuy_promotion SET redemptions_used=0 WHERE code='VPONCE';"

# Different buyers racing the same one-use code. Every racer must start with no
# commitment on this pool, or the duplicate-join guard would refuse them before
# the promotion is ever reached and the race would not be exercised at all.
$racers = (Get-OcSqlScalar "SELECT GROUP_CONCAT(email) FROM (SELECT email FROM oc_customer WHERE email LIKE '%@poolbuy.test' ORDER BY customer_id LIMIT 4) x")
$racerList = @($racers -split ',')

Invoke-OcSql "DELETE FROM oc_poolbuy_promotion_redemption WHERE reservation_id IN (SELECT reservation_id FROM oc_poolbuy_reservation WHERE pool_id=$poolId);"
Invoke-OcSql "DELETE FROM oc_poolbuy_reservation WHERE pool_id=$poolId;"
Invoke-OcSql "UPDATE oc_poolbuy_pool SET reserved_qty=0, status='active' WHERE pool_id=$poolId;"

# Each racer needs a destination address, since the commit step requires one.
foreach ($racer in $racerList) {
    $rid = Get-OcSqlInt "SELECT COALESCE((SELECT customer_id FROM oc_customer WHERE email='$($racer.Trim())'),0);"
    $has = Get-OcSqlInt "SELECT COUNT(*) FROM oc_address WHERE customer_id=$rid;"
    if ($rid -gt 0 -and $has -eq 0) {
        Invoke-OcSql "INSERT INTO oc_address (customer_id, firstname, lastname, company, address_1, address_2, city, postcode, country_id, zone_id, custom_field) VALUES ($rid,'Race','Buyer','','1 Test Street','','Bangalore','560001',99,1587,'');"
    }
}

Write-Host "      racing with $($racerList.Count) buyers on a max_redemptions=1 code"

$jobs = @()
foreach ($racer in $racerList) {
    $rid = Get-OcSqlInt "SELECT COALESCE((SELECT customer_id FROM oc_customer WHERE email='$($racer.Trim())'),0);"
    $raddr = Get-OcSqlInt "SELECT COALESCE((SELECT address_id FROM oc_address WHERE customer_id=$rid LIMIT 1),0);"

    $jobs += Start-Job -ScriptBlock {
        param($base, $mail, $pass, $pool, $addr)
        try {
            $s = New-Object Microsoft.PowerShell.Commands.WebRequestSession
            $p = Invoke-WebRequest -Uri "$base/index.php?route=account/login" -WebSession $s -UseBasicParsing
            if ($p.Content -notmatch 'login_token=([0-9a-zA-Z]+)') { return }
            $t = $Matches[1]
            Invoke-WebRequest -Uri "$base/index.php?route=account/login.login&login_token=$t" -Method POST -Body @{ email = $mail; password = $pass } -WebSession $s -UseBasicParsing | Out-Null
            Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/join&pool_id=$pool" -Method POST -Body "step=3&quantity=10&promo_code=VPONCE&address_id=$addr" -ContentType 'application/x-www-form-urlencoded' -WebSession $s -UseBasicParsing | Out-Null
        } catch { }
    } -ArgumentList $base, $racer.Trim(), $password, $poolId, $raddr
}

$jobs | Wait-Job -Timeout 180 | Out-Null
$jobs | Remove-Job -Force

$committed = Get-OcSqlInt "SELECT COUNT(*) FROM oc_poolbuy_reservation WHERE pool_id=$poolId AND status='confirmed';"
$onceUsed = Get-OcSqlInt "SELECT redemptions_used FROM oc_poolbuy_promotion WHERE code='VPONCE';"
$onceRows = Get-OcSqlInt "SELECT COUNT(*) FROM oc_poolbuy_promotion_redemption rd JOIN oc_poolbuy_promotion p ON p.promotion_id=rd.promotion_id WHERE p.code='VPONCE';"
$discountedCount = Get-OcSqlInt "SELECT COUNT(*) FROM oc_poolbuy_reservation r JOIN oc_poolbuy_promotion p ON p.promotion_id=r.promotion_id WHERE r.pool_id=$poolId AND p.code='VPONCE' AND r.discount_total > 0;"

Write-Host "      committed=$committed redemptions_used=$onceUsed redemption_rows=$onceRows discounted_commitments=$discountedCount"

# The race is only meaningful if several buyers actually committed.
Assert ($committed -ge 2) "the race was genuinely contested: $committed buyers committed"
Assert ($onceUsed -eq 1) "exactly one redemption was taken, not $onceUsed"
Assert ($onceRows -eq 1) "exactly one redemption row was written (found $onceRows)"
Assert ($discountedCount -eq 1) "exactly one commitment carries the discount (found $discountedCount)"

# ---------------------------------------------------------------------------
Write-Host "`n[7] A pool-wide promotion outranks a buyer code"

$sellerId = Get-OcSqlInt "SELECT seller_id FROM oc_poolbuy_seller ORDER BY seller_id LIMIT 1;"
$productId = Get-OcSqlInt "SELECT product_id FROM oc_product ORDER BY product_id LIMIT 1;"

foreach ($ref in @('PB-VPROMO')) {
    $id = Get-OcSqlInt "SELECT COALESCE((SELECT pool_id FROM oc_poolbuy_pool WHERE reference='$ref'),0);"
    if ($id -gt 0) {
        Invoke-OcSql "DELETE FROM oc_poolbuy_promotion_redemption WHERE reservation_id IN (SELECT reservation_id FROM oc_poolbuy_reservation WHERE pool_id=$id);"
        Invoke-OcSql "DELETE FROM oc_poolbuy_reservation WHERE pool_id=$id;"
        Invoke-OcSql "DELETE FROM oc_poolbuy_pool_tier WHERE pool_id=$id;"
        Invoke-OcSql "DELETE FROM oc_poolbuy_pool_event WHERE pool_id=$id;"
        Invoke-OcSql "DELETE FROM oc_poolbuy_settlement WHERE pool_id=$id;"
        Invoke-OcSql "DELETE FROM oc_poolbuy_promotion WHERE pool_id=$id;"
        Invoke-OcSql "DELETE FROM oc_poolbuy_pool WHERE pool_id=$id;"
    }
}

Invoke-OcSql @"
INSERT INTO oc_poolbuy_pool
(product_id, seller_id, title, reference, moq_target, reserved_qty, unit_label, currency_code, status,
 date_start, date_end, retro_pricing, allow_full_moq_buy, min_qty_per_buyer, max_qty_per_buyer,
 lead_time, shipping_terms, freight_total, date_added, date_modified)
VALUES
($productId, $sellerId, 'Pool-wide promo pool', 'PB-VPROMO', 100, 0, 'Units', 'INR', 'active',
 DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_ADD(NOW(), INTERVAL 10 DAY), 1, 1, 10, 0,
 '10 Days', 'FOB Test', '0.0000', NOW(), NOW());
"@

$wPool = Get-OcSqlInt "SELECT pool_id FROM oc_poolbuy_pool WHERE reference='PB-VPROMO';"
Invoke-OcSql "INSERT INTO oc_poolbuy_pool_tier (pool_id, min_qty, max_qty, price, sort_order) VALUES ($wPool, 1, NULL, '1000.0000', 1);"
Invoke-OcSql "INSERT INTO oc_poolbuy_promotion (code,name,scope,pool_id,type,value,min_qty,max_redemptions,redemptions_used,date_start,date_end,status,date_added,date_modified) VALUES ('VPPOOL20','Pool-wide twenty','pool',$wPool,'percent','20.0000',0,0,0,NULL,NULL,1,NOW(),NOW());"

$session2 = Connect-Buyer
$wPage = Join-Step $session2 $wPool 'step=1&quantity=10&promo_code=VPCT10'

Assert ($wPage -match 'discount applied for every participant') 'the pool-wide discount is announced to the buyer'
Assert ($wPage -match 'already has a discount for every participant') 'the buyer code is explicitly refused, not silently dropped'
Assert (-not ($wPage -match 'name="promo_code"[^>]*class="pb-input"')) 'the code field is not offered when a pool-wide promotion applies'

$wSub = Get-Money $wPage 'Subtotal'
$wDisc = Get-Money $wPage 'Discount'
Assert ($wDisc -eq [math]::Round($wSub * 0.20, 2)) "the pool-wide 20% is applied instead ($wDisc of $wSub)"

# ---------------------------------------------------------------------------
Write-Host "`n[8] A pool-wide promotion reaches every participant's order at close"

$buyers = @(Get-OcSqlScalar "SELECT GROUP_CONCAT(customer_id) FROM (SELECT customer_id FROM oc_customer ORDER BY customer_id LIMIT 3) x") -split ','

$n = 0
foreach ($b in $buyers) {
    $n++
    $addr = Get-OcSqlInt "SELECT COALESCE((SELECT address_id FROM oc_address WHERE customer_id=$b LIMIT 1),0);"
    Invoke-OcSql "INSERT INTO oc_poolbuy_reservation (pool_id,customer_id,quantity,unit_price_locked,status,order_id,address_id,shipping_cost,promotion_id,discount_total,reference,date_added,date_modified) VALUES ($wPool,$b,10,'1000.0000','confirmed',0,$addr,'0.0000',0,'0.0000','TX-VP-$n',NOW(),NOW());"
}

Invoke-OcSql "UPDATE oc_poolbuy_pool SET status='closed', reserved_qty=30 WHERE pool_id=$wPool;"
Invoke-OcSql "UPDATE oc_poolbuy_promotion SET redemptions_used=0 WHERE code='VPPOOL20';"

Invoke-Cron | Out-Null

$allDiscounted = Get-OcSqlInt "SELECT COUNT(*) FROM oc_poolbuy_reservation WHERE pool_id=$wPool AND discount_total = 2000;"
Assert ($allDiscounted -eq 3) "all three participants were discounted 2,000 (found $allDiscounted)"

$discountLines = Get-OcSqlInt "SELECT COUNT(*) FROM oc_order_total WHERE code='poolbuy_discount' AND order_id IN (SELECT order_id FROM oc_poolbuy_reservation WHERE pool_id=$wPool AND order_id>0);"
Assert ($discountLines -eq 3) "every order carries a discount line (found $discountLines)"

$negative = Get-OcSqlInt "SELECT COUNT(*) FROM oc_order_total WHERE code='poolbuy_discount' AND value >= 0 AND order_id IN (SELECT order_id FROM oc_poolbuy_reservation WHERE pool_id=$wPool AND order_id>0);"
Assert ($negative -eq 0) 'the discount line is negative, as a reduction must be'

# The discount must sort BEFORE the fee and GST, because both are charged on the
# discounted base and the order should read in that order.
$badOrder = Get-OcSqlInt @"
SELECT COUNT(*) FROM oc_order_total d
JOIN oc_order_total g ON g.order_id = d.order_id AND g.code IN ('poolbuy_gst','poolbuy_fee')
WHERE d.code='poolbuy_discount' AND d.sort_order > g.sort_order
AND d.order_id IN (SELECT order_id FROM oc_poolbuy_reservation WHERE pool_id=$wPool AND order_id>0);
"@
Assert ($badOrder -eq 0) 'the discount line sorts before the fee and GST lines'

# Totals must still reconcile with the discount included.
$mismatch = Get-OcSqlInt @"
SELECT COUNT(*) FROM (
  SELECT o.order_id, ROUND(o.total,4) AS declared,
         ROUND(SUM(CASE WHEN ot.code <> 'total' THEN ot.value ELSE 0 END),4) AS parts
  FROM oc_order o JOIN oc_order_total ot ON ot.order_id=o.order_id
  WHERE o.order_id IN (SELECT order_id FROM oc_poolbuy_reservation WHERE pool_id=$wPool AND order_id>0)
  GROUP BY o.order_id
) x WHERE declared <> parts;
"@
Assert ($mismatch -eq 0) 'every discounted order total equals the sum of its lines'

$noNegativeTotals = Get-OcSqlInt "SELECT COUNT(*) FROM oc_order WHERE total < 0 AND order_id IN (SELECT order_id FROM oc_poolbuy_reservation WHERE pool_id=$wPool AND order_id>0);"
Assert ($noNegativeTotals -eq 0) 'no discounted order has a negative total'

Write-Host "`n[9] Re-running the cron does not discount twice"

$beforeUsed = Get-OcSqlInt "SELECT redemptions_used FROM oc_poolbuy_promotion WHERE code='VPPOOL20';"
Invoke-Cron | Out-Null
$afterUsed = Get-OcSqlInt "SELECT redemptions_used FROM oc_poolbuy_promotion WHERE code='VPPOOL20';"
$dupRows = Get-OcSqlInt "SELECT COUNT(*) FROM oc_poolbuy_promotion_redemption rd JOIN oc_poolbuy_promotion p ON p.promotion_id=rd.promotion_id WHERE p.code='VPPOOL20';"

Assert ($afterUsed -eq $beforeUsed) "redemptions_used unchanged on a re-run ($beforeUsed -> $afterUsed)"
Assert ($dupRows -eq 3) "still exactly three redemption rows (found $dupRows)"

# ---------------------------------------------------------------------------
Write-Host "`n[10] The admin screens are authorised"

$routes = @(
    'extension/poolbuy/poolbuy/promotion',
    'extension/poolbuy/poolbuy/promotion.list',
    'extension/poolbuy/poolbuy/promotion.form',
    'extension/poolbuy/poolbuy/promotion.save',
    'extension/poolbuy/poolbuy/promotion.delete'
)

foreach ($route in $routes) {
    $anon = Invoke-WebRequest -Uri "$base/admin/index.php?route=$route" -UseBasicParsing -MaximumRedirection 5
    Assert ($anon.Content -match 'name="username"' -or $anon.Content -match 'route=common/login') "$route refuses an unauthenticated visitor"
}

$admin = Connect-OcAdmin

$forged = Invoke-WebRequest -Uri "$base/admin/index.php?route=extension/poolbuy/poolbuy/promotion&user_token=deadbeefdeadbeef" -UseBasicParsing -MaximumRedirection 5
Assert ($forged.Content -match 'name="username"' -or $forged.Content -match 'route=common/login') 'a forged user_token is rejected'

Write-Host "`n[11] The admin screens validate and are injection safe"

$before = Get-OcSqlInt "SELECT COUNT(*) FROM oc_poolbuy_promotion;"

$inject = Invoke-OcAdmin $admin 'extension/poolbuy/poolbuy/promotion.save' @{} $null 'POST' ("code=" + [uri]::EscapeDataString("X'; DROP TABLE oc_poolbuy_promotion; --") + "&name=Injection&scope=buyer&type=percent&value=10&status=1")
$stillThere = Get-OcSqlInt "SELECT COUNT(*) FROM oc_poolbuy_promotion;"

Assert ($inject.Content -match 'error') 'a code containing SQL is refused by validation'
Assert ($stillThere -eq $before) "the promotion table is intact after an injection attempt ($before rows)"

$xssCode = 'XSSTEST'
Invoke-OcSql "DELETE FROM oc_poolbuy_promotion WHERE code='$xssCode';"
$xssSave = Invoke-OcAdmin $admin 'extension/poolbuy/poolbuy/promotion.save' @{} $null 'POST' ("code=$xssCode&name=" + [uri]::EscapeDataString('<script>alert(1)</script>') + "&scope=buyer&type=percent&value=10&status=1")
$listAfter = Invoke-OcAdmin $admin 'extension/poolbuy/poolbuy/promotion'

Assert (-not ($listAfter.Content -match '<script>alert\(1\)</script>')) 'an operator-supplied name is escaped on the list screen'

# redemptions_used is a ledger, not an operator field.
$ledgerCode = 'LEDGERTEST'
Invoke-OcSql "DELETE FROM oc_poolbuy_promotion WHERE code='$ledgerCode';"
Invoke-OcAdmin $admin 'extension/poolbuy/poolbuy/promotion.save' @{} $null 'POST' "code=$ledgerCode&name=Ledger+test&scope=buyer&type=percent&value=10&max_redemptions=5&redemptions_used=999&status=1" | Out-Null
$ledger = Get-OcSqlInt "SELECT COALESCE((SELECT redemptions_used FROM oc_poolbuy_promotion WHERE code='$ledgerCode'),-1);"
Assert ($ledger -eq 0) "a posted redemptions_used is ignored (stored $ledger)"

# ---------------------------------------------------------------------------
# Clean up everything this suite created.
# ---------------------------------------------------------------------------
$id = Get-OcSqlInt "SELECT COALESCE((SELECT pool_id FROM oc_poolbuy_pool WHERE reference='PB-VPROMO'),0);"
if ($id -gt 0) {
    Invoke-OcSql "DELETE FROM oc_order_total WHERE order_id IN (SELECT order_id FROM oc_poolbuy_reservation WHERE pool_id=$id AND order_id>0);"
    Invoke-OcSql "DELETE FROM oc_order_product WHERE order_id IN (SELECT order_id FROM oc_poolbuy_reservation WHERE pool_id=$id AND order_id>0);"
    Invoke-OcSql "DELETE FROM oc_order_history WHERE order_id IN (SELECT order_id FROM oc_poolbuy_reservation WHERE pool_id=$id AND order_id>0);"
    Invoke-OcSql "DELETE FROM oc_order WHERE order_id IN (SELECT order_id FROM oc_poolbuy_reservation WHERE pool_id=$id AND order_id>0);"
    Invoke-OcSql "DELETE FROM oc_poolbuy_payment WHERE reservation_id IN (SELECT reservation_id FROM oc_poolbuy_reservation WHERE pool_id=$id);"
    Invoke-OcSql "DELETE FROM oc_poolbuy_promotion_redemption WHERE reservation_id IN (SELECT reservation_id FROM oc_poolbuy_reservation WHERE pool_id=$id);"
    Invoke-OcSql "DELETE FROM oc_poolbuy_reservation WHERE pool_id=$id;"
    Invoke-OcSql "DELETE FROM oc_poolbuy_pool_tier WHERE pool_id=$id;"
    Invoke-OcSql "DELETE FROM oc_poolbuy_pool_event WHERE pool_id=$id;"
    Invoke-OcSql "DELETE FROM oc_poolbuy_settlement WHERE pool_id=$id;"
    Invoke-OcSql "DELETE FROM oc_poolbuy_pool WHERE pool_id=$id;"
}

Invoke-OcSql "DELETE FROM oc_poolbuy_promotion WHERE code IN ('VPCT10','VPFIX500','VPONCE','VPGONE','VPSOON','VPBIG','VPPOOL20','XSSTEST','LEDGERTEST');"
Reset-Pool

Write-Host ""
if ($failures.Count -eq 0) {
    Write-Host "ALL PROMOTION CHECKS PASSED" -ForegroundColor Green
    exit 0
} else {
    Write-Host "$($failures.Count) CHECK(S) FAILED" -ForegroundColor Red
    exit 1
}
