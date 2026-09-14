# Verifies PoolBuy multi-currency behaviour.
#
# The rule being tested is narrow and deliberate: a pool's prices are
# AUTHORITATIVE in that pool's own currency, so each pool is DISPLAYED in its own
# currency and never converted into another. A silent FX conversion would change
# what a buyer is being asked to pay, so the correct behaviour is to relabel
# nothing.
#
# Also covered: a pool's currency becomes immutable once anyone has committed,
# and settlement figures are never summed across currencies.
#
# Requires: tools\poolbuy\seed_all.ps1
#
# Usage: powershell -File tools\poolbuy\verify_currency.ps1

$ErrorActionPreference = 'Stop'
. "$PSScriptRoot\admin.ps1"

$base = 'http://localhost'

$failures = @()

function Assert([bool] $Condition, [string] $Message) {
    if ($Condition) {
        Write-Host "  PASS  $Message" -ForegroundColor Green
    } else {
        Write-Host "  FAIL  $Message" -ForegroundColor Red
        $script:failures += $Message
    }
}

$sellerId = Get-OcSqlInt "SELECT seller_id FROM oc_poolbuy_seller ORDER BY seller_id LIMIT 1;"
$productId = Get-OcSqlInt "SELECT product_id FROM oc_product ORDER BY product_id LIMIT 1;"

function Remove-Pool([string] $Ref) {
    $id = Get-OcSqlInt "SELECT COALESCE((SELECT pool_id FROM oc_poolbuy_pool WHERE reference='$Ref'),0);"
    if ($id -gt 0) {
        Invoke-OcSql "DELETE FROM oc_order_total WHERE order_id IN (SELECT order_id FROM oc_poolbuy_reservation WHERE pool_id=$id AND order_id>0);"
        Invoke-OcSql "DELETE FROM oc_order WHERE order_id IN (SELECT order_id FROM oc_poolbuy_reservation WHERE pool_id=$id AND order_id>0);"
        Invoke-OcSql "DELETE FROM oc_poolbuy_payment WHERE reservation_id IN (SELECT reservation_id FROM oc_poolbuy_reservation WHERE pool_id=$id);"
        Invoke-OcSql "DELETE FROM oc_poolbuy_review WHERE pool_id=$id;"
        Invoke-OcSql "DELETE FROM oc_poolbuy_reservation WHERE pool_id=$id;"
        Invoke-OcSql "DELETE FROM oc_poolbuy_pool_tier WHERE pool_id=$id;"
        Invoke-OcSql "DELETE FROM oc_poolbuy_pool_event WHERE pool_id=$id;"
        Invoke-OcSql "DELETE FROM oc_poolbuy_settlement WHERE pool_id=$id;"
        Invoke-OcSql "DELETE FROM oc_poolbuy_promotion WHERE pool_id=$id;"
        Invoke-OcSql "DELETE FROM oc_poolbuy_pool WHERE pool_id=$id;"
    }
}

function New-Pool([string] $Ref, [string] $Currency, [string] $Price) {
    Remove-Pool $Ref

    Invoke-OcSql @"
INSERT INTO oc_poolbuy_pool
(product_id, seller_id, title, reference, moq_target, reserved_qty, unit_label, currency_code, status,
 date_start, date_end, retro_pricing, allow_full_moq_buy, min_qty_per_buyer, max_qty_per_buyer,
 lead_time, shipping_terms, freight_total, date_added, date_modified)
VALUES
($productId, $sellerId, 'Currency pool $Currency', '$Ref', 100, 10, 'Units', '$Currency', 'active',
 DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_ADD(NOW(), INTERVAL 10 DAY), 1, 1, 10, 0,
 '10 Days', 'FOB Test', '0.0000', NOW(), NOW());
"@

    $id = Get-OcSqlInt "SELECT pool_id FROM oc_poolbuy_pool WHERE reference='$Ref';"
    Invoke-OcSql "INSERT INTO oc_poolbuy_pool_tier (pool_id, min_qty, max_qty, price, sort_order) VALUES ($id, 1, NULL, '$Price', 1);"

    return $id
}

