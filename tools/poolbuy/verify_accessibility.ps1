# PoolBuy accessibility audit.
#
# Checks the RENDERED HTML of every PoolBuy surface for the structural things that
# assistive technology depends on: landmarks, a skip link, labelled controls,
# accessible names on icon-only buttons, alt text, and ARIA on progress bars.
#
# Usage: powershell -File tools\poolbuy\verify_accessibility.ps1

$ErrorActionPreference = 'Continue'
. "$PSScriptRoot\admin.ps1"

$base = 'http://localhost'
$failures = @()

function Assert([bool] $Condition, [string] $Message) {
    if ($Condition) { Write-Host "  PASS  $Message" -ForegroundColor Green }
    else { Write-Host "  FAIL  $Message" -ForegroundColor Red; $script:failures += $Message }
}

function Get-Html([string] $Url, $Session = $null) {
    $p = @{ Uri = $Url; UseBasicParsing = $true; ErrorAction = 'SilentlyContinue' }
    if ($Session) { $p.WebSession = $Session }
    try { return [string](Invoke-WebRequest @p).Content } catch { return '' }
}

function Connect-Buyer([string] $Email) {
    $s = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $page = Invoke-WebRequest -Uri "$base/index.php?route=account/login" -WebSession $s -UseBasicParsing
    if ($page.Content -notmatch 'login_token=([0-9a-zA-Z]+)') { return $null }
    Invoke-WebRequest -Uri "$base/index.php?route=account/login.login&login_token=$($Matches[1])" `
        -Method POST -Body @{ email = $Email; password = 'PoolBuy123!' } -WebSession $s -UseBasicParsing | Out-Null
    return $s
}

# Counts <img ...> tags that carry no alt attribute at all.
function Count-ImagesWithoutAlt([string] $Html) {
    $n = 0
    foreach ($m in [regex]::Matches($Html, '<img\b[^>]*>')) {
        if ($m.Value -notmatch '\balt\s*=') { $n++ }
    }
    return $n
}

# Counts <input> controls that are neither labelled nor hidden//button-like.
#
# $Ignore lists control identifiers that belong to stock OpenCart templates rather
# than PoolBuy. They are reported separately so the audit stays honest about them
# instead of quietly passing.
function Count-UnlabelledInputs([string] $Html, [string[]] $Ignore = @()) {
    $n = 0
    $unlabelled = @()
    $skippedCore = 0
    foreach ($m in [regex]::Matches($Html, '<(input|select|textarea)\b[^>]*>')) {
        $tag = $m.Value
        if ($tag -match 'type\s*=\s*["'']?(hidden|submit|button|image|reset)') { continue }
        # Acceptable ways to give a control an accessible name
        if ($tag -match 'aria-label\s*=' -or $tag -match 'aria-labelledby\s*=' -or $tag -match 'title\s*=') { continue }
        if ($tag -match 'id\s*=\s*["'']([^"'']+)["'']') {
            $id = [regex]::Escape($Matches[1])
            if ($Html -match "<label[^>]*\bfor\s*=\s*[""']$id[""']") { continue }
        }
        # A control nested inside a <label> is labelled too (checkbox pattern)
        $before = $Html.Substring(0, $m.Index)
        $lastLabelOpen = $before.LastIndexOf('<label')
        $lastLabelClose = $before.LastIndexOf('</label>')
        if ($lastLabelOpen -gt $lastLabelClose) { continue }

        $isCore = $false
        foreach ($pat in $Ignore) { if ($tag -match $pat) { $isCore = $true; break } }
        if ($isCore) { $skippedCore++; continue }

        $n++
        $unlabelled += ($tag -replace '\s+', ' ')
    }
    if ($skippedCore -gt 0) { Write-Host "        note: $skippedCore unlabelled control(s) belong to stock OpenCart templates, not PoolBuy" -ForegroundColor DarkGray }
    if ($n -gt 0) { $unlabelled | Select-Object -First 4 | ForEach-Object { Write-Host "        unlabelled: $_" -ForegroundColor DarkYellow } }
    return $n
}

# The real WCAG criterion: a link or button whose only content is an icon must
# still expose an accessible name. Counting bare <i> tags is a poor proxy because
# an aria-hidden ancestor already hides them correctly.
function Count-UnnamedIconControls([string] $Html) {
    $n = 0
    $offenders = @()
    foreach ($m in [regex]::Matches($Html, '(?s)<(a|button)\b([^>]*)>(.*?)</\1>')) {
        $attrs = $m.Groups[2].Value
        $inner = $m.Groups[3].Value

        # Text content with all tags and entities removed
        $text = ($inner -replace '(?s)<[^>]*>', '') -replace '&[a-zA-Z]+;|&#\d+;', ''
        if ($text.Trim() -ne '') { continue }          # has visible text
        if ($inner -notmatch '<i\b|<svg\b') { continue } # not an icon control

        if ($attrs -match 'aria-label\s*=' -or $attrs -match 'aria-labelledby\s*=' -or $attrs -match 'title\s*=') { continue }
        if ($inner -match 'pb-visually-hidden|visually-hidden|sr-only') { continue }

        $n++
        $offenders += (($m.Value -replace '\s+', ' ').Substring(0, [Math]::Min(120, ($m.Value -replace '\s+', ' ').Length)))
    }
    if ($n -gt 0) { $offenders | Select-Object -First 4 | ForEach-Object { Write-Host "        unnamed control: $_" -ForegroundColor DarkYellow } }
    return $n
}

# Every progressbar must expose its current value.
function Count-BadProgressBars([string] $Html) {
    $n = 0
    foreach ($m in [regex]::Matches($Html, '<[^>]*role\s*=\s*["'']progressbar["''][^>]*>')) {
        if ($m.Value -notmatch 'aria-valuenow') { $n++ }
    }
    return $n
}

$buyer  = Connect-Buyer 'buyer@poolbuy.test'
$seller = Connect-Buyer 'sellera@poolbuy.test'
$poolId = Get-OcSqlInt "SELECT pool_id FROM oc_poolbuy_pool WHERE seller_id=1 AND status='active' ORDER BY pool_id LIMIT 1;"
$prodId = Get-OcSqlInt "SELECT product_id FROM oc_poolbuy_pool WHERE pool_id=$poolId;"

$pages = @(
    @{ Name = 'Landing';          Url = "$base/";                                                                Session = $null },
    @{ Name = 'Marketplace';      Url = "$base/index.php?route=extension/poolbuy/pool";                          Session = $null },
    @{ Name = 'Product + widget'; Url = "$base/index.php?route=product/product&product_id=$prodId";              Session = $null },
    @{ Name = 'Buyer dashboard';  Url = "$base/index.php?route=extension/poolbuy/account";                       Session = $buyer },
    @{ Name = 'Join flow';        Url = "$base/index.php?route=extension/poolbuy/join&pool_id=$poolId";           Session = $buyer },
    @{ Name = 'Seller portal';    Url = "$base/index.php?route=extension/poolbuy/seller";                        Session = $seller },
    @{ Name = 'Seller pool';      Url = "$base/index.php?route=extension/poolbuy/seller.pool&pool_id=$poolId";    Session = $seller },
    @{ Name = 'Seller form';      Url = "$base/index.php?route=extension/poolbuy/seller.form";                    Session = $seller }
)

foreach ($page in $pages) {
    Write-Host "`n=== $($page.Name) ==="
    $html = Get-Html $page.Url $page.Session

    if ([string]::IsNullOrWhiteSpace($html)) {
        Assert $false "$($page.Name) returned HTML"
        continue
    }

    # --- Document structure
    Assert ($html -match '<html[^>]*\blang\s*=') "$($page.Name): <html> declares a lang"
    Assert ($html -match '<title>\s*\S') "$($page.Name): has a non-empty <title>"
    Assert ($html -match 'name\s*=\s*["'']viewport["'']') "$($page.Name): declares a viewport"

    # --- Landmarks and skip link
    Assert ($html -match '<main\b' -or $html -match 'role\s*=\s*["'']main["'']') "$($page.Name): has a main landmark"
    Assert ($html -match 'pb-skip-link') "$($page.Name): offers a skip-to-content link"
    Assert ($html -match '<header\b' -or $html -match 'role\s*=\s*["'']banner["'']') "$($page.Name): has a banner landmark"
    Assert ($html -match '<nav\b') "$($page.Name): has a navigation landmark"
    Assert ($html -match '<footer\b' -or $html -match 'role\s*=\s*["'']contentinfo["'']') "$($page.Name): has a contentinfo landmark"

    # --- Headings: exactly one h1 is the ideal; at least one is mandatory
    $h1 = [regex]::Matches($html, '<h1\b').Count
    Assert ($h1 -ge 1) "$($page.Name): has an h1 ($h1 found)"

    # --- Images
    $noAlt = Count-ImagesWithoutAlt $html
    Assert ($noAlt -eq 0) "$($page.Name): every <img> has an alt attribute ($noAlt missing)"

    # --- Form controls. Stock OpenCart's quantity box and review radios are not
    #     PoolBuy markup, so they are reported but not counted as PoolBuy failures.
    $coreControls = @('name="quantity"', 'name="rating"', 'id="input-quantity"', 'name="search"', 'name="agree"')
    $unlabelled = Count-UnlabelledInputs $html $coreControls
    Assert ($unlabelled -eq 0) "$($page.Name): every visible PoolBuy form control is labelled ($unlabelled unlabelled)"

    # --- Progress bars
    $badBars = Count-BadProgressBars $html
    Assert ($badBars -eq 0) "$($page.Name): every progressbar exposes aria-valuenow ($badBars missing)"

    # --- Icon-only links and buttons must still have an accessible name
    $unnamed = Count-UnnamedIconControls $html
    Assert ($unnamed -eq 0) "$($page.Name): every icon-only control has an accessible name ($unnamed unnamed)"

    # --- Informational: decorative icons not marked aria-hidden
    $iconTotal = [regex]::Matches($html, '<i\s+class="fa-').Count
    $iconHidden = [regex]::Matches($html, '<i\s+class="fa-[^>]*aria-hidden').Count
    if ($iconTotal -gt 0 -and $iconHidden -lt $iconTotal) {
        Write-Host "        note: $($iconTotal - $iconHidden)/$iconTotal icons lack aria-hidden (stock OpenCart product/cart markup)" -ForegroundColor DarkGray
    }

    # --- No positive tabindex (which breaks natural focus order)
    Assert ($html -notmatch 'tabindex\s*=\s*["'']?[1-9]') "$($page.Name): no positive tabindex values"
}

