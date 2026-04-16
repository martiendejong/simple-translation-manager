<?php
/**
 * Translation Dashboard
 *
 * Admin dashboard showing translation coverage statistics, missing translations,
 * and recent translation activity across posts and UI strings.
 *
 * @package SimpleTranslationManager
 */

namespace STM;

class Dashboard {

    const CACHE_KEY_COVERAGE = 'stm_dashboard_coverage';
    const CACHE_TTL = 300; // 5 minutes

    /**
     * Initialize hooks
     */
    public static function init() {
        if (!is_admin()) {
            return;
        }

        add_action('admin_post_stm_export_coverage_csv', [__CLASS__, 'export_coverage_csv']);
        add_action('admin_post_stm_export_missing_csv', [__CLASS__, 'export_missing_csv']);
        add_action('wp_ajax_stm_quick_save_translation', [__CLASS__, 'ajax_quick_save_translation']);
        add_action('wp_ajax_stm_refresh_coverage', [__CLASS__, 'ajax_refresh_coverage']);
    }

    /**
     * Render the dashboard page
     */
    public static function render_page() {
        if (!Security::can_manage_translations()) {
            wp_die(__('Insufficient permissions.', 'simple-translation-manager'));
        }

        $active_tab = sanitize_key($_GET['tab'] ?? 'overview');
        $allowed_tabs = ['overview', 'missing', 'recent'];
        if (!in_array($active_tab, $allowed_tabs, true)) {
            $active_tab = 'overview';
        }

        $languages = Database::get_languages();
        $default_lang = Database::get_default_language();

        // Gather data for active tab only (keeps page fast).
        $data = [
            'active_tab'   => $active_tab,
            'languages'    => $languages,
            'default_lang' => $default_lang,
        ];

        if ($active_tab === 'overview') {
            $data['coverage'] = self::get_coverage_stats();
        } elseif ($active_tab === 'missing') {
            $filters = [
                'language'  => sanitize_text_field($_GET['flang'] ?? ''),
                'post_type' => sanitize_text_field($_GET['fptype'] ?? ''),
                'date_from' => sanitize_text_field($_GET['fdate_from'] ?? ''),
                'date_to'   => sanitize_text_field($_GET['fdate_to'] ?? ''),
                'paged'     => max(1, intval($_GET['paged'] ?? 1)),
                'per_page'  => 50,
            ];
            $data['filters']  = $filters;
            $data['missing']  = self::get_missing_translations($filters);
        } elseif ($active_tab === 'recent') {
            $data['recent'] = self::get_recent_translations(50);
        }

        // Expose to template
        extract($data);
        include STM_PLUGIN_DIR . 'templates/admin-dashboard.php';
    }

