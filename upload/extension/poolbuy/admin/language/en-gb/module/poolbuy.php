<?php
// Heading
$_['heading_title']          = 'PoolBuy Wholesale';

// Text
$_['text_extension']         = 'Extensions';
$_['text_success']           = 'Success: You have modified the PoolBuy settings!';
$_['text_edit']              = 'PoolBuy Marketplace Settings';
$_['text_pricing']           = 'Pricing &amp; Tax';
$_['text_pools']             = 'Pool Defaults';
$_['text_nav_manage']        = 'Manage';
$_['text_nav_pools']         = 'Pools';
$_['text_nav_sellers']       = 'Sellers';
$_['text_nav_promotions']    = 'Promotions';
$_['text_nav_reviews']       = 'Reviews';
$_['text_nav_settlements']   = 'Payments &amp; Settlements';
$_['text_payments']          = 'Payments';
$_['text_gateway_mock']      = 'Mock (no credentials, for testing &amp; demo)';
$_['text_gateway_razorpay']  = 'Razorpay (Payment Links)';
$_['text_automation']        = 'Automation';
$_['text_schema_ok']         = 'Database schema installed.';
$_['text_schema_missing']    = 'Database schema is NOT installed. Uninstall and reinstall the extension from Extensions &gt; Modules.';
$_['text_run_lifecycle']     = 'Run Lifecycle Now';
$_['text_run_lifecycle_help'] = 'Process every pool now: activate due pools, lock pools that hit their MOQ, reprice participants, raise orders and expire pools past their window. Safe to run repeatedly. No token needed.';
$_['text_lifecycle_ran']     = 'Lifecycle processed.';
$_['text_lifecycle_activated'] = '%d activated';
$_['text_lifecycle_reached'] = '%d reached MOQ';
$_['text_lifecycle_expired'] = '%d expired';
$_['text_lifecycle_closed']  = '%d closed';
$_['text_lifecycle_fulfilled'] = '%d fulfilled';
$_['text_lifecycle_orders']  = '%d orders created';
$_['text_lifecycle_repriced'] = '%d repriced';

// Entry
$_['entry_status']           = 'Status';
$_['entry_currency_code']    = 'Currency Code';
$_['entry_currency_symbol']  = 'Currency Symbol';
$_['entry_gst_rate']         = 'GST / Tax Rate (%)';
$_['entry_platform_fee']     = 'Platform Fee (%)';
$_['entry_pool_duration']    = 'Default Pool Duration (days)';
$_['entry_retro_pricing']    = 'Retroactive Pricing';
$_['entry_gateway']              = 'Payment Gateway';
$_['entry_razorpay_key']         = 'Razorpay Key ID';
$_['entry_razorpay_secret']      = 'Razorpay Key Secret';
$_['entry_razorpay_webhook_secret'] = 'Razorpay Webhook Secret';
$_['entry_cron_token']       = 'Cron Token';
$_['entry_rate_limit']       = 'API Rate Limit';
$_['entry_rate_window']      = 'Rate Limit Window';

// Help
$_['help_currency_code']     = 'Three-letter ISO code used for pool pricing, e.g. INR or USD.';
$_['help_gst_rate']          = 'Applied to the pool subtotal when a buyer reserves units.';
$_['help_platform_fee']      = 'Marketplace commission applied to the pool subtotal.';
$_['help_pool_duration']     = 'Pre-fills the end date when creating a new pool.';
$_['help_retro_pricing']     = 'When enabled, every participant receives the best price tier unlocked by the pool, not just the tier active when they joined.';
$_['help_gateway']           = 'Mock requires no credentials and marks payments paid instantly - use it for testing and demos. Razorpay is used only when a Key ID and Secret are both set below.';
$_['help_razorpay_webhook_secret'] = 'Secret configured on the Razorpay webhook. Inbound webhooks with a blank or invalid signature are always rejected.';
$_['help_cron_token']        = 'Secret required to trigger the pool lifecycle cron. Keep this private; regenerate it if it leaks.';
$_['help_rate_limit']        = 'Maximum requests per caller, per endpoint, per window, applied to the cron trigger and the payment webhook. Left blank this falls back to 120 rather than to unlimited. Set 0 only if you deliberately want the limiter off.';
$_['help_rate_window']       = 'Length of the rate limit window in seconds. Defaults to 60.';

// Button
$_['button_regenerate']      = 'Regenerate';
$_['button_run_lifecycle']   = 'Run Lifecycle Now';

// Error
$_['error_permission']       = 'Warning: You do not have permission to modify the PoolBuy settings!';
$_['error_currency_code']    = 'Currency code must be exactly three letters!';
$_['error_currency_symbol']  = 'Currency symbol is required!';
$_['error_gst_rate']         = 'GST rate must be a number between 0 and 100!';
$_['error_platform_fee']     = 'Platform fee must be a number between 0 and 100!';
$_['error_pool_duration']    = 'Default pool duration must be a whole number between 1 and 365 days!';
$_['error_disabled']         = 'Enable PoolBuy (Status = Enabled) and save before running the lifecycle.';
$_['error_no_token']         = 'No cron token is configured. Save the settings once to generate one, then try again.';
$_['error_lifecycle']        = 'The lifecycle run did not complete. Check the OpenCart error log for details.';
