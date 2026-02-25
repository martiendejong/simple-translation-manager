<?php
/**
 * Uninstall handler for Simple Translation Manager
 *
 * Fired when the plugin is uninstalled.
 * Only executes if WP_UNINSTALL_PLUGIN is defined.
 *
 * @package SimpleTranslationManager
 */

// If uninstall not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Uninstall Simple Translation Manager
 *
 * Removes all plugin data from database:
 * - Drops 4 custom tables
 * - Deletes all plugin options
 * - Clears object cache
 *
 * Note: This is irreversible. All translations will be permanently deleted.
 */
function stm_uninstall() {
    global $wpdb;

    // Check if user wants to keep data (can be set via Settings page)
    $keep_data = get_option('stm_keep_data_on_uninstall', false);

    if ($keep_data) {
        // Don't delete anything, just deactivate
        return;
    }

    // Security: Require admin privileges
    if (!current_user_can('activate_plugins')) {
        return;
    }

    // Drop all plugin tables
    $tables = [
        $wpdb->prefix . 'stm_languages',
        $wpdb->prefix . 'stm_strings',
        $wpdb->prefix . 'stm_translations',
        $wpdb->prefix . 'stm_post_translations',
    ];

    foreach ($tables as $table) {
        $wpdb->query("DROP TABLE IF EXISTS {$table}");
    }

    // Delete all plugin options
    delete_option('stm_version');
    delete_option('stm_default_language');
    delete_option('stm_enable_url_routing');
    delete_option('stm_cache_duration');
    delete_option('stm_keep_data_on_uninstall');
    delete_option('stm_debug_mode');

    // Clear object cache
    wp_cache_flush();

    // Delete any transients
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_stm_%' OR option_name LIKE '_transient_timeout_stm_%'");
}

// Execute uninstall
stm_uninstall();
