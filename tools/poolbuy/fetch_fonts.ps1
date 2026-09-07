# Refreshes the self-hosted Inter font used by the PoolBuy storefront.
#
# The design system specifies Inter exclusively. Rather than linking Google Fonts
# at runtime (an external dependency on every page load, and a privacy
# consideration), the woff2 subsets are downloaded once and the @font-face rules
# are rewritten to local paths.
#
# Usage: powershell -File tools\poolbuy\fetch_fonts.ps1

$ErrorActionPreference = 'Stop'

$repoRoot  = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$styleDir  = Join-Path $repoRoot 'upload\catalog\view\stylesheet'
$fontDir   = Join-Path $styleDir 'fonts\inter'
$outCss    = Join-Path $styleDir 'poolbuy-fonts.css'

New-Item -ItemType Directory -Force -Path $fontDir | Out-Null

# A modern user agent is required, otherwise Google Fonts serves legacy formats.
$ua  = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36'
$src = 'https://fonts.googleapis.com/css2?family=Inter:wght@400..800&display=swap'

$css = (Invoke-WebRequest -Uri $src -Headers @{ 'User-Agent' = $ua } -UseBasicParsing -TimeoutSec 30).Content

$urls = [regex]::Matches($css, 'url\((https://[^)]+\.woff2)\)') | ForEach-Object { $_.Groups[1].Value }

Write-Host "Downloading $($urls.Count) Inter subsets..."

for ($i = 0; $i -lt $urls.Count; $i++) {
    $target = Join-Path $fontDir "inter-$i.woff2"
    Invoke-WebRequest -Uri $urls[$i] -OutFile $target -UseBasicParsing -TimeoutSec 30
    Write-Host ("  inter-{0}.woff2  {1:N0} bytes" -f $i, (Get-Item $target).Length)
}

$header = @"
/**
 * Inter, self hosted.
 *
 * Generated from the Google Fonts css2 response with every remote URL rewritten
 * to a local file, so the storefront renders its typeface without contacting a
 * third party at runtime. The unicode-range blocks are preserved, so browsers
 * still download only the subsets a page actually needs.
 *
 * To refresh: re-run tools/poolbuy/fetch_fonts.ps1.
 */

"@

$index = 0
$local = [regex]::Replace($css, 'url\(https://[^)]+\.woff2\)', {
    param($m)
    $script:index++
    return "url('fonts/inter/inter-$($script:index - 1).woff2')"
})

Set-Content -Path $outCss -Value ($header + $local) -Encoding UTF8

Write-Host "Wrote $outCss" -ForegroundColor Green
