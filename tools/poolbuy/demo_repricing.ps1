# Live demonstration of the PoolBuy headline mechanic: collective volume unlocks a
# better price for EVERYONE, including the buyers who committed early at a worse one.
#
# Uses pool 5 (PB-88303), which sits at 30 of 100 units with three buyers each locked
# at the worst tier. A fourth buyer takes it to MOQ, crossing two tier boundaries.
#
# Usage: powershell -File tools\poolbuy\demo_repricing.ps1

$ErrorActionPreference = 'Stop'
. "$PSScriptRoot\admin.ps1"

$base     = 'http://localhost'
$poolId   = 5
$email    = 'demo@poolbuy.test'
$password = 'PoolBuy123!'
$rupee    = [char]0x20B9

function Money([object] $v) { return $rupee + ([decimal]$v).ToString('N2') }

function Show-Pool([string] $Caption) {
    $row = Get-OcSqlScalar "SELECT CONCAT_WS('|', reference, reserved_qty, moq_target, status) FROM oc_poolbuy_pool WHERE pool_id=$poolId;"
    $p = $row -split '\|'
    Write-Host ""
    Write-Host "  $Caption" -ForegroundColor Cyan
    Write-Host ("    pool $($p[0])  filled $($p[1]) of $($p[2]) units  status=$($p[3])")

    $rows = @(docker compose exec -T -e MYSQL_PWD=opencart mysql mysql -uroot opencart -N -B -e `
        "SELECT CONCAT_WS('|', r.reservation_id, IFNULL(c.email,'-'), r.quantity, r.unit_price_locked, r.status, IFNULL(r.order_id,0)) FROM oc_poolbuy_reservation r LEFT JOIN oc_customer c ON c.customer_id=r.customer_id WHERE r.pool_id=$poolId AND r.status IN ('pending','confirmed','converted') ORDER BY r.reservation_id;")

    foreach ($line in $rows) {
        if (-not $line.Trim()) { continue }
        $f = $line -split '\|'
        $price = if ([decimal]$f[3] -eq 0) { 'priced at close' } else { Money $f[3] }
        $order = if ([int]$f[5] -gt 0) { "  order #$($f[5])" } else { '' }
        Write-Host ("      buyer {0,-26} {1,4} units  @ {2,-16} {3}{4}" -f $f[1], $f[2], $price, $f[4], $order)
    }
}

Write-Host "`n=============================================================="
Write-Host " PoolBuy demo - collective volume repricing"
Write-Host "=============================================================="

Write-Host "`n  Price ladder (resolved against the pool's TOTAL volume):" -ForegroundColor Cyan
$tiers = @(docker compose exec -T -e MYSQL_PWD=opencart mysql mysql -uroot opencart -N -B -e `
    "SELECT CONCAT_WS('|', min_qty, IFNULL(max_qty,'open'), price) FROM oc_poolbuy_pool_tier WHERE pool_id=$poolId ORDER BY min_qty;")
foreach ($t in $tiers) {
    if (-not $t.Trim()) { continue }
    $f = $t -split '\|'
    Write-Host ("      {0,4} to {1,-6} units   {2}" -f $f[0], $f[1], (Money $f[2]))
}

Show-Pool 'BEFORE - three buyers committed early, at the worst tier'

# ---------------------------------------------------------------- join to MOQ
$reserved = Get-OcSqlInt "SELECT reserved_qty FROM oc_poolbuy_pool WHERE pool_id=$poolId;"
$moq      = Get-OcSqlInt "SELECT moq_target FROM oc_poolbuy_pool WHERE pool_id=$poolId;"
$need     = $moq - $reserved

Write-Host "`n  A fourth buyer ($email) commits the remaining $need units..." -ForegroundColor Yellow

$session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$page = Invoke-WebRequest -Uri "$base/index.php?route=account/login" -WebSession $session -UseBasicParsing
if ($page.Content -notmatch 'login_token=([0-9a-zA-Z]+)') { throw 'no login_token' }
$r = Invoke-WebRequest -Uri "$base/index.php?route=account/login.login&login_token=$($Matches[1])" `
    -Method POST -Body @{ email = $email; password = $password } -WebSession $session -UseBasicParsing
if ($r.Content -notmatch 'customer_token') { throw "login failed for $email" }

$customerId = Get-OcSqlInt "SELECT customer_id FROM oc_customer WHERE email='$email';"
$addressId  = Get-OcSqlInt "SELECT COALESCE(MIN(address_id),0) FROM oc_address WHERE customer_id=$customerId;"

function Post-Join([string] $Body) {
    return Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/join&pool_id=$poolId" `
        -Method POST -Body $Body -ContentType 'application/x-www-form-urlencoded' `
        -WebSession $session -UseBasicParsing
}

# The join flow is a server-rendered 4 step page: quantity -> breakdown -> address -> confirm.
Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/join&pool_id=$poolId" -WebSession $session -UseBasicParsing | Out-Null
Post-Join "step=1&quantity=$need" | Out-Null
Post-Join "step=2&quantity=$need" | Out-Null
$done = Post-Join "step=3&quantity=$need&address_id=$addressId"

if ($done.Content -match 'Reservation confirmed') {
    Write-Host "    reservation confirmed through the real 4 step join flow" -ForegroundColor Green
} else {
    Write-Host "    join did not confirm - showing state anyway" -ForegroundColor Red
}

Show-Pool 'AFTER JOINING - pool is now full, but nothing has been charged yet'

# ---------------------------------------------------------------- run lifecycle
$token = Get-OcSqlScalar "SELECT value FROM oc_setting WHERE code='module_poolbuy' AND $([char]96)key$([char]96)='module_poolbuy_cron_token';"
Write-Host "`n  Running the lifecycle cron (this is what the hourly job does)..." -ForegroundColor Yellow
$cron = Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/cron/poolbuy.run&token=$token" -UseBasicParsing
Write-Host ("    cron HTTP " + $cron.StatusCode)

Show-Pool 'AFTER MOQ - every buyer repriced to the best tier the pool unlocked'

# ---------------------------------------------------------------- the point
$best = Get-OcSqlScalar "SELECT MIN(price) FROM oc_poolbuy_pool_tier WHERE pool_id=$poolId;"
$worst = Get-OcSqlScalar "SELECT MAX(price) FROM oc_poolbuy_pool_tier WHERE pool_id=$poolId;"
$saving = [decimal]$worst - [decimal]$best

Write-Host ""
Write-Host "  The point:" -ForegroundColor Cyan
Write-Host ("    the three early buyers each committed at $(Money $worst) and now pay $(Money $best)")
Write-Host ("    that is $(Money $saving) per unit less than they agreed to, without asking for anything")
Write-Host ("    nobody was charged while the pool was still filling")
Write-Host ""
