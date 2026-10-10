# Verifies the admin Payments & Settlements screen: authorisation, the per-currency
# reporting summaries, and that marking a payout is a one-way, audited transition.
#
# Requires: tools\poolbuy\seed_all.ps1
#
# Usage: powershell -File tools\poolbuy\verify_settlement.ps1

$ErrorActionPreference = 'Stop'
. "$PSScriptRoot\admin.ps1"

$base = 'http://localhost'
$route = 'extension/poolbuy/poolbuy/settlement'

$failures = @()

function Assert([bool] $Condition, [string] $Message) {
    if ($Condition) { Write-Host "  PASS  $Message" -ForegroundColor Green }
    else { Write-Host "  FAIL  $Message" -ForegroundColor Red; $script:failures += $Message }
}

function Get-Anon([string] $Url) {
    try {
        return (Invoke-WebRequest -Uri $Url -UseBasicParsing -MaximumRedirection 5).Content
    } catch {
        return ''
    }
}

# ---------------------------------------------------------------------------
Write-Host "`n[1] The screen requires an authenticated operator"
foreach ($action in @('', '.list', '.pay')) {
    $body = Get-Anon "$base/admin/index.php?route=$route$action&settlement_id=1"
    $isLogin = ($body -match 'name="username"' -or $body -match 'route=common/login')
    $leaks = ($body -match 'Net payable' -or $body -match 'Gateway reference')
    Assert ($isLogin -and -not $leaks) "$route$action refuses an anonymous visitor and leaks nothing"
}

# ---------------------------------------------------------------------------
Write-Host "`n[2] A forged user_token cannot mark a settlement paid"
Invoke-OcSql "UPDATE oc_poolbuy_settlement SET status='pending' WHERE settlement_id=(SELECT MIN(settlement_id) FROM (SELECT settlement_id FROM oc_poolbuy_settlement) s);"
$target = Get-OcSqlInt "SELECT COALESCE(MIN(settlement_id),0) FROM oc_poolbuy_settlement WHERE status='pending';"
Assert ($target -gt 0) "a pending settlement exists to work with (settlement $target)"

$forged = 'deadbeefdeadbeefdeadbeefdeadbeef'
Get-Anon "$base/admin/index.php?route=$route.pay&user_token=$forged&settlement_id=$target" | Out-Null
Assert ((Get-OcSqlScalar "SELECT status FROM oc_poolbuy_settlement WHERE settlement_id=$target;") -eq 'pending') 'a forged user_token cannot mark a settlement paid'

# ---------------------------------------------------------------------------
Write-Host "`n[3] The screen renders payments, settlements and per-currency summaries"
$admin = Connect-OcAdmin
$page = Invoke-OcAdmin $admin $route

Assert ($page.StatusCode -eq 200) 'the screen returns 200 for an operator'
Assert ($page.Content -notmatch 'do not have permission') 'the operator has permission after install'
Assert ($page.Content -match 'Payments by currency') 'the payment summary is grouped by currency'
Assert ($page.Content -match 'Seller settlements by currency') 'the settlement summary is grouped by currency'
Assert ($page.Content -match 'never added across currencies') 'the screen states that currencies are never summed together'
Assert ($page.Content -match 'Gateway reference') 'the payment list shows the gateway reference'
Assert ($page.Content -match 'Net payable') 'the settlement list shows the net payable'

# The currency the pools actually traded in must appear, rather than a single
# store-wide figure.
$poolCurrency = Get-OcSqlScalar "SELECT currency FROM oc_poolbuy_settlement WHERE settlement_id=$target;"
Assert ($page.Content -match [regex]::Escape($poolCurrency)) "the settlement's own currency ($poolCurrency) is shown"

# ---------------------------------------------------------------------------
Write-Host "`n[4] Filters narrow the list without erroring"
$filtered = Invoke-OcAdmin $admin "$route.list" @{ filter_status = 'refunded'; filter_settlement_status = 'paid' }
Assert ($filtered.StatusCode -eq 200) 'a filtered list returns 200'
$filteredOrder = Invoke-OcAdmin $admin "$route.list" @{ filter_order_id = '999999' }
Assert ($filteredOrder.Content -match 'No payments have been raised yet.') 'a filter that matches nothing says so rather than showing everything'

# ---------------------------------------------------------------------------
Write-Host "`n[5] Marking a payout is audited and cannot be repeated"
$eventsBefore = Get-OcSqlInt "SELECT COUNT(*) FROM oc_poolbuy_pool_event WHERE type='settlement_paid';"

$paid = Invoke-OcAdmin $admin "$route.pay" @{ settlement_id = $target }
Assert ($paid.Content -match 'marked as paid out') 'the payout is accepted'
Assert ((Get-OcSqlScalar "SELECT status FROM oc_poolbuy_settlement WHERE settlement_id=$target;") -eq 'paid') 'the settlement is now paid'
Assert ((Get-OcSqlInt "SELECT COUNT(*) FROM oc_poolbuy_pool_event WHERE type='settlement_paid';") -eq ($eventsBefore + 1)) 'the payout is recorded in the pool audit trail'

# Paying twice must not look like a second, fresh payout.
$again = Invoke-OcAdmin $admin "$route.pay" @{ settlement_id = $target }
Assert ($again.Content -match 'already been paid out') 'paying an already-paid settlement is refused'
Assert ((Get-OcSqlInt "SELECT COUNT(*) FROM oc_poolbuy_pool_event WHERE type='settlement_paid';") -eq ($eventsBefore + 1)) 'a refused repeat writes no second audit event'

# A settlement that does not exist is refused the same way, without confirming
# whether the id was real.
$missing = Invoke-OcAdmin $admin "$route.pay" @{ settlement_id = 999999 }
Assert ($missing.Content -match 'already been paid out' -or $missing.Content -match 'could not be paid') 'an unknown settlement id is refused'

# --- Tidy up: leave the settlement pending so the suite is repeatable.
Invoke-OcSql "UPDATE oc_poolbuy_settlement SET status='pending' WHERE settlement_id=$target;"
Invoke-OcSql "DELETE FROM oc_poolbuy_pool_event WHERE type='settlement_paid';"

Write-Host ""
if ($failures.Count -eq 0) {
    Write-Host "ALL SETTLEMENT CHECKS PASSED" -ForegroundColor Green
    exit 0
} else {
    Write-Host "$($failures.Count) CHECK(S) FAILED" -ForegroundColor Red
    exit 1
}