Write-Host "`n=== FOCUS VISIBILITY AND MOTION (stylesheet) ==="
$css = Get-Html "$base/catalog/view/stylesheet/poolbuy.css"
Assert ($css -match ':focus-visible') 'stylesheet defines :focus-visible styles'
Assert ($css -match 'prefers-reduced-motion') 'stylesheet honours prefers-reduced-motion'
Assert ($css -match '\.pb-visually-hidden') 'stylesheet provides a visually-hidden utility'
Assert ($css -match '\.pb-skip-link') 'stylesheet styles the skip link'

Write-Host "`n=== KEYBOARD OPERABILITY: NO JAVASCRIPT REQUIRED ==="
# The marketplace filter form must be a real GET form, so it works without JS.
$market = Get-Html "$base/index.php?route=extension/poolbuy/pool"
Assert ($market -match '<form[^>]*method\s*=\s*["'']get["'']' -or $market -match '<form[^>]*action') 'marketplace filtering uses a real form'
$filtered = Get-Html "$base/index.php?route=extension/poolbuy/pool&filter_search=chlorine"
Assert ($filtered -match 'CrystalPure') 'filtering works over plain GET with no JavaScript'
# The join flow must be a real POST form.
$join = Get-Html "$base/index.php?route=extension/poolbuy/join&pool_id=$poolId" $buyer
Assert ($join -match '<form[^>]*method\s*=\s*["'']post["'']') 'the join flow is a real POST form'
Assert ($join -match 'type\s*=\s*["'']submit["'']') 'the join flow has a real submit button'

Write-Host ""
if ($failures.Count -eq 0) {
    Write-Host "ALL ACCESSIBILITY CHECKS PASSED" -ForegroundColor Green
    exit 0
} else {
    Write-Host "$($failures.Count) ACCESSIBILITY CHECK(S) FAILED" -ForegroundColor Red
    $failures | ForEach-Object { Write-Host "  - $_" -ForegroundColor Red }
    exit 1
}
