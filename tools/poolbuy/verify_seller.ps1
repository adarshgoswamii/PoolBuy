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

Write-Host "`n[12] Seller onboarding: application, mandatory verification fields, no self-approval"
$applicantEmail = 'applicant@poolbuy.test'
$applicantId = Register-Buyer $applicantEmail
# Start from "not a seller" so the application is genuinely a first application.
Invoke-OcSql "DELETE FROM oc_poolbuy_seller WHERE customer_id=$applicantId;"
$applicant = Connect-Buyer $applicantEmail

# An anonymous visitor is sent to login, not shown the form.
$anonApply = Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/seller.register" -UseBasicParsing -MaximumRedirection 5
Assert ($anonApply.Content -match 'route=account/login' -or $anonApply.Content -match 'name="email"') 'the application form refuses an anonymous visitor'

# A signed-in shopper who is not a seller is offered the application.
$denied = Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/seller" -WebSession $applicant -UseBasicParsing
Assert ($denied.Content -match 'seller\.register') 'a non-seller is pointed at the application form, not a dead end'

$applyForm = Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/seller.register" -WebSession $applicant -UseBasicParsing
Assert ($applyForm.StatusCode -eq 200) 'the application form renders for a signed-in shopper'
Assert ($applyForm.Content -match 'Registered business name') 'the business name field is labelled'
Assert ($applyForm.Content -match 'GSTIN') 'the GSTIN verification field is present'
Assert ($applyForm.Content -match 'Submit application') 'the submit control is present'

# Mandatory verification fields are enforced server-side, and nothing is created.
$badApply = Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/seller.register" -Method POST -WebSession $applicant -UseBasicParsing `
    -Body @{ name = 'X'; gst_number = 'NOT-A-GSTIN'; location = ''; description = '' }
Assert ($badApply.Content -match 'between 3 and 128') 'a too-short business name is rejected'
Assert ($badApply.Content -match 'valid 15 character GSTIN') 'a malformed GSTIN is rejected'
Assert ($badApply.Content -match 'city and state') 'a missing location is rejected'
Assert ($badApply.Content -match 'authorised to trade') 'the authorisation confirmation is mandatory'
Assert ((Get-OcSqlInt "SELECT COUNT(*) FROM oc_poolbuy_seller WHERE customer_id=$applicantId;") -eq 0) 'a rejected application creates no seller row'

# A valid application is accepted but deliberately unapproved.
Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/seller.register" -Method POST -WebSession $applicant -UseBasicParsing `
    -Body @{ name = 'Probe Pumps Pvt Ltd'; gst_number = '27ABCDE1234F1Z5'; location = 'Pune, MH'; description = 'Industrial pumps'; terms = '1' } | Out-Null
Assert ((Get-OcSqlInt "SELECT COUNT(*) FROM oc_poolbuy_seller WHERE customer_id=$applicantId;") -eq 1) 'a valid application creates exactly one seller profile'
Assert ((Get-OcSqlInt "SELECT status FROM oc_poolbuy_seller WHERE customer_id=$applicantId;") -eq 0) 'the new profile is unapproved (status 0), so it cannot publish'
Assert ((Get-OcSqlInt "SELECT gst_verified FROM oc_poolbuy_seller WHERE customer_id=$applicantId;") -eq 0) 'GST is not self-verified by the applicant'
Assert ((Get-OcSqlInt "SELECT verified_seller FROM oc_poolbuy_seller WHERE customer_id=$applicantId;") -eq 0) 'the verified-seller badge is not self-awarded'

# A crafted post cannot approve itself or award a badge.
Invoke-OcSql "DELETE FROM oc_poolbuy_seller WHERE customer_id=$applicantId;"
Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/seller.register" -Method POST -WebSession $applicant -UseBasicParsing `
    -Body @{ name = 'Forged Status Ltd'; gst_number = '27ABCDE1234F1Z5'; location = 'Pune, MH'; terms = '1'
             status = '1'; gst_verified = '1'; verified_seller = '1'; rating = '5' } | Out-Null
Assert ((Get-OcSqlInt "SELECT status FROM oc_poolbuy_seller WHERE customer_id=$applicantId;") -eq 0) 'a posted status=1 does not self-approve the seller'
Assert ((Get-OcSqlInt "SELECT gst_verified FROM oc_poolbuy_seller WHERE customer_id=$applicantId;") -eq 0) 'a posted gst_verified=1 is discarded'
Assert ((Get-OcSqlInt "SELECT verified_seller FROM oc_poolbuy_seller WHERE customer_id=$applicantId;") -eq 0) 'a posted verified_seller=1 is discarded'
Assert ((Get-OcSqlInt "SELECT ROUND(rating) FROM oc_poolbuy_seller WHERE customer_id=$applicantId;") -eq 0) 'a posted rating is discarded'

