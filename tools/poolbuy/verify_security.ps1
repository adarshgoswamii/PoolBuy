# PoolBuy security hardening sweep.
#
# Probes EVERY PoolBuy route unauthenticated and with crafted input, and asserts
# that privileged data is never returned and privileged actions never take effect.
#
# Usage: powershell -File tools\poolbuy\verify_security.ps1

$ErrorActionPreference = 'Continue'
. "$PSScriptRoot\admin.ps1"

$base = 'http://localhost'
$failures = @()

function Assert([bool] $Condition, [string] $Message) {
    if ($Condition) { Write-Host "  PASS  $Message" -ForegroundColor Green }
    else { Write-Host "  FAIL  $Message" -ForegroundColor Red; $script:failures += $Message }
}

# Fetch a URL and always return an object, even on a 4xx/5xx.
function Get-Page([string] $Url, $Session = $null, [string] $Method = 'GET', $Body = $null) {
    $p = @{ Uri = $Url; UseBasicParsing = $true; Method = $Method; ErrorAction = 'SilentlyContinue' }
    if ($Session) { $p.WebSession = $Session }
    if ($Body) { $p.Body = $Body; $p.ContentType = 'application/x-www-form-urlencoded' }
    try {
        $r = Invoke-WebRequest @p
        return [pscustomobject]@{ Code = [int]$r.StatusCode; Body = [string]$r.Content }
    } catch {
        $resp = $_.Exception.Response
        $code = 0; $text = ''
        if ($resp) {
            $code = [int]$resp.StatusCode
            try {
                $sr = New-Object System.IO.StreamReader($resp.GetResponseStream())
                $text = $sr.ReadToEnd()
            } catch {}
        }
        return [pscustomobject]@{ Code = $code; Body = $text }
    }
}

Write-Host "=== [A] ADMIN ROUTES REJECT UNAUTHENTICATED CALLERS ==="
# Every admin screen and every mutating admin action.
$adminRoutes = @(
    'extension/poolbuy/poolbuy/seller',
    'extension/poolbuy/poolbuy/seller.list',
    'extension/poolbuy/poolbuy/seller.form',
    'extension/poolbuy/poolbuy/seller.save',
    'extension/poolbuy/poolbuy/seller.delete',
    'extension/poolbuy/poolbuy/seller.autocomplete',
    'extension/poolbuy/poolbuy/pool',
    'extension/poolbuy/poolbuy/pool.list',
    'extension/poolbuy/poolbuy/pool.form',
    'extension/poolbuy/poolbuy/pool.save',
    'extension/poolbuy/poolbuy/pool.delete',
    'extension/poolbuy/poolbuy/pool.detail',
    'extension/poolbuy/poolbuy/pool.close',
    'extension/poolbuy/module/poolbuy',
    'extension/poolbuy/module/poolbuy.save'
)

foreach ($route in $adminRoutes) {
    $r = Get-Page "$base/admin/index.php?route=$route" -Method 'POST' -Body 'name=Injected&seller_id=1&pool_id=1&status=1'
    $leaked = ($r.Body -match 'Titan Pumps|PB-99201|oc_poolbuy|Reservation Map')
    Assert ((-not $leaked)) "$route leaks no data without a session (HTTP $($r.Code))"
}

$sellersBefore = Get-OcSqlInt "SELECT COUNT(*) FROM oc_poolbuy_seller;"
$poolsBefore   = Get-OcSqlInt "SELECT COUNT(*) FROM oc_poolbuy_pool;"
$injected      = Get-OcSqlInt "SELECT COUNT(*) FROM oc_poolbuy_seller WHERE name='Injected';"
Assert ($injected -eq 0) 'no seller was created by unauthenticated POSTs'
Write-Host "    sellers=$sellersBefore pools=$poolsBefore"

Write-Host "`n=== [B] ADMIN ACTIONS REJECT A FORGED user_token ==="
$forged = 'deadbeefdeadbeefdeadbeefdeadbeef'
$r = Get-Page "$base/admin/index.php?route=extension/poolbuy/poolbuy/seller.save&user_token=$forged" -Method 'POST' -Body 'name=ForgedSeller&location=X&gst_number=&rating=0&status=1'
Assert ((Get-OcSqlInt "SELECT COUNT(*) FROM oc_poolbuy_seller WHERE name='ForgedSeller';") -eq 0) 'forged user_token cannot create a seller'
$r = Get-Page "$base/admin/index.php?route=extension/poolbuy/poolbuy/pool.delete&user_token=$forged" -Method 'POST' -Body 'selected%5B%5D=1'
Assert ((Get-OcSqlInt "SELECT COUNT(*) FROM oc_poolbuy_pool WHERE pool_id=1;") -eq 1) 'forged user_token cannot delete a pool'
$r = Get-Page "$base/admin/index.php?route=extension/poolbuy/poolbuy/pool.close&user_token=$forged&pool_id=1" -Method 'POST'
Assert ((Get-OcSqlScalar "SELECT status FROM oc_poolbuy_pool WHERE pool_id=1;") -eq 'active') 'forged user_token cannot close a pool early'

