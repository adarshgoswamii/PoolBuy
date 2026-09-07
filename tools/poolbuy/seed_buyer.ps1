# Creates (or reuses) a real storefront customer that can actually log in, plus a
# delivery address, so the join flow can be exercised end to end.
#
# The synthetic buyers created by seed_pools.ps1 have a dummy password hash and
# cannot authenticate; this one registers through OpenCart's own endpoint so the
# password is hashed correctly.
#
# Usage: powershell -File tools\poolbuy\seed_buyer.ps1
# Outputs the credentials to use.

$ErrorActionPreference = 'Stop'
. "$PSScriptRoot\admin.ps1"

$email    = 'buyer@poolbuy.test'
$password = 'PoolBuy123!'
$base     = 'http://localhost'

$existing = Get-OcSqlInt "SELECT COUNT(*) FROM oc_customer WHERE email='$email';"

if ($existing -gt 0) {
    Write-Host "Customer $email already exists." -ForegroundColor DarkGray
} else {
    $session = New-Object Microsoft.PowerShell.Commands.WebRequestSession

    # OpenCart issues a single-use register_token on the form and refuses any POST
    # without it, so the page must be fetched first and the token extracted.
    $page = Invoke-WebRequest -Uri "$base/index.php?route=account/register" -WebSession $session -UseBasicParsing

    if ($page.Content -notmatch 'register_token=([0-9a-zA-Z]+)') {
        Write-Host 'Could not read register_token from the registration form.' -ForegroundColor Red
        exit 1
    }
    $registerToken = $Matches[1]

    $body = @{
        customer_group_id = '1'
        firstname         = 'Priya'
        lastname          = 'Sharma'
        email             = $email
        telephone         = '9900112233'
        password          = $password
        confirm           = $password
        agree             = '1'
    }

    # The endpoint is account/register.register (not .save) in OpenCart 4.1
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
    Invoke-OcSql "INSERT INTO oc_address (customer_id, firstname, lastname, company, address_1, address_2, city, postcode, country_id, zone_id, custom_field, ``default``) VALUES ($customerId, 'Priya', 'Sharma', 'Sharma Procurement Pvt Ltd', 'Warehouse Alpha, Industrial Zone 4', '', 'Bangalore', '560100', 99, 1490, '', 1);"
    $addressId = Get-OcSqlInt "SELECT address_id FROM oc_address WHERE customer_id=$customerId ORDER BY address_id DESC LIMIT 1;"
    Write-Host "Added default delivery address (address_id=$addressId)" -ForegroundColor Green
} else {
    # In OpenCart 4.1 the default address is flagged on oc_address, not oc_customer
    Invoke-OcSql "UPDATE oc_address SET ``default`` = 1 WHERE customer_id=$customerId ORDER BY address_id LIMIT 1;"
    Write-Host "Address already present." -ForegroundColor DarkGray
}

Write-Host "`nBuyer ready:" -ForegroundColor Cyan
Write-Host "  email    : $email"
Write-Host "  password : $password"
Write-Host "  customer_id: $customerId"
docker compose exec -T -e MYSQL_PWD=opencart mysql mysql -uroot opencart -e `
    "SELECT c.customer_id, c.firstname, c.email, c.status, a.address_id, a.city FROM oc_customer c LEFT JOIN oc_address a ON a.customer_id=c.customer_id WHERE c.email='$email';"
