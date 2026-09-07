# Verifies the PoolBuy seller portal, with the emphasis on TENANCY ISOLATION:
# seller A must not be able to read or mutate seller B's pools under any crafted
# request, and an unapproved seller must not be able to publish.
#
# Usage: powershell -File tools\poolbuy\verify_seller.ps1

$ErrorActionPreference = 'Stop'
. "$PSScriptRoot\admin.ps1"

$base = 'http://localhost'
$failures = @()

function Assert([bool] $Condition, [string] $Message) {
    if ($Condition) { Write-Host "  PASS  $Message" -ForegroundColor Green }
    else { Write-Host "  FAIL  $Message" -ForegroundColor Red; $script:failures += $Message }
}

function Register-Buyer([string] $Email) {
    $id = Get-OcSqlInt "SELECT customer_id FROM oc_customer WHERE email='$Email';"

    if ($id -eq 0) {
        $s = New-Object Microsoft.PowerShell.Commands.WebRequestSession
        $page = Invoke-WebRequest -Uri "$base/index.php?route=account/register" -WebSession $s -UseBasicParsing
        if ($page.Content -notmatch 'register_token=([0-9a-zA-Z]+)') { throw 'no register_token' }
        $rt = $Matches[1]

        Invoke-WebRequest -Uri "$base/index.php?route=account/register.register&register_token=$rt" -Method POST -WebSession $s -UseBasicParsing -Body @{
            customer_group_id = '1'; firstname = 'Seller'; lastname = 'User'; email = $Email
            telephone = '9900000000'; password = 'PoolBuy123!'; confirm = 'PoolBuy123!'; agree = '1'
        } | Out-Null

        $id = Get-OcSqlInt "SELECT customer_id FROM oc_customer WHERE email='$Email';"
    }

    Invoke-OcSql "UPDATE oc_customer SET status=1 WHERE customer_id=$id;"

    return $id
}

