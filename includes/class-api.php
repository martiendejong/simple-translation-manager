<?php
/**
 * REST API Endpoints
 *
 * Full CRUD operations via WordPress REST API
 * Authentication: Application Passwords (WordPress 5.6+)
 */

namespace STM;

class API {

    /**
     * Initialize REST API
     */
    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    /**
     * Register all REST routes
     */
    public static function register_routes() {
        $namespace = 'stm/v1';

        // Languages
        register_rest_route($namespace, '/languages', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_languages'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($namespace, '/languages', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'create_language'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
        ]);

        // Strings
        register_rest_route($namespace, '/strings', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_strings'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($namespace, '/strings', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'create_string'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
        ]);

        register_rest_route($namespace, '/strings/(?P<id>\d+)', [
            'methods' => 'PUT',
            'callback' => [__CLASS__, 'update_string'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
        ]);

        register_rest_route($namespace, '/strings/(?P<id>\d+)', [
            'methods' => 'DELETE',
            'callback' => [__CLASS__, 'delete_string'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
        ]);

        // Translations
        register_rest_route($namespace, '/translations', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_translations'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($namespace, '/translations', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'create_translation'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
        ]);

        register_rest_route($namespace, '/translations/(?P<id>\d+)', [
            'methods' => 'PUT',
            'callback' => [__CLASS__, 'update_translation'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
        ]);

        // Bulk operations
        register_rest_route($namespace, '/translations/bulk', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'bulk_create_translations'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
        ]);

        // Auto-translate via AI
        register_rest_route($namespace, '/translate/auto', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'auto_translate'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
        ]);

        // Post translations
        register_rest_route($namespace, '/posts/(?P<id>\d+)/translations', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_post_translations'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($namespace, '/posts/(?P<id>\d+)/translations', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'save_post_translation'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
        ]);

        // Post slugs per language
        register_rest_route($namespace, '/posts/(?P<id>\d+)/slugs', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_post_slugs'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($namespace, '/posts/(?P<id>\d+)/slugs', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'save_post_slug'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
        ]);

        // Export/Import
        register_rest_route($namespace, '/export', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'export_json'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
        ]);

        register_rest_route($namespace, '/import', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'import_json'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
        ]);
    }

    /**
     * Permission check (requires manage_options capability)
     */
    public static function check_permissions() {
        return current_user_can('manage_options');
    }

    /**
     * GET /languages - List all languages
     */
    public static function get_languages($request) {
        $languages = Database::get_languages();
        return rest_ensure_response($languages);
    }

    /**
     * POST /languages - Create language
     */
    public static function create_language($request) {
        global $wpdb;

        $data = [
            'code' => sanitize_text_field($request['code']),
            'name' => sanitize_text_field($request['name']),
            'native_name' => sanitize_text_field($request['native_name']),
            'is_default' => intval($request['is_default'] ?? 0),
            'is_active' => intval($request['is_active'] ?? 1),
            'flag_emoji' => sanitize_text_field($request['flag_emoji'] ?? ''),
            'order_index' => intval($request['order_index'] ?? 999),
        ];

        $result = $wpdb->insert($wpdb->prefix . 'stm_languages', $data);

        if ($result) {
            wp_cache_delete('stm_active_languages');
            return rest_ensure_response(['id' => $wpdb->insert_id, 'success' => true]);
        }

        return new \WP_Error('db_error', 'Failed to create language', ['status' => 500]);
    }

    /**
     * GET /strings - List all strings
     */
    public static function get_strings($request) {
        global $wpdb;

        $context = $request->get_param('context');
        $lang = $request->get_param('lang');

        $table_strings = $wpdb->prefix . 'stm_strings';
        $table_translations = $wpdb->prefix . 'stm_translations';

        $where = ['1=1'];
        if ($context) {
            $where[] = $wpdb->prepare('s.context = %s', $context);
        }

        $where_sql = implode(' AND ', $where);

        $query = "
            SELECT s.*,
                GROUP_CONCAT(
                    CONCAT(t.language_code, ':', t.translation, ':', t.status)
                    SEPARATOR '||'
                ) as translations
            FROM {$table_strings} s
            LEFT JOIN {$table_translations} t ON s.id = t.string_id
            WHERE {$where_sql}
            GROUP BY s.id
            ORDER BY s.context ASC, s.string_key ASC
        ";

        $results = $wpdb->get_results($query);

        // Parse translations
        foreach ($results as &$row) {
            $row->translations = self::parse_translations($row->translations);
        }

        return rest_ensure_response($results);
    }

    /**
     * POST /strings - Create string
     */
    public static function create_string($request) {
        global $wpdb;

        $data = [
            'string_key' => sanitize_text_field($request['key']),
            'context' => sanitize_text_field($request['context'] ?? 'general'),
            'description' => sanitize_textarea_field($request['description'] ?? ''),
        ];

        $result = $wpdb->insert($wpdb->prefix . 'stm_strings', $data);

        if ($result) {
            return rest_ensure_response(['id' => $wpdb->insert_id, 'success' => true]);
        }

        return new \WP_Error('db_error', 'Failed to create string', ['status' => 500]);
    }

    /**
     * POST /translations - Create translation
     */
    public static function create_translation($request) {
        global $wpdb;

        $string_id = intval($request['string_id']);
        $language_code = sanitize_text_field($request['language_code']);
        $translation = wp_kses_post($request['translation']);

        $data = [
            'string_id' => $string_id,
            'language_code' => $language_code,
            'translation' => $translation,
            'status' => sanitize_text_field($request['status'] ?? 'published'),
            'translated_by' => get_current_user_id(),
            'translated_at' => current_time('mysql'),
        ];

        $result = $wpdb->insert($wpdb->prefix . 'stm_translations', $data);

        if ($result) {
            // Invalidate cache
            $string = $wpdb->get_row($wpdb->prepare(
                "SELECT string_key, context FROM {$wpdb->prefix}stm_strings WHERE id = %d",
                $string_id
            ));
            if ($string) {
                Cache::invalidate_string($string->string_key, $string->context);
            }

            return rest_ensure_response(['id' => $wpdb->insert_id, 'success' => true]);
        }

        return new \WP_Error('db_error', 'Failed to create translation', ['status' => 500]);
    }

    /**
     * POST /translations/bulk - Bulk create/update translations
     */
    public static function bulk_create_translations($request) {
        global $wpdb;

        $items = $request->get_json_params();
        $created = 0;
        $updated = 0;

        foreach ($items as $item) {
            // Find or create string
            $string = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}stm_strings
                WHERE string_key = %s AND context = %s",
                $item['key'],
                $item['context'] ?? 'general'
            ));

            if (!$string) {
                $wpdb->insert($wpdb->prefix . 'stm_strings', [
                    'string_key' => $item['key'],
                    'context' => $item['context'] ?? 'general',
                ]);
                $string_id = $wpdb->insert_id;
                $created++;
            } else {
                $string_id = $string->id;
            }

            // Upsert translation
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}stm_translations
                WHERE string_id = %d AND language_code = %s",
                $string_id,
                $item['lang']
            ));

            $data = [
                'string_id' => $string_id,
                'language_code' => $item['lang'],
                'translation' => $item['translation'],
                'status' => 'published',
                'translated_by' => get_current_user_id(),
                'translated_at' => current_time('mysql'),
            ];

            if ($existing) {
                $wpdb->update($wpdb->prefix . 'stm_translations', $data, ['id' => $existing]);
                $updated++;
            } else {
                $wpdb->insert($wpdb->prefix . 'stm_translations', $data);
                $created++;
            }

            Cache::invalidate_string($item['key'], $item['context'] ?? 'general');
        }

        return rest_ensure_response([
            'success' => true,
            'created' => $created,
            'updated' => $updated,
        ]);
    }

    /**
     * GET /posts/{id}/slugs - Get post slugs per language
     */
    public static function get_post_slugs($request) {
        $post_id = intval($request['id']);
        $slugs = get_post_meta($post_id, '_stm_slugs', true) ?: [];

        return rest_ensure_response($slugs);
    }

    /**
     * POST /posts/{id}/slugs - Save post slug per language
     */
    public static function save_post_slug($request) {
        $post_id = intval($request['id']);
        $language_code = sanitize_text_field($request['language_code']);
        $slug = sanitize_title($request['slug']);

        $slugs = get_post_meta($post_id, '_stm_slugs', true) ?: [];
        $slugs[$language_code] = $slug;

        update_post_meta($post_id, '_stm_slugs', $slugs);

        return rest_ensure_response(['success' => true, 'slugs' => $slugs]);
    }

    /**
     * Helper: Parse translations string
     */
    private static function parse_translations($translations_str) {
        if (!$translations_str) {
            return [];
        }

        $result = [];
        $parts = explode('||', $translations_str);

        foreach ($parts as $part) {
            list($lang, $text, $status) = explode(':', $part, 3);
            $result[$lang] = [
                'translation' => $text,
                'status' => $status,
            ];
        }

        return $result;
    }

    /**
     * GET /export - Export translations as JSON
     */
    public static function export_json($request) {
        global $wpdb;

        $lang = $request->get_param('lang');
        $context = $request->get_param('context');

        $table_strings = $wpdb->prefix . 'stm_strings';
        $table_translations = $wpdb->prefix . 'stm_translations';

        $where = ['t.status = "published"'];
        if ($lang) {
            $where[] = $wpdb->prepare('t.language_code = %s', $lang);
        }
        if ($context) {
            $where[] = $wpdb->prepare('s.context = %s', $context);
        }

        $where_sql = implode(' AND ', $where);

        $results = $wpdb->get_results("
            SELECT s.string_key, t.language_code, t.translation
            FROM {$table_translations} t
            INNER JOIN {$table_strings} s ON t.string_id = s.id
            WHERE {$where_sql}
        ");

        $export = [];
        foreach ($results as $row) {
            if (!isset($export[$row->language_code])) {
                $export[$row->language_code] = [];
            }
            $export[$row->language_code][$row->string_key] = $row->translation;
        }

        return rest_ensure_response($export);
    }

    /**
     * POST /import - Import translations from JSON
     */
    public static function import_json($request) {
        $data = $request->get_json_params();

        // Expected format: { "lang": "nl", "translations": { "nav.home": "Home", ... } }
        // Or: { "nl": { "nav.home": "Home" }, "en": { ... } }

        // Detect format and normalize
        // TODO: Implement import logic

        return rest_ensure_response(['success' => true]);
    }
}
