# Verifies the API rate limiter on the machine-facing endpoints.
#
# The property that matters most is that a blank configured limit does NOT mean
# unlimited, mirroring the cron token's fail-closed behaviour, and that a refused
# request still counts so a caller already over the limit cannot hammer for free.
#
# Requires: tools\poolbuy\seed_all.ps1
#
# Usage: powershell -File tools\poolbuy\verify_ratelimit.ps1

$ErrorActionPreference = 'Stop'
. "$PSScriptRoot\admin.ps1"

$base = 'http://localhost'
$tick = [char]96

$failures = @()

function Assert([bool] $Condition, [string] $Message) {
    if ($Condition) { Write-Host "  PASS  $Message" -ForegroundColor Green }
    else { Write-Host "  FAIL  $Message" -ForegroundColor Red; $script:failures += $Message }
}

function Set-Setting([string] $Key, [string] $Value) {
    # Shared helper: deletes then inserts, because oc_setting has no unique key on
    # (store_id, code, key) and an upsert would silently append a duplicate row.
    Set-OcPoolbuySetting -Key $Key -Value $Value
}

function Get-Setting([string] $Key) {
    return Get-OcSqlScalar "SELECT value FROM oc_setting WHERE code='module_poolbuy' AND $tick`key$tick='$Key' LIMIT 1;"
}

function Reset-Counters {
    Invoke-OcSql "DELETE FROM oc_poolbuy_rate_limit;"
}

# Returns the status code plus the Retry-After header, without throwing on 4xx.
function Send([string] $Url, [string] $Method = 'GET', $Body = $null) {
    try {
        $params = @{ Uri = $Url; Method = $Method; UseBasicParsing = $true; TimeoutSec = 60 }
        if ($null -ne $Body) { $params['Body'] = $Body }
        $r = Invoke-WebRequest @params
        return @{ Code = [int]$r.StatusCode; Retry = ($r.Headers['Retry-After'] -join '') }
    } catch {
        $resp = $_.Exception.Response
        if ($null -eq $resp) { return @{ Code = 0; Retry = '' } }
        $code = [int]$resp.StatusCode
        $retry = ''
        try { $retry = ($resp.Headers['Retry-After'] -join '') } catch { $retry = '' }
        return @{ Code = $code; Retry = $retry }
    }
}

$originalLimit = Get-Setting 'module_poolbuy_rate_limit'
$originalWindow = Get-Setting 'module_poolbuy_rate_window'
Write-Host "  starting limit=$originalLimit window=$originalWindow"

$token = Get-Setting 'module_poolbuy_cron_token'

