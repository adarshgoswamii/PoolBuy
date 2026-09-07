# Verifies the PoolBuy buyer dashboard, including that one buyer can never see or
# affect another buyer's commitments.
#
# Usage: powershell -File tools\poolbuy\verify_account.ps1

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
    if ($r.Content -notmatch 'customer_token') { throw "login failed for $Email : $($r.Content)" }
    return $session
}

$buyerA = 'buyer@poolbuy.test'
$buyerB = 'race1@poolbuy.test'
$idA = Get-OcSqlInt "SELECT customer_id FROM oc_customer WHERE email='$buyerA';"
$idB = Get-OcSqlInt "SELECT customer_id FROM oc_customer WHERE email='$buyerB';"

Write-Host "`n[1] Dashboard requires a login"
$anon = Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/account" -UseBasicParsing -MaximumRedirection 5
Assert ($anon.Content -match 'name="email"' -or $anon.Content -match 'route=account/login') 'anonymous visitor is sent to login'
Assert (-not ($anon.Content -match 'Your commitment')) 'no commitment data leaks to anonymous visitors'

# Give buyer A a fresh commitment on a pool with headroom
$poolId = Get-OcSqlInt "SELECT pool_id FROM oc_poolbuy_pool WHERE reference='PB-88307';"
$addressA = Get-OcSqlInt "SELECT address_id FROM oc_address WHERE customer_id=$idA LIMIT 1;"
Invoke-OcSql "DELETE FROM oc_poolbuy_reservation WHERE pool_id=$poolId AND customer_id=$idA;"
Invoke-OcSql "UPDATE oc_poolbuy_pool SET reserved_qty = (SELECT COALESCE(SUM(quantity),0) FROM oc_poolbuy_reservation WHERE pool_id=$poolId AND status IN ('pending','confirmed','converted')) WHERE pool_id=$poolId;"

$sessionA = Connect-Buyer $buyerA
Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/join&pool_id=$poolId" -Method POST `
    -Body "step=3&quantity=20&address_id=$addressA" -ContentType 'application/x-www-form-urlencoded' `
    -WebSession $sessionA -UseBasicParsing | Out-Null

$refA = Get-OcSqlScalar "SELECT reference FROM oc_poolbuy_reservation WHERE pool_id=$poolId AND customer_id=$idA AND status='confirmed';"
Write-Host "`nBuyer A commitment reference: $refA"

Write-Host "`n[2] Dashboard renders buyer A's own commitment"
$dash = Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/account" -WebSession $sessionA -UseBasicParsing
Assert ($dash.StatusCode -eq 200) 'dashboard returns 200'
Assert ($dash.Content -match 'My Pools') 'heading present'
Assert ($dash.Content -match 'Pools joined \(active\)') 'active pools stat present'
Assert ($dash.Content -match 'Pools completed') 'completed stat present'
Assert ($dash.Content -match 'Committed this year') 'spend stat present'
Assert ($dash.Content -match [regex]::Escape($refA)) 'own commitment reference listed'
Assert ($dash.Content -match 'Copper Conductors') 'pool title listed'
Assert ($dash.Content -match 'role="progressbar"') 'progress bar rendered'
Assert ($dash.Content -match 'Withdraw') 'withdraw offered while the pool is filling'
Assert (-not ($dash.Content -match 'Fatal error|Twig\\Error')) 'no PHP or Twig errors'

Write-Host "`n[3] Tabs filter correctly"
$completed = Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/account&filter_status=completed" -WebSession $sessionA -UseBasicParsing
Assert ($completed.StatusCode -eq 200) 'completed tab returns 200'
Assert (-not ($completed.Content -match [regex]::Escape($refA))) 'an active commitment does not appear under Completed'
Assert ($completed.Content -match 'No completed pools yet') 'completed tab shows its empty state'

Write-Host "`n[4] Buyer B cannot see buyer A's commitment"
$sessionB = Connect-Buyer $buyerB
$dashB = Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/account" -WebSession $sessionB -UseBasicParsing
Assert ($dashB.StatusCode -eq 200) "buyer B dashboard returns 200"
Assert (-not ($dashB.Content -match [regex]::Escape($refA))) "buyer A's reference is NOT visible to buyer B"

Write-Host "`n[5] Buyer B cannot withdraw buyer A's commitment"
$resIdA = Get-OcSqlInt "SELECT reservation_id FROM oc_poolbuy_reservation WHERE reference='$refA';"
Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/account.cancel&reservation_id=$resIdA" `
    -WebSession $sessionB -UseBasicParsing -MaximumRedirection 5 | Out-Null
$stillConfirmed = Get-OcSqlScalar "SELECT status FROM oc_poolbuy_reservation WHERE reservation_id=$resIdA;"
Assert ($stillConfirmed -eq 'confirmed') "buyer A's commitment is untouched (status $stillConfirmed)"

Write-Host "`n[6] CSV export returns only the caller's own rows"
$csv = Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/account.export" -WebSession $sessionA -UseBasicParsing
Assert ($csv.Headers['Content-Type'] -match 'text/csv') 'export served as text/csv'
Assert ("$($csv.Content)" -match 'Reference') 'CSV header row present'
Assert ("$($csv.Content)" -match [regex]::Escape($refA)) "buyer A's own row present in the CSV"

$csvB = Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/account.export" -WebSession $sessionB -UseBasicParsing
Assert (-not ("$($csvB.Content)" -match [regex]::Escape($refA))) "buyer A's row absent from buyer B's CSV"

Write-Host "`n[7] Buyer A can withdraw their own commitment"
Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/account.cancel&reservation_id=$resIdA" `
    -WebSession $sessionA -UseBasicParsing -MaximumRedirection 5 | Out-Null
$afterCancel = Get-OcSqlScalar "SELECT status FROM oc_poolbuy_reservation WHERE reservation_id=$resIdA;"
Assert ($afterCancel -eq 'cancelled') "own commitment withdrawn (status $afterCancel)"

$recomputed = Get-OcSqlInt "SELECT reserved_qty FROM oc_poolbuy_pool WHERE pool_id=$poolId;"
$actual = Get-OcSqlInt "SELECT COALESCE(SUM(quantity),0) FROM oc_poolbuy_reservation WHERE pool_id=$poolId AND status IN ('pending','confirmed','converted');"
Assert ($recomputed -eq $actual) "pool total recomputed after withdrawal ($recomputed = $actual)"

# Tidy up
Invoke-OcSql "DELETE FROM oc_poolbuy_reservation WHERE pool_id=$poolId AND customer_id=$idA;"
Invoke-OcSql "UPDATE oc_poolbuy_pool SET reserved_qty = (SELECT COALESCE(SUM(quantity),0) FROM oc_poolbuy_reservation WHERE pool_id=$poolId AND status IN ('pending','confirmed','converted')) WHERE pool_id=$poolId;"

Write-Host ""
if ($failures.Count -eq 0) {
    Write-Host "ALL DASHBOARD CHECKS PASSED" -ForegroundColor Green
    exit 0
} else {
    Write-Host "$($failures.Count) CHECK(S) FAILED" -ForegroundColor Red
    exit 1
}
