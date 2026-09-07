# Proves that concurrent commitments cannot oversubscribe a pool.
#
# The pool is left with exactly 20 units of headroom, then six buyers each try to
# take 10 units simultaneously. At most two can legitimately succeed. If the
# locking in Reservation::addReservation were wrong, more would get through and
# the pool would exceed its MOQ - which would mean promising a manufacturer more
# units than the deal allows.
#
# Usage: powershell -File tools\poolbuy\verify_race.ps1

$ErrorActionPreference = 'Stop'
. "$PSScriptRoot\admin.ps1"

$base = 'http://localhost'
$buyers = 6
$each = 10
$headroom = 20

$poolId = Get-OcSqlInt "SELECT pool_id FROM oc_poolbuy_pool WHERE reference='PB-88303';"
$moq = Get-OcSqlInt "SELECT moq_target FROM oc_poolbuy_pool WHERE pool_id=$poolId;"
$fill = $moq - $headroom

# Reset the pool to a known state with exactly $headroom units available
Invoke-OcSql "DELETE FROM oc_poolbuy_reservation WHERE pool_id=$poolId;"
$filler = Get-OcSqlInt "SELECT customer_id FROM oc_customer WHERE email='poolbuyer0@example.com';"
Invoke-OcSql "INSERT INTO oc_poolbuy_reservation (pool_id, customer_id, quantity, unit_price_locked, status, reference, date_added, date_modified) VALUES ($poolId, $filler, $fill, 0, 'confirmed', 'TX-RACEFILL', NOW(), NOW());"
Invoke-OcSql "UPDATE oc_poolbuy_pool SET reserved_qty=$fill, status='active' WHERE pool_id=$poolId;"

Write-Host "Pool ${poolId}: MOQ $moq, pre-filled $fill, so exactly $headroom units are available."
Write-Host "$buyers buyers will each attempt $each units simultaneously (demand $($buyers * $each))."

# Ensure the racing buyers exist, are enabled, and have an address
$emails = @()

