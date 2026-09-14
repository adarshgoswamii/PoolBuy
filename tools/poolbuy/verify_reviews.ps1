# Verifies PoolBuy reviews: the eligibility gate, moderation, seller rating
# recomputation, and the privacy and escaping properties of the public display.
#
# The property that matters most is that a rating cannot be faked. A review
# requires a converted commitment, on a fulfilled pool, with a settled payment,
# owned by the person submitting it. Everything else follows from that.
#
# Requires: tools\poolbuy\seed_all.ps1
#
# Usage: powershell -File tools\poolbuy\verify_reviews.ps1

$ErrorActionPreference = 'Stop'
. "$PSScriptRoot\admin.ps1"

$base     = 'http://localhost'
$password = 'PoolBuy123!'

$failures = @()

function Assert([bool] $Condition, [string] $Message) {
    if ($Condition) {
        Write-Host "  PASS  $Message" -ForegroundColor Green
    } else {
        Write-Host "  FAIL  $Message" -ForegroundColor Red
        $script:failures += $Message
    }
}

function Connect-Buyer([string] $Mail) {
    $session = New-Object Microsoft.PowerShell.Commands.WebRequestSession

    $page = Invoke-WebRequest -Uri "$base/index.php?route=account/login" -WebSession $session -UseBasicParsing
    if ($page.Content -notmatch 'login_token=([0-9a-zA-Z]+)') { throw 'no login_token' }
    $token = $Matches[1]

    $response = Invoke-WebRequest -Uri "$base/index.php?route=account/login.login&login_token=$token" `
        -Method POST -Body @{ email = $Mail; password = $password } -WebSession $session -UseBasicParsing

    if ($response.Content -notmatch 'customer_token') { throw "login failed for $Mail" }

    return $session
}

function Submit-Review([object] $Session, [string] $Body) {
    return (Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/review.add" -Method POST `
        -Body $Body -ContentType 'application/x-www-form-urlencoded' -WebSession $Session -UseBasicParsing).Content
}

# Start from no reviews so counts and averages are unambiguous.
Invoke-OcSql "DELETE FROM oc_poolbuy_review;"

# ---------------------------------------------------------------------------
Write-Host "`n[1] Finding an eligible purchase (converted + fulfilled + paid)"

$eligible = Get-OcSqlScalar @"
SELECT CONCAT(r.reservation_id,'|',r.customer_id,'|',p.seller_id,'|',r.product_id_x) FROM (
  SELECT r.reservation_id, r.customer_id, r.pool_id, p.product_id AS product_id_x
  FROM oc_poolbuy_reservation r
  JOIN oc_poolbuy_pool p ON p.pool_id = r.pool_id
  JOIN oc_poolbuy_payment pay ON pay.order_id = r.order_id
  WHERE r.status='converted' AND r.order_id > 0 AND p.status='fulfilled' AND pay.status='paid'
  ORDER BY r.reservation_id DESC LIMIT 1
) r JOIN oc_poolbuy_pool p ON p.pool_id = r.pool_id;
"@

if (-not $eligible) {
    Write-Host "  No eligible purchase exists in this dataset." -ForegroundColor Red
    Write-Host "  Run verify_lifecycle.ps1 / verify_payment.ps1 first so a pool is fulfilled and paid." -ForegroundColor Yellow
    exit 1
}

$parts = $eligible -split '\|'
$resId = [int]$parts[0]
$buyerId = [int]$parts[1]
$sellerId = [int]$parts[2]
$productId = [int]$parts[3]

$buyerMail = Get-OcSqlScalar "SELECT email FROM oc_customer WHERE customer_id=$buyerId;"

Write-Host "      reservation=$resId buyer=$buyerId ($buyerMail) seller=$sellerId product=$productId"
Assert ($resId -gt 0 -and $buyerId -gt 0) 'an eligible purchase was found'

$session = Connect-Buyer $buyerMail

# ---------------------------------------------------------------------------
Write-Host "`n[2] The dashboard offers a review for that purchase"