try {
    # ---------------------------------------------------------------------------
    Write-Host "`n[1] The rate limit table exists and is owned by the extension"
    Assert ((Get-OcSqlInt "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='opencart' AND table_name='oc_poolbuy_rate_limit';") -eq 1) 'oc_poolbuy_rate_limit is installed'

    # ---------------------------------------------------------------------------
    Write-Host "`n[2] The cron trigger is limited, and refused requests still count"
    Set-Setting 'module_poolbuy_rate_limit' '3'
    Set-Setting 'module_poolbuy_rate_window' '60'
    Reset-Counters

    # Deliberately the WRONG token: the limiter must apply before the token check, so
    # this endpoint cannot be used as a free oracle for guessing the token.
    $codes = @()
    foreach ($i in 1..5) {
        $codes += (Send "$base/index.php?route=extension/poolbuy/cron/poolbuy.run&token=definitely-wrong").Code
    }
    Write-Host ("      codes: " + ($codes -join ', '))

    Assert (($codes[0] -eq 403) -and ($codes[1] -eq 403) -and ($codes[2] -eq 403)) 'the first three requests are judged on the token (403), not throttled'
    Assert ($codes[3] -eq 429) 'the fourth request in the window is refused with 429'
    Assert ($codes[4] -eq 429) 'a caller already over the limit stays refused'
    Assert ((Get-OcSqlInt "SELECT COALESCE(MAX(hits),0) FROM oc_poolbuy_rate_limit;") -ge 5) 'refused requests are still counted'
    Assert ((Get-OcSqlInt "SELECT COUNT(*) FROM oc_poolbuy_rate_limit;") -eq 1) 'one window and one caller share a single counter row'

    # ---------------------------------------------------------------------------
    Write-Host "`n[3] A 429 carries Retry-After so a client knows when to come back"
    $limited = Send "$base/index.php?route=extension/poolbuy/cron/poolbuy.run&token=definitely-wrong"
    Assert ($limited.Code -eq 429) 'still limited'
    Assert ($limited.Retry -match '^\d+$') "Retry-After is a number of seconds (got '$($limited.Retry)')"
    Assert ([int]$limited.Retry -ge 1) 'Retry-After is never 0'
    Assert ([int]$limited.Retry -le 60) 'Retry-After never exceeds the window'

    # ---------------------------------------------------------------------------
    Write-Host "`n[4] A valid token does not exempt a caller from the limiter"
    Reset-Counters
    $validCodes = @()
    foreach ($i in 1..4) {
        $validCodes += (Send "$base/index.php?route=extension/poolbuy/cron/poolbuy.run&token=$token").Code
    }
    Write-Host ("      codes: " + ($validCodes -join ', '))
    Assert ($validCodes[0] -eq 200) 'an authenticated run succeeds'
    Assert ($validCodes[3] -eq 429) 'an authenticated caller is still throttled past the limit'

    # ---------------------------------------------------------------------------
    Write-Host "`n[5] The payment webhook is limited on its own counter"
    Reset-Counters
    Set-Setting 'module_poolbuy_rate_limit' '2'

    $whBody = '{"event":"payment.captured","payload":{}}'
    $whCodes = @()
    foreach ($i in 1..4) {
        $whCodes += (Send "$base/index.php?route=extension/poolbuy/payment.webhook" 'POST' $whBody).Code
    }
    Write-Host ("      codes: " + ($whCodes -join ', '))
    Assert ($whCodes[0] -eq 403) 'an unsigned webhook is still refused on its merits first'
    Assert ($whCodes[2] -eq 429) 'the webhook is throttled past its limit'
    Assert ((Get-OcSqlInt "SELECT COUNT(*) FROM oc_poolbuy_rate_limit;") -eq 1) 'the webhook counts against its own bucket'

    # The cron endpoint must have its own counter, not share the webhook's.
    $cronAfterWebhook = Send "$base/index.php?route=extension/poolbuy/cron/poolbuy.run&token=$token"
    Assert ($cronAfterWebhook.Code -ne 429) 'exhausting the webhook does not throttle the cron endpoint'
    Assert ((Get-OcSqlInt "SELECT COUNT(*) FROM oc_poolbuy_rate_limit;") -eq 2) 'each endpoint keeps a separate counter row'

    # ---------------------------------------------------------------------------
    Write-Host "`n[6] A BLANK configured limit falls back to a real limit, not to unlimited"
    Reset-Counters
    Set-Setting 'module_poolbuy_rate_limit' ''
    $blank = Send "$base/index.php?route=extension/poolbuy/cron/poolbuy.run&token=$token"
    Assert ($blank.Code -eq 200) 'a blank limit still serves legitimate traffic'
    Assert ((Get-OcSqlInt "SELECT COALESCE(MAX(hits),0) FROM oc_poolbuy_rate_limit;") -ge 1) 'a blank limit still COUNTS requests, so the limiter is active rather than off'

    # ---------------------------------------------------------------------------
    Write-Host "`n[7] An explicit 0 turns the limiter off, and says so by not counting"
    Reset-Counters
    Set-Setting 'module_poolbuy_rate_limit' '0'
    foreach ($i in 1..3) {
        Send "$base/index.php?route=extension/poolbuy/cron/poolbuy.run&token=$token" | Out-Null
    }
    Assert ((Get-OcSqlInt "SELECT COUNT(*) FROM oc_poolbuy_rate_limit;") -eq 0) 'a limit of 0 records nothing, confirming the limiter is deliberately disabled'

    # ---------------------------------------------------------------------------
    Write-Host "`n[8] Counters for closed windows are pruned by the lifecycle cron"
    Set-Setting 'module_poolbuy_rate_limit' '120'
    Reset-Counters
    Invoke-OcSql "INSERT INTO oc_poolbuy_rate_limit (bucket, hits, window_start) VALUES (REPEAT('a', 64), 9, UNIX_TIMESTAMP() - 100000);"
    Assert ((Get-OcSqlInt "SELECT COUNT(*) FROM oc_poolbuy_rate_limit WHERE bucket=REPEAT('a',64);") -eq 1) 'a stale counter exists before the cron runs'
    Send "$base/index.php?route=extension/poolbuy/cron/poolbuy.run&token=$token" | Out-Null
    Assert ((Get-OcSqlInt "SELECT COUNT(*) FROM oc_poolbuy_rate_limit WHERE bucket=REPEAT('a',64);") -eq 0) 'the lifecycle cron pruned the stale counter'
} finally {
    # Always restore, so a failure mid-suite cannot leave the store throttled.
    Set-Setting 'module_poolbuy_rate_limit' ($(if ([string]::IsNullOrWhiteSpace($originalLimit)) { '120' } else { $originalLimit }))
    Set-Setting 'module_poolbuy_rate_window' ($(if ([string]::IsNullOrWhiteSpace($originalWindow)) { '60' } else { $originalWindow }))
    Reset-Counters
    Write-Host "`n  restored limit=$(Get-Setting 'module_poolbuy_rate_limit') window=$(Get-Setting 'module_poolbuy_rate_window')"
}

Write-Host ""
if ($failures.Count -eq 0) {
    Write-Host "ALL RATE LIMIT CHECKS PASSED" -ForegroundColor Green
    exit 0
} else {
    Write-Host "$($failures.Count) CHECK(S) FAILED" -ForegroundColor Red
    exit 1
}
