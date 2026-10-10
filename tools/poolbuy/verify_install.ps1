# Verifies the PoolBuy extension install lifecycle end to end:
#
#   1. reset to a genuinely clean state (tables dropped, oc_extension row and the
#      per-extension user permission removed) so "first install" is really first
#   2. install once  -> every table created, settings seeded
#   3. settings page renders
#   4. uninstall     -> every table dropped, no orphans
#   5. reinstall     -> every table back
#
# Usage: powershell -File tools\poolbuy\verify_install.ps1

$ErrorActionPreference = 'Stop'
. "$PSScriptRoot\admin.ps1"

# Must stay in step with $tables in
# upload/extension/poolbuy/admin/model/module/poolbuy.php, which is the single
# source of truth for the schema. A stale list here silently under-asserts: the
# clean-state reset would leave the unlisted tables behind and the table-count
# checks would compare against the wrong number.
$expected = @(
    'oc_poolbuy_payment',
    'oc_poolbuy_pool',
    'oc_poolbuy_pool_event',
    'oc_poolbuy_pool_tier',
    'oc_poolbuy_product_seller',
    'oc_poolbuy_promotion',
    'oc_poolbuy_promotion_redemption',
    'oc_poolbuy_rate_limit',
    'oc_poolbuy_reservation',
    'oc_poolbuy_review',
    'oc_poolbuy_seller',
    'oc_poolbuy_settlement'
)
$expectedCount = $expected.Count

# Passing the password via MYSQL_PWD rather than -p keeps mysql from writing an
# "insecure password" warning to stderr, which would otherwise trip
# ErrorActionPreference = 'Stop' on every query.
function Invoke-SqlScalar([string] $Sql) {
    $raw = docker compose exec -T -e MYSQL_PWD=opencart mysql mysql -uroot opencart -N -B -e $Sql
    return @($raw)
}

function Get-PoolbuyTables {
    $raw = Invoke-SqlScalar "SELECT table_name FROM information_schema.tables WHERE table_schema='opencart' AND table_name LIKE 'oc_poolbuy%' ORDER BY table_name;"
    return @($raw | Where-Object { $_ -match '^oc_poolbuy' } | ForEach-Object { $_.Trim() })
}

function Invoke-Sql([string] $Sql) {
    docker compose exec -T -e MYSQL_PWD=opencart mysql mysql -uroot opencart -e $Sql | Out-Null
}

$failures = @()

function Assert([bool] $Condition, [string] $Message) {
    if ($Condition) {
        Write-Host "  PASS  $Message" -ForegroundColor Green
    } else {
        Write-Host "  FAIL  $Message" -ForegroundColor Red
        $script:failures += $Message
    }
}

Write-Host "`n[1] Reset to a clean state"
foreach ($t in $expected) { Invoke-Sql "DROP TABLE IF EXISTS ``$t``;" }
# Belt and braces: drop anything matching the prefix that is not in $expected, so
# a table added to the installer but not yet listed here cannot leave the "first
# install" starting from a dirty database.
foreach ($t in (Get-PoolbuyTables)) { Invoke-Sql "DROP TABLE IF EXISTS ``$t``;" }
Invoke-Sql "DELETE FROM oc_extension WHERE extension='poolbuy';"
Invoke-Sql "DELETE FROM oc_setting WHERE code='module_poolbuy';"
# Strip the per-extension permission so the first install genuinely runs without it
Invoke-Sql "UPDATE oc_user_group SET permission = REPLACE(permission, ',\""extension\\\\/poolbuy\\\\/module\\\\/poolbuy\""', '') WHERE user_group_id = 1;"
Assert ((Get-PoolbuyTables).Count -eq 0) "clean state: no poolbuy tables present"

$admin = Connect-OcAdmin
Write-Host "`n[2] Install (single request, from clean state)"
$r = Invoke-OcAdmin $admin 'extension/module.install' @{ extension = 'poolbuy'; code = 'poolbuy' }
Assert ($r.Content -match 'success') "install returned success"

$tables = Get-PoolbuyTables
$missing = @($expected | Where-Object { $_ -notin $tables })
$extra   = @($tables   | Where-Object { $_ -notin $expected })
Assert (($missing.Count -eq 0) -and ($extra.Count -eq 0)) "all $expectedCount tables created on FIRST install (found $($tables.Count); missing: $($missing -join ', '); unexpected: $($extra -join ', '))"

$settingsRaw = Invoke-SqlScalar "SELECT COUNT(*) FROM oc_setting WHERE code='module_poolbuy';"
$settingCount = [int](($settingsRaw | Where-Object { $_ -match '^\d+$' } | Select-Object -First 1))
Assert ($settingCount -ge 8) "default settings seeded ($settingCount rows)"

$genRaw = Invoke-SqlScalar "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='opencart' AND table_name='oc_poolbuy_reservation' AND column_name='active_customer_id' AND extra LIKE '%GENERATED%';"
$genCount = [int](($genRaw | Where-Object { $_ -match '^\d+$' } | Select-Object -First 1))
Assert ($genCount -eq 1) "generated column active_customer_id exists (DB-level duplicate-reservation guard)"

# oc_setting has no unique key on (store_id, code, key), so anything using an upsert
# to write a setting appends a duplicate row instead of updating, and the effective
# configuration silently becomes whichever row sorts last. That once left a stale
# webhook secret behind and broke five payment checks. Guard against a regression.
Assert ((Get-OcDuplicateSettingCount) -eq 0) 'no module_poolbuy setting key has duplicate rows'

Write-Host "`n[3] Settings page renders"
$admin2 = Connect-OcAdmin   # fresh session so the new permission is loaded
$page = Invoke-OcAdmin $admin2 'extension/poolbuy/module/poolbuy'
Assert ($page.StatusCode -eq 200) "settings page returns 200"
Assert ($page.Content -match 'PoolBuy Marketplace Settings') "settings page shows its heading"
Assert ($page.Content -match 'Database schema installed') "settings page reports schema installed"

Write-Host "`n[4] Uninstall"
$r = Invoke-OcAdmin $admin2 'extension/module.uninstall' @{ extension = 'poolbuy'; code = 'poolbuy' }
Assert ($r.Content -match 'success') "uninstall returned success"
$tables = Get-PoolbuyTables
Assert ($tables.Count -eq 0) "all tables dropped, no orphans (found $($tables.Count))"

Write-Host "`n[5] Reinstall"
$r = Invoke-OcAdmin $admin2 'extension/module.install' @{ extension = 'poolbuy'; code = 'poolbuy' }
Assert ($r.Content -match 'success') "reinstall returned success"
$tables = Get-PoolbuyTables
$missing = @($expected | Where-Object { $_ -notin $tables })
$extra   = @($tables   | Where-Object { $_ -notin $expected })
Assert (($missing.Count -eq 0) -and ($extra.Count -eq 0)) "all $expectedCount tables recreated (found $($tables.Count); missing: $($missing -join ', '); unexpected: $($extra -join ', '))"

Write-Host ""
if ($failures.Count -eq 0) {
    Write-Host "ALL INSTALL LIFECYCLE CHECKS PASSED" -ForegroundColor Green
    exit 0
} else {
    Write-Host "$($failures.Count) CHECK(S) FAILED" -ForegroundColor Red
    exit 1
}
