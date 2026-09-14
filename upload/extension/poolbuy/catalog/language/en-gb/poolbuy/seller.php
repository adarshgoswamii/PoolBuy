<?php
// Heading
$_['heading_title']       = 'Seller Portal';
$_['text_subtitle']       = 'Manage your wholesale pools and track reservation progress.';

// Stats
$_['text_total_volume']   = 'Total committed volume';
$_['text_active_pools']   = 'Active pools';
$_['text_average_fill']   = 'Average fill rate';
$_['text_closing_soon']   = '%d reaching MOQ soon';

// List
$_['text_campaigns']      = 'Your Pool Campaigns';
$_['text_settlements']       = 'Settlements';
$_['text_settlement_pool']   = 'Pool';
$_['text_settlement_orders'] = 'Orders';
$_['text_settlement_gross']  = 'Gross';
$_['text_settlement_fee']    = 'Platform fee';
$_['text_settlement_net']    = 'Net payable';
$_['text_settlement_pending'] = 'Pending';
$_['text_settlement_paid']    = 'Paid';
$_['text_all']            = 'All';
$_['text_pagination']     = 'Showing %d to %d of %d (%d Pages)';
$_['text_filled']         = '%d of %d %s';
$_['text_left']           = '%d left';
$_['text_time_left']      = '%s left';
$_['text_ended']          = 'Ended';
$_['text_no_pools']       = 'You have no pools yet';
$_['text_no_pools_body']  = 'Create your first pool to start collecting commitments from buyers.';
$_['button_add_pool']     = 'Create New Pool';
$_['button_view']         = 'View';
$_['button_edit']         = 'Edit';
$_['button_back']         = 'Back to campaigns';

// Navigation
$_['tab_pools']           = 'Pools';
$_['tab_products']        = 'Products';
$_['tab_orders']          = 'Orders';

// Statuses
$_['text_status_draft']     = 'Draft';
$_['text_status_active']    = 'Active';
$_['text_status_reached']   = 'MOQ Reached';
$_['text_status_closed']    = 'Closed';
$_['text_status_expired']   = 'Expired';
$_['text_status_fulfilled'] = 'Fulfilled';
$_['text_status_cancelled'] = 'Cancelled';

// Approval
$_['text_pending_approval'] = 'Your seller account is awaiting approval';
$_['text_pending_body']     = 'You can prepare pools as drafts now. Once your account is approved you will be able to publish them.';

// Detail
$_['text_reservation_map']  = 'Reservation Map';
$_['text_map_scaled']       = 'Each cell represents %d units.';
$_['text_tiers']            = 'Price Tiers';
$_['text_tier_range']       = '%d to %d';
$_['text_tier_open']        = '%d and above';
$_['text_tier_active']      = 'Current';
$_['text_commitments']      = 'Commitments';
$_['text_no_commitments']   = 'No buyers have committed to this pool yet.';
$_['text_buyer_handle']     = 'Buyer #%d';
$_['text_gold_partner']     = 'Gold Partner';
$_['text_gst_buyer']        = 'GST Verified';
$_['text_orders_completed'] = '%d orders completed';
$_['text_buyer_privacy']    = 'Buyer identities are never shared with sellers. You will receive full delivery details on each order once the pool is fulfilled.';
$_['text_price_pending']    = 'Priced at close';
$_['text_minutes_ago']      = '%dm ago';
$_['text_hours_ago']        = '%dh ago';
$_['text_days_ago']         = '%dd ago';

// Reservation statuses
$_['text_reservation_pending']   = 'Pending';
$_['text_reservation_confirmed'] = 'Confirmed';
$_['text_reservation_converted'] = 'Ordered';

// Form
$_['text_add_pool']        = 'Create Pool';
$_['text_edit_pool']       = 'Edit Pool';
$_['text_general']         = 'Pool details';
$_['text_schedule']        = 'Schedule and limits';
$_['text_price_tiers']     = 'Price tiers';
$_['text_logistics']       = 'Logistics';
$_['entry_product']        = 'Product';
$_['entry_title']          = 'Pool title';
$_['entry_moq_target']     = 'MOQ target';
$_['entry_unit_label']     = 'Unit label';
$_['entry_status']         = 'Status';
$_['entry_date_start']     = 'Start date';
$_['entry_date_end']       = 'End date';
$_['entry_min_qty']        = 'Minimum per buyer';
$_['entry_max_qty']        = 'Maximum per buyer';
$_['entry_retro_pricing']  = 'Retroactive pricing';
$_['entry_allow_full_moq'] = 'Allow one buyer to take the whole MOQ';
$_['entry_lead_time']      = 'Lead time';
$_['entry_shipping_terms'] = 'Shipping terms';
$_['entry_freight_total']  = 'Pool freight total';
$_['help_freight_total']   = 'Total freight for the whole pool. Split across participants by units committed when the pool closes, so each buyer pays less as the pool fills. Leave at 0 if you do not charge freight through the platform.';
$_['entry_tier_min']       = 'From qty';
$_['entry_tier_max']       = 'To qty';
$_['entry_tier_price']     = 'Unit price';
$_['text_open_ended']      = 'Open ended';
$_['text_add_tier']        = 'Add tier';
$_['text_remove_tier']     = 'Remove tier';
$_['text_reference']       = 'Reference';
$_['text_reserved']        = 'Reserved so far';
$_['help_title']           = 'Left blank, the product name is used.';
$_['help_max_qty']         = 'Set 0 for no per-buyer ceiling.';
$_['help_retro_pricing']   = 'When the pool closes, every participant receives the best tier the pool reached.';
$_['help_tiers']           = 'Tiers must start at 1 or more, be contiguous with no gaps or overlaps, and the highest tier must be open ended. Tiers are matched against the pool\'s combined volume.';
$_['help_no_products']     = 'You have no products yet. Add one from the Products tab, then come back and attach a pool to it.';
$_['help_locked']          = 'This pool has progressed beyond drafting, so its terms can no longer be changed.';
$_['button_save']          = 'Save pool';
$_['text_saved']           = 'Your pool has been saved.';