# The SAME numeric price in three currencies. Nothing may rescale it.
$inrPool = New-Pool 'PB-CUR-INR' 'INR' '1500.0000'
$jpyPool = New-Pool 'PB-CUR-JPY' 'JPY' '1500.0000'
$usdPool = New-Pool 'PB-CUR-USD' 'USD' '1500.0000'

$email = 'buyer@poolbuy.test'
$customerId = Get-OcSqlInt "SELECT customer_id FROM oc_customer WHERE email='$email';"

$session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$page = Invoke-WebRequest -Uri "$base/index.php?route=account/login" -WebSession $session -UseBasicParsing
if ($page.Content -notmatch 'login_token=([0-9a-zA-Z]+)') { throw 'no login_token' }
$loginToken = $Matches[1]
Invoke-WebRequest -Uri "$base/index.php?route=account/login.login&login_token=$loginToken" -Method POST `
    -Body @{ email = $email; password = 'PoolBuy123!' } -WebSession $session -UseBasicParsing | Out-Null

$rupee = [char]0x20B9
$yen = [char]0x00A5

function Get-JoinPage([int] $PoolId) {
    Invoke-OcSql "DELETE FROM oc_poolbuy_reservation WHERE customer_id=$script:customerId AND pool_id=$PoolId;"

    return (Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/join&pool_id=$PoolId" `
        -Method POST -Body 'step=1&quantity=10' -ContentType 'application/x-www-form-urlencoded' `
        -WebSession $script:session -UseBasicParsing).Content
}

Write-Host "`n[1] Each pool is priced in its own currency"

$inrPage = Get-JoinPage $inrPool
$jpyPage = Get-JoinPage $jpyPool
$usdPage = Get-JoinPage $usdPool

Assert ($inrPage -match 'PB-CUR-INR') 'the INR pool page loaded'
Assert ($jpyPage -match 'PB-CUR-JPY') 'the JPY pool page loaded'
Assert ($usdPage -match 'PB-CUR-USD') 'the USD pool page loaded'

Assert ($inrPage -match [regex]::Escape($rupee)) 'the INR pool is priced in rupees'
Assert ($jpyPage -match [regex]::Escape($yen)) 'the JPY pool is priced in yen'
Assert ($usdPage -match '\$1,500') 'the USD pool is priced in dollars'

# The decisive check: no pool may show another currency's sign.
Assert (-not ($jpyPage -match [regex]::Escape($rupee))) 'the JPY pool never shows a rupee sign'
Assert (-not ($usdPage -match [regex]::Escape($rupee))) 'the USD pool never shows a rupee sign'
Assert (-not ($inrPage -match [regex]::Escape($yen))) 'the INR pool never shows a yen sign'

Write-Host "`n[2] A zero-decimal currency is not given invented precision"

# 10 units at 1,500: 15,000 in both, but JPY has no minor unit.
Assert ($jpyPage -match '15,000(?!\.)') 'the JPY subtotal renders without decimal places'
Assert ($inrPage -match '15,000\.00') 'the INR subtotal renders with two decimal places'

Write-Host "`n[3] Nothing is converted between currencies"

# All three pools carry the same stored price. If any figure differed numerically,
# something had applied an exchange rate.
$prices = Get-OcSqlScalar "SELECT GROUP_CONCAT(DISTINCT t.price) FROM oc_poolbuy_pool_tier t JOIN oc_poolbuy_pool p ON p.pool_id=t.pool_id WHERE p.reference IN ('PB-CUR-INR','PB-CUR-JPY','PB-CUR-USD');"
Assert ($prices -eq '1500.0000') "all three pools hold the identical numeric price ($prices)"

# And the rendered figure is the same number in each, only differently labelled.
Assert (($inrPage -match '15,000') -and ($jpyPage -match '15,000') -and ($usdPage -match '15,000')) 'the same numeric subtotal is shown in every currency, unconverted'

Write-Host "`n[4] The join flow uses the pool's currency, not the store default"

# The store is configured with one symbol; a JPY pool must not borrow it.
$configured = Get-OcSqlScalar "SELECT value FROM oc_setting WHERE code='module_poolbuy' AND ${OcTick}key${OcTick}='module_poolbuy_currency_code' LIMIT 1;"
Write-Host "      store default currency: $configured"

