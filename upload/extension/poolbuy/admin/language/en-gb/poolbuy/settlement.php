<?php
// Heading
$_['heading_title']            = 'Payments &amp; Settlements';
$_['text_poolbuy']             = 'PoolBuy';
$_['text_subtitle']            = 'What buyers have paid, what is outstanding, and what the platform owes each seller.';

// Reporting
$_['text_revenue']             = 'Payments by currency';
$_['text_liability']           = 'Seller settlements by currency';
$_['text_currency']            = 'Currency';
$_['text_count']               = 'Count';
$_['text_value']               = 'Value';
$_['text_currency_note']       = 'Amounts are shown in the currency each pool traded in and are never added across currencies.';
$_['text_pending_settlements'] = '%d settlement(s) awaiting payout';

// Payments
$_['text_payments']            = 'Payments';
$_['column_payment']           = 'Payment';
$_['column_order']             = 'Order';
$_['column_pool']              = 'Pool';
$_['column_seller']            = 'Seller';
$_['column_gateway']           = 'Gateway';
$_['column_reference']         = 'Gateway reference';
$_['column_amount']            = 'Amount';
$_['column_status']            = 'Status';
$_['column_date']              = 'Raised';
$_['text_payment_pending']     = 'Pending';
$_['text_payment_paid']        = 'Paid';
$_['text_payment_failed']      = 'Failed';
$_['text_payment_refunded']    = 'Refunded';
$_['text_no_payments']         = 'No payments have been raised yet.';

// Settlements
$_['text_settlements']         = 'Seller settlements';
$_['column_settlement']        = 'Settlement';
$_['column_orders']            = 'Orders';
$_['column_gross']             = 'Gross';
$_['column_fee']               = 'Platform fee';
$_['column_net']               = 'Net payable';
$_['column_action']            = 'Action';
$_['text_settlement_pending']  = 'Awaiting payout';
$_['text_settlement_paid']     = 'Paid out';
$_['text_no_settlements']      = 'No settlements have been raised yet.';
$_['button_mark_paid']         = 'Mark paid';
$_['text_paid']                = 'The settlement has been marked as paid out.';
$_['text_confirm_pay']         = 'Mark this settlement as paid out? This records the payout and cannot be undone from here.';

// Filters
$_['text_filter']              = 'Filter';
$_['entry_status']             = 'Payment status';
$_['entry_settlement_status']  = 'Settlement status';
$_['entry_order_id']           = 'Order ID';
$_['entry_reference']          = 'Pool reference';
$_['text_all']                 = 'All';
$_['button_filter']            = 'Filter';
$_['text_pagination']          = 'Showing %d to %d of %d (%d Pages)';
$_['text_none']                = '&mdash;';

// Errors
$_['error_permission']         = 'Warning: You do not have permission to modify PoolBuy settlements.';
$_['error_settlement']         = 'No settlement was selected.';
$_['error_not_pending']        = 'That settlement could not be paid: it does not exist, or it has already been paid out.';
