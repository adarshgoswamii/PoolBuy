# Clears the OpenCart template/data cache without deleting the tracked guard file.
#
# `rm -rf system/storage/cache/*` also removes system/storage/cache/index.html,
# which is a tracked file (it stops directory listing on misconfigured servers).
# Deleting it shows up as a spurious repository change, so always clear the cache
# through this script.
#
# Usage: powershell -File tools\poolbuy\clear_cache.ps1

$ErrorActionPreference = 'Stop'

docker compose exec -T opencart sh -lc "find /var/www/html/system/storage/cache -mindepth 1 ! -name index.html -delete" | Out-Null

$remaining = docker compose exec -T opencart sh -lc "ls /var/www/html/system/storage/cache"

Write-Host "cache cleared; preserved:" -ForegroundColor Green
Write-Host "  $remaining"
