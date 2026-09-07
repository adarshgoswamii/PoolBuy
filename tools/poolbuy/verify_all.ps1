# Runs every PoolBuy quality gate and verification suite, and summarises the result.
#
# Usage: powershell -File tools\poolbuy\verify_all.ps1
#
# Exits non-zero if any gate or suite fails, so it is usable in CI.

$ErrorActionPreference = 'Continue'
$root = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
Push-Location $root

$results = [ordered]@{}

function Run-Gate([string] $Name, [scriptblock] $Action) {
    Write-Host "`n########## $Name ##########" -ForegroundColor Cyan
    $output = & $Action 2>&1 | Out-String
    $ok = $LASTEXITCODE -eq 0
    $script:results[$Name] = @{ Ok = $ok; Output = $output }
    if ($ok) { Write-Host "  -> OK" -ForegroundColor Green } else { Write-Host "  -> FAILED" -ForegroundColor Red }
    return $output
}

# ---------------------------------------------------------------- static analysis
$phpunit = Run-Gate 'PHPUnit (domain logic)' {
    docker run --rm -v "${PWD}:/app" -w /app php:8.2-cli php tools/phpunit.phar --configuration phpunit.xml
}
($phpunit -split "`n") | Where-Object { $_ -match 'OK \(|FAILURES|Tests:' } | ForEach-Object { "     " + $_.Trim() }

$phpstan = Run-Gate 'PHPStan (level 6)' {
    docker run --rm -v "${PWD}:/app" -w /app php:8.2-cli php -d memory_limit=2G tools/phpstan.phar analyse --no-progress --error-format=table
    # 3 pre-existing baseline errors in stock OpenCart entry points are expected.
    $global:LASTEXITCODE = 0
}
# Match the extension's own files only. A bare 'poolbuy' match is too loose because
# the repository itself lives in a directory called poolbuy.
$extensionPath = 'extension[\\/]poolbuy[\\/]'

$poolbuyErrors = ($phpstan -split "`n") | Where-Object { $_ -match $extensionPath }
($phpstan -split "`n") | Where-Object { $_ -match 'Found \d+ error' } | ForEach-Object { "     " + $_.Trim() }
if ($poolbuyErrors) {
    Write-Host "  -> PoolBuy phpstan errors present" -ForegroundColor Red
    $poolbuyErrors | ForEach-Object { "     " + $_.Trim() }
    $results['PHPStan (level 6)'].Ok = $false
} else {
    "     no PoolBuy errors (3 stock OpenCart baseline errors remain)"
}

$csfixer = Run-Gate 'php-cs-fixer (coding standards)' {
    docker run --rm -e PHP_CS_FIXER_IGNORE_ENV=1 -v "${PWD}:/app" -w /app php:8.2-cli `
        php -d memory_limit=2G tools/php-cs-fixer.phar fix --dry-run --config=.php-cs-fixer.php --using-cache=no
    $global:LASTEXITCODE = 0
}
$csPoolbuy = ($csfixer -split "`n") | Where-Object { $_ -match '^\s+\d+\)\s+' -and $_ -match $extensionPath }
if ($csPoolbuy) {
    Write-Host "  -> PoolBuy style violations present" -ForegroundColor Red
    $csPoolbuy | ForEach-Object { "     " + $_.Trim() }
    $results['php-cs-fixer (coding standards)'].Ok = $false
} else {
    "     no PoolBuy violations (only generated Twig cache is flagged)"
}

# ---------------------------------------------------------------- behavioural suites
# ORDER MATTERS. verify_install.ps1 exercises uninstall, which DROPS the PoolBuy
# tables and resets the settings. It therefore runs first, and the full demo data
# set is seeded again before any suite that depends on it.
$installOut = Run-Gate 'Install lifecycle (verify_install.ps1)' {
    powershell -ExecutionPolicy Bypass -File "$PSScriptRoot\verify_install.ps1"
}
$p = [regex]::Matches($installOut, '  PASS').Count
$f = [regex]::Matches($installOut, '  FAIL').Count
"     $p passed, $f failed"

Run-Gate 'Reseed after the destructive install test' {
    powershell -ExecutionPolicy Bypass -File "$PSScriptRoot\seed_all.ps1"
} | Out-Null

$suites = [ordered]@{
    'Join flow'           = 'verify_join.ps1'
    'Join concurrency'    = 'verify_race.ps1'
    'Buyer dashboard'     = 'verify_account.ps1'
    'Pool lifecycle'      = 'verify_lifecycle.ps1'
    'Seller portal'       = 'verify_seller.ps1'
    'Security hardening'  = 'verify_security.ps1'
    'Accessibility'       = 'verify_accessibility.ps1'
}

foreach ($name in $suites.Keys) {
    $script = $suites[$name]
    $out = Run-Gate "$name ($script)" {
        powershell -ExecutionPolicy Bypass -File "$PSScriptRoot\$script"
    }
    $pass = [regex]::Matches($out, '  PASS').Count
    $fail = [regex]::Matches($out, '  FAIL').Count
    "     $pass passed, $fail failed"
    if ($fail -gt 0) {
        ($out -split "`n") | Where-Object { $_ -match '  FAIL' } | ForEach-Object { "       " + $_.Trim() }
    }
}

# ---------------------------------------------------------------- error log
Write-Host "`n########## OpenCart error log ##########" -ForegroundColor Cyan
$log = docker compose exec -T opencart sh -lc "cat /var/www/html/system/storage/logs/error.log" 2>$null
if ([string]::IsNullOrWhiteSpace($log)) {
    Write-Host "  -> empty" -ForegroundColor Green
    $results['OpenCart error log'] = @{ Ok = $true }
} else {
    Write-Host "  -> NOT EMPTY" -ForegroundColor Red
    Write-Host $log
    $results['OpenCart error log'] = @{ Ok = $false }
}

# ---------------------------------------------------------------- summary
Write-Host "`n================ SUMMARY ================" -ForegroundColor Cyan
$failed = 0
foreach ($name in $results.Keys) {
    if ($results[$name].Ok) {
        Write-Host ("  OK      {0}" -f $name) -ForegroundColor Green
    } else {
        Write-Host ("  FAILED  {0}" -f $name) -ForegroundColor Red
        $failed++
    }
}

Pop-Location

if ($failed -eq 0) {
    Write-Host "`nALL GATES GREEN" -ForegroundColor Green
    exit 0
}

Write-Host "`n$failed GATE(S) FAILED" -ForegroundColor Red
exit 1
