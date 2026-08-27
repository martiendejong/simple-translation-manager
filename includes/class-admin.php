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
        add_action('admin_post_stm_scan_strings', [__CLASS__, 'scan_strings']);
        add_action('admin_post_stm_import_json', [__CLASS__, 'import_json']);
        add_action('admin_post_stm_add_language', [__CLASS__, 'add_language']);
        add_action('admin_post_stm_delete_language', [__CLASS__, 'delete_language']);
        add_action('admin_post_stm_add_value_field', [__CLASS__, 'add_value_field']);
        add_action('admin_post_stm_remove_value_field', [__CLASS__, 'remove_value_field']);
        add_action('admin_post_stm_save_field_values', [__CLASS__, 'save_field_values']);
        add_action('admin_post_stm_autofill_field_values', [__CLASS__, 'autofill_field_values']);
        add_action('admin_post_stm_toggle_language_active', [__CLASS__, 'toggle_language_active']);
        add_action('admin_post_stm_save_ai_settings', [__CLASS__, 'save_ai_settings']);
        add_action('admin_notices', [__CLASS__, 'show_translation_warnings']);

        // Session persistence: save/load filter state via AJAX
        add_action('wp_ajax_stm_save_prefs', [__CLASS__, 'ajax_save_prefs']);
        add_action('wp_ajax_stm_load_prefs', [__CLASS__, 'ajax_load_prefs']);

        // Nonce refresh via heartbeat (keeps long-open admin pages valid)
        add_filter('heartbeat_received', [__CLASS__, 'heartbeat_refresh_nonce'], 10, 2);
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

        // Submenu: Dashboard (first so it's the top entry)
        add_submenu_page(
            'stm-translations',
            'Translation Dashboard',
            'Dashboard',
            'manage_options',
            'stm-dashboard',
            ['\\STM\\Dashboard', 'render_page']
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

        // Submenu: Field Values (shared translations for standardized values)
        add_submenu_page(
            'stm-translations',
            'Field Value Translations',
            'Field Values',
            'manage_options',
            'stm-field-values',
            [__CLASS__, 'page_field_values']
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

        // Submenu: Documentation
        add_submenu_page(
            'stm-translations',
            'Documentation',
            'Documentation',
            'manage_options',
            'stm-documentation',
            [__CLASS__, 'page_documentation']
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
            'ajaxUrl'   => admin_url('admin-ajax.php'),
            'nonce'     => wp_create_nonce('stm_admin_nonce'),
            'restNonce' => wp_create_nonce('wp_rest'),
            'restUrl'   => esc_url_raw(rest_url('stm/v1')),
        ]);

        // Dashboard-specific assets
        if (strpos($hook, 'stm-dashboard') !== false) {
            wp_enqueue_style(
                'stm-dashboard',
                STM_PLUGIN_URL . 'assets/admin-dashboard.css',
                ['stm-admin'],
                STM_VERSION
            );

            wp_enqueue_script(
                'stm-dashboard',
                STM_PLUGIN_URL . 'assets/admin-dashboard.js',
                ['jquery', 'stm-admin'],
                STM_VERSION,
                true
            );

            wp_localize_script('stm-dashboard', 'stmDashboard', [
                'ajaxUrl'     => admin_url('admin-ajax.php'),
                'nonce'       => wp_create_nonce('stm_dashboard_nonce'),
                'i18n'        => [
                    'saving'     => __('Savingâ€¦', 'simple-translation-manager'),
                    'saved'      => __('Saved', 'simple-translation-manager'),
                    'error'      => __('Error', 'simple-translation-manager'),
                    'refreshing' => __('Refreshingâ€¦', 'simple-translation-manager'),
                ],
            ]);
        }
    }

    /**
     * Page: Translation Strings
     */
    public static function page_translations() {
        global $wpdb;

        // Get filter values. Read-only list filtering (no state change), so a
        // nonce is not required here â€” see WordPress.Security.NonceVerification docs.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $lang_filter = wp_unslash($_GET['lang'] ?? '');
        $context_filter = wp_unslash($_GET['context'] ?? '');
        $status_filter = wp_unslash($_GET['status'] ?? '');
        $search = wp_unslash($_GET['search'] ?? '');

        // Pagination
        $per_page = 50;
        $current_page = max(1, intval($_GET['paged'] ?? 1));
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        $offset = ($current_page - 1) * $per_page;

        // Get languages
        $languages = Database::get_languages();
        $total_languages = count($languages);

        // The code of the default/fallback language, so the template can show
        // its translation as a placeholder wherever another language's
        // translation is still missing (see get_translation_placeholder()).
        $default_language = Database::get_default_language();
        $default_lang_code = $default_language ? $default_language->code : '';

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

        // 'missing'/'complete' filter on translated_count, which is only known once the
        // per-string correlated subquery below has run â€” so it's applied as a HAVING
        // clause against that subquery's alias, not folded into $where_sql.
        $status_having = self::build_status_having($status_filter, $total_languages);

        $translated_count_sql = "(SELECT COUNT(*) FROM {$table_translations} t
                 WHERE t.string_id = s.id AND t.status = 'published') as translated_count";

        // Get total count for pagination. When a status filter is active the plain
        // COUNT(*) can't see translated_count, so wrap the same per-string query the
        // results below use in a derived table and count that instead.
        if ($status_having !== '') {
            $total_items = $wpdb->get_var("
                SELECT COUNT(*) FROM (
                    SELECT s.id, {$translated_count_sql}
                    FROM {$table_strings} s
                    WHERE {$where_sql}
                    {$status_having}
                ) stm_filtered
            ");
        } else {
            $total_items = $wpdb->get_var("
                SELECT COUNT(*) FROM {$table_strings} s WHERE {$where_sql}
            ");
        }

        $total_pages = ceil($total_items / $per_page);

        // Get paginated results
        $strings = $wpdb->get_results("
            SELECT s.*, {$translated_count_sql}
            FROM {$table_strings} s
            WHERE {$where_sql}
            {$status_having}
            ORDER BY s.context ASC, s.string_key ASC
            LIMIT {$per_page} OFFSET {$offset}
        ");

        // Get unique contexts for filter
        $contexts = $wpdb->get_col("SELECT DISTINCT context FROM {$table_strings} ORDER BY context ASC");

        // Batch-fetch all translations for visible strings (avoids N+1 in template)
        $translations_map = [];
        if (!empty($strings)) {
            $string_ids = implode(',', array_map('intval', array_column($strings, 'id')));
            $all_translations = $wpdb->get_results(
                "SELECT string_id, language_code, id, translation, status
                 FROM {$table_translations}
                 WHERE string_id IN ({$string_ids})"
            );
            foreach ($all_translations as $t) {
                $translations_map[$t->string_id][$t->language_code] = $t;
            }
        }

        include STM_PLUGIN_DIR . 'templates/admin-translations.php';
    }

    /**
     * Build the HAVING clause fragment for the Translation Strings screen's
     * "missing translations" / "fully translated" status filter.
     *
     * translated_count comes from a correlated subquery on the string, not a real
     * column, so it can only be filtered via HAVING once it's computed â€” this can't
     * be folded into $where. Pure/static so the threshold logic is unit-testable
     * without a live or fake $wpdb.
     *
     * @param string $status_filter    '' (all), 'missing', or 'complete'.
     * @param int    $total_languages  Number of active languages a string can be translated into.
     * @return string HAVING clause (including the "HAVING" keyword), or '' for no filter.
     */
    public static function build_status_having($status_filter, $total_languages) {
        $total_languages = intval($total_languages);

        if ($status_filter === 'missing') {
            return "HAVING translated_count < {$total_languages}";
        }

        if ($status_filter === 'complete') {
            return "HAVING translated_count >= {$total_languages}";
        }

        return '';
    }

    /**
     * What placeholder text to show in a translation input on the Translation
     * Strings screen.
     *
     * When a language has no translation yet, STM's runtime string lookup
     * (see functions.php::__stm()) has nothing published for that language,
     * so visitors effectively see whatever the default language's text is.
     * Showing that same text as the input's placeholder lets whoever is
     * managing translations see at a glance what the UI currently displays
     * for that string, instead of a generic "type something here" hint.
     *
     * @param string|null $default_translation The default language's translation text for this string, or null/empty if it has none either.
     * @param bool        $is_default_language  Whether this input IS the default language's own column (it has no "other" default to fall back to).
     * @return string The placeholder text to render.
     */
    public static function get_translation_placeholder($default_translation, $is_default_language) {
        if (!$is_default_language && $default_translation !== null && $default_translation !== '') {
            return $default_translation;
        }

        return 'Translation';
    }

    /**
     * Page: Languages
     */
    public static function page_languages() {
        // Admin management screen needs every language row, including
        // hidden (is_active = 0) ones, so the admin can see and toggle
        // them back on. Database::get_languages() is active-only by
        // design (it backs the public switcher/hreflang/frontend), so it
        // would silently hide inactive languages from this screen too.
        $languages = Database::get_all_languages();
        include STM_PLUGIN_DIR . 'templates/admin-languages.php';
    }

    /**
     * Page: Field Value Translations
     *
     * Without ?field= shows the list of value-translatable fields;
     * with ?field=<name> shows all distinct values for that field with
     * one input per language.
     */
    public static function page_field_values() {
        $registered = FieldValues::get_registered_fields();
        $languages = Database::get_languages();
        $default_language = Database::get_default_language();
        $default_code = $default_language ? $default_language->code : 'en';

        // Read-only view selector on an admin listing page — no state change,
        // so no nonce (same policy as the filter params on page_translations).
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $field = sanitize_key($_GET['field'] ?? '');

        if ($field && isset($registered[$field])) {
            $field_config = $registered[$field];
            $values = FieldValues::get_distinct_values($field);
            $translations = FieldValues::get_translations_for_field($field);
            include STM_PLUGIN_DIR . 'templates/admin-field-value-edit.php';
            return;
        }

        $coverage = [];
        foreach ($registered as $name => $config) {
            $coverage[$name] = FieldValues::get_coverage($name);
        }
        $post_types = get_post_types(['show_ui' => true], 'objects');
        include STM_PLUGIN_DIR . 'templates/admin-field-values.php';
    }

    /**
     * Mark a field as value-translatable (admin form handler)
     */
    public static function add_value_field() {
        if (!check_admin_referer('stm_add_value_field') || !current_user_can('manage_options')) {
            wp_die('Unauthorized', 403);
        }

        $field_name = sanitize_key($_POST['field_name'] ?? '');
        if (!$field_name) {
            wp_redirect(add_query_arg('stm_error', 'invalid_field', wp_get_referer()));
            exit;
        }

        FieldValues::save_field($field_name, [
            'label'      => sanitize_text_field($_POST['field_label'] ?? $field_name),
            'post_types' => array_map('sanitize_key', (array) ($_POST['field_post_types'] ?? [])),
        ]);

        wp_redirect(add_query_arg('stm_added', '1', wp_get_referer()));
        exit;
    }

    /**
     * Unmark a value-translatable field (admin form handler).
     * Stored value translations are kept and restored when re-added.
     */
    public static function remove_value_field() {
        if (!check_admin_referer('stm_remove_value_field') || !current_user_can('manage_options')) {
            wp_die('Unauthorized', 403);
        }

        $field_name = sanitize_key($_POST['field_name'] ?? '');
        FieldValues::remove_field($field_name);

        wp_redirect(add_query_arg('stm_deleted', '1', wp_get_referer()));
        exit;
    }

    /**
     * Bulk-save value translations for one field (admin form handler)
     */
    public static function save_field_values() {
        if (!check_admin_referer('stm_save_field_values') || !current_user_can('manage_options')) {
            wp_die('Unauthorized', 403);
        }

        $field = sanitize_key($_POST['field'] ?? '');
        if (!$field || !FieldValues::is_value_translatable($field)) {
            wp_die('Unknown field', 400);
        }

        // source[hash] carries the exact original value; do not sanitize it
        // beyond unslashing or the md5 key would no longer match the meta value
        $sources = wp_unslash($_POST['source'] ?? []);
        $translations = wp_unslash($_POST['translations'] ?? []);
        $saved = 0;

        foreach ((array) $translations as $hash => $per_language) {
            if (!isset($sources[$hash]) || !is_array($per_language)) {
                continue;
            }
            $source_value = $sources[$hash];
            if (md5($source_value) !== $hash) {
                continue;
            }
            foreach ($per_language as $lang_code => $translation) {
                $lang_code = sanitize_text_field($lang_code);
                if (!Security::validate_language_code($lang_code)) {
                    continue;
                }
                FieldValues::save_translation($field, $source_value, $lang_code, sanitize_text_field($translation));
                $saved++;
            }
        }

        wp_redirect(add_query_arg('stm_saved', $saved, wp_get_referer()));
        exit;
    }

    /**
     * Auto-translate missing value translations for one field (admin form handler)
     */
    public static function autofill_field_values() {
        if (!check_admin_referer('stm_autofill_field_values') || !current_user_can('manage_options')) {
            wp_die('Unauthorized', 403);
        }

        $field = sanitize_key($_POST['field'] ?? '');
        if (!$field || !FieldValues::is_value_translatable($field)) {
            wp_die('Unknown field', 400);
        }

        $target = sanitize_text_field($_POST['target_language'] ?? '');
        $default_language = Database::get_default_language();
        $default_code = $default_language ? $default_language->code : 'en';

        $target_codes = [];
        foreach (Database::get_languages() as $language) {
            if ($language->code === $default_code) {
                continue;
            }
            if ($target === '' || $target === $language->code) {
                $target_codes[] = $language->code;
            }
        }

        $values = FieldValues::get_distinct_values($field);
        $existing = FieldValues::get_translations_for_field($field);

        if (function_exists('set_time_limit')) {
            set_time_limit(300);
        }

        $filled = 0;
        $failed = 0;
        $last_error = '';

        foreach ($values as $value) {
            foreach ($target_codes as $lang_code) {
                if (!empty($existing[$value['hash']][$lang_code])) {
                    continue;
                }
                $result = AutoTranslate::translate(
                    $value['value'],
                    $default_code,
                    $lang_code,
                    "Standardized value of the '{$field}' field. Translate concisely; keep proper names unchanged."
                );
                if (!empty($result['success']) && $result['translation'] !== '') {
                    FieldValues::save_translation($field, $value['value'], $lang_code, $result['translation']);
                    $filled++;
                } else {
                    $failed++;
                    $last_error = $result['error'] ?? 'unknown error';
                }
            }
        }

        $args = ['stm_autofilled' => $filled, 'stm_autofill_failed' => $failed];
        if ($failed > 0 && $last_error) {
            $args['stm_error'] = urlencode($last_error);
        }
        wp_redirect(add_query_arg($args, wp_get_referer()));
        exit;
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
     * Page: Documentation
     */
    public static function page_documentation() {
        include STM_PLUGIN_DIR . 'templates/admin-documentation.php';
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
        if (!check_admin_referer('stm_save_translation') || !current_user_can('manage_options')) {
            wp_die('Unauthorized', 403);
        }

        global $wpdb;

        // Validate and sanitize inputs
        $string_id = intval($_POST['string_id']);
        $language_code = sanitize_text_field(wp_unslash($_POST['language_code']));
        $translation = Security::sanitize_translation(wp_unslash($_POST['translation']));

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

            wp_safe_redirect(add_query_arg('updated', '1', wp_get_referer()));
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
        if (!check_admin_referer('stm_add_string') || !current_user_can('manage_options')) {
            wp_die('Unauthorized', 403);
        }

        global $wpdb;

        // Validate and sanitize inputs
        $string_key = Security::sanitize_translation_key(wp_unslash($_POST['string_key']));
        $context = Security::sanitize_context(wp_unslash($_POST['context'] ?? 'general'));
        $description = sanitize_textarea_field(wp_unslash($_POST['description'] ?? ''));

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

            wp_safe_redirect(add_query_arg('added', '1', wp_get_referer()));
            exit;
        } catch (\Exception $e) {
            Security::log('Error adding string: ' . $e->getMessage(), 'error');
            wp_die('Failed to add string', 500);
        }
    }

    /**
     * Scan the active theme and plugin templates for translatable strings
     * (admin form handler)
     */
    public static function scan_strings() {
        if (!check_admin_referer('stm_scan_strings') || !current_user_can('manage_options')) {
            wp_die('Unauthorized', 403);
        }

        try {
            $result = StringScanner::scan_and_register();

            wp_safe_redirect(add_query_arg([
                'stm_scanned' => '1',
                'stm_scan_found' => $result['unique_found'],
                'stm_scan_added' => $result['added'],
            ], wp_get_referer()));
            exit;
        } catch (\Exception $e) {
            Security::log('Error scanning for strings: ' . $e->getMessage(), 'error');
            wp_safe_redirect(add_query_arg('stm_error', 'scan_failed', wp_get_referer()));
            exit;
        }
    }

    /**
     * Import JSON file (admin form handler)
     */
    public static function import_json() {
        if (!check_admin_referer('stm_import_json') || !current_user_can('manage_options')) {
            wp_die('Unauthorized', 403);
        }

        if (empty($_FILES['stm_import_file']['tmp_name'])) {
            wp_safe_redirect(add_query_arg('stm_error', 'no_file', wp_get_referer()));
            exit;
        }

        $file = $_FILES['stm_import_file'];

        // Only allow JSON files
        $ext = strtolower(pathinfo(sanitize_file_name($file['name']), PATHINFO_EXTENSION));
        if ($ext !== 'json') {
            wp_safe_redirect(add_query_arg('stm_error', 'invalid_type', wp_get_referer()));
            exit;
        }

        $json = file_get_contents($file['tmp_name']);
        $data = json_decode($json, true);

        if (!is_array($data)) {
            wp_safe_redirect(add_query_arg('stm_error', 'invalid_json', wp_get_referer()));
            exit;
        }

        $result = API::process_import($data);

        if (isset($result['error'])) {
            wp_safe_redirect(add_query_arg('stm_error', urlencode($result['error']), wp_get_referer()));
            exit;
        }

        wp_safe_redirect(add_query_arg([
            'imported' => $result['created'] + $result['updated'],
            'stm_errors' => count($result['errors']),
        ], wp_get_referer()));
        exit;
    }

    /**
     * Add language (admin form handler)
     *
     * `code` has a UNIQUE KEY (class-database.php), so re-adding a code that
     * already exists â€” most commonly one an admin previously deactivated â€”
     * must reactivate that row instead of attempting (and failing) a raw
     * insert. Only a genuinely new code inserts a new row.
     */
    public static function add_language() {
        if (!check_admin_referer('stm_add_language') || !current_user_can('manage_options')) {
            wp_die('Unauthorized', 403);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'stm_languages';

        $code        = sanitize_text_field(wp_unslash($_POST['lang_code'] ?? ''));
        $name        = sanitize_text_field(wp_unslash($_POST['lang_name'] ?? ''));
        $native_name = sanitize_text_field(wp_unslash($_POST['lang_native'] ?? $name));
        $flag        = sanitize_text_field(wp_unslash($_POST['lang_flag'] ?? ''));
        $is_default  = isset($_POST['lang_default']) ? 1 : 0;

        if (!Security::validate_language_code($code) || empty($name)) {
            wp_safe_redirect(add_query_arg('stm_error', 'invalid_fields', wp_get_referer()));
            exit;
        }

        $code = strtolower($code);

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, is_active FROM {$table} WHERE code = %s",
            $code
        ));

        if ($existing && $existing->is_active) {
            wp_safe_redirect(add_query_arg('stm_error', 'already_active', wp_get_referer()));
            exit;
        }

        if ($is_default) {
            $wpdb->update($table, ['is_default' => 0], ['is_default' => 1]);
        }

        $fields = [
            'name'        => $name,
            'native_name' => $native_name,
            'flag_emoji'  => $flag,
            'is_default'  => $is_default,
            'is_active'   => 1,
            'order_index' => intval($_POST['lang_order'] ?? 99),
        ];

        if ($existing) {
            // Reactivate the existing inactive row â€” never delete/reinsert, so any
            // translations already tied to this language code are left untouched.
            $result = $wpdb->update($table, $fields, ['id' => $existing->id]);
            $success_arg = 'stm_reactivated';
        } else {
            $result = $wpdb->insert($table, $fields + ['code' => $code]);
            $success_arg = 'stm_added';
        }

        wp_cache_delete('stm_active_languages');
        wp_cache_delete('stm_all_languages');
        wp_cache_delete('stm_default_language');

        wp_safe_redirect(add_query_arg(
            $result === false ? 'stm_error' : $success_arg,
            $result === false ? 'db_error'  : '1',
            wp_get_referer()
        ));
        exit;
    }

    /**
     * Delete language (admin form handler)
     */
    public static function delete_language() {
        if (!check_admin_referer('stm_delete_language') || !current_user_can('manage_options')) {
            wp_die('Unauthorized', 403);
        }

        $code = sanitize_text_field(wp_unslash($_POST['lang_code'] ?? ''));

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
            wp_safe_redirect(add_query_arg('stm_error', 'cannot_delete_default', wp_get_referer()));
            exit;
        }

        $wpdb->delete($wpdb->prefix . 'stm_languages', ['code' => $code]);

        wp_cache_delete('stm_active_languages');
        wp_cache_delete('stm_all_languages');

        wp_safe_redirect(add_query_arg('stm_deleted', '1', wp_get_referer()));
        exit;
    }

    /**
     * Toggle a language between active and inactive (admin form handler).
     *
     * Inactive languages stay invisible on the front end (switcher, URLs,
     * hreflang) but remain fully editable/previewable in the post editor, so
     * admins can prepare a language before switching it live.
     */
    public static function toggle_language_active() {
        if (!check_admin_referer('stm_toggle_language_active') || !current_user_can('manage_options')) {
            wp_die('Unauthorized', 403);
        }

        $code = sanitize_text_field(wp_unslash($_POST['lang_code'] ?? ''));

        if (!Security::validate_language_code($code)) {
            wp_die('Invalid language code', 400);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'stm_languages';

        $lang = $wpdb->get_row($wpdb->prepare(
            "SELECT is_active, is_default FROM {$table} WHERE code = %s",
            $code
        ));

        $redirect_arg = 'stm_toggled';
        $redirect_val = '1';

        if (!$lang) {
            $redirect_arg = 'stm_error';
            $redirect_val = 'not_found';
        } elseif ($lang->is_default && $lang->is_active) {
            // The default language always needs to be reachable on the front end.
            $redirect_arg = 'stm_error';
            $redirect_val = 'cannot_deactivate_default';
        } else {
            $wpdb->update($table, ['is_active' => $lang->is_active ? 0 : 1], ['code' => $code]);
            wp_cache_delete('stm_active_languages');
            wp_cache_delete('stm_all_languages');
        }

        wp_safe_redirect(add_query_arg($redirect_arg, $redirect_val, wp_get_referer()));
        exit;
    }

    /**
     * AJAX: save user filter preferences to user_meta
     * Called by admin JS when filters change or a dashboard tab is selected.
     * Stored per-page so different STM pages have independent state.
     */
    public static function ajax_save_prefs() {
        check_ajax_referer('stm_admin_nonce', 'nonce');

        $page  = sanitize_key($_POST['page'] ?? '');
        $prefs = $_POST['prefs'] ?? [];

        if (!$page || !is_array($prefs)) {
            wp_send_json_error('Invalid request');
        }

        // Whitelist allowed keys to prevent arbitrary meta storage
        $allowed = ['lang', 'context', 'status', 'search', 'paged', 'tab'];
        $clean   = [];
        foreach ($allowed as $key) {
            if (isset($prefs[$key])) {
                $clean[$key] = sanitize_text_field($prefs[$key]);
            }
        }

        $all = (array) get_user_meta(get_current_user_id(), 'stm_admin_prefs', true);
        $all[$page] = $clean;
        update_user_meta(get_current_user_id(), 'stm_admin_prefs', $all);

        wp_send_json_success();
    }

    /**
     * AJAX: return saved preferences for the current user
     */
    public static function ajax_load_prefs() {
        check_ajax_referer('stm_admin_nonce', 'nonce');

        $page = sanitize_key($_POST['page'] ?? '');
        $all  = (array) get_user_meta(get_current_user_id(), 'stm_admin_prefs', true);

        wp_send_json_success($all[$page] ?? []);
    }

    /**
     * Heartbeat: refresh STM nonce so admin pages open > 12h stay valid
     */
    public static function heartbeat_refresh_nonce($response, $data) {
        if (!empty($data['stm_refresh_nonce'])) {
            $response['stm_nonce'] = wp_create_nonce('stm_admin_nonce');
        }
        return $response;
    }

    /**
     * Save AI/auto-translate settings
     */
    public static function save_ai_settings() {
        if (!check_admin_referer('stm_ai_settings') || !current_user_can('manage_options')) {
            wp_die('Unauthorized', 403);
        }

        $provider   = sanitize_text_field(wp_unslash($_POST['ai_provider'] ?? 'openai'));
        $openai_key = sanitize_text_field(wp_unslash($_POST['openai_key'] ?? ''));
        $deepl_key  = sanitize_text_field(wp_unslash($_POST['deepl_key'] ?? ''));

        AutoTranslate::save_settings($provider, $openai_key ?: null, $deepl_key ?: null, [
            'openai_model'           => sanitize_text_field($_POST['openai_model'] ?? ''),
            'openai_temperature'     => (float) ($_POST['openai_temperature'] ?? 0.3),
            'openai_prompt_template' => sanitize_textarea_field($_POST['openai_prompt_template'] ?? ''),
        ]);

        wp_safe_redirect(add_query_arg('stm_saved', '1', wp_get_referer()));
        exit;
    }
}
