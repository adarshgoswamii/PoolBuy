# Seeds a complete, demonstrable PoolBuy environment from a bare OpenCart install.
#
# Idempotent: safe to run repeatedly. Each step either creates what is missing or
# leaves the existing record alone.
#
# Usage: powershell -File tools\poolbuy\seed_all.ps1

$ErrorActionPreference = 'Stop'
. "$PSScriptRoot\admin.ps1"

$base = 'http://localhost'
$root = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent

function Step([string] $Message) { Write-Host "`n=== $Message ===" -ForegroundColor Cyan }

Step 'Registering the extension package'
Get-Content "$PSScriptRoot\register_extension.sql" -Raw |
    docker compose exec -T -e MYSQL_PWD=opencart mysql mysql -uroot opencart
Write-Host '  oc_extension_install row present'

Step 'Installing the PoolBuy extension (schema + permissions)'
$admin = Connect-OcAdmin
Invoke-OcAdmin $admin 'extension/module.install' @{ extension = 'poolbuy'; code = 'poolbuy' } | Out-Null
$tables = Get-OcSqlInt "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='opencart' AND table_name LIKE 'oc_poolbuy%';"
Write-Host "  $tables PoolBuy tables present"
if ($tables -lt 6) { throw "Expected 6 PoolBuy tables, found $tables" }

Step 'Configuring PoolBuy settings'
# save() replaces the whole setting group, so every field must be supplied.
# Reuse an existing token if one is present; otherwise use a stable, fixed token so the
# manual HTTP trigger URL keeps working across (destructive) reseeds. A fixed dev token
# is fine here because the security guard still rejects blank and mismatched tokens; the
# value is only "secret" relative to anonymous callers, and this is a seeded dev/demo env.
$cronToken = Get-OcSqlScalar "SELECT value FROM oc_setting WHERE code='module_poolbuy' AND $([char]96)key$([char]96)='module_poolbuy_cron_token';"
if ([string]::IsNullOrWhiteSpace($cronToken)) { $cronToken = 'poolbuy-dev-cron-token' }

Invoke-OcAdmin $admin 'extension/poolbuy/module/poolbuy.save' @{} @{
    module_poolbuy_status          = '1'
    module_poolbuy_currency_code   = 'INR'
    module_poolbuy_currency_symbol = [char]0x20B9
    module_poolbuy_gst_rate        = '18'
    module_poolbuy_platform_fee    = '2'
    module_poolbuy_pool_duration   = '7'
    module_poolbuy_retro_pricing   = '1'
    module_poolbuy_cron_token      = $cronToken
} | Out-Null

$status = Get-OcSqlScalar "SELECT value FROM oc_setting WHERE code='module_poolbuy' AND $([char]96)key$([char]96)='module_poolbuy_status';"
Write-Host "  module_poolbuy_status = $status (MUST be 1 or the storefront silently hides PoolBuy)"
if ($status -ne '1') { throw 'PoolBuy is not enabled; the landing page and widget will not render.' }
Write-Host "  cron token = $cronToken"

Step 'Registering the lifecycle cron job'
Get-Content "$PSScriptRoot\register_cron.sql" -Raw |
    docker compose exec -T -e MYSQL_PWD=opencart mysql mysql -uroot opencart
Write-Host "  oc_cron rows for poolbuy: $(Get-OcSqlInt "SELECT COUNT(*) FROM oc_cron WHERE code='poolbuy';")"

Step 'Seeding sellers'
& "$PSScriptRoot\seed_sellers.ps1"

Step 'Seeding pools, tiers and reservations'
& "$PSScriptRoot\seed_pools.ps1"

Step 'Seeding the test buyer'
& "$PSScriptRoot\seed_buyer.ps1"

Step 'Seeding the fresh demo buyer'
& "$PSScriptRoot\seed_demo_buyer.ps1"

