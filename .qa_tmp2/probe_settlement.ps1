$ErrorActionPreference = 'Stop'
. "$PSScriptRoot\..\tools\poolbuy\admin.ps1"

$base = 'http://localhost'

docker compose exec -T opencart sh -lc "rm -f /var/www/html/system/storage/logs/error.log" | Out-Null

# Re-grant permissions for the new route by reinstalling the extension permissions.
$admin = Connect-OcAdmin
Invoke-OcAdmin $admin 'extension/module.install' @{ extension = 'poolbuy'; code = 'poolbuy' } | Out-Null

$admin2 = Connect-OcAdmin
$page = Invoke-OcAdmin $admin2 'extension/poolbuy/poolbuy/settlement'
Write-Output ("SETTLEMENT page HTTP " + $page.StatusCode + " len=" + $page.Content.Length)
Write-Output ("  heading            : " + ($page.Content -match 'Payments'))
Write-Output ("  payments table     : " + ($page.Content -match 'Gateway reference'))
Write-Output ("  settlements table  : " + ($page.Content -match 'Net payable'))
Write-Output ("  per-currency note  : " + ($page.Content -match 'never added across currencies'))
Write-Output ("  permission error   : " + ($page.Content -match 'do not have permission'))

$settings = Invoke-OcAdmin $admin2 'extension/poolbuy/module/poolbuy'
Write-Output ("  linked from settings: " + ($settings.Content -match 'poolbuy/poolbuy/settlement'))

Write-Output "=== settlement rows in db ==="
@(docker compose exec -T -e MYSQL_PWD=opencart mysql mysql -uroot opencart -N -B -e "SELECT CONCAT_WS(' | ', settlement_id, seller_id, status, currency, net) FROM oc_poolbuy_settlement ORDER BY settlement_id LIMIT 5;")

$pending = Get-OcSqlInt "SELECT COALESCE(MIN(settlement_id),0) FROM oc_poolbuy_settlement WHERE status='pending';"
Write-Output ("first pending settlement = " + $pending)

if ($pending -gt 0) {
    $r = Invoke-OcAdmin $admin2 'extension/poolbuy/poolbuy/settlement.pay' @{ settlement_id = $pending }
    Write-Output ("  pay response : " + $r.Content)
    Write-Output ("  status now   : " + (Get-OcSqlScalar "SELECT status FROM oc_poolbuy_settlement WHERE settlement_id=$pending;"))
    Write-Output ("  audit event  : " + (Get-OcSqlInt "SELECT COUNT(*) FROM oc_poolbuy_pool_event WHERE type='settlement_paid';"))
    $r2 = Invoke-OcAdmin $admin2 'extension/poolbuy/poolbuy/settlement.pay' @{ settlement_id = $pending }
    Write-Output ("  double pay   : " + $r2.Content)
}

Write-Output "=== anonymous access ==="
try {
    $anon = Invoke-WebRequest -Uri "$base/admin/index.php?route=extension/poolbuy/poolbuy/settlement" -UseBasicParsing -MaximumRedirection 5
    Write-Output ("  anon sees login : " + ($anon.Content -match 'name="username"' -or $anon.Content -match 'route=common/login'))
    Write-Output ("  anon leaks data : " + ($anon.Content -match 'Net payable'))
} catch { Write-Output ("  anon ERR " + $_.Exception.Message) }

Write-Output "--- error log ---"
$log = docker compose exec -T opencart sh -lc "cat /var/www/html/system/storage/logs/error.log" 2>$null
if ([string]::IsNullOrWhiteSpace($log)) { Write-Output "empty" } else { Write-Output ($log | Select-Object -First 25) }
