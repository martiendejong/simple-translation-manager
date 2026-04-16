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
        add_action('admin_post_stm_add_language', [__CLASS__, 'add_language']);
        add_action('admin_post_stm_delete_language', [__CLASS__, 'delete_language']);
        add_action('admin_post_stm_save_ai_settings', [__CLASS__, 'save_ai_settings']);
        add_action('admin_notices', [__CLASS__, 'show_translation_warnings']);
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
        $search = $_GET['search'] ?? '';

        // Pagination
        $per_page = 50;
        $current_page = max(1, intval($_GET['paged'] ?? 1));
        $offset = ($current_page - 1) * $per_page;

        // Get languages
        $languages = Database::get_languages();

        // Get strings with translation status
        $table_strings = $wpdb->prefix . 'stm_strings';
        $table_translations = $wpdb->prefix . 'stm_translations';

        $where = ['1=1'];
        if ($context_filter) {
            $where[] = $wpdb->prepare('s.context = %s', $context_filter);
        }
        if ($search) {
            $where[] = $wpdb->prepare('s.string_key LIKE %s', '%' . $wpdb->esc_like($search) . '%');
        }

        $where_sql = implode(' AND ', $where);

        // Get total count for pagination
        $total_items = $wpdb->get_var("
            SELECT COUNT(*) FROM {$table_strings} s WHERE {$where_sql}
        ");

        $total_pages = ceil($total_items / $per_page);

        // Get paginated results
        $strings = $wpdb->get_results("
            SELECT s.*,
                (SELECT COUNT(*) FROM {$table_translations} t
                 WHERE t.string_id = s.id AND t.status = 'published') as translated_count
            FROM {$table_strings} s
            WHERE {$where_sql}
            ORDER BY s.context ASC, s.string_key ASC
            LIMIT {$per_page} OFFSET {$offset}
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
     * Show admin notices for untranslated content
     */
    public static function show_translation_warnings() {
        // Only show on STM pages and post listing pages
        $screen = get_current_screen();
        if (!$screen) {
            return;
        }

        $show_on = ['edit', 'stm-translations', 'toplevel_page_stm-translations'];
        $is_stm_page = strpos($screen->id, 'stm-') !== false;
        $is_post_list = $screen->base === 'edit';

        if (!$is_stm_page && !$is_post_list) {
            return;
        }

        // Use transient to cache the warning check (avoid DB query on every page load)
        $cache_key = 'stm_untranslated_warning';
        $warning_data = get_transient($cache_key);

        if (false === $warning_data) {
            global $wpdb;

            $languages = Database::get_languages();
            $default_lang = Database::get_default_language();
            $default_code = $default_lang ? $default_lang->code : 'en';

            $non_default_langs = array_filter($languages, function($lang) use ($default_code) {
                return $lang->code !== $default_code;
            });

            if (empty($non_default_langs)) {
                set_transient($cache_key, ['count' => 0], 3600);
                return;
            }

            $table_pt = $wpdb->prefix . 'stm_post_translations';

            // Count published posts
            $total_posts = $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('post', 'page')"
            );

            // Count posts with title translations per non-default language
            $missing_by_lang = [];
            foreach ($non_default_langs as $lang) {
                $translated = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(DISTINCT post_id) FROM {$table_pt}
                     WHERE language_code = %s AND field_name = 'title'
                     AND post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('post', 'page'))",
                    $lang->code
                ));

                $missing = $total_posts - intval($translated);
                if ($missing > 0) {
                    $missing_by_lang[] = [
                        'code' => $lang->code,
                        'name' => $lang->native_name,
                        'emoji' => $lang->flag_emoji,
                        'missing' => $missing,
                        'total' => $total_posts,
                    ];
                }
            }

            $warning_data = ['count' => count($missing_by_lang), 'langs' => $missing_by_lang];
            set_transient($cache_key, $warning_data, 3600);
        }

        if ($warning_data['count'] === 0) {
            return;
        }

        $lines = [];
        foreach ($warning_data['langs'] as $info) {
            $pct = round(($info['missing'] / $info['total']) * 100);
            $lines[] = sprintf(
                '%s %s: %d/%d posts missing translations (%d%%)',
                $info['emoji'],
                $info['name'],
                $info['missing'],
                $info['total'],
                $pct
            );
        }

        $dashboard_url = admin_url('admin.php?page=stm-translations');
        echo '<div class="notice notice-warning is-dismissible">';
        echo '<p><strong>Translation Manager:</strong> Untranslated content detected.</p>';
        echo '<ul style="margin:5px 0 5px 20px;list-style:disc;">';
        foreach ($lines as $line) {
            echo '<li>' . esc_html($line) . '</li>';
        }
        echo '</ul>';
        echo '<p><a href="' . esc_url($dashboard_url) . '">Manage translations &rarr;</a></p>';
        echo '</div>';
    }

    /**
     * Save translation (AJAX/POST handler)
     */
    public static function save_translation() {
        if (!Security::verify_admin_action('stm_save_translation')) {
            wp_die('Unauthorized', 403);
        }

        global $wpdb;

        // Validate and sanitize inputs
        $string_id = intval($_POST['string_id']);
        $language_code = sanitize_text_field($_POST['language_code']);
        $translation = Security::sanitize_translation($_POST['translation']);

        if (!Security::validate_language_code($language_code)) {
            wp_die('Invalid language code', 400);
        }

        try {
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
                $result = $wpdb->update($table, $data, ['id' => $existing]);
            } else {
                $result = $wpdb->insert($table, $data);
            }

            if ($result === false) {
                throw new \Exception('Database operation failed');
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
        } catch (\Exception $e) {
            Security::log('Error saving translation: ' . $e->getMessage(), 'error');
            wp_die('Failed to save translation', 500);
        }
    }

    /**
     * Add new string
     */
    public static function add_string() {
        if (!Security::verify_admin_action('stm_add_string')) {
            wp_die('Unauthorized', 403);
        }

        global $wpdb;

        // Validate and sanitize inputs
        $string_key = Security::sanitize_translation_key($_POST['string_key']);
        $context = Security::sanitize_context($_POST['context'] ?? 'general');
        $description = sanitize_textarea_field($_POST['description'] ?? '');

        if (!Security::validate_translation_key($string_key)) {
            wp_die('Invalid translation key format', 400);
        }

        if (!Security::validate_context($context)) {
            wp_die('Invalid context format', 400);
        }

        try {
            $table = $wpdb->prefix . 'stm_strings';

            $data = [
                'string_key' => $string_key,
                'context' => $context,
                'description' => $description,
            ];

            $result = $wpdb->insert($table, $data);

            if ($result === false) {
                throw new \Exception('Database operation failed');
            }

            wp_redirect(add_query_arg('added', '1', wp_get_referer()));
            exit;
        } catch (\Exception $e) {
            Security::log('Error adding string: ' . $e->getMessage(), 'error');
            wp_die('Failed to add string', 500);
        }
    }

    /**
     * Import JSON file (admin form handler)
     */
    public static function import_json() {
        if (!Security::verify_admin_action('stm_import_json')) {
            wp_die('Unauthorized', 403);
        }

        if (empty($_FILES['stm_import_file']['tmp_name'])) {
            wp_redirect(add_query_arg('stm_error', 'no_file', wp_get_referer()));
            exit;
        }

        $file = $_FILES['stm_import_file'];

        // Only allow JSON files
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'json') {
            wp_redirect(add_query_arg('stm_error', 'invalid_type', wp_get_referer()));
            exit;
        }

        $json = file_get_contents($file['tmp_name']);
        $data = json_decode($json, true);

        if (!is_array($data)) {
            wp_redirect(add_query_arg('stm_error', 'invalid_json', wp_get_referer()));
            exit;
        }

        $result = API::process_import($data);

        if (isset($result['error'])) {
            wp_redirect(add_query_arg('stm_error', urlencode($result['error']), wp_get_referer()));
            exit;
        }

        wp_redirect(add_query_arg([
            'imported' => $result['created'] + $result['updated'],
            'stm_errors' => count($result['errors']),
        ], wp_get_referer()));
        exit;
    }

    /**
     * Add language (admin form handler)
     */
    public static function add_language() {
        if (!Security::verify_admin_action('stm_add_language')) {
            wp_die('Unauthorized', 403);
        }

        global $wpdb;

        $code        = sanitize_text_field($_POST['lang_code'] ?? '');
        $name        = sanitize_text_field($_POST['lang_name'] ?? '');
        $native_name = sanitize_text_field($_POST['lang_native'] ?? $name);
        $flag        = sanitize_text_field($_POST['lang_flag'] ?? '');
        $is_default  = isset($_POST['lang_default']) ? 1 : 0;

        if (!Security::validate_language_code($code) || empty($name)) {
            wp_redirect(add_query_arg('stm_error', 'invalid_fields', wp_get_referer()));
            exit;
        }

        if ($is_default) {
            $wpdb->update($wpdb->prefix . 'stm_languages', ['is_default' => 0], ['is_default' => 1]);
        }

        $result = $wpdb->insert($wpdb->prefix . 'stm_languages', [
            'code'        => strtolower($code),
            'name'        => $name,
            'native_name' => $native_name,
            'flag_emoji'  => $flag,
            'is_default'  => $is_default,
            'is_active'   => 1,
            'order_index' => intval($_POST['lang_order'] ?? 99),
        ]);

        wp_cache_delete('stm_active_languages');
        wp_cache_delete('stm_default_language');

        wp_redirect(add_query_arg(
            $result === false ? 'stm_error' : 'stm_added',
            $result === false ? 'db_error'  : '1',
            wp_get_referer()
        ));
        exit;
    }

    /**
     * Delete language (admin form handler)
     */
    public static function delete_language() {
        if (!Security::verify_admin_action('stm_delete_language')) {
            wp_die('Unauthorized', 403);
        }

        $code = sanitize_text_field($_POST['lang_code'] ?? '');

        if (!Security::validate_language_code($code)) {
            wp_die('Invalid language code', 400);
        }

        global $wpdb;

        // Prevent deleting the default language
        $is_default = $wpdb->get_var($wpdb->prepare(
            "SELECT is_default FROM {$wpdb->prefix}stm_languages WHERE code = %s",
            $code
        ));

        if ($is_default) {
            wp_redirect(add_query_arg('stm_error', 'cannot_delete_default', wp_get_referer()));
            exit;
        }

        $wpdb->delete($wpdb->prefix . 'stm_languages', ['code' => $code]);

        wp_cache_delete('stm_active_languages');

        wp_redirect(add_query_arg('stm_deleted', '1', wp_get_referer()));
        exit;
    }

    /**
     * Save AI/auto-translate settings
     */
    public static function save_ai_settings() {
        if (!Security::verify_admin_action('stm_ai_settings')) {
            wp_die('Unauthorized', 403);
        }

        $provider   = sanitize_text_field($_POST['ai_provider'] ?? 'openai');
        $openai_key = sanitize_text_field($_POST['openai_key'] ?? '');
        $deepl_key  = sanitize_text_field($_POST['deepl_key'] ?? '');

        AutoTranslate::save_settings($provider, $openai_key ?: null, $deepl_key ?: null);

        wp_redirect(add_query_arg('stm_saved', '1', wp_get_referer()));
        exit;
    }
}
