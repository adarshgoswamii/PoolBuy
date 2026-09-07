<?php
// Heading
$_['heading_title']      = 'Join Pool';
$_['text_marketplace']   = 'Marketplace';

// Steps
$_['text_step_of']       = 'Step %d of %d';
$_['text_step_1_title']  = 'Enter Quantity';
$_['text_step_2_title']  = 'Price Breakdown';
$_['text_step_3_title']  = 'Shipping';
$_['text_step_4_title']  = 'Reservation Confirmed';

// Pool summary
$_['text_pool_ref']      = 'Pool';
$_['text_filled']        = '%d of %d %s filled';
$_['text_time_left']     = '%s left';
$_['text_ended']         = 'Closed';

// Step 1
$_['text_units_reserve'] = 'Units to reserve';
$_['text_qty_help']      = 'Between %d and %d %s.';
$_['text_live_price']    = 'Price you unlock';
$_['text_per_unit']      = 'per unit';
$_['text_decrease']      = 'Decrease quantity';
$_['text_increase']      = 'Increase quantity';

// Step 2
$_['text_subtotal']      = 'Subtotal (%d x %s)';
$_['text_gst']           = 'GST / Tax (%s%%)';
$_['text_platform_fee']  = 'Platform Fee (%s%%)';
$_['text_total']         = 'Estimated Total';
$_['text_price_note']    = 'Your final unit price can only improve. If more buyers join and the pool unlocks a better tier, every participant is repriced down before payment is requested.';

// Step 3
$_['text_destination']   = 'Destination address';
$_['text_add_address']   = 'Add a new address';
$_['text_freight_title'] = 'Direct manufacturer freight';
$_['text_freight_note']  = 'Freight is quoted when the pool closes, because cost depends on the final pooled volume. Nothing is charged now.';
$_['text_lead_time']     = 'Lead time';
$_['text_shipping_terms'] = 'Shipping terms';
$_['text_not_specified'] = 'Not specified';
$_['text_commit_note']   = 'Submitting reserves your units. You will be invoiced only once the pool reaches its MOQ.';

// Step 4
$_['text_confirmed']       = 'Reservation confirmed';
$_['text_confirmed_body']  = 'You have reserved %d %s in this pool.';
$_['text_transaction']     = 'Transaction';
$_['text_waiting']         = 'Waiting for the pool to reach its MOQ';
$_['text_view_pools']      = 'View My Pools';
$_['text_keep_browsing']   = 'Continue browsing';

// Buttons
$_['button_continue']    = 'Continue';
$_['button_back']        = 'Back';
$_['button_confirm']     = 'Confirm reservation';

// Blocked states
$_['text_blocked_already']       = 'You have already joined this pool';
$_['text_blocked_already_body']  = 'You are holding %d %s under reference %s. Each business may hold one commitment per pool. Manage it from My Pools.';
$_['text_blocked_closed']        = 'This pool is no longer accepting commitments';
$_['text_blocked_closed_body']   = 'The window has closed. Browse the marketplace for pools that are still filling.';
$_['text_blocked_complete']      = 'This pool has reached its MOQ';
$_['text_blocked_complete_body'] = 'The deal is locked and being prepared for fulfilment, so no further units can be added.';
$_['text_blocked_full']          = 'This pool is full';
$_['text_blocked_full_body']     = 'Every unit has been committed. Browse the marketplace for other live pools.';

// Errors
$_['error_invalid']        = 'That request was not valid.';
$_['error_not_found']      = 'That pool could not be found.';
$_['error_closed']         = 'This pool closed before your reservation could be saved. Nothing has been committed.';
$_['error_full']           = 'The remaining units were taken while you were deciding. Nothing has been committed.';
$_['error_too_many']       = 'Another buyer committed units while you were deciding, so that quantity is no longer available. Please try a smaller quantity.';
$_['error_below_min']      = 'That quantity is below this pool\'s minimum per buyer.';
$_['error_above_max']      = 'That quantity is above this pool\'s maximum per buyer.';
$_['error_already_joined'] = 'You already hold a commitment in this pool.';
$_['error_exception']      = 'Your reservation could not be saved. Nothing has been committed, so please try again.';
$_['error_address']        = 'Select a destination address.';
$_['error_no_address']     = 'Add a delivery address to your account before reserving units.';
