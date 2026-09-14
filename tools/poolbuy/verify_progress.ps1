# Verifies the real-time pool progress endpoint and its client hooks.
#
# The endpoint is progressive enhancement over server-rendered figures, so the
# checks below assert three things: it reports live values correctly, it never
# exposes a pool the public may not see, and it leaks no buyer data.
#
# Usage: powershell -File tools\poolbuy\verify_progress.ps1

$ErrorActionPreference = 'Stop'
. "$PSScriptRoot\admin.ps1"

$base = 'http://localhost'
$route = "$base/index.php?route=extension/poolbuy/pool.progress"
$failures = @()

function Assert([bool] $Condition, [string] $Message) {
    if ($Condition) { Write-Host "  PASS  $Message" -ForegroundColor Green }
    else { Write-Host "  FAIL  $Message" -ForegroundColor Red; $script:failures += $Message }
}

function Get-Progress([string] $PoolIds) {
    $r = Invoke-WebRequest -Uri "$route&pool_id=$PoolIds" -UseBasicParsing -TimeoutSec 30
    return ($r.Content | ConvertFrom-Json)
}

Write-Host "`n[1] Endpoint reports live figures for an active pool"
$poolId = Get-OcSqlInt "SELECT pool_id FROM oc_poolbuy_pool WHERE reference='PB-99201';"
$reserved = Get-OcSqlInt "SELECT reserved_qty FROM oc_poolbuy_pool WHERE pool_id=$poolId;"
$moq = Get-OcSqlInt "SELECT moq_target FROM oc_poolbuy_pool WHERE pool_id=$poolId;"

$json = Get-Progress "$poolId"
Assert ($json.pools.Count -eq 1) "one pool returned (got $($json.pools.Count))"

$p = $json.pools[0]
Assert ([int]$p.pool_id -eq $poolId) "pool_id echoed correctly ($($p.pool_id))"
Assert ([int]$p.reserved_qty -eq $reserved) "reserved_qty matches the database ($($p.reserved_qty) = $reserved)"
Assert ([int]$p.moq_target -eq $moq) "moq_target matches the database ($($p.moq_target) = $moq)"

# fill must equal reserved/moq as a percentage, capped at 100.
$expectedFill = [math]::Round([math]::Min(100, ($reserved / $moq) * 100), 2)
Assert ([math]::Abs([double]$p.fill - $expectedFill) -lt 0.02) "fill percentage is computed correctly ($($p.fill) ~ $expectedFill)"
Assert ([int]$p.remaining -eq [math]::Max(0, $moq - $reserved)) "remaining units correct ($($p.remaining))"

Write-Host "`n[2] Progress reflects a real change in commitments"
$before = [int](Get-Progress "$poolId").pools[0].reserved_qty
Invoke-OcSql "UPDATE oc_poolbuy_pool SET reserved_qty = reserved_qty + 7 WHERE pool_id=$poolId;"
$after = [int](Get-Progress "$poolId").pools[0].reserved_qty
Assert ($after -eq $before + 7) "endpoint picked up the change without a page reload ($before -> $after)"
Invoke-OcSql "UPDATE oc_poolbuy_pool SET reserved_qty = reserved_qty - 7 WHERE pool_id=$poolId;"

Write-Host "`n[3] Draft and cancelled pools are never exposed"
$origStatus = Get-OcSqlScalar "SELECT status FROM oc_poolbuy_pool WHERE pool_id=$poolId;"

Invoke-OcSql "UPDATE oc_poolbuy_pool SET status='draft' WHERE pool_id=$poolId;"
$draft = Get-Progress "$poolId"
Assert ($draft.pools.Count -eq 0) 'a draft pool returns no progress data'

Invoke-OcSql "UPDATE oc_poolbuy_pool SET status='cancelled' WHERE pool_id=$poolId;"
$cancelled = Get-Progress "$poolId"
Assert ($cancelled.pools.Count -eq 0) 'a cancelled pool returns no progress data'

Invoke-OcSql "UPDATE oc_poolbuy_pool SET status='$origStatus' WHERE pool_id=$poolId;"
Assert ((Get-Progress "$poolId").pools.Count -eq 1) "the pool is visible again once restored to '$origStatus'"

Write-Host "`n[4] No buyer or private data is exposed"
$raw = (Invoke-WebRequest -Uri "$route&pool_id=$poolId" -UseBasicParsing).Content
Assert ($raw -notmatch '@poolbuy\.test|@example\.com') 'no email addresses in the payload'
Assert ($raw -notmatch 'TX-[A-Z0-9]') 'no reservation references in the payload'
Assert ($raw -notmatch 'customer_id|firstname|lastname') 'no customer fields in the payload'

Write-Host "`n[5] Input handling is safe"
Assert ((Get-Progress "0").pools.Count -eq 0) 'pool_id=0 returns an empty set'
Assert ((Get-Progress "999999").pools.Count -eq 0) 'an unknown pool id returns an empty set'

$injected = Invoke-WebRequest -Uri "$route&pool_id=1%20OR%201%3D1--" -UseBasicParsing
Assert ($injected.Content -notmatch 'SQL syntax|mysqli|Unknown column') 'an injection attempt surfaces no SQL error'

# Batch cap: ask for more ids than the endpoint allows and confirm it is bounded.
$manyIds = (1..60) -join ','
$batch = Get-Progress $manyIds
Assert ($batch.pools.Count -le 24) "batch size is capped at 24 (got $($batch.pools.Count))"

Write-Host "`n[6] Client hooks are present in the rendered HTML"
$market = Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/pool" -UseBasicParsing
Assert ($market.Content -match 'data-pb-progress="\d+"') 'marketplace cards carry a data-pb-progress hook'
Assert ($market.Content -match 'role="progressbar"') 'server-rendered progress bars still present (no-JS fallback)'
Assert ($market.Content -match 'aria-valuenow="[\d.]+"') 'aria-valuenow rendered server side'

$productId = Get-OcSqlInt "SELECT product_id FROM oc_poolbuy_pool WHERE pool_id=$poolId;"
$widget = Invoke-WebRequest -Uri "$base/index.php?route=product/product&product_id=$productId" -UseBasicParsing
Assert ($widget.Content -match 'data-pb-progress="\d+"') 'product pool widget carries a data-pb-progress hook'
Assert ($widget.Content -match 'data-pb-progress-reserved') 'product widget exposes the reserved-count hook'
Assert (-not ($widget.Content -match 'Fatal error|Twig\\Error')) 'no PHP or Twig errors on the product page'

Write-Host "`n[7] Endpoint stays silent when PoolBuy is disabled"
$tick = [char]96
$statusWhere = "code='module_poolbuy' AND ${tick}key${tick}='module_poolbuy_status'"
Invoke-OcSql "UPDATE oc_setting SET value='0' WHERE $statusWhere;"
$disabled = Get-Progress "$poolId"
Assert ($disabled.pools.Count -eq 0) 'no data is served while module_poolbuy_status = 0'
Invoke-OcSql "UPDATE oc_setting SET value='1' WHERE $statusWhere;"
Assert ((Get-Progress "$poolId").pools.Count -eq 1) 'data resumes once PoolBuy is re-enabled'

Write-Host ""
if ($failures.Count -eq 0) {
    Write-Host "ALL PROGRESS CHECKS PASSED" -ForegroundColor Green
    exit 0
} else {
    Write-Host "$($failures.Count) CHECK(S) FAILED" -ForegroundColor Red
    exit 1
}
