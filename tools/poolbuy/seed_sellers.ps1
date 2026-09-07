# Seeds PoolBuy demo sellers through the real admin endpoints, so the seed data
# passes exactly the same validation a human operator would face.
#
# Idempotent: sellers are matched on their generated slug and skipped if present.
#
# Usage: powershell -File tools\poolbuy\seed_sellers.ps1

$ErrorActionPreference = 'Stop'
. "$PSScriptRoot\admin.ps1"

$sellers = @(
    @{ name = 'Titan Pumps Global Mfg.';    gst = '29ABCDE1234F1Z5'; rating = '4.8'; count = '240'; location = 'Main Warehouse, NJ';   desc = 'Industrial submersible and drainage pump manufacturer. ISO 9001 certified.' }
    @{ name = 'AquaGlobal Industrial Ltd.'; gst = '27AAAAA0000A1Z5'; rating = '4.5'; count = '88';  location = 'Bangalore, KA';        desc = 'Commercial filtration systems for industrial and municipal use.' }
    @{ name = 'ChemBulk Logistics';         gst = '24BBBBB1111B1Z5'; rating = '4.6'; count = '132'; location = 'Surat, GJ';           desc = 'Bulk industrial chemicals and water treatment compounds.' }
    @{ name = 'BrightTech Solutions';       gst = '06CCCCC2222C1Z5'; rating = '4.3'; count = '54';  location = 'Gurugram, HR';        desc = 'Smart LED lighting and IoT control systems.' }
    @{ name = 'VoltaGlobal Industrial';     gst = '33DDDDD3333D1Z5'; rating = '4.7'; count = '196'; location = 'Chennai, TN';         desc = 'Enterprise grade lithium-ion cells and battery packs.' }
)

function Get-Slug([string] $Name) {
    $slug = $Name.ToLowerInvariant()
    $slug = [regex]::Replace($slug, '[^a-z0-9]+', '-')
    return $slug.Trim('-')
}

$admin = Connect-OcAdmin
$created = 0
$skipped = 0

foreach ($seller in $sellers) {
    $slug = Get-Slug $seller.name

    $count = Get-OcSqlInt "SELECT COUNT(*) FROM oc_poolbuy_seller WHERE slug='$slug';"

    if ($count -gt 0) {
        Write-Host "  skip    $($seller.name) (already present)" -ForegroundColor DarkGray
        $skipped++
        continue
    }

    $body = "seller_id=0" +
        "&name=" + [uri]::EscapeDataString($seller.name) +
        "&slug=" + [uri]::EscapeDataString($slug) +
        "&gst_number=" + $seller.gst +
        "&gst_verified=1&verified_seller=1" +
        "&rating=" + $seller.rating +
        "&rating_count=" + $seller.count +
        "&location=" + [uri]::EscapeDataString($seller.location) +
        "&description=" + [uri]::EscapeDataString($seller.desc) +
        "&status=1"

    $uri = "$($admin.BaseUrl)/admin/index.php?route=extension/poolbuy/poolbuy/seller.save&user_token=$($admin.UserToken)"
    $response = Invoke-WebRequest -Uri $uri -Method POST -Body $body -ContentType 'application/x-www-form-urlencoded' -WebSession $admin.Session -UseBasicParsing

    if ($response.Content -match '"success"') {
        Write-Host "  created $($seller.name)" -ForegroundColor Green
        $created++
    } else {
        Write-Host "  FAILED  $($seller.name): $($response.Content)" -ForegroundColor Red
        exit 1
    }
}

Write-Host "`nSellers: $created created, $skipped already present." -ForegroundColor Cyan
docker compose exec -T -e MYSQL_PWD=opencart mysql mysql -uroot opencart -e "SELECT seller_id, name, gst_number, rating, location, status FROM oc_poolbuy_seller ORDER BY seller_id;"
