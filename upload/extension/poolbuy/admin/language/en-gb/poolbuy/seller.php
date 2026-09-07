<?php
// Heading
$_['heading_title']         = 'PoolBuy Sellers';

// Text
$_['text_extension']        = 'Extensions';
$_['text_add']              = 'Add Seller';
$_['text_edit']             = 'Edit Seller';
$_['text_success']          = 'Success: You have modified the seller!';
$_['text_success_delete']   = 'Success: You have deleted the selected sellers!';
$_['text_list']             = 'Seller List';
$_['text_general']          = 'General';
$_['text_trust']            = 'Trust &amp; Verification';
$_['text_media']            = 'Media';
$_['text_products']         = 'Products';
$_['text_no_results']       = 'No sellers found.';
$_['text_pagination']       = 'Showing %d to %d of %d (%d Pages)';
$_['text_confirm']          = 'Are you sure you want to delete the selected sellers?';
$_['text_enabled']          = 'Enabled';
$_['text_disabled']         = 'Disabled';
$_['text_yes']              = 'Yes';
$_['text_no']               = 'No';
$_['text_all']              = 'All';
$_['text_add_image']        = 'Add Image';
$_['text_no_products']      = 'No products assigned yet.';

// Column
$_['column_name']           = 'Seller';
$_['column_location']       = 'Location';
$_['column_gst']            = 'GST';
$_['column_rating']         = 'Rating';
$_['column_pools']          = 'Pools';
$_['column_status']         = 'Status';
$_['column_action']         = 'Action';

// Entry
$_['entry_name']            = 'Seller Name';
$_['entry_slug']            = 'URL Slug';
$_['entry_gst_number']      = 'GST Number';
$_['entry_gst_verified']    = 'GST Verified';
$_['entry_verified_seller'] = 'Verified Seller';
$_['entry_rating']          = 'Rating';
$_['entry_rating_count']    = 'Rating Count';
$_['entry_description']     = 'Description';
$_['entry_logo']            = 'Logo';
$_['entry_images']          = 'Factory Gallery';
$_['entry_location']        = 'Location';
$_['entry_status']          = 'Status';
$_['entry_product']         = 'Assign Products';
$_['entry_customer']        = 'Linked Account';

// Button
$_['button_unlink']         = 'Unlink account';

// Help
$_['help_slug']             = 'Left blank, this is generated from the seller name.';
$_['help_gst_number']       = 'A 15 character Indian GSTIN, for example 29ABCDE1234F1Z5. Leave blank if the seller is not GST registered.';
$_['help_gst_verified']     = 'Displays the GST Verified trust badge on the storefront. Requires a GST number.';
$_['help_verified_seller']  = 'Displays the green Verified Seller badge on the storefront.';
$_['help_rating']           = 'Between 0.0 and 5.0.';
$_['help_images']           = 'Shown on the product page as proof of manufacturing capability.';
$_['help_product']          = 'Products supplied by this seller. A product can belong to only one seller.';
$_['help_customer']         = 'Link a storefront customer account so this seller can sign in to the seller portal and manage their own pools. Leave blank to keep the seller admin managed. The seller must also be Enabled before they can publish.';

// Error
$_['error_permission']      = 'Warning: You do not have permission to modify PoolBuy sellers!';
$_['error_name']            = 'Seller name must be between 3 and 128 characters!';
$_['error_gst_number']      = 'That is not a valid GSTIN. Expected 15 characters, for example 29ABCDE1234F1Z5.';
$_['error_gst_required']    = 'A GST number is required before the seller can be marked GST verified.';
$_['error_rating']          = 'Rating must be between 0.0 and 5.0!';
$_['error_location']        = 'Location must be 128 characters or fewer!';
$_['error_slug_unique']     = 'Another seller already uses that URL slug!';
$_['error_customer']        = 'That customer account does not exist!';
$_['error_customer_taken']  = 'That customer account is already linked to a different seller!';
$_['error_selection']       = 'Warning: No sellers selected!';
$_['error_has_pools']       = 'Cannot delete %s because pools are still attached to this seller. Remove or reassign those pools first.';
