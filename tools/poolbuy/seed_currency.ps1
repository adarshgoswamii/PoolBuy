# Enables Indian Rupee on the storefront.
#
# WHY THIS IS NEEDED
#
# A stock OpenCart install ships INR in oc_currency but with status = 0, so it never
# appears in the storefront currency switcher. PoolBuy, meanwhile, is configured to
# trade in rupees (module_poolbuy_currency_code = INR) and the lifecycle stamps each
# converted order with currencyId('INR'). That left the store stamping orders with a
# currency it had disabled, and buyers unable to select rupees at all.
#
# WHAT THIS DOES NOT DO
#
# It does not rebase the catalogue. USD remains the base currency (value = 1.0), so
# stock OpenCart product prices are still held in USD and converted for display.
# PoolBuy pool prices are unaffected either way: each pool carries its own
# currency_code and orders are written with currency_value = 1.0, deliberately
# unconverted, so a pool priced at 1,550 rupees is always 1,550 rupees.
#
# Idempotent: safe to run repeatedly.
#
# Usage: powershell -File tools\poolbuy\seed_currency.ps1

$ErrorActionPreference = 'Stop'
. "$PSScriptRoot\admin.ps1"

$rupee = [char]0x20B9

# The row exists in a stock install; create it only if someone has removed it.
$exists = Get-OcSqlInt "SELECT COUNT(*) FROM oc_currency WHERE code='INR';"

if ($exists -eq 0) {
    Invoke-OcSql @"
INSERT INTO oc_currency (title, code, symbol_left, symbol_right, decimal_place, value, status, date_modified)
VALUES ('Indian Rupee', 'INR', '$rupee', '', 2, 95.11028150, 1, NOW());
"@
    Write-Host "  created the INR currency row"
} else {
    Write-Host "  INR currency row already present"
}

# Enable it, and make sure the symbol is the rupee sign on the left with two decimals.
# A disabled currency is silently dropped from the switcher and from currency
# formatting, which is the actual bug being fixed here.
Invoke-OcSql @"
UPDATE oc_currency
SET status = 1,
    symbol_left = '$rupee',
    symbol_right = '',
    decimal_place = 2,
    date_modified = NOW()
WHERE code = 'INR';
"@

# OpenCart caches the currency list, so a newly enabled currency will not appear in
# the switcher until the cache is dropped.
& "$PSScriptRoot\clear_cache.ps1" | Out-Null

$enabled = Get-OcSqlScalar "SELECT GROUP_CONCAT(code ORDER BY code) FROM oc_currency WHERE status=1;"
$default = Get-OcSqlScalar "SELECT value FROM oc_setting WHERE code='config' AND $([char]96)key$([char]96)='config_currency';"

Write-Host "  enabled currencies : $enabled"
Write-Host "  store default      : $default"

if ((Get-OcSqlInt "SELECT status FROM oc_currency WHERE code='INR';") -ne 1) {
    throw 'INR is still disabled; the currency switcher will not offer rupees.'
}
