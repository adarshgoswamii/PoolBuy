<?php
// Notification emails sent by the pool lifecycle.
//
// Each body takes: firstname, pool title, quantity, unit label, reservation reference.

$_['subject_reached']   = 'Your pool %s has reached its MOQ';
$_['body_reached']      = "Hello %s,\n\nGood news: the pool \"%s\" has reached its minimum order quantity, so the deal is confirmed.\n\nYour commitment: %d %s\nReference: %s\n\nEvery participant now receives the best price the pool unlocked. If a better tier was reached after you joined, your price has already been reduced.\n\nWe will raise your invoice shortly and the supplier will ship directly to your delivery address.\n\nThank you for pooling with us.";

$_['subject_expired']   = 'Pool %s did not reach its MOQ';
$_['body_expired']      = "Hello %s,\n\nThe pool \"%s\" closed without reaching its minimum order quantity, so the deal will not proceed.\n\nYour commitment: %d %s\nReference: %s\n\nNothing has been charged and your commitment has been released. You are free to join another pool at any time.\n\nWe are sorry this one did not fill.";

$_['subject_fulfilled'] = 'Your pool order for %s is confirmed';
$_['body_fulfilled']    = "Hello %s,\n\nThe pool \"%s\" has been fulfilled and your order has been created.\n\nYour commitment: %d %s\nReference: %s\n\nYou can view the order in your account. The supplier ships directly to your delivery address.\n\nThank you for pooling with us.";

// Payment notifications. payment_requested takes: firstname, order id, pay url.
// payment_received and refund_issued take: firstname, order id.
$_['subject_payment_requested'] = 'Payment due for your pool order %d';
$_['body_payment_requested']    = "Hello %s,\n\nYour pool order #%d is ready for payment.\n\nPlease complete payment here:\n%s\n\nOnce paid, the supplier ships directly to your delivery address.\n\nThank you for pooling with us.";

$_['subject_payment_received']  = 'Payment received for order %d';
$_['body_payment_received']     = "Hello %s,\n\nWe have received payment for your pool order #%d. Thank you!\n\nThe supplier will now ship directly to your delivery address. You can track the order in your account.";

$_['subject_refund_issued']     = 'Refund issued for order %d';
$_['body_refund_issued']        = "Hello %s,\n\nThe pool behind your order #%d did not proceed, so the payment you made has been refunded in full.\n\nThe refund will appear on your original payment method according to your provider's timelines. You are welcome to join another pool at any time.";
