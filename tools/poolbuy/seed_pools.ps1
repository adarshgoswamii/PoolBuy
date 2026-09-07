# Seeds PoolBuy demo pools through the real admin endpoints, so seed data passes
# exactly the same validation a human operator would face.
#
# Idempotent: pools are matched on their reference and skipped if already present.
#
# Requires sellers to exist first: tools\poolbuy\seed_sellers.ps1
#
# Usage: powershell -File tools\poolbuy\seed_pools.ps1

$ErrorActionPreference = 'Stop'
. "$PSScriptRoot\admin.ps1"

$admin = Connect-OcAdmin

# Real product ids from the OpenCart demo catalogue, so pools attach to products
# that actually render on the storefront.
$productIds = @(docker compose exec -T -e MYSQL_PWD=opencart mysql mysql -uroot opencart -N -B -e `
    "SELECT p.product_id FROM oc_product p WHERE p.status=1 AND p.date_available <= NOW() ORDER BY p.product_id LIMIT 12;" |
    Where-Object { $_ -match '^\d+$' } | ForEach-Object { [int]$_.Trim() })

if ($productIds.Count -lt 6) {
    Write-Host "Need at least 6 enabled products; found $($productIds.Count)." -ForegroundColor Red
    exit 1
}

# reference, title, seller_id, moq, unit label, reserved target, tier ladder, lead time, terms
$pools = @(
    @{ ref='PB-99201'; title='Titan-X Industrial Grade 5HP Submersible Drainage Pump'; seller=1; moq=200; unit='Units';   fill=150; days=3;  lead='15-20 Days'; terms='FOB Shanghai';
       tiers=@(@{min=10;max=49;price=1550},@{min=50;max=149;price=1380},@{min=150;max='';price=1250}) }
    @{ ref='PB-99210'; title='Industrial Grade Filtration Units - Q4 Procurement';     seller=2; moq=500; unit='Units';   fill=410; days=1;  lead='10-14 Days'; terms='FOB Nhava Sheva';
       tiers=@(@{min=10;max=49;price=1250},@{min=50;max=99;price=1100},@{min=100;max='';price=950}) }
    @{ ref='PB-88301'; title='EliteFlow Filtration Pro-7';                             seller=2; moq=200; unit='Units';   fill=150; days=5;  lead='12-18 Days'; terms='FOB Mundra';
       tiers=@(@{min=10;max=99;price=165},@{min=100;max=199;price=152},@{min=200;max='';price=145}) }
    @{ ref='PB-88302'; title='CrystalPure Chlorine Tablets (25kg)';                     seller=3; moq=500; unit='Buckets'; fill=420; days=2;  lead='7-10 Days';  terms='Ex Works Surat';
       tiers=@(@{min=25;max=199;price=94},@{min=200;max=499;price=88},@{min=500;max='';price=82}) }
    @{ ref='PB-88303'; title='LumiPool RGB Smart LED Kits';                             seller=4; moq=100; unit='Kits';    fill=12;  days=12; lead='20-25 Days'; terms='FOB Shenzhen';
       tiers=@(@{min=5;max=49;price=240},@{min=50;max=99;price=225},@{min=100;max='';price=210}) }
    @{ ref='PB-88304'; title='Li-Ion Enterprise Cells (Batch 42)';                      seller=5; moq=1000; unit='Units';  fill=780; days=1;  lead='18-22 Days'; terms='FOB Chennai';
       tiers=@(@{min=50;max=499;price=64},@{min=500;max=999;price=58},@{min=1000;max='';price=52}) }
    @{ ref='PB-88305'; title='Brushed Al-Chassis (Model X-5)';                          seller=1; moq=500; unit='Units';   fill=420; days=3;  lead='14-18 Days'; terms='FOB Mumbai';
       tiers=@(@{min=25;max=249;price=310},@{min=250;max=499;price=288},@{min=500;max='';price=265}) }
    @{ ref='PB-88306'; title='Ceramic Mosaic XL';                                       seller=3; moq=200; unit='Boxes';   fill=195; days=2;  lead='9-12 Days';  terms='Ex Works Morbi';
       tiers=@(@{min=10;max=99;price=1180},@{min=100;max=199;price=1090},@{min=200;max='';price=990}) }
    @{ ref='PB-88307'; title='Copper Conductors (200m)';                                seller=5; moq=300; unit='Reels';   fill=90;  days=9;  lead='11-15 Days'; terms='FOB Chennai';
       tiers=@(@{min=10;max=99;price=1450},@{min=100;max=199;price=1340},@{min=200;max='';price=1240}) }
    @{ ref='PB-88308'; title='Medical Grade Vials (Case)';                              seller=4; moq=400; unit='Cases';   fill=0;   days=15; lead='16-20 Days'; terms='FOB Gurugram';
       tiers=@(@{min=20;max=199;price=980},@{min=200;max=399;price=930},@{min=400;max='';price=890}) }
)

$created = 0
$skipped = 0
$i = 0

foreach ($pool in $pools) {
    $existing = Get-OcSqlInt "SELECT COUNT(*) FROM oc_poolbuy_pool WHERE reference='$($pool.ref)';"

    if ($existing -gt 0) {
        Write-Host "  skip    $($pool.ref) $($pool.title)" -ForegroundColor DarkGray
        $skipped++
        $i++
        continue
    }

    $productId = $productIds[$i % $productIds.Count]
    $i++

    $start = (Get-Date).AddDays(-2).ToString('yyyy-MM-dd HH:mm:ss')
    $end   = (Get-Date).AddDays($pool.days).ToString('yyyy-MM-dd HH:mm:ss')

    $body = "pool_id=0&status=active" +
        "&product_id=$productId" +
        "&seller_id=$($pool.seller)" +
        "&title=" + [uri]::EscapeDataString($pool.title) +
        "&reference=$($pool.ref)" +
        "&moq_target=$($pool.moq)" +
        "&unit_label=" + [uri]::EscapeDataString($pool.unit) +
        "&currency_code=INR" +
        "&date_start=" + [uri]::EscapeDataString($start) +
        "&date_end=" + [uri]::EscapeDataString($end) +
        "&min_qty_per_buyer=10&max_qty_per_buyer=0" +
        "&retro_pricing=1&allow_full_moq_buy=1" +
        "&lead_time=" + [uri]::EscapeDataString($pool.lead) +
        "&shipping_terms=" + [uri]::EscapeDataString($pool.terms)

    $t = 0
    foreach ($tier in $pool.tiers) {
        $body += "&pool_tier%5B$t%5D%5Bmin_qty%5D=$($tier.min)"
        $body += "&pool_tier%5B$t%5D%5Bmax_qty%5D=$($tier.max)"
        $body += "&pool_tier%5B$t%5D%5Bprice%5D=$($tier.price)"
        $t++
    }

    $uri = "$($admin.BaseUrl)/admin/index.php?route=extension/poolbuy/poolbuy/pool.save&user_token=$($admin.UserToken)"
    $response = Invoke-WebRequest -Uri $uri -Method POST -Body $body -ContentType 'application/x-www-form-urlencoded' -WebSession $admin.Session -UseBasicParsing

    if ($response.Content -notmatch '"success"') {
        Write-Host "  FAILED  $($pool.ref): $($response.Content)" -ForegroundColor Red
        exit 1
    }

    Write-Host "  created $($pool.ref) $($pool.title)" -ForegroundColor Green
    $created++

    # Demo participation. Reservations are written directly here because the join
    # flow needs a logged-in customer; the storefront path is exercised separately.
    if ($pool.fill -gt 0) {
        $poolId = Get-OcSqlInt "SELECT pool_id FROM oc_poolbuy_pool WHERE reference='$($pool.ref)';"
        $customerId = Get-OcSqlInt "SELECT customer_id FROM oc_customer ORDER BY customer_id LIMIT 1;"

        if ($customerId -gt 0) {
            # Spread the fill across a few synthetic buyers so participant counts look real
            $half = [int][math]::Floor($pool.fill * 0.5)
            $third = [int][math]::Floor($pool.fill * 0.3)
            $rest = [int]$pool.fill - $half - $third
            $chunks = @($half, $third, $rest)
            $n = 0
            foreach ($qty in $chunks) {
                if ($qty -le 0) { continue }
                $ref = "TX-$($pool.ref.Substring(3))-$n"
                # Each chunk needs a distinct customer because of the unique active
                # reservation constraint, so synthetic buyers are created as needed.
                $email = "poolbuyer$n@example.com"
                $buyerId = Get-OcSqlInt "SELECT customer_id FROM oc_customer WHERE email='$email';"
                if ($buyerId -eq 0) {
                    Invoke-OcSql "INSERT INTO oc_customer (customer_group_id, store_id, language_id, firstname, lastname, email, password, telephone, custom_field, newsletter, ip, status, safe, commenter, token, code, date_added) VALUES (1,0,1,'Pool','Buyer $n','$email','x','','',0,'127.0.0.1',1,0,0,'','',NOW());"
                    $buyerId = Get-OcSqlInt "SELECT customer_id FROM oc_customer WHERE email='$email';"
                }
                Invoke-OcSql "INSERT INTO oc_poolbuy_reservation (pool_id, customer_id, quantity, unit_price_locked, status, reference, date_added, date_modified) VALUES ($poolId, $buyerId, $qty, 0, 'confirmed', '$ref', NOW() - INTERVAL $($n*5) HOUR, NOW());"
                $n++
            }
            Invoke-OcSql "UPDATE oc_poolbuy_pool SET reserved_qty = (SELECT COALESCE(SUM(quantity),0) FROM oc_poolbuy_reservation WHERE pool_id=$poolId AND status IN ('pending','confirmed','converted')) WHERE pool_id=$poolId;"
        }
    }
}

Write-Host "`nPools: $created created, $skipped already present." -ForegroundColor Cyan
docker compose exec -T -e MYSQL_PWD=opencart mysql mysql -uroot opencart -e `
    "SELECT p.pool_id, p.reference, LEFT(p.title,38) AS title, s.name AS seller, p.moq_target, p.reserved_qty, ROUND(p.reserved_qty/p.moq_target*100) AS pct, p.status FROM oc_poolbuy_pool p LEFT JOIN oc_poolbuy_seller s ON s.seller_id=p.seller_id ORDER BY p.pool_id;"
