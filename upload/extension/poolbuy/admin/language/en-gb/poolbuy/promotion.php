<?php
// Heading
$_['heading_title']            = 'PoolBuy Promotions';

// Text
$_['text_home']                = 'Dashboard';
$_['text_poolbuy']             = 'PoolBuy';
$_['text_success']             = 'Success: You have modified PoolBuy promotions!';
$_['text_list']                = 'Promotion List';
$_['text_add']                 = 'Add Promotion';
$_['text_edit']                = 'Edit Promotion';
$_['text_form']                = 'Promotion Details';
$_['text_no_results']          = 'No promotions found.';
$_['text_pagination']          = 'Showing %d to %d of %d (%d Pages)';
$_['text_enabled']             = 'Enabled';
$_['text_disabled']            = 'Disabled';
$_['text_select']              = '--- Select ---';
$_['text_all']                 = 'All';
$_['text_confirm']             = 'Are you sure you want to delete the selected promotions?';

$_['text_scope_buyer']         = 'Buyer code';
$_['text_scope_pool']          = 'Pool-wide';
$_['text_type_percent']        = 'Percentage';
$_['text_type_fixed']          = 'Fixed amount';

$_['text_always']              = 'Always';
$_['text_from']                = 'From %s';
$_['text_until']               = 'Until %s';
$_['text_unlimited']           = 'Unlimited';
$_['text_unlimited_used']      = '%d (unlimited)';
$_['text_any_pool']            = 'Any pool';

$_['text_redemptions']         = 'Redemptions';
$_['text_no_redemptions']      = 'This promotion has not been redeemed yet.';
$_['text_redemption_pool']     = 'Pool';
$_['text_redemption_ref']      = 'Commitment';
$_['text_redemption_amount']   = 'Discount';
$_['text_redemption_date']     = 'Date';

$_['text_scope_help']          = 'A buyer code is entered during the join flow and discounts one commitment. A pool-wide promotion is attached to a pool and applied to every participant when that pool closes, taking precedence over any buyer code.';

// Column
$_['column_code']              = 'Code';
$_['column_name']              = 'Name';
$_['column_scope']             = 'Scope';
$_['column_value']             = 'Discount';
$_['column_pool']              = 'Pool';
$_['column_redemptions']       = 'Redeemed';
$_['column_window']            = 'Active window';
$_['column_status']            = 'Status';
$_['column_action']            = 'Action';

// Entry
$_['entry_code']               = 'Code';
$_['entry_name']               = 'Name';
$_['entry_scope']              = 'Scope';
$_['entry_pool']               = 'Pool';
$_['entry_type']               = 'Discount Type';
$_['entry_value']              = 'Discount Value';
$_['entry_min_qty']            = 'Minimum Quantity';
$_['entry_max_redemptions']    = 'Maximum Redemptions';
$_['entry_date_start']         = 'Start Date';
$_['entry_date_end']           = 'End Date';
$_['entry_status']             = 'Status';
$_['entry_filter_code']        = 'Code';
$_['entry_filter_scope']       = 'Scope';
$_['entry_filter_status']      = 'Status';

// Help
$_['help_code']                = 'Uppercase letters, digits and hyphens, 2 to 32 characters. This is what a buyer types.';
$_['help_value']               = 'A percentage of the commitment subtotal, or a fixed amount in the pool currency.';
$_['help_min_qty']             = 'The smallest commitment this code applies to. 0 for no minimum.';
$_['help_max_redemptions']     = 'Total times this code may be used across all buyers. 0 for unlimited.';
$_['help_dates']               = 'Leave blank for no bound. A promotion with no dates is always active while enabled.';
$_['help_pool']                = 'Required for a pool-wide promotion. For a buyer code, leave unset to allow any pool.';
$_['help_redemptions_used']    = 'Maintained automatically as the code is claimed and released. Not editable.';

// Button
$_['button_add']               = 'Add New';
$_['button_save']              = 'Save';
$_['button_back']              = 'Back';
$_['button_edit']              = 'Edit';
$_['button_delete']            = 'Delete';
$_['button_filter']            = 'Filter';

// Error
$_['error_permission']         = 'Warning: You do not have permission to modify PoolBuy promotions!';
$_['error_code']               = 'Code must be 2 to 32 characters using uppercase letters, digits and hyphens!';
$_['error_code_unique']        = 'Another promotion already uses that code!';
$_['error_name']               = 'Name must be between 2 and 128 characters!';
$_['error_scope']              = 'Select a valid scope!';
$_['error_type']               = 'Select a valid discount type!';
$_['error_value']              = 'Discount value must be greater than zero!';
$_['error_percent_range']      = 'A percentage discount cannot exceed 100!';
$_['error_pool_required']      = 'A pool-wide promotion must be attached to a pool!';
$_['error_pool']               = 'Select an existing pool!';
$_['error_pool_taken']         = 'That pool already has an active pool-wide promotion!';
$_['error_min_qty']            = 'Minimum quantity cannot be negative!';
$_['error_max_redemptions']    = 'Maximum redemptions cannot be negative!';
$_['error_date']               = 'Enter a valid date!';
$_['error_date_order']         = 'The end date must be later than the start date!';
$_['error_selection']          = 'Warning: No promotions selected!';
