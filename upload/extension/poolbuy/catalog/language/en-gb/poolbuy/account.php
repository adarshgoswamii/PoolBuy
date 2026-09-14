<?php
// Heading
$_['heading_title']        = 'My Pools';
$_['text_account']         = 'Account';
$_['text_subtitle']        = 'Your commitments, live progress and savings.';

// Stats
$_['text_stat_active']     = 'Pools joined (active)';
$_['text_stat_week']       = '%d joined this week';
$_['text_stat_completed']  = 'Pools completed';
$_['text_stat_rate']       = '%s%% fulfilment rate';
$_['text_stat_spend']      = 'Committed this year';
$_['text_stat_saving']     = 'Saved %s versus retail';
$_['text_no_saving']       = 'Savings appear once pools are priced';

// Tabs
$_['text_tab_active']      = 'Active';
$_['text_tab_completed']   = 'Completed';
$_['text_tab_cancelled']   = 'Not fulfilled';

// Reservation list
$_['text_your_commitment'] = 'Your commitment';
$_['text_reserved']        = '%d %s reserved';
$_['text_unit_price']      = 'Locked unit price';
$_['text_committed']       = 'Committed value';
$_['text_price_pending']   = 'Priced when the pool closes';
$_['text_filled']          = '%d of %d %s';
$_['text_time_left']       = '%s left';
$_['text_ended']           = 'Closed';
$_['text_urgent']          = 'Closing soon';
$_['text_pagination']      = 'Showing %d to %d of %d (%d Pages)';
$_['button_withdraw']      = 'Withdraw';
$_['text_withdraw_confirm'] = 'Withdraw your commitment from this pool?';

// Payments
$_['button_pay_now']            = 'Pay now';
$_['text_payment_pending']      = 'Payment due';
$_['text_payment_paid']         = 'Paid';
$_['text_payment_failed']       = 'Payment failed';
$_['text_payment_refunded']     = 'Refunded';
$_['text_payment_success']      = 'Payment received. Thank you!';
$_['text_payment_already']      = 'This order has already been paid.';
$_['text_payment_return']       = 'Thanks - we are confirming your payment. Your order will update shortly.';
$_['text_payment_refunded_notice'] = 'This payment has been refunded because the pool did not proceed.';
$_['error_payment_missing']     = 'That payment could not be found.';
$_['error_payment_unavailable'] = 'This order is not payable online just yet. Please try again shortly or contact us.';
$_['error_payment_failed']      = 'That payment did not go through. You can try paying again.';
$_['error_payment_cancelled']   = 'Payment was cancelled. Your order is still awaiting payment.';

// Reservation statuses
$_['text_reservation_pending']   = 'Pending';
$_['text_reservation_confirmed'] = 'Confirmed';
$_['text_reservation_cancelled'] = 'Withdrawn';
$_['text_reservation_converted'] = 'Ordered';
$_['text_reservation_released']  = 'Released';

// Pool statuses
$_['text_pool_active']    = 'Filling';
$_['text_pool_reached']   = 'MOQ reached';
$_['text_pool_closed']    = 'Closed';
$_['text_pool_expired']   = 'Expired';
$_['text_pool_fulfilled'] = 'Fulfilled';
$_['text_pool_cancelled'] = 'Cancelled';
$_['text_pool_draft']     = 'Draft';

// Empty states
$_['text_empty_active']       = 'You have no active commitments';
$_['text_empty_active_body']  = 'Join a pool and it will appear here with live progress.';
$_['text_empty_completed']    = 'No completed pools yet';
$_['text_empty_completed_body'] = 'Pools that reach their MOQ and ship will be listed here.';
$_['text_empty_cancelled']    = 'Nothing here';
$_['text_empty_cancelled_body'] = 'Withdrawn commitments and pools that did not reach their MOQ appear here.';
$_['button_browse']           = 'Browse the marketplace';

// Recommendations
$_['text_recommended']      = 'Recommended pools';
$_['text_recommended_help'] = 'Live pools you have not joined yet.';
$_['button_join']           = 'Join Pool';

// Orders
$_['text_orders']         = 'Pool orders';
$_['text_orders_help']    = 'Commitments that became orders.';
$_['text_no_orders']      = 'No pool orders yet.';
$_['button_export']       = 'Export CSV';
$_['button_view_order']   = 'View order';
$_['button_all_orders']   = 'All orders';

// CSV columns
$_['column_reference']   = 'Reference';
$_['column_pool']        = 'Pool';
$_['column_seller']      = 'Seller';
$_['column_quantity']    = 'Quantity';
$_['column_unit_price']  = 'Unit price';
$_['column_committed']   = 'Committed value';
$_['column_status']      = 'Reservation status';
$_['column_pool_status'] = 'Pool status';
$_['column_date']        = 'Date joined';

// Start a pool
$_['text_start_pool']    = 'Request a pool';

// Messages
$_['text_cancel_success'] = 'Your commitment has been withdrawn and the units returned to the pool.';
$_['error_cancel']        = 'That commitment could not be withdrawn. A pool can only be left while it is still filling.';

// Reviews
$_['text_reviews_heading']    = 'Rate your completed purchases';
$_['text_reviews_intro']      = 'You can review a purchase once the pool has been fulfilled and paid. Reviews are checked before they appear publicly.';
$_['text_review_for']         = 'Review %s';
$_['text_your_reviews']       = 'Your reviews';
$_['text_review_pending']     = 'Awaiting moderation';
$_['text_review_approved']    = 'Published';
$_['text_review_rejected']    = 'Not published';
$_['text_review_submitted']   = 'Thank you. Your review has been submitted and will appear once it has been checked.';
$_['text_no_reviewable']      = 'You have no completed purchases waiting for a review.';

$_['entry_review_rating']     = 'Rating';
$_['entry_review_title']      = 'Headline';
$_['entry_review_text']       = 'Your review';
$_['button_submit_review']    = 'Submit review';
$_['help_review_text']        = 'Tell other buyers about the product quality, the packaging and how the seller handled the order.';

$_['error_review_method']           = 'A review must be submitted from the review form.';
$_['error_review_not_found']        = 'That purchase could not be found.';
$_['error_review_not_purchased']    = 'You can only review a pool you actually bought into.';
$_['error_review_not_fulfilled']    = 'You can review this pool once it has been fulfilled.';
$_['error_review_not_paid']         = 'You can review this purchase once its payment has been settled.';
$_['error_review_already_reviewed'] = 'You have already reviewed that purchase.';
$_['error_review_empty_text']       = 'Please write a few words about your purchase.';