for ($i = 1; $i -le $buyers; $i++) {
    $email = "race$i@poolbuy.test"
    $emails += $email

    $id = Get-OcSqlInt "SELECT customer_id FROM oc_customer WHERE email='$email';"

    if ($id -eq 0) {
        $s = New-Object Microsoft.PowerShell.Commands.WebRequestSession
        $page = Invoke-WebRequest -Uri "$base/index.php?route=account/register" -WebSession $s -UseBasicParsing

        if ($page.Content -notmatch 'register_token=([0-9a-zA-Z]+)') { throw 'no register_token' }
        $rt = $Matches[1]

        $body = @{
            customer_group_id = '1'; firstname = "Race$i"; lastname = 'Buyer'; email = $email
            telephone = '9900000000'; password = 'PoolBuy123!'; confirm = 'PoolBuy123!'; agree = '1'
        }

        Invoke-WebRequest -Uri "$base/index.php?route=account/register.register&register_token=$rt" `
            -Method POST -Body $body -WebSession $s -UseBasicParsing | Out-Null

        $id = Get-OcSqlInt "SELECT customer_id FROM oc_customer WHERE email='$email';"
    }

    Invoke-OcSql "UPDATE oc_customer SET status=1 WHERE customer_id=$id;"

    $hasAddress = Get-OcSqlInt "SELECT COUNT(*) FROM oc_address WHERE customer_id=$id;"

    if ($hasAddress -eq 0) {
        Invoke-OcSql "INSERT INTO oc_address (customer_id, firstname, lastname, company, address_1, address_2, city, postcode, country_id, zone_id, custom_field, ``default``) VALUES ($id,'Race','Buyer','','Industrial Zone 4','','Bangalore','560100',99,1490,'',1);"
    }
}

# The address picker only appears on step 3, and these jobs post straight to the
# commit step, so each buyer's own address id is resolved here and passed in.
$targets = @()

foreach ($email in $emails) {
    $id = Get-OcSqlInt "SELECT customer_id FROM oc_customer WHERE email='$email';"
    $addressId = Get-OcSqlInt "SELECT address_id FROM oc_address WHERE customer_id=$id ORDER BY address_id LIMIT 1;"

    $targets += [pscustomobject]@{ Email = $email; AddressId = $addressId }
}

Write-Host "Racing buyers ready.`n"

$work = {
    param($email, $poolId, $each, $addressId)

    $base = 'http://localhost'
    $s = New-Object Microsoft.PowerShell.Commands.WebRequestSession

    $page = Invoke-WebRequest -Uri "$base/index.php?route=account/login" -WebSession $s -UseBasicParsing
    if ($page.Content -notmatch 'login_token=([0-9a-zA-Z]+)') { return "$email LOGIN_FAIL" }
    $lt = $Matches[1]

    Invoke-WebRequest -Uri "$base/index.php?route=account/login.login&login_token=$lt" `
        -Method POST -Body @{ email = $email; password = 'PoolBuy123!' } -WebSession $s -UseBasicParsing | Out-Null

    $r = Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/join&pool_id=$poolId" `
        -Method POST -ContentType 'application/x-www-form-urlencoded' `
        -Body "step=3&quantity=$each&address_id=$addressId" -WebSession $s -UseBasicParsing

    if ($r.Content -match 'Reservation confirmed') { return "$email CONFIRMED" }
    if ($r.Content -match 'no longer available|pool is full|were taken|is full') { return "$email REFUSED_CAPACITY" }
    if ($r.Content -match 'Select a destination|Add a delivery address') { return "$email REFUSED_ADDRESS" }
    return "$email REFUSED_OTHER"
}

$jobs = foreach ($target in $targets) { Start-Job -ScriptBlock $work -ArgumentList $target.Email, $poolId, $each, $target.AddressId }

$jobs | Wait-Job -Timeout 120 | Out-Null

$confirmed = 0
foreach ($job in $jobs) {
    $out = Receive-Job $job
    Write-Host "  $out"
    if ("$out" -match 'CONFIRMED') { $confirmed++ }
}
$jobs | Remove-Job -Force

$reserved = Get-OcSqlInt "SELECT reserved_qty FROM oc_poolbuy_pool WHERE pool_id=$poolId;"
$actual = Get-OcSqlInt "SELECT COALESCE(SUM(quantity),0) FROM oc_poolbuy_reservation WHERE pool_id=$poolId AND status IN ('pending','confirmed','converted');"

Write-Host "`nConfirmed: $confirmed of $buyers"
Write-Host "Pool reserved_qty ${reserved}   (sum of reservation rows: $actual)   MOQ: $moq"

$ok = $true

if ($reserved -gt $moq) { Write-Host "  FAIL  pool OVERSUBSCRIBED ($reserved > $moq)" -ForegroundColor Red; $ok = $false }
else { Write-Host "  PASS  pool not oversubscribed ($reserved <= $moq)" -ForegroundColor Green }

if ($actual -ne $reserved) { Write-Host "  FAIL  cached reserved_qty drifted from the rows ($reserved vs $actual)" -ForegroundColor Red; $ok = $false }
else { Write-Host "  PASS  cached total matches the reservation rows" -ForegroundColor Green }

$maxWinners = [math]::Floor($headroom / $each)
if ($confirmed -gt $maxWinners) { Write-Host "  FAIL  $confirmed buyers succeeded but only $maxWinners could fit" -ForegroundColor Red; $ok = $false }
else { Write-Host "  PASS  at most $maxWinners buyers could fit and $confirmed succeeded" -ForegroundColor Green }

# Restore the pool to its seeded state
Invoke-OcSql "DELETE FROM oc_poolbuy_reservation WHERE pool_id=$poolId;"
$b0 = Get-OcSqlInt "SELECT customer_id FROM oc_customer WHERE email='poolbuyer0@example.com';"
Invoke-OcSql "INSERT INTO oc_poolbuy_reservation (pool_id, customer_id, quantity, unit_price_locked, status, reference, date_added, date_modified) VALUES ($poolId, $b0, 12, 0, 'confirmed', 'TX-88303-0', NOW(), NOW());"
Invoke-OcSql "UPDATE oc_poolbuy_pool SET reserved_qty=12 WHERE pool_id=$poolId;"
Write-Host "`nPool restored to its seeded 12 of $moq."

if ($ok) { exit 0 } else { exit 1 }