# Applying twice does not create a second profile.
Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/seller.register" -Method POST -WebSession $applicant -UseBasicParsing `
    -Body @{ name = 'Second Attempt Ltd'; gst_number = '27ABCDE1234F1Z5'; location = 'Pune, MH'; terms = '1' } -MaximumRedirection 5 | Out-Null
Assert ((Get-OcSqlInt "SELECT COUNT(*) FROM oc_poolbuy_seller WHERE customer_id=$applicantId;") -eq 1) 'a second application does not create a duplicate profile'

Write-Host "`n[13] Seller product management: own catalogue, no cross-tenant reach"
$sellerA = Connect-Buyer 'sellera@poolbuy.test'
$sellerB = Connect-Buyer 'sellerb@poolbuy.test'

$catalogue = Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/seller_product" -WebSession $sellerA -UseBasicParsing
Assert ($catalogue.StatusCode -eq 200) 'the product list renders for a seller'
Assert ($catalogue.Content -match 'Your Products') 'the product list is titled'
Assert ($catalogue.Content -match 'Add Product') 'the add control is offered'

$blankForm = Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/seller_product.form" -WebSession $sellerA -UseBasicParsing
Assert ($blankForm.Content -match 'Product name') 'the product form is labelled'
Assert ($blankForm.Content -match 'type="file"') 'a primary image can be uploaded'

# Invalid input is refused server-side and creates nothing.
$productsBefore = Get-OcSqlInt "SELECT COUNT(*) FROM oc_poolbuy_product_seller WHERE seller_id=1;"
$badProduct = Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/seller_product.save" -Method POST -WebSession $sellerA -UseBasicParsing `
    -Body @{ name = 'X'; price = 'abc'; quantity = '-4'; model = '' }
Assert ($badProduct.Content -match 'between 3 and 255') 'a too-short product name is rejected'
Assert ($badProduct.Content -match 'price of 0 or more') 'a non-numeric price is rejected'
Assert ($badProduct.Content -match 'whole number of 0 or more') 'a negative stock figure is rejected'
Assert ((Get-OcSqlInt "SELECT COUNT(*) FROM oc_poolbuy_product_seller WHERE seller_id=1;") -eq $productsBefore) 'a rejected product creates no row'

# A valid product is created, mapped to this seller, and visible to the storefront.
Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/seller_product.save" -Method POST -WebSession $sellerA -UseBasicParsing `
    -Body @{ name = 'Verify Widget A1'; description = 'Created by verify_seller.'; model = 'VW-A1'; price = '1500.00'; quantity = '25'; status = '1' } -MaximumRedirection 5 | Out-Null

$newProduct = Get-OcSqlInt "SELECT p.product_id FROM oc_product p JOIN oc_product_description pd ON pd.product_id=p.product_id WHERE pd.name='Verify Widget A1' ORDER BY p.product_id DESC LIMIT 1;"
Assert ($newProduct -gt 0) 'a valid product is created'
Assert ((Get-OcSqlInt "SELECT seller_id FROM oc_poolbuy_product_seller WHERE product_id=$newProduct;") -eq 1) 'the new product is mapped to the creating seller'
Assert ((Get-OcSqlScalar "SELECT model FROM oc_product WHERE product_id=$newProduct;") -eq 'VW-A1') 'the product code is stored'
Assert ([math]::Round([decimal](Get-OcSqlScalar "SELECT price FROM oc_product WHERE product_id=$newProduct;"), 2) -eq 1500.00) 'the price is stored'
Assert ((Get-OcSqlInt "SELECT quantity FROM oc_product WHERE product_id=$newProduct;") -eq 25) 'the stock figure is stored'
Assert ((Get-OcSqlInt "SELECT COUNT(*) FROM oc_product_to_store WHERE product_id=$newProduct AND store_id=0;") -eq 1) 'the product is assigned to the storefront'

# Editing updates price and stock.
Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/seller_product.save&product_id=$newProduct" -Method POST -WebSession $sellerA -UseBasicParsing `
    -Body @{ name = 'Verify Widget A1'; description = 'Edited.'; model = 'VW-A1'; price = '1310.00'; quantity = '9'; status = '1' } -MaximumRedirection 5 | Out-Null