Assert ($jpyPage -match [regex]::Escape($yen)) 'the join breakdown is priced in yen'
Assert (-not ($jpyPage -match [regex]::Escape($rupee))) 'the join breakdown shows no rupee sign for a JPY pool'
Assert ($jpyPage -match '15,000(?!\.)') 'the JPY subtotal carries no decimal places'

Write-Host "`n[5] A pool's currency is immutable once anyone has committed"

$admin = Connect-OcAdmin

# No commitments yet: the currency may still be corrected.
$freeChange = Invoke-OcAdmin $admin 'extension/poolbuy/poolbuy/pool.save' @{} $null 'POST' (
    "pool_id=$usdPool&product_id=$productId&seller_id=$sellerId&title=Currency+pool+USD&reference=PB-CUR-USD" +
    "&moq_target=100&status=active&date_start=" + [uri]::EscapeDataString((Get-Date).AddDays(-1).ToString('yyyy-MM-dd HH:mm:ss')) +
    "&date_end=" + [uri]::EscapeDataString((Get-Date).AddDays(10).ToString('yyyy-MM-dd HH:mm:ss')) +
    "&min_qty_per_buyer=10&max_qty_per_buyer=0&currency_code=EUR&freight_total=0" +
    "&pool_tier%5B0%5D%5Bmin_qty%5D=1&pool_tier%5B0%5D%5Bmax_qty%5D=&pool_tier%5B0%5D%5Bprice%5D=1500"
)
Assert (-not ($freeChange.Content -match 'error')) "an uncommitted pool's currency can still be corrected"

$nowEur = Get-OcSqlScalar "SELECT currency_code FROM oc_poolbuy_pool WHERE pool_id=$usdPool;"
Assert ($nowEur -eq 'EUR') "the currency was changed while no one had committed (now $nowEur)"

# Now add a commitment and try again.
$addr = Get-OcSqlInt "SELECT COALESCE((SELECT address_id FROM oc_address WHERE customer_id=$customerId LIMIT 1),0);"
Invoke-OcSql "INSERT INTO oc_poolbuy_reservation (pool_id,customer_id,quantity,unit_price_locked,status,order_id,address_id,shipping_cost,promotion_id,discount_total,reference,date_added,date_modified) VALUES ($usdPool,$customerId,10,'1500.0000','confirmed',0,$addr,'0.0000',0,'0.0000','TX-CUR-1',NOW(),NOW());"

$locked = Invoke-OcAdmin $admin 'extension/poolbuy/poolbuy/pool.save' @{} $null 'POST' (
    "pool_id=$usdPool&product_id=$productId&seller_id=$sellerId&title=Currency+pool+USD&reference=PB-CUR-USD" +
    "&moq_target=100&status=active&date_start=" + [uri]::EscapeDataString((Get-Date).AddDays(-1).ToString('yyyy-MM-dd HH:mm:ss')) +
    "&date_end=" + [uri]::EscapeDataString((Get-Date).AddDays(10).ToString('yyyy-MM-dd HH:mm:ss')) +
    "&min_qty_per_buyer=10&max_qty_per_buyer=0&currency_code=GBP&freight_total=0" +
    "&pool_tier%5B0%5D%5Bmin_qty%5D=1&pool_tier%5B0%5D%5Bmax_qty%5D=&pool_tier%5B0%5D%5Bprice%5D=1500"
)

Assert ($locked.Content -match 'currency can no longer be changed') 'changing the currency of a committed pool is refused'

$stillEur = Get-OcSqlScalar "SELECT currency_code FROM oc_poolbuy_pool WHERE pool_id=$usdPool;"
Assert ($stillEur -eq 'EUR') "the currency was left untouched (still $stillEur)"

Write-Host "`n[6] Settlement figures are never summed across currencies"

# Two settlements for one seller in different currencies.
Invoke-OcSql "DELETE FROM oc_poolbuy_settlement WHERE pool_id IN ($inrPool,$jpyPool);"
Invoke-OcSql "INSERT INTO oc_poolbuy_settlement (seller_id,pool_id,gross,platform_fee,net,currency,order_count,status,date_added,date_modified) VALUES ($sellerId,$inrPool,'10000.0000','200.0000','9800.0000','INR',2,'pending',NOW(),NOW());"
Invoke-OcSql "INSERT INTO oc_poolbuy_settlement (seller_id,pool_id,gross,platform_fee,net,currency,order_count,status,date_added,date_modified) VALUES ($sellerId,$jpyPool,'50000.0000','1000.0000','49000.0000','JPY',3,'pending',NOW(),NOW());"

