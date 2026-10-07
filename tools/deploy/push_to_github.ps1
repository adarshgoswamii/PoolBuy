# ==============================================================================
# POOLBUY B2B WHOLESALE: GITHUB DEPLOYMENT HELPER
# Script: tools/deploy/push_to_github.ps1
# Description: Cleanly pushes local commits to origin on GitHub.
# ==============================================================================

param(
    [string]$GitHubToken = "",
    [string]$RemoteUrl = "https://github.com/adarshgoswamii/PoolBuy.git",
    [string]$Branch = "main"
)

Write-Host "==================================================" -ForegroundColor Cyan
Write-Host "PoolBuy: Pushing Latest Commits to GitHub" -ForegroundColor Cyan
Write-Host "==================================================" -ForegroundColor Cyan

if ([string]::IsNullOrWhiteSpace($GitHubToken)) {
    Write-Host "Attempting standard git push to origin..." -ForegroundColor Yellow
    git push origin $Branch
    if ($LASTEXITCODE -ne 0) {
        Write-Host ""
        Write-Host "[!] Standard push requires collaborator credentials." -ForegroundColor Red
        Write-Host "If you have a GitHub Personal Access Token (PAT), run:" -ForegroundColor Yellow
        Write-Host "  powershell -File tools\deploy\push_to_github.ps1 -GitHubToken <YOUR_PAT>" -ForegroundColor Green
    }
} else {
    Write-Host "Pushing with provided authentication token..." -ForegroundColor Yellow
    $AuthUrl = $RemoteUrl.Replace("https://", "https://$($GitHubToken)@")
    git push $AuthUrl $Branch
    if ($LASTEXITCODE -eq 0) {
        Write-Host ""
        Write-Host "[✓] Successfully pushed all commits to GitHub $Branch!" -ForegroundColor Green
    } else {
        Write-Host ""
        Write-Host "[✗] Push failed. Please verify your token permissions (repo scope required)." -ForegroundColor Red
    }
}