Assert ([math]::Round([decimal](Get-OcSqlScalar "SELECT price FROM oc_product WHERE product_id=$newProduct;"), 2) -eq 1310.00) 'editing updates the price'
Assert ((Get-OcSqlInt "SELECT quantity FROM oc_product WHERE product_id=$newProduct;") -eq 9) 'editing updates the stock figure'

# The new product becomes attachable to a pool.
$poolFormA = Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/seller.form" -WebSession $sellerA -UseBasicParsing
Assert ($poolFormA.Content -match 'Verify Widget A1') 'the new product is selectable in the pool form'

# Seller B must not be able to read or mutate seller A's product.
$crossProductForm = Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/seller_product.form&product_id=$newProduct" -WebSession $sellerB -UseBasicParsing -MaximumRedirection 5
Assert ($crossProductForm.Content -notmatch 'Verify Widget A1') "seller B cannot open seller A's product"
Assert ($crossProductForm.Content -match 'could not be found') 'a cross-tenant product reads as missing, not forbidden'

Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/seller_product.save&product_id=$newProduct" -Method POST -WebSession $sellerB -UseBasicParsing `
    -Body @{ name = 'HIJACKED'; model = 'X'; price = '1'; quantity = '1'; status = '1' } -MaximumRedirection 5 | Out-Null
Assert ((Get-OcSqlScalar "SELECT pd.name FROM oc_product_description pd WHERE pd.product_id=$newProduct AND pd.language_id=1;") -eq 'Verify Widget A1') "seller B cannot rename seller A's product"
Assert ([math]::Round([decimal](Get-OcSqlScalar "SELECT price FROM oc_product WHERE product_id=$newProduct;"), 2) -eq 1310.00) "seller B cannot reprice seller A's product"
Assert ((Get-OcSqlInt "SELECT quantity FROM oc_product WHERE product_id=$newProduct;") -eq 9) "seller B cannot change seller A's stock"

# An unapproved seller may draft a product but never enable it.
Invoke-OcSql "UPDATE oc_poolbuy_seller SET status=0 WHERE seller_id=1;"
$unapproved = Connect-Buyer 'sellera@poolbuy.test'
$pendingSave = Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/seller_product.save&product_id=$newProduct" -Method POST -WebSession $unapproved -UseBasicParsing `
    -Body @{ name = 'Verify Widget A1'; model = 'VW-A1'; price = '1310.00'; quantity = '9'; status = '1' } -MaximumRedirection 5
Assert ($pendingSave.Content -match 'must be approved') 'an unapproved seller cannot enable a product'
Invoke-OcSql "UPDATE oc_poolbuy_seller SET status=1 WHERE seller_id=1;"

Write-Host "`n[14] Seller order processing: own orders only, buyer privacy, tracking"
$sellerA = Connect-Buyer 'sellera@poolbuy.test'

$orderId = Get-OcSqlInt "SELECT r.order_id FROM oc_poolbuy_reservation r JOIN oc_poolbuy_pool p ON p.pool_id=r.pool_id WHERE p.seller_id=1 AND r.order_id>0 ORDER BY r.order_id DESC LIMIT 1;"
Assert ($orderId -gt 0) "seller A has at least one order to process (order $orderId)"

$orderList = Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/seller_order" -WebSession $sellerA -UseBasicParsing
Assert ($orderList.StatusCode -eq 200) 'the order list renders for a seller'
Assert ($orderList.Content -match "#$orderId") "seller A's own order appears in the list"
Assert ($orderList.Content -match 'Buyer #\d+') 'buyers are shown as opaque handles'
Assert (-not ($orderList.Content -match '@poolbuy\.test|@example\.com')) 'no buyer email addresses in the order list'
Assert (-not ($orderList.Content -match 'Pool Buyer|Priya')) 'no buyer names in the order list'

$poolRef = Get-OcSqlScalar "SELECT p.reference FROM oc_poolbuy_reservation r JOIN oc_poolbuy_pool p ON p.pool_id=r.pool_id WHERE r.order_id=$orderId LIMIT 1;"
$poolIdForOrder = Get-OcSqlInt "SELECT p.pool_id FROM oc_poolbuy_reservation r JOIN oc_poolbuy_pool p ON p.pool_id=r.pool_id WHERE r.order_id=$orderId LIMIT 1;"

$orderView = Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/seller_order.view&order_id=$orderId" -WebSession $sellerA -UseBasicParsing
Assert ($orderView.Content -match 'Move order to') 'the fulfilment control is offered'
Assert ($orderView.Content -match 'Platform Fee') 'the invoiced breakdown is shown'