Write-Host "`n=== [C] STOREFRONT PRIVATE ROUTES REQUIRE A LOGIN ==="
$privateRoutes = @(
    'extension/poolbuy/account',
    'extension/poolbuy/account.export',
    'extension/poolbuy/account.cancel',
    'extension/poolbuy/join',
    'extension/poolbuy/seller',
    'extension/poolbuy/seller.pool',
    'extension/poolbuy/seller.form'
)

foreach ($route in $privateRoutes) {
    $r = Get-Page "$base/index.php?route=$route&pool_id=1&reservation_id=1"
    $isLogin = ($r.Body -match 'route=account/login' -or $r.Body -match 'name="email"')
    $leaked  = ($r.Body -match 'TX-[A-Z0-9]{8}|Your Pool Campaigns|Withdraw|Buyer #\d+')
    Assert ($isLogin -and -not $leaked) "$route redirects to login and leaks nothing"
}

# seller.save is a JSON endpoint, so refusing with a JSON error is the correct
# behaviour rather than serving an HTML login page to an API caller.
$r = Get-Page "$base/index.php?route=extension/poolbuy/seller.save" -Method 'POST' -Body 'product_id=1&moq_target=10&status=active'
Assert ($r.Body -match '"error"') 'seller.save refuses an anonymous caller with a JSON error'
Assert ($r.Body -notmatch 'Your Pool Campaigns|PB-') 'seller.save leaks no seller data anonymously'
Assert ((Get-OcSqlInt "SELECT COUNT(*) FROM oc_poolbuy_pool WHERE title='';") -eq 0) 'seller.save created no pool anonymously'

Assert ((Get-Page "$base/index.php?route=extension/poolbuy/account.export").Body -notmatch 'Reference,Pool,Seller') 'CSV export is not downloadable anonymously'

Write-Host "`n=== [D] PUBLIC ROUTES ARE PUBLIC BUT LEAK NO PRIVATE DATA ==="
foreach ($route in @('extension/poolbuy/pool', 'extension/poolbuy/pool.list')) {
    $r = Get-Page "$base/index.php?route=$route"
    Assert ($r.Code -eq 200) "$route is publicly reachable (HTTP $($r.Code))"
    Assert ($r.Body -notmatch '@example\.com|@poolbuy\.test') "$route exposes no buyer email addresses"
    Assert ($r.Body -notmatch 'TX-[A-Z0-9]{8}') "$route exposes no reservation references"
}
$landing = Get-Page "$base/"
Assert ($landing.Body -notmatch '@example\.com|@poolbuy\.test') 'landing page exposes no buyer emails'
Assert ($landing.Body -notmatch 'TX-[A-Z0-9]{8}') 'landing page exposes no reservation references'

Write-Host "`n=== [E] DRAFT AND CANCELLED POOLS ARE NEVER PUBLIC ==="
$hidden = Get-OcSqlInt "SELECT COUNT(*) FROM oc_poolbuy_pool WHERE status IN ('draft','cancelled');"
Invoke-OcSql "UPDATE oc_poolbuy_pool SET status='draft' WHERE pool_id=9;"
$refHidden = Get-OcSqlScalar "SELECT reference FROM oc_poolbuy_pool WHERE pool_id=9;"
$market = Get-Page "$base/index.php?route=extension/poolbuy/pool"
Assert ($market.Body -notmatch [regex]::Escape($refHidden)) "a draft pool ($refHidden) is absent from the marketplace"
Invoke-OcSql "UPDATE oc_poolbuy_pool SET status='cancelled' WHERE pool_id=9;"
$market = Get-Page "$base/index.php?route=extension/poolbuy/pool"
Assert ($market.Body -notmatch [regex]::Escape($refHidden)) "a cancelled pool ($refHidden) is absent from the marketplace"
Invoke-OcSql "UPDATE oc_poolbuy_pool SET status='active' WHERE pool_id=9;"