Step 'Linking seller portal accounts'
# Two real storefront logins mapped to two different sellers, so tenancy isolation
# can be demonstrated (and tested) end to end.
foreach ($pair in @(@{ Email = 'sellera@poolbuy.test'; SellerId = 1 }, @{ Email = 'sellerb@poolbuy.test'; SellerId = 3 })) {
    $id = Get-OcSqlInt "SELECT customer_id FROM oc_customer WHERE email='$($pair.Email)';"

    if ($id -eq 0) {
        $s = New-Object Microsoft.PowerShell.Commands.WebRequestSession
        $page = Invoke-WebRequest -Uri "$base/index.php?route=account/register" -WebSession $s -UseBasicParsing
        if ($page.Content -notmatch 'register_token=([0-9a-zA-Z]+)') { throw 'no register_token' }
        Invoke-WebRequest -Uri "$base/index.php?route=account/register.register&register_token=$($Matches[1])" `
            -Method POST -WebSession $s -UseBasicParsing -Body @{
                customer_group_id = '1'; firstname = 'Seller'; lastname = 'Account'; email = $pair.Email
                telephone = '9900000000'; password = 'PoolBuy123!'; confirm = 'PoolBuy123!'; agree = '1'
            } | Out-Null
        $id = Get-OcSqlInt "SELECT customer_id FROM oc_customer WHERE email='$($pair.Email)';"
    }

    Invoke-OcSql "UPDATE oc_customer SET status=1 WHERE customer_id=$id;"
    Invoke-OcSql "UPDATE oc_poolbuy_seller SET customer_id=$id, status=1 WHERE seller_id=$($pair.SellerId);"
    Write-Host "  $($pair.Email) (customer $id) -> seller_id $($pair.SellerId)"
}

Step 'Assigning products to sellers'
# The seller portal can only attach a pool to a product the seller owns, so every
# product that already carries a pool is mapped to that pool's seller. Without this
# a freshly seeded seller portal would have an empty product list.
Invoke-OcSql @'
INSERT INTO oc_poolbuy_product_seller (product_id, seller_id)
SELECT p.product_id, MIN(p.seller_id) FROM oc_poolbuy_pool p
GROUP BY p.product_id
ON DUPLICATE KEY UPDATE seller_id = VALUES(seller_id);
'@
Write-Host "  mapped products: $(Get-OcSqlInt 'SELECT COUNT(*) FROM oc_poolbuy_product_seller;')"

Step 'Summary'
Write-Host ("  sellers      : {0}" -f (Get-OcSqlInt 'SELECT COUNT(*) FROM oc_poolbuy_seller;'))
Write-Host ("  pools        : {0}" -f (Get-OcSqlInt 'SELECT COUNT(*) FROM oc_poolbuy_pool;'))
Write-Host ("  tiers        : {0}" -f (Get-OcSqlInt 'SELECT COUNT(*) FROM oc_poolbuy_pool_tier;'))
Write-Host ("  reservations : {0}" -f (Get-OcSqlInt 'SELECT COUNT(*) FROM oc_poolbuy_reservation;'))
Write-Host ("  products     : {0}" -f (Get-OcSqlInt 'SELECT COUNT(*) FROM oc_poolbuy_product_seller;'))

Write-Host "`nSEED COMPLETE" -ForegroundColor Green
Write-Host @"

  Storefront      $base/
  Marketplace     $base/index.php?route=extension/poolbuy/pool
  Admin           $base/admin/            admin / admin
  Adminer         $base`:8080

  Buyer           buyer@poolbuy.test      PoolBuy123!
  Demo buyer      demo@poolbuy.test       PoolBuy123!   (clean account, no commitments)
  Seller A        sellera@poolbuy.test    PoolBuy123!   (Titan Pumps)
  Seller B        sellerb@poolbuy.test    PoolBuy123!   (ChemBulk)

  Run the lifecycle by hand:
    $base/index.php?route=extension/poolbuy/cron/poolbuy.run&token=$cronToken
"@
