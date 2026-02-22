<?php
/**
 * Admin Interface
 *
 * WordPress admin pages for managing translations
 */

namespace STM;

class Admin {

    /**
     * Initialize admin
     */
    public static function init() {
        if (!is_admin()) {
            return;
        }

        add_action('admin_menu', [__CLASS__, 'add_menu_pages']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_action('admin_post_stm_save_translation', [__CLASS__, 'save_translation']);
        add_action('admin_post_stm_add_string', [__CLASS__, 'add_string']);
        add_action('admin_post_stm_import_json', [__CLASS__, 'import_json']);
    }

    /**
     * Add admin menu pages
     */
    public static function add_menu_pages() {
        // Main menu
        add_menu_page(
            'Translation Manager',
            'Translations',
            'manage_options',
            'stm-translations',
            [__CLASS__, 'page_translations'],
            'dashicons-translation',
            30
        );

        // Submenu: Strings
        add_submenu_page(
            'stm-translations',
            'Translation Strings',
            'Strings',
            'manage_options',
            'stm-translations',
            [__CLASS__, 'page_translations']
        );

        // Submenu: Languages
        add_submenu_page(
            'stm-translations',
            'Languages',
            'Languages',
            'manage_options',
            'stm-languages',
            [__CLASS__, 'page_languages']
        );

        // Submenu: Import/Export
        add_submenu_page(
            'stm-translations',
            'Import/Export',
            'Import/Export',
            'manage_options',
            'stm-import-export',
            [__CLASS__, 'page_import_export']
        );

        // Submenu: Settings
        add_submenu_page(
            'stm-translations',
            'Settings',
            'Settings',
            'manage_options',
            'stm-settings',
            [__CLASS__, 'page_settings']
        );
    }

    /**
     * Enqueue admin assets
     */
    public static function enqueue_assets($hook) {
        if (strpos($hook, 'stm-') === false) {
            return;
        }

        wp_enqueue_style(
            'stm-admin',
            STM_PLUGIN_URL . 'assets/admin.css',
            [],
            STM_VERSION
        );

        wp_enqueue_script(
            'stm-admin',
            STM_PLUGIN_URL . 'assets/admin.js',
            ['jquery'],
            STM_VERSION,
            true
        );

        wp_localize_script('stm-admin', 'stmAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('stm_admin_nonce'),
        ]);
    }

    /**
     * Page: Translation Strings
     */
    public static function page_translations() {
        global $wpdb;

        // Get filter values
        $lang_filter = $_GET['lang'] ?? '';
        $context_filter = $_GET['context'] ?? '';
        $status_filter = $_GET['status'] ?? '';

        // Get languages
        $languages = Database::get_languages();

        // Get strings with translation status
        $table_strings = $wpdb->prefix . 'stm_strings';
        $table_translations = $wpdb->prefix . 'stm_translations';

        $where = ['1=1'];
        if ($context_filter) {
            $where[] = $wpdb->prepare('s.context = %s', $context_filter);
        }

        $where_sql = implode(' AND ', $where);

        $strings = $wpdb->get_results("
            SELECT s.*,
                (SELECT COUNT(*) FROM {$table_translations} t
                 WHERE t.string_id = s.id AND t.status = 'published') as translated_count
            FROM {$table_strings} s
            WHERE {$where_sql}
            ORDER BY s.context ASC, s.string_key ASC
        ");

        // Get unique contexts for filter
        $contexts = $wpdb->get_col("SELECT DISTINCT context FROM {$table_strings} ORDER BY context ASC");

        include STM_PLUGIN_DIR . 'templates/admin-translations.php';
    }

    /**
     * Page: Languages
     */
    public static function page_languages() {
        $languages = Database::get_languages();
        include STM_PLUGIN_DIR . 'templates/admin-languages.php';
    }

    /**
     * Page: Import/Export
     */
    public static function page_import_export() {
        include STM_PLUGIN_DIR . 'templates/admin-import-export.php';
    }

    /**
     * Page: Settings
     */
    public static function page_settings() {
        include STM_PLUGIN_DIR . 'templates/admin-settings.php';
    }

    /**
     * Save translation (AJAX/POST handler)
     */
    public static function save_translation() {
        check_admin_referer('stm_save_translation');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        global $wpdb;

        $string_id = intval($_POST['string_id']);
        $language_code = sanitize_text_field($_POST['language_code']);
        $translation = wp_kses_post($_POST['translation']);

        $table = $wpdb->prefix . 'stm_translations';

        // Upsert translation
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE string_id = %d AND language_code = %s",
            $string_id,
            $language_code
        ));

        $data = [
            'string_id' => $string_id,
            'language_code' => $language_code,
            'translation' => $translation,
            'status' => 'published',
            'translated_by' => get_current_user_id(),
            'translated_at' => current_time('mysql'),
        ];

        if ($existing) {
            $wpdb->update($table, $data, ['id' => $existing]);
        } else {
            $wpdb->insert($table, $data);
        }

        // Invalidate cache
        $string = $wpdb->get_row($wpdb->prepare(
            "SELECT string_key, context FROM {$wpdb->prefix}stm_strings WHERE id = %d",
            $string_id
        ));

        if ($string) {
            Cache::invalidate_string($string->string_key, $string->context);
        }

        wp_redirect(add_query_arg('updated', '1', wp_get_referer()));
        exit;
    }

    /**
     * Add new string
     */
    public static function add_string() {
        check_admin_referer('stm_add_string');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        global $wpdb;

        $string_key = sanitize_text_field($_POST['string_key']);
        $context = sanitize_text_field($_POST['context'] ?? 'general');
        $description = sanitize_textarea_field($_POST['description'] ?? '');

        $table = $wpdb->prefix . 'stm_strings';

        $data = [
            'string_key' => $string_key,
            'context' => $context,
            'description' => $description,
        ];

        $wpdb->insert($table, $data);

        wp_redirect(add_query_arg('added', '1', wp_get_referer()));
        exit;
    }

    /**
     * Import JSON file
     */
    public static function import_json() {
        check_admin_referer('stm_import_json');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        // TODO: Handle file upload and JSON parsing
        // Import format: { "nav.home": "Home", "nav.about": "About", ... }

        wp_redirect(add_query_arg('imported', '1', wp_get_referer()));
        exit;
    }
}
