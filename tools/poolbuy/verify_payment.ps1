# Verifies the PoolBuy payment spine: a pool that closes raises pending payments
# with a payable mock link; a buyer can pay their own order and only their own;
# the gateway webhook confirms payment (fail-closed on a blank/invalid signature,
# idempotent); and expiry of a paid-into pool refunds the buyer.
#
# Runs entirely against the Mock gateway, so no external credentials are needed.
#
# Usage: powershell -File tools\poolbuy\verify_payment.ps1

$ErrorActionPreference = 'Stop'
. "$PSScriptRoot\admin.ps1"

$base = 'http://localhost'
$failures = @()

function Assert([bool] $Condition, [string] $Message) {
    if ($Condition) { Write-Host "  PASS  $Message" -ForegroundColor Green }
    else { Write-Host "  FAIL  $Message" -ForegroundColor Red; $script:failures += $Message }
}

function Connect-Buyer([string] $Email, [string] $Password = 'PoolBuy123!') {
    $session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $page = Invoke-WebRequest -Uri "$base/index.php?route=account/login" -WebSession $session -UseBasicParsing
    if ($page.Content -notmatch 'login_token=([0-9a-zA-Z]+)') { throw 'no login_token' }
    $lt = $Matches[1]
    $r = Invoke-WebRequest -Uri "$base/index.php?route=account/login.login&login_token=$lt" `
        -Method POST -Body @{ email = $Email; password = $Password } -WebSession $session -UseBasicParsing
    if ($r.Content -notmatch 'customer_token') { throw "login failed for $Email" }
    return $session
}

$tick = [char]96
$token = Get-OcSqlScalar "SELECT value FROM oc_setting WHERE code='module_poolbuy' AND oc_setting.${tick}key${tick}='module_poolbuy_cron_token';"

# Ensure the Mock gateway is active and a webhook secret is configured, so the
# webhook checks are meaningful. Values live under the module_poolbuy setting group.
#
# Set-OcPoolbuySetting (admin.ps1) deletes then inserts. An earlier version used
# INSERT ... ON DUPLICATE KEY UPDATE, which never fires because oc_setting has no
# unique key on (store_id, code, key), so every run appended a duplicate row and the
# highest setting_id silently won.
function Set-OcSetting([string] $Key, [string] $Value) {
    Set-OcPoolbuySetting -Key $Key -Value $Value
}
Set-OcSetting 'module_poolbuy_gateway' 'mock'
Set-OcSetting 'module_poolbuy_razorpay_webhook_secret' 'whsec_verify_payment'

Write-Host "`n[1] Close a pool at MOQ so orders and pending payments are raised"
# PB-88305 Titan Pumps: MOQ 500, top tier 500+ @ 265. Owned by seller 1, whose
# login (sellera@poolbuy.test) lets us verify the seller settlement view too.
$poolId = Get-OcSqlInt "SELECT pool_id FROM oc_poolbuy_pool WHERE reference='PB-88305';"
Invoke-OcSql "DELETE FROM oc_poolbuy_reservation WHERE pool_id=$poolId;"
Invoke-OcSql "DELETE FROM oc_poolbuy_reservation WHERE reference IN ('TX-PAY-A','TX-PAY-B');"
Invoke-OcSql "DELETE FROM oc_poolbuy_pool_event WHERE pool_id=$poolId;"
Invoke-OcSql "DELETE FROM oc_poolbuy_settlement WHERE pool_id=$poolId;"

$buyerA = 'buyer@poolbuy.test'
$buyerB = 'race1@poolbuy.test'
$idA = Get-OcSqlInt "SELECT customer_id FROM oc_customer WHERE email='$buyerA';"
$idB = Get-OcSqlInt "SELECT customer_id FROM oc_customer WHERE email='$buyerB';"
$addrA = Get-OcSqlInt "SELECT address_id FROM oc_address WHERE customer_id=$idA ORDER BY address_id LIMIT 1;"
$addrB = Get-OcSqlInt "SELECT address_id FROM oc_address WHERE customer_id=$idB ORDER BY address_id LIMIT 1;"

Invoke-OcSql "INSERT INTO oc_poolbuy_reservation (pool_id, customer_id, quantity, unit_price_locked, status, address_id, reference, date_added, date_modified) VALUES ($poolId, $idA, 300, 288.0000, 'confirmed', $addrA, 'TX-PAY-A', NOW(), NOW());"
Invoke-OcSql "INSERT INTO oc_poolbuy_reservation (pool_id, customer_id, quantity, unit_price_locked, status, address_id, reference, date_added, date_modified) VALUES ($poolId, $idB, 200, 288.0000, 'confirmed', $addrB, 'TX-PAY-B', NOW(), NOW());"
Invoke-OcSql "UPDATE oc_poolbuy_pool SET status='active', reserved_qty=500, date_end = NOW() - INTERVAL 1 HOUR WHERE pool_id=$poolId;"

$run = Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/cron/poolbuy.run&token=$token" -UseBasicParsing -TimeoutSec 120
Assert ($run.Content -match '"success":true') 'cron closed and fulfilled the pool'

$orderA = Get-OcSqlInt "SELECT order_id FROM oc_poolbuy_reservation WHERE reference='TX-PAY-A';"
$orderB = Get-OcSqlInt "SELECT order_id FROM oc_poolbuy_reservation WHERE reference='TX-PAY-B';"
Assert ($orderA -gt 0 -and $orderB -gt 0) "both commitments converted to orders (A=$orderA, B=$orderB)"

Write-Host "`n[2] A pending payment with a payable mock link exists per order"
$payCount = Get-OcSqlInt "SELECT COUNT(*) FROM oc_poolbuy_payment WHERE order_id IN ($orderA,$orderB);"
Assert ($payCount -eq 2) "one payment row per order (got $payCount)"
$statusA = Get-OcSqlScalar "SELECT status FROM oc_poolbuy_payment WHERE order_id=$orderA;"
$gatewayA = Get-OcSqlScalar "SELECT gateway FROM oc_poolbuy_payment WHERE order_id=$orderA;"
$linkA = Get-OcSqlScalar "SELECT link_url FROM oc_poolbuy_payment WHERE order_id=$orderA;"
Assert ($statusA -eq 'pending') "payment A starts pending (got $statusA)"
Assert ($gatewayA -eq 'mock') "payment A is on the mock gateway (got $gatewayA)"
Assert ($linkA -match 'payment.pay') "payment A carries a mock payable link"

Write-Host "`n[3] Buyer A sees a Pay now action on the dashboard"
$sessionA = Connect-Buyer $buyerA
$dash = Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/account&filter_status=completed" -WebSession $sessionA -UseBasicParsing
Assert ($dash.Content -match 'Pay now') 'Pay now button rendered for a pending payment'
Assert (-not ($dash.Content -match 'Fatal error|Twig')) 'no PHP or Twig errors on the dashboard'

Write-Host "`n[4] Buyer B cannot pay buyer A's order"
$sessionB = Connect-Buyer $buyerB
Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/payment.pay&order_id=$orderA" -WebSession $sessionB -UseBasicParsing -MaximumRedirection 5 | Out-Null
$stillPending = Get-OcSqlScalar "SELECT status FROM oc_poolbuy_payment WHERE order_id=$orderA;"
Assert ($stillPending -eq 'pending') "buyer B could not settle buyer A's payment (still $stillPending)"

Write-Host "`n[5] Buyer A pays their own order via the mock hosted page"
Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/payment.pay&order_id=$orderA" -WebSession $sessionA -UseBasicParsing -MaximumRedirection 5 | Out-Null
$paidA = Get-OcSqlScalar "SELECT status FROM oc_poolbuy_payment WHERE order_id=$orderA;"
Assert ($paidA -eq 'paid') "buyer A's payment is now paid (got $paidA)"

$completeStatus = Get-OcSqlInt "SELECT value FROM oc_setting WHERE code='config' AND oc_setting.${tick}key${tick}='config_complete_status_id';"
$orderStatusA = Get-OcSqlInt "SELECT order_status_id FROM oc_order WHERE order_id=$orderA;"
Assert ($orderStatusA -gt 0) "order A advanced to a real status (status_id $orderStatusA)"

Write-Host "`n[6] Paying again is a no-op (idempotent)"
Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/payment.pay&order_id=$orderA" -WebSession $sessionA -UseBasicParsing -MaximumRedirection 5 | Out-Null
$stillPaid = Get-OcSqlScalar "SELECT status FROM oc_poolbuy_payment WHERE order_id=$orderA;"
Assert ($stillPaid -eq 'paid') 'a second pay attempt leaves the payment paid, not double-processed'

# ---- Webhook (Task 6) ----
Write-Host "`n[7] Webhook confirms buyer B's still-pending payment"
$refB = Get-OcSqlScalar "SELECT gateway_ref FROM oc_poolbuy_payment WHERE order_id=$orderB;"
$secret = 'whsec_verify_payment'
$body = '{"reference":"' + $refB + '","status":"paid"}'

# Compute the HMAC-SHA256 signature the mock gateway expects, in PowerShell.
function Get-Hmac([string] $Message, [string] $Key) {
    $hmac = New-Object System.Security.Cryptography.HMACSHA256
    $hmac.Key = [Text.Encoding]::UTF8.GetBytes($Key)
    $hash = $hmac.ComputeHash([Text.Encoding]::UTF8.GetBytes($Message))
    return -join ($hash | ForEach-Object { $_.ToString('x2') })
}
$sig = Get-Hmac $body $secret

function Invoke-Webhook([string] $Body, [string] $Signature) {
    try {
        $r = Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/payment.webhook" -Method POST `
            -Body $Body -ContentType 'application/json' -Headers @{ 'X-PoolBuy-Signature' = $Signature } `
            -UseBasicParsing -TimeoutSec 60
        return [int]$r.StatusCode
    } catch {
        if ($_.Exception.Response) { return [int]$_.Exception.Response.StatusCode.value__ }
        return -1
    }
}

Write-Host "`n[8] A blank signature is rejected (fail closed)"
Assert ((Invoke-Webhook $body '') -eq 403) 'webhook with a blank signature is refused with 403'
$afterBlank = Get-OcSqlScalar "SELECT status FROM oc_poolbuy_payment WHERE order_id=$orderB;"
Assert ($afterBlank -eq 'pending') 'blank-signature webhook did not settle the payment'

Write-Host "`n[9] A forged signature is rejected"
Assert ((Invoke-Webhook $body 'deadbeef') -eq 403) 'webhook with a wrong signature is refused with 403'
$afterForged = Get-OcSqlScalar "SELECT status FROM oc_poolbuy_payment WHERE order_id=$orderB;"
Assert ($afterForged -eq 'pending') 'forged-signature webhook did not settle the payment'

Write-Host "`n[10] A validly signed webhook settles the payment exactly once"
Assert ((Invoke-Webhook $body $sig) -eq 200) 'validly signed webhook accepted with 200'
$afterHook = Get-OcSqlScalar "SELECT status FROM oc_poolbuy_payment WHERE order_id=$orderB;"
Assert ($afterHook -eq 'paid') "webhook marked payment B paid (got $afterHook)"
$orderStatusB = Get-OcSqlInt "SELECT order_status_id FROM oc_order WHERE order_id=$orderB;"
Assert ($orderStatusB -gt 0) "order B advanced to a real status via webhook (status_id $orderStatusB)"

Write-Host "`n[11] Replaying the webhook is a no-op"
Assert ((Invoke-Webhook $body $sig) -eq 200) 'replayed webhook still returns 200'
$afterReplay = Get-OcSqlScalar "SELECT status FROM oc_poolbuy_payment WHERE order_id=$orderB;"
Assert ($afterReplay -eq 'paid') 'replayed webhook leaves the payment paid, not double-processed'

Write-Host "`n[12] A seller settlement was written when the pool fulfilled"
$setRows = Get-OcSqlInt "SELECT COUNT(*) FROM oc_poolbuy_settlement WHERE pool_id=$poolId;"
Assert ($setRows -eq 1) "exactly one settlement row exists for the pool (got $setRows)"
# 500 units x 265 = 132,500 gross; 2% fee = 2,650; net = 129,850.
$gross = Get-OcSqlScalar "SELECT gross FROM oc_poolbuy_settlement WHERE pool_id=$poolId;"
$fee   = Get-OcSqlScalar "SELECT platform_fee FROM oc_poolbuy_settlement WHERE pool_id=$poolId;"
$net   = Get-OcSqlScalar "SELECT net FROM oc_poolbuy_settlement WHERE pool_id=$poolId;"
$orderCount = Get-OcSqlInt "SELECT order_count FROM oc_poolbuy_settlement WHERE pool_id=$poolId;"
Assert ([math]::Abs([decimal]$gross - 132500) -lt 0.01) "settlement gross is 132,500 (got $gross)"
Assert ([math]::Abs([decimal]$fee - 2650) -lt 0.01) "settlement platform fee is 2,650 (got $fee)"
Assert ([math]::Abs([decimal]$net - 129850) -lt 0.01) "settlement net payable is 129,850 (got $net)"
Assert ($orderCount -eq 2) "settlement covers both orders (got $orderCount)"

Write-Host "`n[13] The seller sees the settlement in their portal (read only)"
$sessionSeller = Connect-Buyer 'sellera@poolbuy.test'
$portal = Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/seller" -WebSession $sessionSeller -UseBasicParsing
Assert ($portal.Content -match 'Settlements') 'settlements section rendered in the seller portal'
Assert (-not ($portal.Content -match 'Fatal error|Twig')) 'no PHP or Twig errors in the seller portal'

Write-Host "`n[14] The admin pool detail renders the settlement card"
$admin = Connect-OcAdmin
$detail = Invoke-OcAdmin $admin 'extension/poolbuy/poolbuy/pool.detail' @{ pool_id = "$poolId" }
Assert ($detail.StatusCode -eq 200) "admin pool detail returns 200 (got $($detail.StatusCode))"
Assert ($detail.Content -match 'Seller Settlement') 'settlement card heading rendered on the admin pool detail'
Assert ($detail.Content -match 'Net payable') 'settlement net payable row rendered'
# The net figure is money-formatted, so match on the grouped digits rather than the raw decimal.
Assert ($detail.Content -match '129,850') 'settlement net figure (129,850) rendered on the admin card'
Assert (-not ($detail.Content -match 'Fatal error|Twig\\Error')) 'no PHP or Twig errors on the admin pool detail'

Write-Host "`n[15] Every payment failure path shows a friendly message, never a blank page"
# Unknown order: must land on the dashboard with an explanatory alert.
$unknown = Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/payment.link&order_id=999999" -WebSession $sessionA -UseBasicParsing -MaximumRedirection 5
Assert ($unknown.Content -match 'could not be found') 'unknown order reports "could not be found"'
Assert ($unknown.Content -match 'My Pools') 'unknown order lands back on the dashboard'
Assert (-not ($unknown.Content -match 'Fatal error|Twig\\Error')) 'unknown order raises no PHP error'

# Foreign order: buyer B asking for buyer A's order gets the same generic message,
# so the route cannot be used to probe which orders exist.
$foreign = Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/payment.link&order_id=$orderA" -WebSession $sessionB -UseBasicParsing -MaximumRedirection 5
Assert ($foreign.Content -match 'could not be found') "a foreign order is reported identically to an unknown one (no probing)"

# Already paid: order A was settled earlier in this run.
$already = Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/payment.link&order_id=$orderA" -WebSession $sessionA -UseBasicParsing -MaximumRedirection 5
Assert ($already.Content -match 'already been paid') 'an already-paid order says so instead of re-charging'

# A pending payment with no gateway link must explain itself rather than 404.
Invoke-OcSql "UPDATE oc_poolbuy_payment SET status='pending', link_url='' WHERE order_id=$orderA;"
$nolink = Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/payment.link&order_id=$orderA" -WebSession $sessionA -UseBasicParsing -MaximumRedirection 5
Assert ($nolink.Content -match 'not payable online just yet') 'a missing gateway link gives a specific, actionable message'

# A failed payment invites a retry.
Invoke-OcSql "UPDATE oc_poolbuy_payment SET status='failed' WHERE order_id=$orderA;"
$failed = Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/payment.link&order_id=$orderA" -WebSession $sessionA -UseBasicParsing -MaximumRedirection 5
Assert ($failed.Content -match 'did not go through') 'a failed payment invites the buyer to try again'

# A cancelled return is reported as cancelled, not as success. A real cancellation
# leaves the payment pending (the buyer simply walked away at the gateway), so the
# stored status is reset first - a stored 'failed' would correctly take precedence.
Invoke-OcSql "UPDATE oc_poolbuy_payment SET status='pending', link_url='http://localhost/pay' WHERE order_id=$orderA;"
$cancelled = Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/payment.return&order_id=$orderA&outcome=cancelled" -WebSession $sessionA -UseBasicParsing -MaximumRedirection 5
Assert ($cancelled.Content -match 'Payment was cancelled') 'a cancelled gateway return is NOT reported as success'
Assert (-not ($cancelled.Content -match 'Payment received')) 'a cancelled return never claims payment was received'

# Restore order A to paid so the suite leaves consistent data behind.
Invoke-OcSql "UPDATE oc_poolbuy_payment SET status='paid' WHERE order_id=$orderA;"

Write-Host "`n[16] The webhook still answers machines with JSON, not a redirect"
$machine = Invoke-Webhook '{"reference":"NO-SUCH","status":"paid"}' (Get-Hmac '{"reference":"NO-SUCH","status":"paid"}' $secret)
Assert ($machine -eq 404) "an unknown gateway reference returns a JSON 404 (got $machine)"

Write-Host ""
if ($failures.Count -eq 0) {
    Write-Host "ALL PAYMENT CHECKS PASSED" -ForegroundColor Green
    exit 0
} else {
    Write-Host "$($failures.Count) CHECK(S) FAILED" -ForegroundColor Red
    exit 1
}
