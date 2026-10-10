<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.database

/**
 * Database Schema and Operations
 *
 * Tables:
 * - stm_languages: Available languages (en, nl, fr, etc.)
 * - stm_strings: Translatable strings with context
 * - stm_translations: Actual translations per language
 * - stm_post_translations: Dynamic content translations (posts/pages/CPT fields)
 * - stm_post_associations: Links translated post versions (translation groups)
 * - stm_term_translations: Category/tag translations
 * - stm_field_value_translations: Shared translations for standardized field values
 */

namespace STM;

class Database {

    /**
     * [STM-SYM-03] Option holding the plugin version the schema was last
     * brought up to. maybe_upgrade() compares it with STM_VERSION.
     */
    const OPTION_DB_VERSION = 'stm_db_version';

    /**
     * [STM-SYM-04] Object-cache key for the active-language list. Everything
     * that adds, toggles, edits or deletes a language deletes this key, so
     * the writers and get_languages() must agree on one spelling.
     */
    const CACHE_ACTIVE_LANGUAGES = 'stm_active_languages';

    /**
     * [STM-SYM-05] Object-cache key for the default-language row; deleted
     * together with CACHE_ACTIVE_LANGUAGES whenever the default may change.
     */
    const CACHE_DEFAULT_LANGUAGE = 'stm_default_language';

    /**
     * Create database tables
     */
    public static function create_tables() {
        global $wpdb;

        try {
            $charset_collate = $wpdb->get_charset_collate();

            // Table 1: Languages
            $sql_languages = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}stm_languages (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                code varchar(10) NOT NULL,
                name varchar(100) NOT NULL,
                native_name varchar(100) NOT NULL,
                is_default tinyint(1) NOT NULL DEFAULT 0,
                is_active tinyint(1) NOT NULL DEFAULT 1,
                flag_emoji varchar(10) DEFAULT '',
                order_index int(11) NOT NULL DEFAULT 0,
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY code (code)
            ) {$charset_collate};";

            // Table 2: Translatable Strings (template strings)
            $sql_strings = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}stm_strings (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                string_key varchar(255) NOT NULL,
                context varchar(100) DEFAULT 'general',
                description text DEFAULT NULL,
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY string_key_context (string_key, context),
                KEY context (context)
            ) {$charset_collate};";