$dash = (Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/account" -WebSession $session -UseBasicParsing).Content

Assert ($dash -match 'Rate your completed purchases') 'the review section is present'
Assert ($dash -match ('name="reservation_id" value="' + $resId + '"')) "a review form is offered for reservation $resId"

# ---------------------------------------------------------------------------
Write-Host "`n[3] A review from the real buyer is accepted and held for moderation"

$ok = Submit-Review $session "reservation_id=$resId&rating=4&title=Solid+order&text=Arrived+on+time+and+well+packed."

Assert ($ok -match 'Thank you') 'the review was accepted'

$status = Get-OcSqlScalar "SELECT status FROM oc_poolbuy_review WHERE reservation_id=$resId;"
Assert ($status -eq 'pending') "the review is held as pending, not published immediately (got '$status')"

$rating = Get-OcSqlInt "SELECT rating FROM oc_poolbuy_review WHERE reservation_id=$resId;"
Assert ($rating -eq 4) "the rating was stored as submitted ($rating)"

# ---------------------------------------------------------------------------
Write-Host "`n[4] A pending review is invisible on the storefront"

$publicPage = (Invoke-WebRequest -Uri "$base/index.php?route=product/product&product_id=$productId" -UseBasicParsing).Content

Assert (-not ($publicPage -match 'Arrived on time and well packed')) 'a pending review does not appear publicly'
Assert ($publicPage -match 'Buyer reviews') 'the reviews section still renders'

# ---------------------------------------------------------------------------
Write-Host "`n[5] The same buyer cannot review the same purchase twice"

$dupe = Submit-Review $session "reservation_id=$resId&rating=1&title=Again&text=Second+go."
Assert ($dupe -match 'already reviewed') 'a duplicate review is refused'

$count = Get-OcSqlInt "SELECT COUNT(*) FROM oc_poolbuy_review WHERE reservation_id=$resId;"
Assert ($count -eq 1) "still exactly one review for that purchase (found $count)"

# ---------------------------------------------------------------------------
Write-Host "`n[6] Nobody can review a purchase that is not theirs"

$otherRes = Get-OcSqlInt @"
SELECT COALESCE((SELECT reservation_id FROM oc_poolbuy_reservation WHERE customer_id <> $buyerId AND status='converted' AND order_id > 0 ORDER BY reservation_id DESC LIMIT 1), 0);
"@

if ($otherRes -gt 0) {
    $foreign = Submit-Review $session "reservation_id=$otherRes&rating=5&title=Not+mine&text=Someone+elses+purchase."
    Assert ($foreign -match 'could not be found') "a commitment owned by another buyer is refused (reservation $otherRes)"

    $leaked = Get-OcSqlInt "SELECT COUNT(*) FROM oc_poolbuy_review WHERE reservation_id=$otherRes;"
    Assert ($leaked -eq 0) 'no review was written against the foreign commitment'
} else {
    Write-Host "  SKIP  no other buyer's commitment available to test against" -ForegroundColor Yellow
}

# ---------------------------------------------------------------------------
Write-Host "`n[7] An unpaid or unfulfilled purchase cannot be reviewed"

$unpaid = Get-OcSqlInt @"
SELECT COALESCE((
  SELECT r.reservation_id FROM oc_poolbuy_reservation r
  LEFT JOIN oc_poolbuy_payment pay ON pay.order_id = r.order_id
  WHERE r.customer_id = $buyerId AND r.status='converted' AND r.order_id > 0
  AND (pay.status IS NULL OR pay.status <> 'paid')
  ORDER BY r.reservation_id DESC LIMIT 1
), 0);
"@

if ($unpaid -gt 0) {
    $notPaid = Submit-Review $session "reservation_id=$unpaid&rating=5&title=Unpaid&text=Payment+not+settled."
    Assert ($notPaid -match 'payment has been settled' -or $notPaid -match 'could not be found') "an unsettled purchase is refused (reservation $unpaid)"

    $written = Get-OcSqlInt "SELECT COUNT(*) FROM oc_poolbuy_review WHERE reservation_id=$unpaid;"
    Assert ($written -eq 0) 'no review was written for the unpaid purchase'
} else {
    Write-Host "  SKIP  this buyer has no unpaid converted commitment" -ForegroundColor Yellow
}

$bogus = Submit-Review $session "reservation_id=999999&rating=5&title=Nope&text=Does+not+exist."
Assert ($bogus -match 'could not be found') 'a nonexistent commitment is refused'

# ---------------------------------------------------------------------------
Write-Host "`n[8] A review must be POSTed, and the rating is clamped server side"

$viaGet = (Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/review.add&reservation_id=$resId&rating=1&text=via+get" -WebSession $session -UseBasicParsing).Content
Assert ($viaGet -match 'must be submitted from the review form') 'a GET cannot submit a review'

# Find a second eligible purchase to test clamping without disturbing the first.
$second = Get-OcSqlInt @"
SELECT COALESCE((
  SELECT r.reservation_id FROM oc_poolbuy_reservation r
  JOIN oc_poolbuy_pool p ON p.pool_id = r.pool_id
  JOIN oc_poolbuy_payment pay ON pay.order_id = r.order_id
  LEFT JOIN oc_poolbuy_review rv ON rv.reservation_id = r.reservation_id
  WHERE r.customer_id = $buyerId AND r.status='converted' AND r.order_id > 0
  AND p.status='fulfilled' AND pay.status='paid' AND rv.review_id IS NULL
  ORDER BY r.reservation_id DESC LIMIT 1
), 0);
"@

if ($second -gt 0) {
    Submit-Review $session "reservation_id=$second&rating=99&title=Clamp&text=Rating+must+be+clamped." | Out-Null
    $clamped = Get-OcSqlInt "SELECT rating FROM oc_poolbuy_review WHERE reservation_id=$second;"
    Assert ($clamped -eq 5) "a posted rating of 99 was clamped to 5 (stored $clamped)"

    Invoke-OcSql "DELETE FROM oc_poolbuy_review WHERE reservation_id=$second;"
} else {
    Write-Host "  SKIP  no second eligible purchase for the clamp test" -ForegroundColor Yellow
}

$outOfRange = Get-OcSqlInt "SELECT COUNT(*) FROM oc_poolbuy_review WHERE rating < 1 OR rating > 5;"
Assert ($outOfRange -eq 0) 'no review carries a rating outside 1-5'

# ---------------------------------------------------------------------------
Write-Host "`n[9] Moderation publishes the review and recomputes the seller rating"

$admin = Connect-OcAdmin

$queue = Invoke-OcAdmin $admin 'extension/poolbuy/poolbuy/review'
Assert ($queue.Content -match 'Review Moderation') 'the moderation queue renders'
Assert ($queue.Content -match 'Solid order') 'the pending review appears in the queue'

# Buyers must not be identifiable to operators either.
$handle = 'Buyer #' + $buyerId.ToString('0000')
Assert ($queue.Content -match [regex]::Escape($handle)) "the buyer is shown as an opaque handle ($handle)"
Assert (-not ($queue.Content -match [regex]::Escape($buyerMail))) 'the buyer email is not exposed in the queue'

$reviewId = Get-OcSqlInt "SELECT review_id FROM oc_poolbuy_review WHERE reservation_id=$resId;"

$approve = Invoke-OcAdmin $admin 'extension/poolbuy/poolbuy/review.approve' @{ review_id = $reviewId } $null 'POST'
Assert ($approve.Content -match 'approved') 'the review was approved'

$newStatus = Get-OcSqlScalar "SELECT status FROM oc_poolbuy_review WHERE review_id=$reviewId;"
Assert ($newStatus -eq 'approved') "the review status is now approved (got '$newStatus')"

# The seller rating must be the average of APPROVED reviews, and nothing else.
$sellerRating = Get-OcSqlScalar "SELECT rating FROM oc_poolbuy_seller WHERE seller_id=$sellerId;"
$sellerCount = Get-OcSqlInt "SELECT rating_count FROM oc_poolbuy_seller WHERE seller_id=$sellerId;"
$approvedAvg = Get-OcSqlScalar "SELECT ROUND(COALESCE(AVG(rating),0),2) FROM oc_poolbuy_review WHERE seller_id=$sellerId AND status='approved';"
$approvedCount = Get-OcSqlInt "SELECT COUNT(*) FROM oc_poolbuy_review WHERE seller_id=$sellerId AND status='approved';"

Write-Host "      seller rating=$sellerRating count=$sellerCount ; approved avg=$approvedAvg count=$approvedCount"
Assert ([decimal]$sellerRating -eq [decimal]$approvedAvg) "the stored rating equals the approved average ($sellerRating vs $approvedAvg)"
Assert ($sellerCount -eq $approvedCount) "the stored count equals the approved count ($sellerCount vs $approvedCount)"

# ---------------------------------------------------------------------------
Write-Host "`n[10] An approved review is public, and buyers stay anonymous"

$publicAfter = (Invoke-WebRequest -Uri "$base/index.php?route=product/product&product_id=$productId" -UseBasicParsing).Content

Assert ($publicAfter -match 'Arrived on time and well packed') 'the approved review is now public'
Assert ($publicAfter -match 'out of 5') 'the rating average is shown'
Assert ($publicAfter -match [regex]::Escape($handle)) 'the reviewer is attributed by opaque handle'
Assert (-not ($publicAfter -match [regex]::Escape($buyerMail))) 'the buyer email never reaches the storefront'

$firstName = Get-OcSqlScalar "SELECT firstname FROM oc_customer WHERE customer_id=$buyerId;"
if ($firstName) {
    # A common first name could appear incidentally, so only assert it is absent
    # from the review block itself.
    $idx = $publicAfter.IndexOf('Buyer reviews')
    $block = $publicAfter.Substring($idx, [Math]::Min(2000, $publicAfter.Length - $idx))
    Assert (-not ($block -match [regex]::Escape($firstName))) "the buyer's real name is not in the review block ($firstName)"
}

# ---------------------------------------------------------------------------
Write-Host "`n[11] Rejecting a review unpublishes it and moves the rating back"

$reject = Invoke-OcAdmin $admin 'extension/poolbuy/poolbuy/review.reject' @{ review_id = $reviewId } $null 'POST'
Assert ($reject.Content -match 'rejected') 'the review was rejected'

$afterReject = (Invoke-WebRequest -Uri "$base/index.php?route=product/product&product_id=$productId" -UseBasicParsing).Content
Assert (-not ($afterReject -match 'Arrived on time and well packed')) 'a rejected review is removed from the storefront'

$ratingAfter = Get-OcSqlScalar "SELECT rating FROM oc_poolbuy_seller WHERE seller_id=$sellerId;"
$countAfter = Get-OcSqlInt "SELECT rating_count FROM oc_poolbuy_seller WHERE seller_id=$sellerId;"
$approvedAfter = Get-OcSqlInt "SELECT COUNT(*) FROM oc_poolbuy_review WHERE seller_id=$sellerId AND status='approved';"

Assert ($countAfter -eq $approvedAfter) "the rating count followed the rejection ($countAfter approved)"
if ($approvedAfter -eq 0) {
    Assert ([decimal]$ratingAfter -eq [decimal]0) "with no approved reviews the rating is 0.00, not a stale figure (got $ratingAfter)"
}

# Put it back so later sections have a published review.
Invoke-OcAdmin $admin 'extension/poolbuy/poolbuy/review.approve' @{ review_id = $reviewId } $null 'POST' | Out-Null

# ---------------------------------------------------------------------------
Write-Host "`n[12] Review text is escaped, not executed"

Invoke-OcSql "UPDATE oc_poolbuy_review SET title='<script>alert(1)</script>', text='<img src=x onerror=alert(2)>bad' WHERE review_id=$reviewId;"

$xssPage = (Invoke-WebRequest -Uri "$base/index.php?route=product/product&product_id=$productId" -UseBasicParsing).Content

Assert (-not ($xssPage -match '<script>alert\(1\)</script>')) 'a script tag in a review title is escaped'
Assert (-not ($xssPage -match '<img src=x onerror=alert\(2\)>')) 'an event handler in review text is escaped'
Assert ($xssPage -match '&lt;script&gt;' -or $xssPage -match '&lt;img') 'the markup is present only in escaped form'

$xssQueue = Invoke-OcAdmin $admin 'extension/poolbuy/poolbuy/review'
Assert (-not ($xssQueue.Content -match '<script>alert\(1\)</script>')) 'the admin queue escapes review markup too'

# ---------------------------------------------------------------------------
Write-Host "`n[13] The admin routes are authorised"

foreach ($route in @(
    'extension/poolbuy/poolbuy/review',
    'extension/poolbuy/poolbuy/review.list',
    'extension/poolbuy/poolbuy/review.approve',
    'extension/poolbuy/poolbuy/review.reject',
    'extension/poolbuy/poolbuy/review.delete'
)) {
    $anon = Invoke-WebRequest -Uri "$base/admin/index.php?route=$route" -UseBasicParsing -MaximumRedirection 5
    Assert ($anon.Content -match 'name="username"' -or $anon.Content -match 'route=common/login') "$route refuses an unauthenticated visitor"
}

$forged = Invoke-WebRequest -Uri "$base/admin/index.php?route=extension/poolbuy/poolbuy/review&user_token=deadbeefdeadbeef" -UseBasicParsing -MaximumRedirection 5
Assert ($forged.Content -match 'name="username"' -or $forged.Content -match 'route=common/login') 'a forged user_token is rejected'

# An anonymous visitor cannot submit a review at all.
$anonPost = Invoke-WebRequest -Uri "$base/index.php?route=extension/poolbuy/review.add" -Method POST `
    -Body "reservation_id=$resId&rating=1&text=anonymous" -ContentType 'application/x-www-form-urlencoded' `
    -UseBasicParsing -MaximumRedirection 5
Assert ($anonPost.Content -match 'name="email"' -or $anonPost.Content -match 'route=account/login') 'an anonymous visitor is sent to login rather than reviewing'

# ---------------------------------------------------------------------------
Invoke-OcSql "DELETE FROM oc_poolbuy_review;"

$sellers = Get-OcSqlScalar "SELECT GROUP_CONCAT(seller_id) FROM oc_poolbuy_seller;"
foreach ($s in @($sellers -split ',')) {
    if ($s.Trim() -ne '') {
        Invoke-OcSql "UPDATE oc_poolbuy_seller SET rating='0.00', rating_count=0 WHERE seller_id=$($s.Trim());"
    }
}

Write-Host ""
if ($failures.Count -eq 0) {
    Write-Host "ALL REVIEW CHECKS PASSED" -ForegroundColor Green
    exit 0
} else {
    Write-Host "$($failures.Count) CHECK(S) FAILED" -ForegroundColor Red
    exit 1
}