Write-Host "`n=== [F] CRON IS TOKEN GATED ==="
$r = Get-Page "$base/index.php?route=extension/poolbuy/cron/poolbuy.run"
Assert ($r.Code -eq 403) "cron with no token is refused (HTTP $($r.Code))"
$r = Get-Page "$base/index.php?route=extension/poolbuy/cron/poolbuy.run&token=wrong-token"
Assert ($r.Code -eq 403) "cron with a wrong token is refused (HTTP $($r.Code))"
# A blank configured token must mean "deny", never "no auth required".
# `key` is a reserved word, so it needs backtick quoting; $OcTick avoids fighting
# PowerShell's own use of the backtick as an escape character.
$keyCol = "$OcTick" + 'key' + "$OcTick"
$tokenWhere = "code='module_poolbuy' AND $keyCol='module_poolbuy_cron_token'"
$realToken = Get-OcSqlScalar "SELECT value FROM oc_setting WHERE store_id=0 AND $tokenWhere;"
Assert (-not [string]::IsNullOrWhiteSpace($realToken)) 'the real cron token was readable (backtick quoting works)'
Invoke-OcSql "UPDATE oc_setting SET value='' WHERE $tokenWhere;"
$nowBlank = Get-OcSqlScalar "SELECT CONCAT('[', value, ']') FROM oc_setting WHERE $tokenWhere;"
Assert ($nowBlank -eq '[]') "the token really was blanked before testing (read back '$nowBlank')"
$r = Get-Page "$base/index.php?route=extension/poolbuy/cron/poolbuy.run"
Assert ($r.Code -eq 403) "a BLANK configured token still denies (HTTP $($r.Code)) - fail closed"
$r = Get-Page "$base/index.php?route=extension/poolbuy/cron/poolbuy.run&token="
Assert ($r.Code -eq 403) "blank token supplied against blank config still denies (HTTP $($r.Code))"
Invoke-OcSql "UPDATE oc_setting SET value='$realToken' WHERE $tokenWhere;"
Assert ((Get-OcSqlScalar "SELECT value FROM oc_setting WHERE $tokenWhere;") -eq $realToken) 'the real cron token was restored'

Write-Host "`n=== [G] XSS: OPERATOR DATA IS ESCAPED ON THE STOREFRONT ==="
# Deliberately free of quote characters so the payload survives the SQL round trip
# unchanged; the angle brackets are what matter for the escaping test.
$payload = '<img src=x onerror=alert(1)>'
$origTitle  = Get-OcSqlScalar "SELECT title FROM oc_poolbuy_pool WHERE pool_id=5;"
$origUnit   = Get-OcSqlScalar "SELECT unit_label FROM oc_poolbuy_pool WHERE pool_id=5;"
$origSeller = Get-OcSqlScalar "SELECT name FROM oc_poolbuy_seller WHERE seller_id=4;"

Invoke-OcSql "UPDATE oc_poolbuy_pool SET title='$payload', unit_label='$payload' WHERE pool_id=5;"
Invoke-OcSql "UPDATE oc_poolbuy_seller SET name='$payload' WHERE seller_id=4;"

# Prove the payload actually landed in the database, otherwise the escaping
# assertions below would pass for the wrong reason.
$stored = Get-OcSqlScalar "SELECT title FROM oc_poolbuy_pool WHERE pool_id=5;"
Assert ($stored -eq $payload) "the XSS payload is genuinely stored in pool 5 (read back '$stored')"

$market = Get-Page "$base/index.php?route=extension/poolbuy/pool"
Assert ($market.Body -notmatch 'onerror=alert\(1\)>') 'marketplace does NOT emit the payload as live HTML'
Assert ($market.Body -match '&lt;img src=x') 'the payload IS present on the marketplace, HTML-encoded'
$landing2 = Get-Page "$base/"
Assert ($landing2.Body -notmatch 'onerror=alert\(1\)>') 'landing page does NOT emit the payload as live HTML'

# The seller name is rendered by the product widget too.
$prod5 = Get-OcSqlInt "SELECT product_id FROM oc_poolbuy_pool WHERE pool_id=5;"
$widget = Get-Page "$base/index.php?route=product/product&product_id=$prod5"
Assert ($widget.Body -notmatch 'onerror=alert\(1\)>') 'product pool widget does NOT emit the payload as live HTML'

Invoke-OcSql "UPDATE oc_poolbuy_pool SET title='$origTitle', unit_label='$origUnit' WHERE pool_id=5;"
Invoke-OcSql "UPDATE oc_poolbuy_seller SET name='$origSeller' WHERE seller_id=4;"
Assert ((Get-OcSqlScalar "SELECT title FROM oc_poolbuy_pool WHERE pool_id=5;") -eq $origTitle) 'pool 5 title restored'
Assert ((Get-OcSqlScalar "SELECT name FROM oc_poolbuy_seller WHERE seller_id=4;") -eq $origSeller) 'seller 4 name restored'

