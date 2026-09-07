# Creates (or reuses) a fresh demo buyer account that can log in and join pools.
#
# This is a clean, empty account (no pre-seeded commitments) intended for a live
# demo or a first-time walkthrough of the buyer journey. It registers through
# OpenCart's own endpoint so the password is hashed correctly, then approves the
# account and gives it a default delivery address so the join flow's shipping
# step works end to end.
#
# Usage: powershell -File tools\poolbuy\seed_demo_buyer.ps1

$ErrorActionPreference = 'Stop'
. "$PSScriptRoot\admin.ps1"

$email    = 'demo@poolbuy.test'
$password = 'PoolBuy123!'
$base     = 'http://localhost'

$existing = Get-OcSqlInt "SELECT COUNT(*) FROM oc_customer WHERE email='$email';"

if ($existing -gt 0) {
    Write-Host "Customer $email already exists." -ForegroundColor DarkGray
} else {
    $session = New-Object Microsoft.PowerShell.Commands.WebRequestSession

    $page = Invoke-WebRequest -Uri "$base/index.php?route=account/register" -WebSession $session -UseBasicParsing

    if ($page.Content -notmatch 'register_token=([0-9a-zA-Z]+)') {
        Write-Host 'Could not read register_token from the registration form.' -ForegroundColor Red
        exit 1
    }
    $registerToken = $Matches[1]

    $body = @{
        customer_group_id = '1'
        firstname         = 'Demo'
        lastname          = 'Buyer'
        email             = $email
        telephone         = '9800000000'
        password          = $password
        confirm           = $password
        agree             = '1'
    }

    $response = Invoke-WebRequest -Uri "$base/index.php?route=account/register.register&register_token=$registerToken" `
        -Method POST -Body $body -WebSession $session -UseBasicParsing

    if ($response.Content -notmatch '"success"|redirect') {
        Write-Host "Registration response: $($response.Content)" -ForegroundColor Yellow
    }

    $created = Get-OcSqlInt "SELECT COUNT(*) FROM oc_customer WHERE email='$email';"

    if ($created -eq 0) {
        Write-Host "Registration failed." -ForegroundColor Red
        exit 1
    }

    Write-Host "Registered $email" -ForegroundColor Green
}

# Approve and enable, so login is not blocked by store settings
Invoke-OcSql "UPDATE oc_customer SET status=1, safe=1 WHERE email='$email';"

$customerId = Get-OcSqlInt "SELECT customer_id FROM oc_customer WHERE email='$email';"

# A delivery address is required by the join flow's shipping step
$addressCount = Get-OcSqlInt "SELECT COUNT(*) FROM oc_address WHERE customer_id=$customerId;"

if ($addressCount -eq 0) {
    Invoke-OcSql "INSERT INTO oc_address (customer_id, firstname, lastname, company, address_1, address_2, city, postcode, country_id, zone_id, custom_field, ``default``) VALUES ($customerId, 'Demo', 'Buyer', 'Demo Trading Co', 'Unit 7, Trade Centre', '', 'Mumbai', '400001', 99, 1490, '', 1);"
    $addressId = Get-OcSqlInt "SELECT address_id FROM oc_address WHERE customer_id=$customerId ORDER BY address_id DESC LIMIT 1;"
    Write-Host "Added default delivery address (address_id=$addressId)" -ForegroundColor Green
} else {
    Invoke-OcSql "UPDATE oc_address SET ``default`` = 1 WHERE customer_id=$customerId ORDER BY address_id LIMIT 1;"
    Write-Host "Address already present." -ForegroundColor DarkGray
}

Write-Host "`nDemo buyer ready:" -ForegroundColor Cyan
Write-Host "  email    : $email"
Write-Host "  password : $password"
Write-Host "  customer_id: $customerId"