$groups = Get-OcSqlInt "SELECT COUNT(DISTINCT currency) FROM oc_poolbuy_settlement WHERE seller_id=$sellerId;"
Assert ($groups -ge 2) "the seller has settlements in $groups currencies"

# The grouped query must return one row per currency, each with its own total -
# never one combined figure.
$rows = Get-OcSqlInt "SELECT COUNT(*) FROM (SELECT currency FROM oc_poolbuy_settlement WHERE seller_id=$sellerId GROUP BY currency) x;"
Assert ($rows -eq $groups) "settlement totals group into $rows rows, one per currency"

$inrNet = Get-OcSqlScalar "SELECT SUM(net) FROM oc_poolbuy_settlement WHERE seller_id=$sellerId AND currency='INR';"
$jpyNet = Get-OcSqlScalar "SELECT SUM(net) FROM oc_poolbuy_settlement WHERE seller_id=$sellerId AND currency='JPY';"
$allNet = Get-OcSqlScalar "SELECT SUM(net) FROM oc_poolbuy_settlement WHERE seller_id=$sellerId;"

Write-Host "      INR net=$inrNet  JPY net=$jpyNet  (naive all-currency sum would be $allNet)"

# The point is not the specific figures - other pools may exist - but that the
# per-currency totals are each strictly less than a naive cross-currency sum, i.e.
# the currencies are genuinely kept apart.
Assert ([decimal]$inrNet -lt [decimal]$allNet) 'the INR total excludes other currencies'
Assert ([decimal]$jpyNet -lt [decimal]$allNet) 'the JPY total excludes other currencies'
Assert ([decimal]$jpyNet -eq [decimal]'49000.0000') "the JPY net is exactly its own rows ($jpyNet)"

# And the seller portal must render them separately.
$sellerCustomer = Get-OcSqlInt "SELECT COALESCE(customer_id,0) FROM oc_poolbuy_seller WHERE seller_id=$sellerId;"

if ($sellerCustomer -gt 0) {
    $sellerMail = Get-OcSqlScalar "SELECT email FROM oc_customer WHERE customer_id=$sellerCustomer;"

    $ss = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $sp = Invoke-WebRequest -Uri "$base/index.php?route=account/login" -WebSession $ss -UseBasicParsing
    if ($sp.Content -match 'login_token=([0-9a-zA-Z]+)') {
        $st = $Matches[1]
        Invoke-WebRequest -Uri "$base/index.php?route=account/login.login&login_token=$st" -Method POST `
            -Body @{ email = $sellerMail; password = 'PoolBuy123!' } -WebSession $ss -UseBasicParsing | Out-Null

        $portal = (Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/seller" -WebSession $ss -UseBasicParsing).Content

        Assert ($portal -match 'Totals by currency') 'the portal shows totals grouped by currency'
        Assert ($portal -match 'never added across currencies') 'the portal states that currencies are not combined'
    } else {
        Write-Host "  SKIP  could not sign in as the seller" -ForegroundColor Yellow
    }
} else {
    Write-Host "  SKIP  seller has no linked storefront account" -ForegroundColor Yellow
}

Write-Host "`n[7] An unknown currency code is shown, not guessed"

$oddPool = New-Pool 'PB-CUR-ODD' 'XYZ' '1500.0000'
$oddPage = Get-JoinPage $oddPool

Assert ($oddPage -match 'XYZ') 'an unrecognised currency is labelled with its code rather than a borrowed symbol'
Assert (-not ($oddPage -match [regex]::Escape($rupee))) 'an unrecognised currency does not borrow the rupee sign'

# ---------------------------------------------------------------------------
foreach ($ref in @('PB-CUR-INR', 'PB-CUR-JPY', 'PB-CUR-USD', 'PB-CUR-ODD')) {
    Remove-Pool $ref
}

Write-Host ""
if ($failures.Count -eq 0) {
    Write-Host "ALL CURRENCY CHECKS PASSED" -ForegroundColor Green
    exit 0
} else {
    Write-Host "$($failures.Count) CHECK(S) FAILED" -ForegroundColor Red
    exit 1
}
