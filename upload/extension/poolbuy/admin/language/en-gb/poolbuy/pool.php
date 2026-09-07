<?php
// Heading
$_['heading_title']          = 'PoolBuy Pools';

// Text
$_['text_extension']         = 'Extensions';
$_['text_add']               = 'Create New Pool';
$_['text_edit']              = 'Edit Pool';
$_['text_success']           = 'Success: You have modified the pool!';
$_['text_success_delete']    = 'Success: You have deleted the selected pools!';
$_['text_list']              = 'Active Pool Campaigns';
$_['text_subtitle']          = 'Manage your wholesale clusters and track reservation progress.';
$_['text_general']           = 'General';
$_['text_schedule']          = 'Schedule &amp; Limits';
$_['text_tiers']             = 'Price Tiers';
$_['text_logistics']         = 'Logistics';
$_['text_no_results']        = 'No pools found.';
$_['text_pagination']        = 'Showing %d to %d of %d (%d Pages)';
$_['text_confirm']           = 'Are you sure you want to delete the selected pools?';
$_['text_all']               = 'All';
$_['text_default_unit_label'] = 'Units';
$_['text_add_tier']          = 'Add Tier';
$_['text_open_ended']        = 'Open ended';
$_['text_ended']             = 'Ended';
$_['text_time_days']         = '%dd %02dh';
$_['text_time_hours']        = '%02dh %02dm';
$_['text_reserved_locked']   = 'Reserved quantity is derived from buyer commitments and cannot be edited here.';

// Statistics
$_['text_total_volume']      = 'TOTAL VOLUME';
$_['text_active_pools']      = 'ACTIVE POOLS';
$_['text_average_fill']      = 'AVERAGE FILL RATE';
$_['text_closing_soon']      = '%d reaching MOQ soon';

// Status
$_['text_status_draft']      = 'Draft';
$_['text_status_active']     = 'Active';
$_['text_status_reached']    = 'MOQ Reached';
$_['text_status_closed']     = 'Closed';
$_['text_status_expired']    = 'Expired';
$_['text_status_fulfilled']  = 'Fulfilled';
$_['text_status_cancelled']  = 'Cancelled';

// Column
$_['column_product']         = 'Product';
$_['column_seller']          = 'Seller';
$_['column_moq']             = 'MOQ';
$_['column_reserved']        = 'Reserved';
$_['column_progress']        = 'Progress';
$_['column_time']            = 'Time Remaining';
$_['column_status']          = 'Status';
$_['column_action']          = 'Action';

// Entry
$_['entry_product']          = 'Product';
$_['entry_seller']           = 'Seller';
$_['entry_title']            = 'Pool Title';
$_['entry_reference']        = 'Reference';
$_['entry_moq_target']       = 'MOQ Target';
$_['entry_unit_label']       = 'Unit Label';
$_['entry_currency_code']    = 'Currency';
$_['entry_status']           = 'Status';
$_['entry_date_start']       = 'Start Date';
$_['entry_date_end']         = 'End Date';
$_['entry_retro_pricing']    = 'Retroactive Pricing';
$_['entry_allow_full_moq']   = 'Allow Buy Entire MOQ';
$_['entry_min_qty']          = 'Minimum Per Buyer';
$_['entry_max_qty']          = 'Maximum Per Buyer';
$_['entry_lead_time']        = 'Lead Time';
$_['entry_shipping_terms']   = 'Shipping Terms';
$_['entry_tier_min']         = 'From Qty';
$_['entry_tier_max']         = 'To Qty';
$_['entry_tier_price']       = 'Unit Price';
$_['entry_filter_product']   = 'Product';
$_['entry_filter_status']    = 'Status';

// Help
$_['help_title']             = 'Left blank, the product name is used.';
$_['help_reference']         = 'Left blank, a reference such as PB-4F2A19 is generated.';
$_['help_unit_label']        = 'How the quantity reads on the storefront, for example Units, Buckets, Kits or Boxes.';
$_['help_retro_pricing']     = 'When the pool closes, every participant is moved to the best tier the pool unlocked.';
$_['help_allow_full_moq']    = 'Lets one buyer take the entire remaining MOQ in a single commitment.';
$_['help_max_qty']           = 'Set 0 for no per-buyer ceiling.';
$_['help_tiers']             = 'Tiers must start at 1 or more, be contiguous with no gaps or overlaps, and the highest tier must be open ended (leave To Qty blank) so any quantity can be priced. Tiers are matched against the pool\'s total reserved quantity, so collective volume unlocks the price for everyone.';
$_['help_lead_time']         = 'For example: 15-20 Days.';
$_['help_shipping_terms']    = 'For example: FOB Shanghai.';