# The pool behind this order is fulfilled, so the delivery address is released.
Assert ($orderView.Content -match '<address') 'the delivery address is released once the pool is fulfilled'

# Withhold it again while the pool is not fulfilled.
Invoke-OcSql "UPDATE oc_poolbuy_pool SET status='closed' WHERE pool_id=$poolIdForOrder;"
$withheld = Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/seller_order.view&order_id=$orderId" -WebSession $sellerA -UseBasicParsing
Assert ($withheld.Content -notmatch '<address') 'the delivery address is withheld while the pool is unfulfilled'
Assert ($withheld.Content -match 'released when the pool is fulfilled') 'the privacy reason is explained to the seller'
Invoke-OcSql "UPDATE oc_poolbuy_pool SET status='fulfilled' WHERE pool_id=$poolIdForOrder;"

# Advancing the order writes history and stores tracking.
$historyBefore = Get-OcSqlInt "SELECT COUNT(*) FROM oc_order_history WHERE order_id=$orderId;"
Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/seller_order.status&order_id=$orderId" -Method POST -WebSession $sellerA -UseBasicParsing `
    -Body @{ order_status_id = '3'; tracking = 'AWB-VERIFY-1' } -MaximumRedirection 5 | Out-Null
Assert ((Get-OcSqlInt "SELECT order_status_id FROM oc_order WHERE order_id=$orderId;") -eq 3) 'the seller can advance the order to Shipped'
Assert ((Get-OcSqlScalar "SELECT tracking FROM oc_order WHERE order_id=$orderId;") -eq 'AWB-VERIFY-1') 'the tracking reference is stored'
Assert ((Get-OcSqlInt "SELECT COUNT(*) FROM oc_order_history WHERE order_id=$orderId;") -eq ($historyBefore + 1)) 'the change is recorded in the order history for admin and the buyer'
Assert ((Get-OcSqlInt "SELECT COUNT(*) FROM oc_poolbuy_pool_event WHERE pool_id=$poolIdForOrder AND type='order_status_changed';") -ge 1) 'the change is recorded in the pool audit trail'

# A status outside the offered working set is refused.
Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/seller_order.status&order_id=$orderId" -Method POST -WebSession $sellerA -UseBasicParsing `
    -Body @{ order_status_id = '11'; tracking = 'AWB-VERIFY-1' } -MaximumRedirection 5 | Out-Null
Assert ((Get-OcSqlInt "SELECT order_status_id FROM oc_order WHERE order_id=$orderId;") -eq 3) 'a status outside the offered set (Refunded) is refused'

# Seller B must not see or mutate seller A's order.
$sellerB = Connect-Buyer 'sellerb@poolbuy.test'
$bOrders = Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/seller_order" -WebSession $sellerB -UseBasicParsing
Assert ($bOrders.Content -notmatch "#$orderId") "seller B's order list does not contain seller A's order"

$bOrderView = Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/seller_order.view&order_id=$orderId" -WebSession $sellerB -UseBasicParsing -MaximumRedirection 5
Assert ($bOrderView.Content -notmatch [regex]::Escape($poolRef)) "seller B cannot read seller A's order"
Assert ($bOrderView.Content -match 'could not be found') 'a cross-tenant order reads as missing, not forbidden'

Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/seller_order.status&order_id=$orderId" -Method POST -WebSession $sellerB -UseBasicParsing `
    -Body @{ order_status_id = '5'; tracking = 'HIJACKED' } -MaximumRedirection 5 | Out-Null
Assert ((Get-OcSqlInt "SELECT order_status_id FROM oc_order WHERE order_id=$orderId;") -eq 3) "seller B cannot advance seller A's order"
Assert ((Get-OcSqlScalar "SELECT tracking FROM oc_order WHERE order_id=$orderId;") -eq 'AWB-VERIFY-1') "seller B cannot overwrite seller A's tracking"

# --- Tidy up
Invoke-OcSql "DELETE FROM oc_poolbuy_product_seller WHERE product_id=$newProduct;"
Invoke-OcSql "DELETE FROM oc_product_to_store WHERE product_id=$newProduct;"
Invoke-OcSql "DELETE FROM oc_product_description WHERE product_id=$newProduct;"
Invoke-OcSql "DELETE FROM oc_product WHERE product_id=$newProduct;"
Invoke-OcSql "DELETE FROM oc_poolbuy_seller WHERE customer_id=$applicantId;"
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
