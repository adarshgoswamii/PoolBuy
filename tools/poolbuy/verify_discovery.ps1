# Verifies the marketplace discovery surfaces: the "Ending soon" and "Trending
# pools" rails, their visibility rules, and that they never advertise a pool a
# shopper cannot actually join.
#
# Usage: powershell -File tools\poolbuy\verify_discovery.ps1

$ErrorActionPreference = 'Stop'
. "$PSScriptRoot\admin.ps1"

$base = 'http://localhost'
$market = "$base/index.php?route=extension/poolbuy/pool"
$failures = @()

function Assert([bool] $Condition, [string] $Message) {
    if ($Condition) { Write-Host "  PASS  $Message" -ForegroundColor Green }
    else { Write-Host "  FAIL  $Message" -ForegroundColor Red; $script:failures += $Message }
}

function Get-Html([string] $Url) {
    return (Invoke-WebRequest -Uri $Url -UseBasicParsing -TimeoutSec 30).Content
}

Write-Host "`n[1] Both rails render on the unfiltered marketplace"
$html = Get-Html $market
# Match on the section ids, not the visible text: the sort dropdown already
# contains the label "Ending soonest", which would match a looser check.
Assert ($html -match 'id="pb-rail-ending"') 'the "Ending soon" rail renders'
Assert ($html -match 'id="pb-rail-trending"') 'the "Trending pools" rail renders'
Assert ($html -match 'All pools') 'the full grid keeps its own heading below the rails'
$cards = ([regex]::Matches($html, 'pb-rail-card')).Count
Assert ($cards -gt 0) "rail cards are present (found $cards)"
Assert (-not ($html -match 'Fatal error|Twig\\Error')) 'no PHP or Twig errors on the marketplace'

Write-Host "`n[2] Rails are suppressed once the shopper filters or searches"
$searched = Get-Html "$market&filter_search=chlorine"
Assert (-not ($searched -match 'id="pb-rail-trending"')) 'searching hides the trending rail'
Assert (-not ($searched -match 'id="pb-rail-ending"')) 'searching hides the ending-soon rail'
Assert ($searched -match 'CrystalPure') 'search results themselves still render'

$filtered = Get-Html "$market&filter_available=1"
Assert (-not ($filtered -match 'id="pb-rail-trending"')) 'filtering hides the rails'

Write-Host "`n[3] Rails only advertise joinable pools"
# Every rail card links to the join flow, so anything listed must be joinable.
$railJoinLinks = ([regex]::Matches($html, 'pb-rail-card[\s\S]{0,2500}?poolbuy/join[^"]*?pool_id=(\d+)'))
Assert ($railJoinLinks.Count -gt 0) "rail cards link to the join flow ($($railJoinLinks.Count) links)"

$badPools = 0
foreach ($m in $railJoinLinks) {
    $id = [int]$m.Groups[1].Value
    $joinable = Get-OcSqlInt "SELECT COUNT(*) FROM oc_poolbuy_pool WHERE pool_id=$id AND status='active' AND date_end > NOW() AND reserved_qty < moq_target;"
    if ($joinable -ne 1) { $badPools++ }
}
Assert ($badPools -eq 0) "every pool in a rail is genuinely joinable (violations: $badPools)"

Write-Host "`n[4] Draft and cancelled pools never reach a rail"
if ($railJoinLinks.Count -eq 0) {
    Assert $false 'cannot test hidden statuses: no rail cards were found'
} else {
    # Force the pool currently shown first into a hidden status and confirm it goes.
    $firstId = [int]$railJoinLinks[0].Groups[1].Value
    $origStatus = Get-OcSqlScalar "SELECT status FROM oc_poolbuy_pool WHERE pool_id=$firstId;"

    Invoke-OcSql "UPDATE oc_poolbuy_pool SET status='draft' WHERE pool_id=$firstId;"
    $afterDraft = Get-Html $market
    Assert ($afterDraft -notmatch "pool_id=$firstId`"") "a draft pool (id $firstId) disappears from the rails"

    Invoke-OcSql "UPDATE oc_poolbuy_pool SET status='cancelled' WHERE pool_id=$firstId;"
    $afterCancelled = Get-Html $market
    Assert ($afterCancelled -notmatch "pool_id=$firstId`"") "a cancelled pool (id $firstId) disappears from the rails"

    Invoke-OcSql "UPDATE oc_poolbuy_pool SET status='$origStatus' WHERE pool_id=$firstId;"
    Assert ((Get-OcSqlScalar "SELECT status FROM oc_poolbuy_pool WHERE pool_id=$firstId;") -eq $origStatus) "pool $firstId restored to '$origStatus'"
}

Write-Host "`n[5] A pool is not duplicated across both rails"
$ids = @()
foreach ($m in ([regex]::Matches((Get-Html $market), 'pb-rail-card[\s\S]{0,2500}?poolbuy/join[^"]*?pool_id=(\d+)'))) {
    $ids += [int]$m.Groups[1].Value
}
$unique = ($ids | Sort-Object -Unique).Count
Assert ($ids.Count -eq $unique) "no pool appears in both rails ($($ids.Count) cards, $unique unique)"

Write-Host "`n[6] Rail cards participate in live progress updates"
Assert ((Get-Html $market) -match 'pb-rail-card[\s\S]{0,1600}?data-pb-progress=') 'rail cards carry the live progress hook'

Write-Host "`n[7] The See all links point at the matching sorted feed"
# link() omits parameters left at their default, so the trending rail (progress
# DESC is the default order) links to the plain feed, while ending-soon carries
# an explicit sort.
Assert ($html -match 'sort=ending') 'the ending-soon rail links to the ending-soonest feed'
Assert ($html -match 'id="pb-rail-trending"[\s\S]{0,400}?route=extension/poolbuy/pool') 'the trending rail has a see-all link to the feed'

$endingFeed = Get-Html "$market&sort=ending&order=ASC"
Assert ($endingFeed -match 'pb-card') 'the ending-soon sorted feed returns results'

Write-Host "`n[8] The mobile filter drawer is still operable without JavaScript"
Assert ($html -match 'data-pb-drawer-toggle="pb-filters"') 'the filter drawer toggle is present'
Assert ($html -match 'id="pb-filters"') 'the filter panel is present in the markup'
Assert ($html -match 'aria-controls="pb-filters"') 'the toggle is wired to the panel for assistive tech'

Write-Host ""
if ($failures.Count -eq 0) {
    Write-Host "ALL DISCOVERY CHECKS PASSED" -ForegroundColor Green
    exit 0
} else {
    Write-Host "$($failures.Count) CHECK(S) FAILED" -ForegroundColor Red
    exit 1
}