// Detail panel
$_['text_reservation_map']     = 'Reservation Map';
$_['text_cell_filled']         = 'Reserved';
$_['text_cell_empty']          = 'Available';
$_['text_map_scaled']          = 'Each cell represents %d units, because this pool\'s MOQ is too large to draw unit by unit.';
$_['text_slots_remaining']     = '%d more units unlock the next tier at %s per unit.';
$_['text_current_price']       = 'Best tier unlocked: %s per unit. No further tiers remain.';
$_['text_no_tier']             = 'No tier unlocked yet';
$_['text_recent_reservations'] = 'Recent Reservations';
$_['text_no_reservations']     = 'No buyers have committed to this pool yet.';
$_['text_buyer_handle']        = 'Buyer #%d';
$_['text_gold_partner']        = 'Gold Partner';
$_['text_gst_buyer']           = 'GST Verified';
$_['text_orders_completed']    = '%d orders completed';
$_['text_buyer_privacy']       = 'Buyer names and contact details are deliberately not shown here. Reach participants through their orders once the pool is fulfilled.';
$_['text_quick_actions']       = 'Quick Actions';
$_['text_order_readiness']     = 'Order Readiness';
$_['text_readiness_body']      = 'This pool is at %s%% of its MOQ. Prepare fulfilment logistics so it can dispatch as soon as the target is met.';
$_['text_confirm_close']       = 'Close this pool now? If the MOQ has been met it becomes a live deal; if not, it expires and every commitment is released.';
$_['text_closed_reached']      = 'Pool closed with %d of %d units. The MOQ was met, so it is now a live deal.';
$_['text_closed_expired']      = 'Pool closed with only %d of %d units. The MOQ was not met, so it expired and all commitments were released.';

// Reservation statuses
$_['text_reservation_pending']   = 'Pending';
$_['text_reservation_confirmed'] = 'Confirmed';
$_['text_reservation_cancelled'] = 'Withdrawn';
$_['text_reservation_converted'] = 'Ordered';
$_['text_reservation_released']  = 'Released';

// Relative time
$_['text_minutes_ago']         = '%dm ago';
$_['text_hours_ago']           = '%dh ago';
$_['text_days_ago']            = '%dd ago';

// Buttons
$_['button_view']              = 'View';
$_['button_close_panel']       = 'Close panel';
$_['button_edit_pool']         = 'Edit pool and tiers';
$_['button_edit_product']      = 'Edit product';
$_['button_edit_seller']       = 'Edit seller';
$_['button_view_orders']       = 'Sales orders';
$_['button_close_early']       = 'Close pool early';
$_['help_close_early']         = 'The outcome is decided by the units actually committed, not by you.';

// Error
$_['error_permission']       = 'Warning: You do not have permission to modify PoolBuy pools!';
$_['error_not_found']        = 'That pool could not be found.';
$_['error_not_active']       = 'Only a pool that is still filling can be closed early.';
$_['error_product']          = 'Select an existing product for this pool!';
$_['error_seller']           = 'Select an existing seller for this pool!';
$_['error_moq_target']       = 'MOQ target must be at least 1!';
$_['error_date_start']       = 'A valid start date is required!';
$_['error_date_end']         = 'A valid end date is required!';
$_['error_date_order']       = 'The end date must be later than the start date!';
$_['error_min_qty']          = 'Minimum per buyer must be at least 1!';
$_['error_max_qty']          = 'Maximum per buyer must be 0 for no limit, or greater than or equal to the minimum!';
$_['error_status']           = 'That is not a valid pool status!';
$_['error_transition']       = 'A pool cannot move from %s to %s.';
$_['error_reference']        = 'Reference may contain only uppercase letters, digits and hyphens, 3 to 32 characters!';
$_['error_reference_unique']  = 'Another pool already uses that reference!';
$_['error_selection']        = 'Warning: No pools selected!';
$_['error_has_reservations'] = 'Cannot delete %s because buyers have already reserved units in it. Cancel the pool instead so participants are handled properly.';
