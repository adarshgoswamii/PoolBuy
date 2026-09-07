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