Write-Host "`n=== [H] SQL INJECTION ATTEMPTS DO NOT EXECUTE ==="
$tablesBefore = Get-OcSqlInt "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='opencart' AND table_name LIKE 'oc_poolbuy%';"
$injections = @(
    "extension/poolbuy/pool&filter_search=%27%20OR%201%3D1--%20",
    "extension/poolbuy/pool&filter_category_id=1%3B%20DROP%20TABLE%20oc_poolbuy_pool%3B--",
    "extension/poolbuy/pool&sort=pr.name%3B%20DROP%20TABLE%20oc_poolbuy_seller%3B--",
    "extension/poolbuy/pool&order=%3B%20DROP%20TABLE%20oc_poolbuy_pool_tier%3B--",
    "extension/poolbuy/pool&filter_location=%27%20UNION%20SELECT%20email%20FROM%20oc_customer--",
    "extension/poolbuy/pool&page=1%20UNION%20SELECT%20password%20FROM%20oc_customer"
)
foreach ($inj in $injections) {
    $r = Get-Page "$base/index.php?route=$inj"
    $sqlError = ($r.Body -match 'SQL syntax|Unknown column|mysqli|Notice: Error')
    Assert (-not $sqlError) "injection rejected with no SQL error surfaced: $(($inj -split '&')[1])"
}
$tablesAfter = Get-OcSqlInt "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='opencart' AND table_name LIKE 'oc_poolbuy%';"
Assert ($tablesAfter -eq $tablesBefore) "all $tablesBefore PoolBuy tables still exist after injection attempts"
Assert ((Get-Page "$base/index.php?route=extension/poolbuy/pool&filter_location=%27%20UNION%20SELECT%20email%20FROM%20oc_customer--").Body -notmatch '@poolbuy\.test') 'a UNION attempt returns no customer emails'