            // Table 3: Translations
            $sql_translations = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}stm_translations (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                string_id bigint(20) unsigned NOT NULL,
                language_code varchar(10) NOT NULL,
                translation text NOT NULL,
                status varchar(20) NOT NULL DEFAULT 'draft',
                translated_by bigint(20) unsigned DEFAULT NULL,
                translated_at datetime DEFAULT NULL,
                reviewed_by bigint(20) unsigned DEFAULT NULL,
                reviewed_at datetime DEFAULT NULL,
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY string_lang (string_id, language_code),
                KEY language_code (language_code),
                KEY status (status)
            ) {$charset_collate};";

            // Table 4: Post/Page Translations
            $sql_post_translations = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}stm_post_translations (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                post_id bigint(20) unsigned NOT NULL,
                field_name varchar(100) NOT NULL,
                language_code varchar(10) NOT NULL,
                translation text NOT NULL,
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY post_field_lang (post_id, field_name, language_code),
                KEY post_id (post_id),
                KEY language_code (language_code)
            ) {$charset_collate};";

            // Table 5: Post Associations (links translated versions)
            $sql_post_associations = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}stm_post_associations (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                post_id bigint(20) unsigned NOT NULL,
                language_code varchar(10) NOT NULL,
                translation_group varchar(32) NOT NULL,
                is_original tinyint(1) NOT NULL DEFAULT 0,
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY post_lang (post_id, language_code),
                KEY translation_group (translation_group),
                KEY language_code (language_code),
                KEY post_id (post_id)
            ) {$charset_collate};";

            // Table 6: Taxonomy Term Translations
            $sql_term_translations = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}stm_term_translations (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                term_id bigint(20) unsigned NOT NULL,
                language_code varchar(10) NOT NULL,
                name varchar(200) NOT NULL,
                slug varchar(200) NOT NULL,
                description text DEFAULT NULL,
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY term_lang (term_id, language_code),
                KEY language_code (language_code),
                KEY term_id (term_id)
            ) {$charset_collate};";

            // Table 7: Field Value Translations (standardized values shared across posts)
            // value_hash = md5(source_value) keeps the unique key within index limits
            $sql_field_values = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}stm_field_value_translations (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                field_name varchar(100) NOT NULL,
                value_hash char(32) NOT NULL,
                source_value text NOT NULL,
                language_code varchar(10) NOT NULL,
                translation text NOT NULL,
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY field_value_lang (field_name, value_hash, language_code),
                KEY field_name (field_name),
                KEY language_code (language_code)
            ) {$charset_collate};";

            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql_languages);
            dbDelta($sql_strings);
            dbDelta($sql_translations);
            dbDelta($sql_post_translations);
            dbDelta($sql_post_associations);
            dbDelta($sql_term_translations);
            dbDelta($sql_field_values);
        } catch (\Exception $e) {
            // [STM-DN-02] Log, do not rethrow: this also runs from plugins_loaded
            // (via maybe_upgrade()), where an uncaught exception would take the
            // whole site down instead of only degrading this plugin.
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- logs a failed database operation (not debug output); the message carries a stable [STM-E-...] diagnostic code so a site owner can report it
            error_log('[STM] [STM-E-DB-CREATE-TABLES-SCHEMA] Error creating tables: ' . $e->getMessage());
        }
    }

    /**
     * Run schema upgrades when the plugin was updated without reactivation
     * (e.g. FTP deploys). Compares the stored schema version to STM_VERSION
     * and re-runs dbDelta, which only applies missing changes.
     *
     * [STM-DN-01] Upgrades stay additive: dbDelta creates missing tables,
     * columns and indexes and never drops or rewrites data, which is what lets
     * an update keep every stored translation. A destructive schema change
     * belongs in its own explicit, reviewed migration, not in this method.
     */
    public static function maybe_upgrade() {
        $installed = get_option(self::OPTION_DB_VERSION, '');
        if ($installed === STM_VERSION) {
            return;
        }
        self::create_tables();
        update_option(self::OPTION_DB_VERSION, STM_VERSION);
    }

    /**
     * Seed default languages
     *
     * Can be customized via filter 'stm_default_languages'
     * Example:
     * add_filter('stm_default_languages', function($languages) {
     *     return [
     *         ['code' => 'es', 'name' => 'Spanish', 'native_name' => 'Español', 'is_default' => 1, 'flag_emoji' => '🇪🇸', 'order_index' => 1],
     *         ['code' => 'fr', 'name' => 'French', 'native_name' => 'Français', 'is_default' => 0, 'flag_emoji' => '🇫🇷', 'order_index' => 2],
     *     ];
     * });
     */
    public static function seed_default_languages() {
        global $wpdb;
        $table = $wpdb->prefix . 'stm_languages';

        try {
            // Default languages - English and Dutch
            // Can be overridden via 'stm_default_languages' filter
            $default_languages = [
                ['code' => 'en', 'name' => 'English', 'native_name' => 'English', 'is_default' => 1, 'flag_emoji' => '🇬🇧', 'order_index' => 1],
                ['code' => 'nl', 'name' => 'Dutch', 'native_name' => 'Nederlands', 'is_default' => 0, 'flag_emoji' => '🇳🇱', 'order_index' => 2],
            ];

            // Allow customization via filter
            $languages = apply_filters('stm_default_languages', $default_languages);

            foreach ($languages as $lang) {
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}stm_languages WHERE code = %s",
                    $lang['code']
                ));

                if (!$exists) {
                    $result = $wpdb->insert($table, $lang);
                    if ($result === false) {
                        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- logs a failed database operation (not debug output); the message carries a stable [STM-E-...] diagnostic code so a site owner can report it
                        error_log('[STM] [STM-E-DB-SEED-DEFAULT-LANGUAGES-INSERT] Failed to insert language: ' . $lang['code']);
                    }
                }
            }
        } catch (\Exception $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- logs a failed database operation (not debug output); the message carries a stable [STM-E-...] diagnostic code so a site owner can report it
            error_log('[STM] [STM-E-DB-SEED-DEFAULT-LANGUAGES-EXCEPTION] Error seeding languages: ' . $e->getMessage());
        }
    }

    /**
     * Get all active languages
     *
     * [STM-DN-03] Cached for an hour: language rows change rarely and are read
     * on nearly every translated request. Writers delete the CACHE_* keys
     * instead of waiting for the TTL, so the TTL is a safety net and not the
     * invalidation mechanism.
     */
    public static function get_languages() {
        global $wpdb;

        $cache_key = self::CACHE_ACTIVE_LANGUAGES;
        $languages = wp_cache_get($cache_key);

        if (false === $languages) {
            $languages = $wpdb->get_results(
                "SELECT * FROM {$wpdb->prefix}stm_languages WHERE is_active = 1 ORDER BY order_index ASC"
            );
            wp_cache_set($cache_key, $languages, '', 3600);
        }

        return $languages;
    }

    /**
     * Get every language row regardless of is_active, for the admin
     * Languages management screen — an admin must be able to see and
     * re-activate (or delete) a hidden language, not just active ones.
     * Intentionally uncached: this is a low-traffic wp-admin page and it
     * must never serve a stale row right after a toggle/add/delete.
     */
    public static function get_all_languages() {
        global $wpdb;

        return $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}stm_languages ORDER BY order_index ASC"
        );
    }

    /**
     * Get default language
     */
    public static function get_default_language() {
        global $wpdb;

        $cache_key = self::CACHE_DEFAULT_LANGUAGE;
        $language = wp_cache_get($cache_key);

        if (false === $language) {
            $language = $wpdb->get_row(
                "SELECT * FROM {$wpdb->prefix}stm_languages WHERE is_default = 1 LIMIT 1"
            );
            wp_cache_set($cache_key, $language, '', 3600);
        }

        return $language;
    }
}
