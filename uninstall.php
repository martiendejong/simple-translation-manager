<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.uninstall

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
 * - Drops the 7 custom tables
 * - Deletes all plugin options and the per-user admin preferences
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

    // Drop all plugin tables (the same seven that Database::create_tables() creates)
    $tables = [
        'stm_languages',
        'stm_strings',
        'stm_translations',
        'stm_post_translations',
        'stm_post_associations',
        'stm_term_translations',
        'stm_field_value_translations',
    ];

    foreach ($tables as $table) {
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}{$table}");
    }

    // Delete all plugin options, including the auto-translate API keys
    $options = [
        'stm_version',
        'stm_db_version',
        'stm_default_language',
        'stm_enable_url_routing',
        'stm_cache_duration',
        'stm_keep_data_on_uninstall',
        'stm_debug_mode',
        'stm_switcher_style',
        'stm_switcher_show_flags',
        'stm_switcher_show_names',
        'stm_switcher_position',
        'stm_value_translatable_fields',
        'stm_auto_translate_provider',
        'stm_openai_api_key',
        'stm_openai_model',
        'stm_openai_temperature',
        'stm_openai_prompt_template',
        'stm_deepl_api_key',
    ];
    foreach ($options as $option) {
        delete_option($option);
    }

    // Per-user admin screen preferences
    delete_metadata('user', 0, 'stm_admin_prefs', '', true);

    // Clear object cache
    wp_cache_flush();

    // Delete any transients
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_stm_%' OR option_name LIKE '_transient_timeout_stm_%'");
}

// Execute uninstall
stm_uninstall();