Write-Host "`n=== [I] THE CLIENT CANNOT DICTATE PRICE OR QUANTITY ==="
# A logged-in buyer forcing a price and an over-large quantity.
$s = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$lp = Invoke-WebRequest -Uri "$base/index.php?route=account/login" -WebSession $s -UseBasicParsing
if ($lp.Content -match 'login_token=([0-9a-zA-Z]+)') {
    Invoke-WebRequest -Uri "$base/index.php?route=account/login.login&login_token=$($Matches[1])" -Method POST `
        -Body @{ email = 'buyer@poolbuy.test'; password = 'PoolBuy123!' } -WebSession $s -UseBasicParsing | Out-Null
}
# Use a pool this buyer has not joined.
$targetPool = Get-OcSqlInt "SELECT p.pool_id FROM oc_poolbuy_pool p WHERE p.status='active' AND p.pool_id NOT IN (SELECT pool_id FROM oc_poolbuy_reservation WHERE customer_id=8 AND status IN ('pending','confirmed')) AND p.reserved_qty < p.moq_target ORDER BY p.pool_id LIMIT 1;"
if ($targetPool -gt 0) {
    $addr = Get-OcSqlInt "SELECT address_id FROM oc_address WHERE customer_id=8 LIMIT 1;"
    $avail = Get-OcSqlInt "SELECT (moq_target - reserved_qty) FROM oc_poolbuy_pool WHERE pool_id=$targetPool;"
    $body = "step=3&quantity=999999&unit_price=1&subtotal=1&total=1&gst=0&fee=0&address_id=$addr&shipping_cost=0"
    $r = Get-Page "$base/index.php?route=extension/poolbuy/join&pool_id=$targetPool" $s 'POST' $body
    $row = Get-OcSqlScalar "SELECT CONCAT(quantity,'|',unit_price_locked) FROM oc_poolbuy_reservation WHERE pool_id=$targetPool AND customer_id=8 ORDER BY reservation_id DESC LIMIT 1;"
    Write-Host "    pool $targetPool available=$avail stored qty|price = $row"
    if ($row) {
        $parts = $row -split '\|'
        Assert ([int]$parts[0] -le $avail) "quantity clamped to what was available ($($parts[0]) <= $avail), not 999999"
        Assert ([double]$parts[1] -ne 1) "unit price was recomputed server-side ($($parts[1])), not the posted 1"
        Assert ([double]$parts[1] -gt 0) 'a real tier price was locked'
        # Clean up this probe reservation
        $rid = Get-OcSqlInt "SELECT reservation_id FROM oc_poolbuy_reservation WHERE pool_id=$targetPool AND customer_id=8 ORDER BY reservation_id DESC LIMIT 1;"
        Invoke-OcSql "DELETE FROM oc_poolbuy_reservation WHERE reservation_id=$rid;"
        Invoke-OcSql "UPDATE oc_poolbuy_pool p SET reserved_qty=(SELECT COALESCE(SUM(quantity),0) FROM oc_poolbuy_reservation r WHERE r.pool_id=p.pool_id AND r.status IN ('pending','confirmed','converted')) WHERE p.pool_id=$targetPool;"
        Invoke-OcSql "DELETE FROM oc_poolbuy_pool_event WHERE pool_id=$targetPool AND type='reservation_created' AND customer_id=8;"
    } else {
        Assert $false 'the price/quantity probe did not create a reservation to inspect'
    }
} else {
    Assert $false 'no suitable pool found for the price trust probe'
}

Write-Host "`n=== [J] reserved_qty IS NOT WRITABLE THROUGH ANY FORM ==="
$admin = Connect-OcAdmin
$before = Get-OcSqlInt "SELECT reserved_qty FROM oc_poolbuy_pool WHERE pool_id=1;"
$pool1 = Get-OcSqlScalar "SELECT CONCAT_WS('|', product_id, seller_id, moq_target, reference, title) FROM oc_poolbuy_pool WHERE pool_id=1;"
$f = $pool1 -split '\|'
$body = "pool_id=1&product_id=$($f[0])&seller_id=$($f[1])&moq_target=$($f[2])&reference=$($f[3])&title=" + [uri]::EscapeDataString($f[4]) +
        "&unit_label=Units&status=active&reserved_qty=999999&currency_code=INR" +
        "&date_start=" + [uri]::EscapeDataString((Get-Date).AddDays(-1).ToString('yyyy-MM-dd HH:mm:ss')) +
        "&date_end=" + [uri]::EscapeDataString((Get-Date).AddDays(3).ToString('yyyy-MM-dd HH:mm:ss')) +
        "&min_qty_per_buyer=10&max_qty_per_buyer=0&retro_pricing=1&allow_full_moq_buy=1" +
        "&lead_time=15-20+Days&shipping_terms=FOB+Shanghai" +
        "&pool_tier%5B0%5D%5Bmin_qty%5D=10&pool_tier%5B0%5D%5Bmax_qty%5D=49&pool_tier%5B0%5D%5Bprice%5D=1550" +
        "&pool_tier%5B1%5D%5Bmin_qty%5D=50&pool_tier%5B1%5D%5Bmax_qty%5D=149&pool_tier%5B1%5D%5Bprice%5D=1380" +
        "&pool_tier%5B2%5D%5Bmin_qty%5D=150&pool_tier%5B2%5D%5Bmax_qty%5D=&pool_tier%5B2%5D%5Bprice%5D=1250"
$resp = Invoke-OcAdmin $admin 'extension/poolbuy/poolbuy/pool.save' @{ pool_id = '1' } -RawBody $body
Write-Host "    save response: $((($resp.Content) -replace '\s+',' ').Substring(0, [Math]::Min(160, $resp.Content.Length)))"
# The save MUST have succeeded, otherwise reserved_qty staying put proves nothing.
Assert ($resp.Content -match '"success"') 'the admin pool save actually succeeded (so this test is meaningful)'
$after = Get-OcSqlInt "SELECT reserved_qty FROM oc_poolbuy_pool WHERE pool_id=1;"
Assert ($after -eq $before) "posting reserved_qty=999999 left it at the real $after (was $before)"
Assert ($after -ne 999999) 'reserved_qty is not client-writable'

Write-Host "`n=== [K] NO PHP ERRORS WERE LOGGED DURING THIS SWEEP ==="
$log = docker compose exec -T opencart sh -lc "cat /var/www/html/system/storage/logs/error.log" 2>$null
if ($log) { Write-Host $log -ForegroundColor Yellow }
Assert ([string]::IsNullOrWhiteSpace($log)) 'OpenCart error.log is empty'

Write-Host ""
if ($failures.Count -eq 0) {
    Write-Host "ALL SECURITY CHECKS PASSED" -ForegroundColor Green
    exit 0
} else {
    Write-Host "$($failures.Count) SECURITY CHECK(S) FAILED" -ForegroundColor Red
    $failures | ForEach-Object { Write-Host "  - $_" -ForegroundColor Red }
    exit 1
}
