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

        // Bulk post translations
        register_rest_route($namespace, '/posts/bulk-translations', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'bulk_post_translations'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
        ]);

        // Auto-translate via AI (handled by AutoTranslate class)

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
        return Security::can_manage_translations();
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

        $code = sanitize_text_field($request['code']);
        $flag_emoji = sanitize_text_field($request['flag_emoji'] ?? '');

        // Validate inputs
        if (!Security::validate_language_code($code)) {
            return new \WP_Error('invalid_code', 'Invalid language code (must be 2-3 letters)', ['status' => 400]);
        }

        if ($flag_emoji && !Security::validate_flag_emoji($flag_emoji)) {
            return new \WP_Error('invalid_emoji', 'Invalid flag emoji', ['status' => 400]);
        }

        try {
            $data = [
                'code' => $code,
                'name' => sanitize_text_field($request['name']),
                'native_name' => sanitize_text_field($request['native_name']),
                'is_default' => intval($request['is_default'] ?? 0),
                'is_active' => intval($request['is_active'] ?? 1),
                'flag_emoji' => $flag_emoji,
                'order_index' => intval($request['order_index'] ?? 999),
            ];

            $result = $wpdb->insert($wpdb->prefix . 'stm_languages', $data);

            if ($result === false) {
                throw new \Exception('Database operation failed');
            }

            wp_cache_delete('stm_active_languages');
            return rest_ensure_response(['id' => $wpdb->insert_id, 'success' => true]);
        } catch (\Exception $e) {
            Security::log('Error creating language: ' . $e->getMessage(), 'error');
            return new \WP_Error('db_error', 'Failed to create language', ['status' => 500]);
        }
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

        $string_key = Security::sanitize_translation_key($request['key']);
        $context = Security::sanitize_context($request['context'] ?? 'general');

        // Validate inputs
        if (!Security::validate_translation_key($string_key)) {
            return new \WP_Error('invalid_key', 'Invalid translation key format', ['status' => 400]);
        }

        if (!Security::validate_context($context)) {
            return new \WP_Error('invalid_context', 'Invalid context format', ['status' => 400]);
        }

        try {
            $data = [
                'string_key' => $string_key,
                'context' => $context,
                'description' => sanitize_textarea_field($request['description'] ?? ''),
            ];

            $result = $wpdb->insert($wpdb->prefix . 'stm_strings', $data);

            if ($result === false) {
                throw new \Exception('Database operation failed');
            }

            return rest_ensure_response(['id' => $wpdb->insert_id, 'success' => true]);
        } catch (\Exception $e) {
            Security::log('Error creating string: ' . $e->getMessage(), 'error');
            return new \WP_Error('db_error', 'Failed to create string', ['status' => 500]);
        }
    }

    /**
     * PUT /strings/{id} - Update a string
     */
    public static function update_string($request) {
        global $wpdb;

        $id = intval($request['id']);
        $string_key = Security::sanitize_translation_key($request['key'] ?? '');
        $context = Security::sanitize_context($request['context'] ?? 'general');

        if ($string_key && !Security::validate_translation_key($string_key)) {
            return new \WP_Error('invalid_key', 'Invalid translation key format', ['status' => 400]);
        }

        $update = [];
        if ($string_key) {
            $update['string_key'] = $string_key;
        }
        if ($request['context'] !== null) {
            if (!Security::validate_context($context)) {
                return new \WP_Error('invalid_context', 'Invalid context format', ['status' => 400]);
            }
            $update['context'] = $context;
        }
        if ($request['description'] !== null) {
            $update['description'] = sanitize_textarea_field($request['description']);
        }

        if (empty($update)) {
            return new \WP_Error('no_data', 'No fields to update', ['status' => 400]);
        }

        $result = $wpdb->update($wpdb->prefix . 'stm_strings', $update, ['id' => $id]);

        if ($result === false) {
            return new \WP_Error('db_error', 'Failed to update string', ['status' => 500]);
        }

        return rest_ensure_response(['success' => true, 'updated' => $result]);
    }

    /**
     * DELETE /strings/{id} - Delete a string and its translations
     */
    public static function delete_string($request) {
        global $wpdb;

        $id = intval($request['id']);

        $string = $wpdb->get_row($wpdb->prepare(
            "SELECT string_key, context FROM {$wpdb->prefix}stm_strings WHERE id = %d",
            $id
        ));

        if (!$string) {
            return new \WP_Error('not_found', 'String not found', ['status' => 404]);
        }

        $wpdb->delete($wpdb->prefix . 'stm_translations', ['string_id' => $id]);
        $wpdb->delete($wpdb->prefix . 'stm_strings', ['id' => $id]);

        Cache::invalidate_string($string->string_key, $string->context);

        return rest_ensure_response(['success' => true]);
    }

    /**
     * GET /translations - List translations (optionally filtered)
     */
    public static function get_translations($request) {
        global $wpdb;

        $string_id = intval($request->get_param('string_id'));
        $lang = sanitize_text_field($request->get_param('lang') ?? '');

        $where = ['1=1'];
        if ($string_id) {
            $where[] = $wpdb->prepare('t.string_id = %d', $string_id);
        }
        if ($lang) {
            $where[] = $wpdb->prepare('t.language_code = %s', $lang);
        }

        $results = $wpdb->get_results("
            SELECT t.*, s.string_key, s.context
            FROM {$wpdb->prefix}stm_translations t
            INNER JOIN {$wpdb->prefix}stm_strings s ON t.string_id = s.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY s.context ASC, s.string_key ASC, t.language_code ASC
        ");

        return rest_ensure_response($results);
    }

    /**
     * PUT /translations/{id} - Update a translation
     */
    public static function update_translation($request) {
        global $wpdb;

        $id = intval($request['id']);

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT t.*, s.string_key, s.context
             FROM {$wpdb->prefix}stm_translations t
             INNER JOIN {$wpdb->prefix}stm_strings s ON t.string_id = s.id
             WHERE t.id = %d",
            $id
        ));

        if (!$row) {
            return new \WP_Error('not_found', 'Translation not found', ['status' => 404]);
        }

        $update = [
            'translation'   => Security::sanitize_translation($request['translation']),
            'status'        => sanitize_text_field($request['status'] ?? $row->status),
            'translated_by' => get_current_user_id(),
            'translated_at' => current_time('mysql'),
        ];

        $result = $wpdb->update($wpdb->prefix . 'stm_translations', $update, ['id' => $id]);

        if ($result === false) {
            return new \WP_Error('db_error', 'Failed to update translation', ['status' => 500]);
        }

        Cache::invalidate_string($row->string_key, $row->context);

        return rest_ensure_response(['success' => true]);
    }

    /**
     * GET /posts/{id}/translations - Get all translations for a post
     */
    public static function get_post_translations($request) {
        global $wpdb;

        $post_id = intval($request['id']);

        if (!get_post($post_id)) {
            return new \WP_Error('not_found', 'Post not found', ['status' => 404]);
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT language_code, field_name, translation
             FROM {$wpdb->prefix}stm_post_translations
             WHERE post_id = %d
             ORDER BY language_code ASC, field_name ASC",
            $post_id
        ));

        $result = [];
        foreach ($rows as $row) {
            if (!isset($result[$row->language_code])) {
                $result[$row->language_code] = [];
            }
            $result[$row->language_code][$row->field_name] = $row->translation;
        }

        return rest_ensure_response($result);
    }

    /**
     * POST /posts/{id}/translations - Save a post field translation
     */
    public static function save_post_translation($request) {
        global $wpdb;

        $post_id = intval($request['id']);
        $language_code = sanitize_text_field($request['language_code'] ?? '');
        $field = sanitize_text_field($request['field'] ?? '');
        $translation = Security::sanitize_translation($request['translation'] ?? '');

        if (!get_post($post_id)) {
            return new \WP_Error('not_found', 'Post not found', ['status' => 404]);
        }

        if (!Security::validate_language_code($language_code)) {
            return new \WP_Error('invalid_language', 'Invalid language code', ['status' => 400]);
        }

        if (empty($field) || !preg_match('/^[a-z0-9_-]+$/i', $field)) {
            return new \WP_Error('invalid_field', 'Invalid field name', ['status' => 400]);
        }

        $table = $wpdb->prefix . 'stm_post_translations';

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE post_id = %d AND field_name = %s AND language_code = %s",
            $post_id, $field, $language_code
        ));

        if ($existing) {
            $wpdb->update(
                $table,
                ['translation' => $translation, 'updated_at' => current_time('mysql')],
                ['id' => $existing]
            );
        } else {
            $wpdb->insert($table, [
                'post_id'       => $post_id,
                'field_name'    => $field,
                'language_code' => $language_code,
                'translation'   => $translation,
            ]);
        }

        Cache::invalidate_post($post_id, $field);

        return rest_ensure_response(['success' => true]);
    }

    /**
     * POST /translations - Create translation
     */
    public static function create_translation($request) {
        global $wpdb;

        $string_id = intval($request['string_id']);
        $language_code = sanitize_text_field($request['language_code']);
        $translation = Security::sanitize_translation($request['translation']);

        // Validate inputs
        if (!Security::validate_language_code($language_code)) {
            return new \WP_Error('invalid_language', 'Invalid language code', ['status' => 400]);
        }

        try {
            $data = [
                'string_id' => $string_id,
                'language_code' => $language_code,
                'translation' => $translation,
                'status' => sanitize_text_field($request['status'] ?? 'published'),
                'translated_by' => get_current_user_id(),
                'translated_at' => current_time('mysql'),
            ];

            $result = $wpdb->insert($wpdb->prefix . 'stm_translations', $data);

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

            return rest_ensure_response(['id' => $wpdb->insert_id, 'success' => true]);
        } catch (\Exception $e) {
            Security::log('Error creating translation: ' . $e->getMessage(), 'error');
            return new \WP_Error('db_error', 'Failed to create translation', ['status' => 500]);
        }
    }

    /**
     * POST /translations/bulk - Bulk create/update translations
     */
    public static function bulk_create_translations($request) {
        global $wpdb;

        $items = $request->get_json_params();
        $created = 0;
        $updated = 0;
        $errors = [];

        try {
            foreach ($items as $item) {
                // Validate and sanitize inputs
                $string_key = Security::sanitize_translation_key($item['key']);
                $context = Security::sanitize_context($item['context'] ?? 'general');
                $language_code = sanitize_text_field($item['lang']);
                $translation = Security::sanitize_translation($item['translation']);

                if (!Security::validate_translation_key($string_key)) {
                    $errors[] = "Invalid key: {$item['key']}";
                    continue;
                }

                if (!Security::validate_context($context)) {
                    $errors[] = "Invalid context: {$item['context']}";
                    continue;
                }

                if (!Security::validate_language_code($language_code)) {
                    $errors[] = "Invalid language code: {$language_code}";
                    continue;
                }

                // Find or create string
                $string = $wpdb->get_row($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}stm_strings
                    WHERE string_key = %s AND context = %s",
                    $string_key,
                    $context
                ));

                if (!$string) {
                    $result = $wpdb->insert($wpdb->prefix . 'stm_strings', [
                        'string_key' => $string_key,
                        'context' => $context,
                    ]);
                    if ($result === false) {
                        throw new \Exception('Failed to create string');
                    }
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
                    $result = $wpdb->update($wpdb->prefix . 'stm_translations', $data, ['id' => $existing]);
                    if ($result !== false) {
                        $updated++;
                    }
                } else {
                    $result = $wpdb->insert($wpdb->prefix . 'stm_translations', $data);
                    if ($result !== false) {
                        $created++;
                    }
                }

                Cache::invalidate_string($string_key, $context);
            }

            return rest_ensure_response([
                'success' => true,
                'created' => $created,
                'updated' => $updated,
                'errors' => $errors,
            ]);
        } catch (\Exception $e) {
            Security::log('Error in bulk create translations: ' . $e->getMessage(), 'error');
            return new \WP_Error('db_error', 'Failed to bulk create translations', ['status' => 500]);
        }
    }

    /**
     * GET /posts/{id}/slugs - Get post slugs per language
     */
    public static function get_post_slugs($request) {
        $post_id = intval($request['id']);

        global $wpdb;
        $table = $wpdb->prefix . 'stm_post_translations';

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT language_code, translation FROM {$table} WHERE post_id = %d AND field_name = 'post_name'",
            $post_id
        ), ARRAY_A);

        $slugs = [];
        foreach ($rows as $row) {
            $slugs[$row['language_code']] = $row['translation'];
        }

        return rest_ensure_response($slugs);
    }

    /**
     * POST /posts/{id}/slugs - Save post slug per language
     */
    public static function save_post_slug($request) {
        $post_id = intval($request['id']);
        $language_code = sanitize_text_field($request['language_code']);
        $slug = sanitize_title($request['slug']);

        if (!Security::validate_language_code($language_code)) {
            return new \WP_Error('invalid_language', 'Invalid language code', ['status' => 400]);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'stm_post_translations';

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE post_id = %d AND field_name = 'post_name' AND language_code = %s",
            $post_id, $language_code
        ));

        $data = [
            'post_id'       => $post_id,
            'field_name'    => 'post_name',
            'language_code' => $language_code,
            'translation'   => $slug,
        ];

        if ($existing) {
            $wpdb->update($table, $data, ['id' => $existing]);
        } else {
            $wpdb->insert($table, $data);
        }

        return rest_ensure_response(['success' => true, 'slug' => $slug]);
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
     *
     * Accepts two formats:
     *   Format A: { "nl": { "nav.home": "Home", ... }, "en": { ... } }
     *   Format B: { "lang": "nl", "translations": { "nav.home": "Home", ... } }
     */
    public static function import_json($request) {
        $data = $request->get_json_params();

        if (empty($data) || !is_array($data)) {
            return new \WP_Error('empty_data', 'No data provided', ['status' => 400]);
        }

        $result = self::process_import($data);

        if (isset($result['error'])) {
            return new \WP_Error('import_error', $result['error'], ['status' => 400]);
        }

        return rest_ensure_response($result);
    }

    /**
     * Shared import logic — called by REST endpoint and admin handler
     *
     * @param array $data Decoded JSON array
     * @return array { created, updated, errors } or { error }
     */
    public static function process_import(array $data) {
        // Normalize to { lang_code => [ key => translation ] }
        $normalized = [];

        if (isset($data['lang']) && isset($data['translations']) && is_array($data['translations'])) {
            $normalized[$data['lang']] = $data['translations'];
        } else {
            foreach ($data as $key => $value) {
                if (is_array($value) && Security::validate_language_code($key)) {
                    $normalized[$key] = $value;
                }
            }
        }

        if (empty($normalized)) {
            return ['error' => 'Unrecognized import format. Expected {"nl":{"key":"value"}} or {"lang":"nl","translations":{"key":"value"}}'];
        }

        global $wpdb;
        $created = 0;
        $updated = 0;
        $errors  = [];

        foreach ($normalized as $lang_code => $translations) {
            if (!Security::validate_language_code($lang_code)) {
                $errors[] = "Invalid language code: $lang_code";
                continue;
            }

            foreach ($translations as $string_key => $translation) {
                $string_key  = Security::sanitize_translation_key($string_key);
                $translation = Security::sanitize_translation($translation);

                if (!Security::validate_translation_key($string_key)) {
                    $errors[] = "Invalid key: $string_key";
                    continue;
                }

                // Find or create the string record
                $string_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}stm_strings WHERE string_key = %s",
                    $string_key
                ));

                if (!$string_id) {
                    $wpdb->insert($wpdb->prefix . 'stm_strings', [
                        'string_key' => $string_key,
                        'context'    => 'general',
                    ]);
                    $string_id = $wpdb->insert_id;
                }

                // Upsert translation
                $existing = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}stm_translations WHERE string_id = %d AND language_code = %s",
                    $string_id, $lang_code
                ));

                $t_data = [
                    'string_id'     => $string_id,
                    'language_code' => $lang_code,
                    'translation'   => $translation,
                    'status'        => 'published',
                    'translated_by' => get_current_user_id(),
                    'translated_at' => current_time('mysql'),
                ];

                if ($existing) {
                    $wpdb->update($wpdb->prefix . 'stm_translations', $t_data, ['id' => $existing]);
                    $updated++;
                } else {
                    $wpdb->insert($wpdb->prefix . 'stm_translations', $t_data);
                    $created++;
                }

                Cache::invalidate_string($string_key, 'general');
            }
        }

        return ['success' => true, 'created' => $created, 'updated' => $updated, 'errors' => $errors];
    }

    /**
     * POST /posts/bulk-translations - Bulk save post translations via REST API
     *
     * Expected JSON format:
     * {
     *   "lang": "nl",
     *   "translations": {
     *     "123": { "title": "Titel", "content": "Inhoud" },
     *     "456": { "title": "Andere titel" }
     *   }
     * }
     */
    public static function bulk_post_translations($request) {
        $params = $request->get_json_params();
        $lang_code = sanitize_text_field($params['lang'] ?? '');
        $translations = $params['translations'] ?? [];

        // Validate using helper function
        $validation = stm_validate_bulk_translation_data($translations, $lang_code);
        if (!$validation['valid']) {
            return new \WP_Error('validation_error', implode('; ', $validation['errors']), ['status' => 400]);
        }

        $results = [
            'success' => true,
            'processed' => 0,
            'saved' => 0,
            'errors' => [],
            'warnings' => $validation['warnings'] ?? [],
        ];

        foreach ($translations as $post_id => $fields) {
            $post_id = intval($post_id);
            if (!get_post($post_id)) {
                $results['errors'][] = "Post $post_id not found";
                continue;
            }

            $results['processed']++;

            // Sanitize field values
            $sanitized = [];
            foreach ($fields as $field => $value) {
                $sanitized[sanitize_text_field($field)] = Security::sanitize_translation($value);
            }

            $result = self::save_post_translations($post_id, $sanitized, $lang_code);
            $results['saved'] += $result['success'];

            if (!empty($result['errors'])) {
                foreach ($result['errors'] as $error) {
                    $results['errors'][] = "Post $post_id: $error";
                }
            }
        }

        return rest_ensure_response($results);
    }

    /**
     * Save multiple post translations in bulk (helper method, not REST endpoint)
     *
     * @param int $post_id Post ID
     * @param array $translations Associative array: ['field_name' => 'translation']
     * @param string $lang_code Language code (e.g., 'nl', 'en')
     * @return array Result with success count and errors
     */
    public static function save_post_translations($post_id, $translations, $lang_code) {
        global $wpdb;
        $table = $wpdb->prefix . 'stm_post_translations';

        $success = 0;
        $errors = [];

        foreach ($translations as $field => $translation) {
            // Check if exists
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE post_id = %d AND field_name = %s AND language_code = %s",
                $post_id, $field, $lang_code
            ));

            if ($existing) {
                // Update
                $result = $wpdb->update(
                    $table,
                    ['translation' => $translation, 'updated_at' => current_time('mysql')],
                    ['id' => $existing],
                    ['%s', '%s'],
                    ['%d']
                );
            } else {
                // Insert
                $result = $wpdb->insert(
                    $table,
                    [
                        'post_id' => $post_id,
                        'field_name' => $field,
                        'language_code' => $lang_code,
                        'translation' => $translation
                    ],
                    ['%d', '%s', '%s', '%s']
                );
            }

            if ($result !== false) {
                $success++;
            } else {
                $errors[] = "Failed to save $field: " . $wpdb->last_error;
                error_log("[STM] Translation save error for post $post_id field $field: " . $wpdb->last_error);
            }
        }

        // Clear cache
        Cache::invalidate_post($post_id);

        return [
            'success' => $success,
            'total' => count($translations),
            'errors' => $errors
        ];
    }
}