function Connect-Buyer([string] $Email) {
    $s = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $page = Invoke-WebRequest -Uri "$base/index.php?route=account/login" -WebSession $s -UseBasicParsing
    if ($page.Content -notmatch 'login_token=([0-9a-zA-Z]+)') { throw 'no login_token' }
    $r = Invoke-WebRequest -Uri "$base/index.php?route=account/login.login&login_token=$($Matches[1])" `
        -Method POST -Body @{ email = $Email; password = 'PoolBuy123!' } -WebSession $s -UseBasicParsing
    if ($r.Content -notmatch 'customer_token') { throw "login failed for $Email" }
    return $s
}

# --- Link two sellers to two real accounts (seller 1 Titan, seller 3 ChemBulk)
$idA = Register-Buyer 'sellera@poolbuy.test'
$idB = Register-Buyer 'sellerb@poolbuy.test'
Invoke-OcSql "UPDATE oc_poolbuy_seller SET customer_id=$idA, status=1 WHERE seller_id=1;"
Invoke-OcSql "UPDATE oc_poolbuy_seller SET customer_id=$idB, status=1 WHERE seller_id=3;"

$poolA = Get-OcSqlInt "SELECT pool_id FROM oc_poolbuy_pool WHERE seller_id=1 ORDER BY pool_id LIMIT 1;"
$poolB = Get-OcSqlInt "SELECT pool_id FROM oc_poolbuy_pool WHERE seller_id=3 ORDER BY pool_id LIMIT 1;"
$refA = Get-OcSqlScalar "SELECT reference FROM oc_poolbuy_pool WHERE pool_id=$poolA;"
$refB = Get-OcSqlScalar "SELECT reference FROM oc_poolbuy_pool WHERE pool_id=$poolB;"

Write-Host "`nSeller A = seller_id 1 (customer $idA), owns pool $poolA / $refA"
Write-Host "Seller B = seller_id 3 (customer $idB), owns pool $poolB / $refB"

Write-Host "`n[1] Portal requires a login"
$anon = Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/seller" -UseBasicParsing -MaximumRedirection 5
Assert ($anon.Content -match 'name="email"' -or $anon.Content -match 'route=account/login') 'anonymous visitor is sent to login'
Assert (-not ($anon.Content -match 'Your Pool Campaigns')) 'no seller data leaks to anonymous visitors'

Write-Host "`n[2] A signed-in shopper who is not a seller is told so, politely"
$shopper = Connect-Buyer 'buyer@poolbuy.test'
$denied = Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/seller" -WebSession $shopper -UseBasicParsing
Assert ($denied.StatusCode -eq 200) 'non-seller gets a normal page, not an error'
Assert ($denied.Content -match 'This area is for sellers') 'explains the area is for sellers'
Assert (-not ($denied.Content -match [regex]::Escape($refA))) 'no pool references leak to a non-seller'

Write-Host "`n[3] Seller A sees only their own campaigns"
$sessionA = Connect-Buyer 'sellera@poolbuy.test'
$portalA = Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/seller" -WebSession $sessionA -UseBasicParsing
Assert ($portalA.StatusCode -eq 200) 'portal returns 200 for a seller'
Assert ($portalA.Content -match 'Seller Portal') 'heading present'
Assert ($portalA.Content -match 'Titan Pumps') 'own seller name shown'
Assert ($portalA.Content -match [regex]::Escape($refA)) "own pool $refA listed"
Assert (-not ($portalA.Content -match [regex]::Escape($refB))) "seller B's pool $refB NOT listed"
Assert (-not ($portalA.Content -match 'Fatal error|Twig\\Error')) 'no PHP or Twig errors'

Write-Host "`n[4] Seller A cannot READ seller B's pool by id"
$cross = Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/seller.pool&pool_id=$poolB" -WebSession $sessionA -UseBasicParsing -MaximumRedirection 5
Assert (-not ($cross.Content -match [regex]::Escape($refB))) "seller B's reference is NOT rendered to seller A"
Assert ($cross.Content -match 'could not be found in your account') 'shown a not-found message instead'

Write-Host "`n[5] Seller A cannot open the EDIT FORM for seller B's pool"
$crossForm = Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/seller.form&pool_id=$poolB" -WebSession $sessionA -UseBasicParsing -MaximumRedirection 5
Assert (-not ($crossForm.Content -match [regex]::Escape($refB))) "seller B's reference is NOT exposed in the form"

Write-Host "`n[6] Seller A cannot SAVE changes to seller B's pool"
$titleBefore = Get-OcSqlScalar "SELECT title FROM oc_poolbuy_pool WHERE pool_id=$poolB;"
$productB = Get-OcSqlInt "SELECT product_id FROM oc_poolbuy_pool WHERE pool_id=$poolB;"
$body = "product_id=$productB&title=HIJACKED&moq_target=999&unit_label=Units&status=active" +
        "&date_start=" + [uri]::EscapeDataString((Get-Date).ToString('yyyy-MM-dd HH:mm:ss')) +
        "&date_end=" + [uri]::EscapeDataString((Get-Date).AddDays(5).ToString('yyyy-MM-dd HH:mm:ss')) +
        "&min_qty_per_buyer=1&max_qty_per_buyer=0" +
        "&pool_tier%5B0%5D%5Bmin_qty%5D=1&pool_tier%5B0%5D%5Bmax_qty%5D=&pool_tier%5B0%5D%5Bprice%5D=10"
$hijack = Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/seller.save&pool_id=$poolB" `
    -Method POST -Body $body -ContentType 'application/x-www-form-urlencoded' -WebSession $sessionA -UseBasicParsing
Write-Host "    response: $($hijack.Content)"
$titleAfter = Get-OcSqlScalar "SELECT title FROM oc_poolbuy_pool WHERE pool_id=$poolB;"
Assert ($titleAfter -eq $titleBefore) "seller B's pool title unchanged (still '$titleAfter')"
$moqAfter = Get-OcSqlInt "SELECT moq_target FROM oc_poolbuy_pool WHERE pool_id=$poolB;"
Assert ($moqAfter -ne 999) "seller B's MOQ was NOT overwritten (still $moqAfter)"

Write-Host "`n[6b] The POOL ownership guard is what stops it, not just the product guard"
# The attempt above was refused because the product belonged to seller B. Repeat it
# using a product seller A genuinely owns, so the product guard passes and the pool
# ownership guard is the thing under test.
$ownedByA = Get-OcSqlInt "SELECT product_id FROM oc_poolbuy_product_seller WHERE seller_id=1 LIMIT 1;"
if ($ownedByA -eq 0) {
    $ownedByA = Get-OcSqlInt "SELECT product_id FROM oc_poolbuy_pool WHERE seller_id=1 LIMIT 1;"
    Invoke-OcSql "REPLACE INTO oc_poolbuy_product_seller (product_id, seller_id) VALUES ($ownedByA, 1);"
}
$titleBefore2 = Get-OcSqlScalar "SELECT title FROM oc_poolbuy_pool WHERE pool_id=$poolB;"
$productBefore2 = Get-OcSqlInt "SELECT product_id FROM oc_poolbuy_pool WHERE pool_id=$poolB;"
$body6b = "product_id=$ownedByA&title=HIJACKED2&moq_target=777&unit_label=Units&status=active" +
        "&date_start=" + [uri]::EscapeDataString((Get-Date).ToString('yyyy-MM-dd HH:mm:ss')) +
        "&date_end=" + [uri]::EscapeDataString((Get-Date).AddDays(5).ToString('yyyy-MM-dd HH:mm:ss')) +
        "&min_qty_per_buyer=1&max_qty_per_buyer=0" +
        "&pool_tier%5B0%5D%5Bmin_qty%5D=1&pool_tier%5B0%5D%5Bmax_qty%5D=&pool_tier%5B0%5D%5Bprice%5D=10"
$hijack2 = Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/seller.save&pool_id=$poolB" `
    -Method POST -Body $body6b -ContentType 'application/x-www-form-urlencoded' -WebSession $sessionA -UseBasicParsing
Write-Host "    response: $($hijack2.Content)"
Assert ($hijack2.Content -match 'could not be found in your account') 'refused specifically by the pool ownership guard'
$titleAfter2 = Get-OcSqlScalar "SELECT title FROM oc_poolbuy_pool WHERE pool_id=$poolB;"
$productAfter2 = Get-OcSqlInt "SELECT product_id FROM oc_poolbuy_pool WHERE pool_id=$poolB;"
$moqAfter2 = Get-OcSqlInt "SELECT moq_target FROM oc_poolbuy_pool WHERE pool_id=$poolB;"
Assert ($titleAfter2 -eq $titleBefore2) "title still '$titleAfter2'"
Assert ($productAfter2 -eq $productBefore2) "product_id NOT repointed (still $productAfter2)"
Assert ($moqAfter2 -ne 777) "MOQ NOT overwritten (still $moqAfter2)"
$tiersB = Get-OcSqlInt "SELECT COUNT(*) FROM oc_poolbuy_pool_tier WHERE pool_id=$poolB;"
Assert ($tiersB -gt 1) "seller B's tier ladder NOT wiped ($tiersB tiers remain)"

Write-Host "`n[7] Seller A cannot create a pool on a product they do not own"
$foreignProduct = Get-OcSqlInt "SELECT ps.product_id FROM oc_poolbuy_product_seller ps WHERE ps.seller_id != 1 LIMIT 1;"
if ($foreignProduct -eq 0) {
  # Ensure there is at least one product owned by another seller
  $anyProduct = Get-OcSqlInt "SELECT product_id FROM oc_product WHERE status=1 ORDER BY product_id DESC LIMIT 1;"
  Invoke-OcSql "REPLACE INTO oc_poolbuy_product_seller (product_id, seller_id) VALUES ($anyProduct, 3);"
  $foreignProduct = $anyProduct
}
$countBefore = Get-OcSqlInt "SELECT COUNT(*) FROM oc_poolbuy_pool WHERE seller_id=1;"
$body2 = "product_id=$foreignProduct&title=Sneaky&moq_target=50&unit_label=Units&status=draft" +
        "&date_start=" + [uri]::EscapeDataString((Get-Date).ToString('yyyy-MM-dd HH:mm:ss')) +
        "&date_end=" + [uri]::EscapeDataString((Get-Date).AddDays(5).ToString('yyyy-MM-dd HH:mm:ss')) +
        "&min_qty_per_buyer=1&max_qty_per_buyer=0" +
        "&pool_tier%5B0%5D%5Bmin_qty%5D=1&pool_tier%5B0%5D%5Bmax_qty%5D=&pool_tier%5B0%5D%5Bprice%5D=10"
$r7 = Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/seller.save" -Method POST -Body $body2 `
    -ContentType 'application/x-www-form-urlencoded' -WebSession $sessionA -UseBasicParsing
Assert ($r7.Content -match 'own products') 'refused with a product ownership error'
$countAfter = Get-OcSqlInt "SELECT COUNT(*) FROM oc_poolbuy_pool WHERE seller_id=1;"
Assert ($countAfter -eq $countBefore) "no pool created (still $countAfter)"

Write-Host "`n[8] An UNAPPROVED seller may draft but not publish"
Invoke-OcSql "UPDATE oc_poolbuy_seller SET status=0 WHERE seller_id=1;"
$sessionA2 = Connect-Buyer 'sellera@poolbuy.test'
$ownProduct = Get-OcSqlInt "SELECT product_id FROM oc_poolbuy_product_seller WHERE seller_id=1 LIMIT 1;"
if ($ownProduct -eq 0) {
  $ownProduct = Get-OcSqlInt "SELECT product_id FROM oc_poolbuy_pool WHERE seller_id=1 LIMIT 1;"
  Invoke-OcSql "REPLACE INTO oc_poolbuy_product_seller (product_id, seller_id) VALUES ($ownProduct, 1);"
}
$mk = {
  param($status, $title)
  return "product_id=$ownProduct&title=$title&moq_target=50&unit_label=Units&status=$status" +
    "&date_start=" + [uri]::EscapeDataString((Get-Date).ToString('yyyy-MM-dd HH:mm:ss')) +
    "&date_end=" + [uri]::EscapeDataString((Get-Date).AddDays(5).ToString('yyyy-MM-dd HH:mm:ss')) +
    "&min_qty_per_buyer=1&max_qty_per_buyer=0" +
    "&pool_tier%5B0%5D%5Bmin_qty%5D=1&pool_tier%5B0%5D%5Bmax_qty%5D=&pool_tier%5B0%5D%5Bprice%5D=10"
}
$pubAttempt = Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/seller.save" -Method POST `
    -Body (& $mk 'active' 'ShouldNotPublish') -ContentType 'application/x-www-form-urlencoded' -WebSession $sessionA2 -UseBasicParsing
Assert ($pubAttempt.Content -match 'approved before you can publish') 'publishing refused for an unapproved seller'
$activeCreated = Get-OcSqlInt "SELECT COUNT(*) FROM oc_poolbuy_pool WHERE title='ShouldNotPublish';"
Assert ($activeCreated -eq 0) 'no active pool created by an unapproved seller'

$draftAttempt = Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/seller.save" -Method POST `
    -Body (& $mk 'draft' 'SellerDraftPool') -ContentType 'application/x-www-form-urlencoded' -WebSession $sessionA2 -UseBasicParsing
Assert ($draftAttempt.Content -match '"success"') 'an unapproved seller CAN still save a draft'
$draftId = Get-OcSqlInt "SELECT pool_id FROM oc_poolbuy_pool WHERE title='SellerDraftPool';"
Assert ($draftId -gt 0) "draft pool created (pool_id $draftId)"
$draftSeller = Get-OcSqlInt "SELECT seller_id FROM oc_poolbuy_pool WHERE pool_id=$draftId;"
Assert ($draftSeller -eq 1) "pool was created under the SESSION seller (1), not a posted seller_id (got $draftSeller)"
$draftStatus = Get-OcSqlScalar "SELECT status FROM oc_poolbuy_pool WHERE pool_id=$draftId;"
Assert ($draftStatus -eq 'draft') "pool saved as draft (got $draftStatus)"
$draftRef = Get-OcSqlScalar "SELECT reference FROM oc_poolbuy_pool WHERE pool_id=$draftId;"
Assert ($draftRef -match '^PB-') "platform issued the reference ($draftRef), not the seller"

Write-Host "`n[9] Drafts are hidden from the public storefront"
$market = Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/pool" -UseBasicParsing
Assert (-not ($market.Content -match 'SellerDraftPool')) 'a draft pool does NOT appear in the marketplace'

Write-Host "`n[10] Seller commitments are anonymised"
Invoke-OcSql "UPDATE oc_poolbuy_seller SET status=1 WHERE seller_id=1;"
$sessionA3 = Connect-Buyer 'sellera@poolbuy.test'
$detail = Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/seller.pool&pool_id=$poolA" -WebSession $sessionA3 -UseBasicParsing
Assert ($detail.Content -match 'Reservation Map') 'reservation map rendered'
Assert ($detail.Content -match 'Buyer #\d+') 'buyers shown as opaque handles'
Assert (-not ($detail.Content -match '@example\.com|@poolbuy\.test')) 'no buyer email addresses in the HTML'
Assert (-not ($detail.Content -match 'Pool Buyer|Priya')) 'no buyer names in the HTML'
Assert ($detail.Content -match 'never shared with sellers') 'privacy note rendered'

Write-Host "`n[11] The Seller Portal link appears only for linked sellers"
$sellerHome = Invoke-WebRequest -Uri "$base/" -WebSession $sessionA3 -UseBasicParsing
Assert ($sellerHome.Content -match 'Seller Portal') 'a linked seller sees the Seller Portal link in the header'
$shopperHome = Invoke-WebRequest -Uri "$base/" -WebSession $shopper -UseBasicParsing
Assert ($shopperHome.Content -notmatch 'Seller Portal') 'a plain shopper does NOT see the Seller Portal link'
$anonHome = Invoke-WebRequest -Uri "$base/" -UseBasicParsing
Assert ($anonHome.Content -notmatch 'Seller Portal') 'an anonymous visitor does NOT see the Seller Portal link'

# --- Tidy up
Invoke-OcSql "DELETE FROM oc_poolbuy_pool_tier WHERE pool_id=$draftId;"
Invoke-OcSql "DELETE FROM oc_poolbuy_pool_event WHERE pool_id=$draftId;"
Invoke-OcSql "DELETE FROM oc_poolbuy_pool WHERE pool_id=$draftId;"
Invoke-OcSql "UPDATE oc_poolbuy_seller SET status=1 WHERE seller_id IN (1,3);"

Write-Host ""
if ($failures.Count -eq 0) {
    Write-Host "ALL SELLER PORTAL CHECKS PASSED" -ForegroundColor Green
    exit 0
} else {
    Write-Host "$($failures.Count) CHECK(S) FAILED" -ForegroundColor Red
    exit 1
}
