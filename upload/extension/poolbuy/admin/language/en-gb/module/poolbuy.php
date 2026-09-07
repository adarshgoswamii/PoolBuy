<?php
// Heading
$_['heading_title']          = 'PoolBuy Wholesale';

// Text
$_['text_extension']         = 'Extensions';
$_['text_success']           = 'Success: You have modified the PoolBuy settings!';
$_['text_edit']              = 'PoolBuy Marketplace Settings';
$_['text_pricing']           = 'Pricing &amp; Tax';
$_['text_pools']             = 'Pool Defaults';
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
$_['entry_cron_token']       = 'Cron Token';

// Help
$_['help_currency_code']     = 'Three-letter ISO code used for pool pricing, e.g. INR or USD.';
$_['help_gst_rate']          = 'Applied to the pool subtotal when a buyer reserves units.';
$_['help_platform_fee']      = 'Marketplace commission applied to the pool subtotal.';
$_['help_pool_duration']     = 'Pre-fills the end date when creating a new pool.';
$_['help_retro_pricing']     = 'When enabled, every participant receives the best price tier unlocked by the pool, not just the tier active when they joined.';
$_['help_cron_token']        = 'Secret required to trigger the pool lifecycle cron. Keep this private; regenerate it if it leaks.';

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