// Not a seller
$_['text_not_seller']       = 'This area is for sellers';
$_['text_not_seller_body']  = 'Your account is not registered as a PoolBuy seller. If you manufacture or distribute wholesale goods and would like to list pools, apply below and our team will verify your business.';
$_['button_become_seller']  = 'Apply to sell';
$_['button_browse']         = 'Browse the marketplace';
$_['button_account']        = 'Your account';

// Registration / onboarding
$_['text_register']          = 'Apply to sell on PoolBuy';
$_['text_register_intro']    = 'Tell us about your business. We verify every seller before their pools go live, so buyers know who they are committing to.';
$_['text_register_business'] = 'Business details';
$_['text_register_verify']   = 'Verification';
$_['entry_business_name']    = 'Registered business name';
$_['entry_gst_number']       = 'GSTIN';
$_['entry_location']         = 'Primary location';
$_['entry_description']      = 'What do you supply?';
$_['entry_terms']            = 'I confirm the details above are accurate and I am authorised to trade on behalf of this business.';
$_['help_business_name']     = 'As it appears on your GST registration.';
$_['help_gst_number']        = 'Your 15 character GST identification number, for example 27ABCDE1234F1Z5. We verify this before approving your account.';
$_['help_location']          = 'City and state your goods ship from.';
$_['help_description']       = 'Optional. A short description shown on your seller profile.';
$_['button_apply']           = 'Submit application';
$_['text_register_pending']  = 'Applications are reviewed by the marketplace team. You can prepare pools as drafts straight away, and publish them once your account is approved.';

// Errors
$_['error_login']        = 'Sign in to use the seller portal.';
$_['error_not_seller']   = 'Your account is not registered as a seller.';
$_['error_unavailable']  = 'The PoolBuy marketplace is currently unavailable.';
$_['error_business_name'] = 'Business name must be between 3 and 128 characters.';
$_['error_gst_number']    = 'Enter a valid 15 character GSTIN, for example 27ABCDE1234F1Z5.';
$_['error_location']      = 'Enter the city and state you ship from.';
$_['error_description']   = 'Description must be 1000 characters or fewer.';
$_['error_terms']         = 'Please confirm you are authorised to trade on behalf of this business.';
$_['error_check_form']    = 'Please correct the highlighted fields and submit again.';
$_['error_pool']         = 'That pool could not be found in your account.';
$_['error_locked']       = 'This pool can no longer be edited because it has progressed beyond drafting.';
$_['error_product']      = 'Choose one of your own products for this pool.';
$_['error_moq_target']   = 'MOQ target must be at least 1.';
$_['error_date_start']   = 'A valid start date is required.';
$_['error_date_end']     = 'A valid end date is required.';
$_['error_date_order']   = 'The end date must be later than the start date.';
$_['error_min_qty']      = 'Minimum per buyer must be at least 1.';
$_['error_max_qty']      = 'Maximum per buyer must be 0 for no limit, or at least the minimum.';
$_['error_freight_total'] = 'Pool freight total must be a number of 0 or more.';
$_['error_status']       = 'That status change is not allowed.';
$_['error_not_approved'] = 'Your seller account must be approved before you can publish a pool. Save it as a draft for now.';

// Settlement totals, grouped by currency
$_['text_settlement_totals']           = 'Totals by currency';
$_['text_settlement_currency']         = 'Currency';
$_['text_settlement_pools']            = 'Pools';
$_['text_settlement_net_pending']      = 'Awaiting payout';
$_['text_settlement_net_paid']         = 'Paid out';
$_['text_settlement_unknown_currency'] = 'Unspecified';
$_['text_settlement_currency_note']    = 'Amounts are shown in the currency each pool traded in and are never added across currencies.';
