<?php

/**
 * Uninstall script for Edel Stripe Payment.
 *
 * This script runs only when the plugin is deleted via the WordPress admin interface.
 * It checks a setting to determine whether to remove database tables and options.
 * User meta data is generally preserved.
 */

// Exit if accessed directly or not during uninstall
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// --- Configuration ---
// Make sure these constants match your main plugin file if possible,
// or define them directly here. Using direct strings is safer in uninstall.php.
$option_name = 'edel_stripe_payment_options'; // Consistent prefix + 'options'
$table_prefix = 'edel_stripe_payment_';      // Consistent prefix
$main_table_suffix = 'main';
// --- End Configuration ---


// --- Check Setting ---
// Get the plugin options
$options = get_option($option_name);

// Check if the delete data flag is explicitly set to '1'
$delete_data = isset($options['delete_data_on_uninstall']) && $options['delete_data_on_uninstall'] === '1';


// --- Perform Deletion (ONLY IF $delete_data is true) ---
if ($delete_data) {

    // 1. Delete Options
    delete_option($option_name);
    // delete_option($option_name . '_db_version'); // If you used a DB version option

    // 2. Delete Custom Database Table
    global $wpdb;
    $table_name = $wpdb->prefix . $table_prefix . $main_table_suffix;
    $wpdb->query("DROP TABLE IF EXISTS {$table_name}");

    // 3. Delete User Meta (Optional - Generally recommended NOT to delete user meta automatically)
    /*
    $meta_keys_to_delete = [
        $table_prefix . 'customer_id',
        $table_prefix . 'subscription_id',
        $table_prefix . 'subscription_status'
    ];
    $all_user_ids = get_users(['fields' => 'ID']);
    foreach ($all_user_ids as $user_id) {
        foreach ($meta_keys_to_delete as $meta_key) {
            delete_user_meta($user_id, $meta_key);
        }
    }
    */

    // Add logging if possible? Uninstall hooks run in a different context,
    // standard WP error logging might not be available easily.

}

// --- End Deletion Logic ---
