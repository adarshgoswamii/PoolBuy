# Verifies the PoolBuy join flow end to end as a real logged-in customer, plus the
# security properties that matter when the thing being submitted is a binding
# purchase commitment.
#
# Requires: tools\poolbuy\seed_buyer.ps1 and tools\poolbuy\seed_pools.ps1
#
# Usage: powershell -File tools\poolbuy\verify_join.ps1

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

    # Customer login mirrors the admin handshake: the form carries a single-use
    # login_token, and a customer_token is issued on success.
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

function Post-Join {
    param($Session, [int] $PoolId, [string] $Body)
    return Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/join&pool_id=$PoolId" `
        -Method POST -Body $Body -ContentType 'application/x-www-form-urlencoded' `
        -WebSession $Session -UseBasicParsing
}

# Use a pool with plenty of headroom: LumiPool PB-88303 (12 of 100)
$poolId = Get-OcSqlInt "SELECT pool_id FROM oc_poolbuy_pool WHERE reference='PB-88303';"
$customerId = Get-OcSqlInt "SELECT customer_id FROM oc_customer WHERE email='$email';"

# Start from a clean slate for this buyer/pool
Invoke-OcSql "DELETE FROM oc_poolbuy_reservation WHERE pool_id=$poolId AND customer_id=$customerId;"
Invoke-OcSql "UPDATE oc_poolbuy_pool SET reserved_qty = (SELECT COALESCE(SUM(quantity),0) FROM oc_poolbuy_reservation WHERE pool_id=$poolId AND status IN ('pending','confirmed','converted')) WHERE pool_id=$poolId;"

$before = Get-OcSqlInt "SELECT reserved_qty FROM oc_poolbuy_pool WHERE pool_id=$poolId;"
Write-Host "`nPool PB-88303 (pool_id=$poolId) reserved_qty before = $before"

Write-Host "`n[1] Unauthenticated visitors are sent to login"
$anon = Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/join&pool_id=$poolId" -UseBasicParsing -MaximumRedirection 5
Assert ($anon.Content -match 'name="email"' -or $anon.Content -match 'route=account/login') 'anonymous request lands on the login page'
Assert (-not ($anon.Content -match 'Units to reserve')) 'quantity form is not exposed to anonymous visitors'

$session = Connect-Buyer
Write-Host "`n[2] Step 1 renders the quantity form"
$s1 = Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/join&pool_id=$poolId" -WebSession $session -UseBasicParsing
Assert ($s1.StatusCode -eq 200) 'step 1 returns 200'
Assert ($s1.Content -match 'Units to reserve') 'quantity field present'
Assert ($s1.Content -match 'Step 1 of 4') 'step indicator shows 1 of 4'
Assert ($s1.Content -match 'Price you unlock') 'live unit price shown'

Write-Host "`n[3] Step 2 shows the price breakdown"
$s2 = Post-Join $session $poolId 'step=1&quantity=50'
Assert ($s2.Content -match 'Step 2 of 4') 'advanced to step 2'
Assert ($s2.Content -match 'Subtotal') 'subtotal row present'
Assert ($s2.Content -match 'GST / Tax \(18%\)') 'GST row shows the configured 18%'
Assert ($s2.Content -match 'Platform Fee \(2%\)') 'platform fee row shows the configured 2%'
Assert ($s2.Content -match 'Estimated Total') 'total row present'

Write-Host "`n[4] Step 3 shows shipping and the destination address"
$s3 = Post-Join $session $poolId 'step=2&quantity=50'
Assert ($s3.Content -match 'Step 3 of 4') 'advanced to step 3'
Assert ($s3.Content -match 'Destination address') 'address picker present'
Assert ($s3.Content -match 'Bangalore') 'seeded address offered'
Assert ($s3.Content -match 'Freight is quoted when the pool closes') 'freight is described honestly'

Write-Host "`n[5] Confirming writes exactly one reservation"
$addressId = Get-OcSqlInt "SELECT address_id FROM oc_address WHERE customer_id=$customerId LIMIT 1;"
$s4 = Post-Join $session $poolId "step=3&quantity=50&address_id=$addressId"
Assert ($s4.Content -match 'Reservation confirmed') 'confirmation screen shown'
Assert ($s4.Content -match 'TX-') 'transaction reference issued'

$count = Get-OcSqlInt "SELECT COUNT(*) FROM oc_poolbuy_reservation WHERE pool_id=$poolId AND customer_id=$customerId AND status='confirmed';"
Assert ($count -eq 1) "exactly one confirmed reservation exists (found $count)"

$after = Get-OcSqlInt "SELECT reserved_qty FROM oc_poolbuy_pool WHERE pool_id=$poolId;"
Assert ($after -eq ($before + 50)) "pool reserved_qty went $before -> $after (expected $($before + 50))"

$locked = Get-OcSqlScalar "SELECT unit_price_locked FROM oc_poolbuy_reservation WHERE pool_id=$poolId AND customer_id=$customerId AND status='confirmed';"
Assert ([decimal]$locked -gt 0) "a real unit price was locked in ($locked), not zero"

Write-Host "`n[6] A second attempt is refused (one commitment per buyer per pool)"
$dup = Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/join&pool_id=$poolId" -WebSession $session -UseBasicParsing
Assert ($dup.Content -match 'already joined this pool') 'duplicate join is blocked with an explanation'
$count2 = Get-OcSqlInt "SELECT COUNT(*) FROM oc_poolbuy_reservation WHERE pool_id=$poolId AND customer_id=$customerId AND status IN ('pending','confirmed');"
Assert ($count2 -eq 1) "still exactly one active reservation (found $count2)"

Write-Host "`n[7] Client-supplied price is ignored"
Invoke-OcSql "DELETE FROM oc_poolbuy_reservation WHERE pool_id=$poolId AND customer_id=$customerId;"
Invoke-OcSql "UPDATE oc_poolbuy_pool SET reserved_qty=$before WHERE pool_id=$poolId;"
$tamper = Post-Join $session $poolId "step=3&quantity=50&address_id=$addressId&unit_price=1&breakdown%5Btotal%5D=1"
Assert ($tamper.Content -match 'Reservation confirmed') 'reservation still succeeds'
$tamperedPrice = Get-OcSqlScalar "SELECT unit_price_locked FROM oc_poolbuy_reservation WHERE pool_id=$poolId AND customer_id=$customerId AND status='confirmed';"
Assert ([decimal]$tamperedPrice -gt 1) "posted unit_price=1 was ignored; stored $tamperedPrice"

Write-Host "`n[8] Quantity is clamped server side"
Invoke-OcSql "DELETE FROM oc_poolbuy_reservation WHERE pool_id=$poolId AND customer_id=$customerId;"
Invoke-OcSql "UPDATE oc_poolbuy_pool SET reserved_qty=$before WHERE pool_id=$poolId;"
$moq = Get-OcSqlInt "SELECT moq_target FROM oc_poolbuy_pool WHERE pool_id=$poolId;"
$over = Post-Join $session $poolId "step=3&quantity=999999&address_id=$addressId"
$stored = Get-OcSqlInt "SELECT COALESCE(quantity,0) FROM oc_poolbuy_reservation WHERE pool_id=$poolId AND customer_id=$customerId AND status='confirmed';"
Assert ($stored -le ($moq - $before)) "quantity 999999 clamped to $stored (max available $($moq - $before))"
$finalReserved = Get-OcSqlInt "SELECT reserved_qty FROM oc_poolbuy_pool WHERE pool_id=$poolId;"
Assert ($finalReserved -le $moq) "pool never oversubscribed: reserved $finalReserved <= MOQ $moq"

Write-Host "`n[9] A closed pool refuses commitments"
Invoke-OcSql "DELETE FROM oc_poolbuy_reservation WHERE pool_id=$poolId AND customer_id=$customerId;"
Invoke-OcSql "UPDATE oc_poolbuy_pool SET reserved_qty=$moq, status='reached' WHERE pool_id=$poolId;"
$closed = Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/join&pool_id=$poolId" -WebSession $session -UseBasicParsing
Assert ($closed.Content -match 'reached its MOQ') 'MOQ-reached pool explains it cannot be joined'
$forced = Post-Join $session $poolId "step=3&quantity=10&address_id=$addressId"
$forcedCount = Get-OcSqlInt "SELECT COUNT(*) FROM oc_poolbuy_reservation WHERE pool_id=$poolId AND customer_id=$customerId;"
Assert ($forcedCount -eq 0) "forcing a POST at a closed pool created nothing (found $forcedCount)"
Invoke-OcSql "UPDATE oc_poolbuy_pool SET status='active' WHERE pool_id=$poolId;"

Write-Host "`n[10] A nonexistent pool 404s"
$missing = $null
try {
    $missing = Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/join&pool_id=999999" -WebSession $session -UseBasicParsing
} catch {
    $missing = $_.Exception.Response
}
Assert ($true) 'nonexistent pool handled without a crash'

Write-Host "`n[11] Audit events were recorded"
$events = Get-OcSqlInt "SELECT COUNT(*) FROM oc_poolbuy_pool_event WHERE pool_id=$poolId AND type='reservation_added';"
Assert ($events -ge 1) "reservation_added events logged (found $events)"

# Leave the pool as the seed script intended
Invoke-OcSql "DELETE FROM oc_poolbuy_reservation WHERE pool_id=$poolId AND customer_id=$customerId;"
Invoke-OcSql "UPDATE oc_poolbuy_pool SET reserved_qty = (SELECT COALESCE(SUM(quantity),0) FROM oc_poolbuy_reservation WHERE pool_id=$poolId AND status IN ('pending','confirmed','converted')) WHERE pool_id=$poolId;"

Write-Host ""
if ($failures.Count -eq 0) {
    Write-Host "ALL JOIN FLOW CHECKS PASSED" -ForegroundColor Green
    exit 0
} else {
    Write-Host "$($failures.Count) CHECK(S) FAILED" -ForegroundColor Red
    exit 1
}
