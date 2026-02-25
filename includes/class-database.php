<?php
/**
 * Database Schema and Operations
 *
 * Tables:
 * - stm_languages: Available languages (en, nl, fr, etc.)
 * - stm_strings: Translatable strings with context
 * - stm_translations: Actual translations per language
 * - stm_post_translations: Dynamic content translations (posts/pages)
 */

namespace STM;

class Database {

    /**
     * Create database tables
     */
    public static function create_tables() {
        global $wpdb;

        try {
            $charset_collate = $wpdb->get_charset_collate();

            // Table 1: Languages
            $table_languages = $wpdb->prefix . 'stm_languages';
            $sql_languages = "CREATE TABLE IF NOT EXISTS {$table_languages} (
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
            $table_strings = $wpdb->prefix . 'stm_strings';
            $sql_strings = "CREATE TABLE IF NOT EXISTS {$table_strings} (
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
            $table_translations = $wpdb->prefix . 'stm_translations';
            $sql_translations = "CREATE TABLE IF NOT EXISTS {$table_translations} (
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
            $table_post_translations = $wpdb->prefix . 'stm_post_translations';
            $sql_post_translations = "CREATE TABLE IF NOT EXISTS {$table_post_translations} (
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

            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql_languages);
            dbDelta($sql_strings);
            dbDelta($sql_translations);
            dbDelta($sql_post_translations);
        } catch (\Exception $e) {
            error_log('[STM] Error creating tables: ' . $e->getMessage());
        }
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
                    "SELECT id FROM {$table} WHERE code = %s",
                    $lang['code']
                ));

                if (!$exists) {
                    $result = $wpdb->insert($table, $lang);
                    if ($result === false) {
                        error_log('[STM] Failed to insert language: ' . $lang['code']);
                    }
                }
            }
        } catch (\Exception $e) {
            error_log('[STM] Error seeding languages: ' . $e->getMessage());
        }
    }

    /**
     * Get all active languages
     */
    public static function get_languages() {
        global $wpdb;
        $table = $wpdb->prefix . 'stm_languages';

        $cache_key = 'stm_active_languages';
        $languages = wp_cache_get($cache_key);

        if (false === $languages) {
            $languages = $wpdb->get_results(
                "SELECT * FROM {$table} WHERE is_active = 1 ORDER BY order_index ASC"
            );
            wp_cache_set($cache_key, $languages, '', 3600);
        }

        return $languages;
    }

    /**
     * Get default language
     */
    public static function get_default_language() {
        global $wpdb;
        $table = $wpdb->prefix . 'stm_languages';

        $cache_key = 'stm_default_language';
        $language = wp_cache_get($cache_key);

        if (false === $language) {
            $language = $wpdb->get_row(
                "SELECT * FROM {$table} WHERE is_default = 1 LIMIT 1"
            );
            wp_cache_set($cache_key, $language, '', 3600);
        }

        return $language;
    }
}