    /**
     * Compute coverage stats per non-default language.
     *
     * Returns an array like:
     * [
     *   'default_code' => 'en',
     *   'total_posts'  => 120,
     *   'by_language'  => [
     *     'nl' => ['name'=>'Nederlands','emoji'=>'🇳🇱','title'=>['translated'=>80,'total'=>120,'pct'=>66.7], 'strings'=>...],
     *   ],
     *   'strings_total' => 340,
     * ]
     */
    public static function get_coverage_stats($use_cache = true) {
        if ($use_cache) {
            $cached = get_transient(self::CACHE_KEY_COVERAGE);
            if (false !== $cached) {
                return $cached;
            }
        }

        global $wpdb;

        $languages    = Database::get_languages();
        $default_lang = Database::get_default_language();
        $default_code = $default_lang ? $default_lang->code : 'en';

        $post_types = self::get_translatable_post_types();
        $placeholders = implode(',', array_fill(0, count($post_types), '%s'));

        $total_posts = 0;
        if (!empty($post_types)) {
            $total_posts = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts}
                 WHERE post_status = 'publish' AND post_type IN ({$placeholders})",
                ...$post_types
            ));
        }

        $strings_total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}stm_strings");

        $table_pt = $wpdb->prefix . 'stm_post_translations';
        $table_tx = $wpdb->prefix . 'stm_translations';

        $by_language = [];
        foreach ($languages as $lang) {
            if ($lang->code === $default_code) {
                continue;
            }

            // Title coverage for posts/pages/CPT
            $title_translated = 0;
            if ($total_posts > 0) {
                $title_translated = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(DISTINCT pt.post_id) FROM {$table_pt} pt
                     INNER JOIN {$wpdb->posts} p ON p.ID = pt.post_id
                     WHERE pt.language_code = %s
                       AND pt.field_name = 'title'
                       AND p.post_status = 'publish'
                       AND p.post_type IN ({$placeholders})",
                    $lang->code,
                    ...$post_types
                ));
            }

            $content_translated = 0;
            if ($total_posts > 0) {
                $content_translated = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(DISTINCT pt.post_id) FROM {$table_pt} pt
                     INNER JOIN {$wpdb->posts} p ON p.ID = pt.post_id
                     WHERE pt.language_code = %s
                       AND pt.field_name = 'content'
                       AND p.post_status = 'publish'
                       AND p.post_type IN ({$placeholders})",
                    $lang->code,
                    ...$post_types
                ));
            }

            // UI string coverage
            $strings_translated = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table_tx}
                 WHERE language_code = %s AND status = 'published'",
                $lang->code
            ));

            $by_language[$lang->code] = [
                'code'    => $lang->code,
                'name'    => $lang->native_name,
                'emoji'   => $lang->flag_emoji,
                'title'   => self::pct_row($title_translated, $total_posts),
                'content' => self::pct_row($content_translated, $total_posts),
                'strings' => self::pct_row($strings_translated, $strings_total),
            ];
        }

        $stats = [
            'default_code'  => $default_code,
            'total_posts'   => $total_posts,
            'strings_total' => $strings_total,
            'by_language'   => $by_language,
            'generated_at'  => current_time('mysql'),
        ];

        set_transient(self::CACHE_KEY_COVERAGE, $stats, self::CACHE_TTL);

        return $stats;
    }

    /**
     * Get list of posts missing translations for the given filters.
     *
     * If filters['language'] is empty, each non-default language is considered
     * (row per missing language-per-post).
     */
    public static function get_missing_translations($filters) {
        global $wpdb;

        $languages    = Database::get_languages();
        $default_lang = Database::get_default_language();
        $default_code = $default_lang ? $default_lang->code : 'en';

        $target_langs = [];
        if (!empty($filters['language'])) {
            foreach ($languages as $lang) {
                if ($lang->code === $filters['language'] && $lang->code !== $default_code) {
                    $target_langs[] = $lang;
                }
            }
        } else {
            foreach ($languages as $lang) {
                if ($lang->code !== $default_code) {
                    $target_langs[] = $lang;
                }
            }
        }

        if (empty($target_langs)) {
            return [
                'rows'        => [],
                'total'       => 0,
                'total_pages' => 0,
                'current'     => $filters['paged'],
            ];
        }

        $post_types = self::get_translatable_post_types();
        if (!empty($filters['post_type']) && in_array($filters['post_type'], $post_types, true)) {
            $post_types = [$filters['post_type']];
        }

        $pt_placeholders = implode(',', array_fill(0, count($post_types), '%s'));
        $table_pt        = $wpdb->prefix . 'stm_post_translations';

        // Build date filter
        $date_sql = '';
        $date_args = [];
        if (!empty($filters['date_from'])) {
            $date_sql .= ' AND p.post_date >= %s';
            $date_args[] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $date_sql .= ' AND p.post_date <= %s';
            $date_args[] = $filters['date_to'] . ' 23:59:59';
        }

        $rows  = [];
        foreach ($target_langs as $lang) {
            $args = array_merge([$lang->code], $post_types, $date_args);

            $sql = "SELECT p.ID, p.post_title, p.post_type, p.post_date, p.post_status,
                           EXISTS(SELECT 1 FROM {$table_pt} pt_t
                                  WHERE pt_t.post_id = p.ID AND pt_t.language_code = %s AND pt_t.field_name = 'title') as has_title
                    FROM {$wpdb->posts} p
                    WHERE p.post_status = 'publish'
                      AND p.post_type IN ({$pt_placeholders})
                      {$date_sql}
                      AND NOT EXISTS (
                          SELECT 1 FROM {$table_pt} pt
                          WHERE pt.post_id = p.ID
                            AND pt.language_code = %s
                            AND pt.field_name IN ('title', 'content')
                          GROUP BY pt.post_id
                          HAVING COUNT(DISTINCT pt.field_name) >= 2
                      )
                    ORDER BY p.post_date DESC";

            // Add lang param for both EXISTS subquery and the outer NOT EXISTS
            $args_full = array_merge($args, [$lang->code]);

            $results = $wpdb->get_results($wpdb->prepare($sql, ...$args_full));

            foreach ($results as $row) {
                $rows[] = [
                    'post_id'       => (int) $row->ID,
                    'title'         => $row->post_title ?: '(no title)',
                    'post_type'     => $row->post_type,
                    'post_date'     => $row->post_date,
                    'language_code' => $lang->code,
                    'language_name' => $lang->native_name,
                    'language_emoji' => $lang->flag_emoji,
                    'has_title'     => (bool) $row->has_title,
                ];
            }
        }

        // Sort combined rows by date desc
        usort($rows, function($a, $b) {
            return strcmp($b['post_date'], $a['post_date']);
        });

        $total       = count($rows);
        $per_page    = max(1, (int) $filters['per_page']);
        $current     = max(1, (int) $filters['paged']);
        $total_pages = (int) ceil($total / $per_page);
        $offset      = ($current - 1) * $per_page;
        $paged_rows  = array_slice($rows, $offset, $per_page);

        return [
            'rows'        => $paged_rows,
            'total'       => $total,
            'total_pages' => $total_pages,
            'current'     => $current,
        ];
    }

    /**
     * Get recent translations activity (post translations + string translations).
     */
    public static function get_recent_translations($limit = 50) {
        global $wpdb;

        $limit    = max(1, min(200, (int) $limit));
        $table_pt = $wpdb->prefix . 'stm_post_translations';
        $table_tx = $wpdb->prefix . 'stm_translations';
        $table_s  = $wpdb->prefix . 'stm_strings';

        $post_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT 'post' as kind, pt.id, pt.post_id, pt.field_name, pt.language_code, pt.updated_at,
                    p.post_title, p.post_type, NULL as string_key, NULL as user_id
             FROM {$table_pt} pt
             LEFT JOIN {$wpdb->posts} p ON p.ID = pt.post_id
             ORDER BY pt.updated_at DESC
             LIMIT %d",
            $limit
        ));

        $string_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT 'string' as kind, t.id, NULL as post_id, NULL as field_name, t.language_code, t.updated_at,
                    NULL as post_title, NULL as post_type, s.string_key, t.translated_by as user_id
             FROM {$table_tx} t
             LEFT JOIN {$table_s} s ON s.id = t.string_id
             ORDER BY t.updated_at DESC
             LIMIT %d",
            $limit
        ));

        $rows = array_merge($post_rows, $string_rows);
        usort($rows, function($a, $b) {
            return strcmp($b->updated_at ?? '', $a->updated_at ?? '');
        });

        return array_slice($rows, 0, $limit);
    }

    /**
     * AJAX: quick-save a post field translation.
     */
    public static function ajax_quick_save_translation() {
        check_ajax_referer('stm_dashboard_nonce', 'nonce');

        if (!Security::can_manage_translations()) {
            wp_send_json_error(['message' => __('Unauthorized', 'simple-translation-manager')], 403);
        }

        global $wpdb;

        $post_id       = intval($_POST['post_id'] ?? 0);
        $language_code = sanitize_text_field($_POST['language_code'] ?? '');
        $field_name    = sanitize_key($_POST['field_name'] ?? 'title');
        $translation   = wp_kses_post(wp_unslash($_POST['translation'] ?? ''));

        if ($post_id <= 0 || !Security::validate_language_code($language_code)) {
            wp_send_json_error(['message' => __('Invalid input', 'simple-translation-manager')], 400);
        }

        if (!in_array($field_name, ['title', 'content', 'excerpt'], true)) {
            wp_send_json_error(['message' => __('Invalid field', 'simple-translation-manager')], 400);
        }

        $post = get_post($post_id);
        if (!$post) {
            wp_send_json_error(['message' => __('Post not found', 'simple-translation-manager')], 404);
        }

        $table = $wpdb->prefix . 'stm_post_translations';

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE post_id = %d AND field_name = %s AND language_code = %s",
            $post_id,
            $field_name,
            $language_code
        ));

        $data = [
            'post_id'       => $post_id,
            'field_name'    => $field_name,
            'language_code' => $language_code,
            'translation'   => $translation,
        ];

        if ($existing) {
            $wpdb->update($table, $data, ['id' => $existing]);
        } else {
            $wpdb->insert($table, $data);
        }

        delete_transient(self::CACHE_KEY_COVERAGE);
        delete_transient('stm_untranslated_warning');

        wp_send_json_success([
            'message' => __('Translation saved.', 'simple-translation-manager'),
        ]);
    }

    /**
     * AJAX: force refresh of coverage cache.
     */
    public static function ajax_refresh_coverage() {
        check_ajax_referer('stm_dashboard_nonce', 'nonce');

        if (!Security::can_manage_translations()) {
            wp_send_json_error(['message' => __('Unauthorized', 'simple-translation-manager')], 403);
        }

        delete_transient(self::CACHE_KEY_COVERAGE);
        $stats = self::get_coverage_stats(false);

        wp_send_json_success($stats);
    }

    /**
     * Export coverage summary as CSV.
     */
    public static function export_coverage_csv() {
        if (!Security::verify_admin_action('stm_export_coverage_csv')) {
            wp_die(__('Unauthorized', 'simple-translation-manager'), 403);
        }

        $stats = self::get_coverage_stats(false);

        self::stream_csv_headers('stm-coverage-' . date('Y-m-d') . '.csv');
        $out = fopen('php://output', 'w');

        fputcsv($out, ['Language Code', 'Language', 'Field', 'Translated', 'Total', 'Percent']);

        foreach ($stats['by_language'] as $row) {
            foreach (['title', 'content', 'strings'] as $field) {
                fputcsv($out, [
                    $row['code'],
                    $row['name'],
                    $field,
                    $row[$field]['translated'],
                    $row[$field]['total'],
                    $row[$field]['pct'],
                ]);
            }
        }

        fclose($out);
        exit;
    }

    /**
     * Export missing translations as CSV (source text included for translation agencies).
     */
    public static function export_missing_csv() {
        if (!Security::verify_admin_action('stm_export_missing_csv')) {
            wp_die(__('Unauthorized', 'simple-translation-manager'), 403);
        }

        $filters = [
            'language'  => sanitize_text_field($_GET['flang'] ?? ''),
            'post_type' => sanitize_text_field($_GET['fptype'] ?? ''),
            'date_from' => sanitize_text_field($_GET['fdate_from'] ?? ''),
            'date_to'   => sanitize_text_field($_GET['fdate_to'] ?? ''),
            'paged'     => 1,
            'per_page'  => 100000,
        ];

        $result = self::get_missing_translations($filters);

        self::stream_csv_headers('stm-missing-' . date('Y-m-d') . '.csv');
        $out = fopen('php://output', 'w');

        fputcsv($out, [
            'Post ID',
            'Post Type',
            'Target Language',
            'Source Title',
            'Source Content',
            'Source Excerpt',
            'Permalink',
            'Edit Link',
            'Translated Title',
            'Translated Content',
            'Translated Excerpt',
        ]);

        foreach ($result['rows'] as $row) {
            $post = get_post($row['post_id']);
            if (!$post) {
                continue;
            }

            fputcsv($out, [
                $row['post_id'],
                $row['post_type'],
                $row['language_code'],
                $post->post_title,
                wp_strip_all_tags($post->post_content),
                $post->post_excerpt,
                get_permalink($post),
                admin_url('post.php?action=edit&post=' . $row['post_id']),
                '',
                '',
                '',
            ]);
        }

        fclose($out);
        exit;
    }

    /**
     * Helpers
     */

    public static function get_translatable_post_types() {
        $default = ['post', 'page'];

        $public = get_post_types(['public' => true], 'names');
        unset($public['attachment']);

        $types = array_values(array_unique(array_merge($default, array_values($public))));

        /**
         * Filter the list of post types considered for translation coverage.
         */
        return apply_filters('stm_translatable_post_types', $types);
    }

    private static function pct_row($translated, $total) {
        $translated = max(0, (int) $translated);
        $total      = max(0, (int) $total);
        $pct        = $total > 0 ? round(($translated / $total) * 100, 1) : 0.0;
        return [
            'translated' => $translated,
            'total'      => $total,
            'missing'    => max(0, $total - $translated),
            'pct'        => $pct,
        ];
    }

    private static function stream_csv_headers($filename) {
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        // UTF-8 BOM for Excel compatibility
        echo "\xEF\xBB\xBF";
    }
}
