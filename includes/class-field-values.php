<?php
/**
 * Field Value Translations
 *
 * Some custom post type fields hold standardized values that repeat across
 * many posts (e.g. coachwork = "Cabriolet", color = "Black"). Instead of
 * translating those per post in stm_post_translations, a field can be marked
 * as "value-translatable": each distinct VALUE gets one translation per
 * language, shared by every post that uses it.
 *
 * Registration:
 * - Code: stm_register_value_translatable_field('coachwork', ['post_types' => ['bcc_chassis'], 'label' => 'Coachwork'])
 * - Admin UI: Translations > Field Values
 * - Filter: 'stm_value_translatable_fields' receives the merged registry
 *
 * Storage: {prefix}stm_field_value_translations keyed on
 * (field_name, value_hash, language_code) where value_hash = md5(source_value)
 * so long values stay indexable.
 */

namespace STM;

class FieldValues {

    const OPTION_KEY = 'stm_value_translatable_fields';

    /**
     * Fields registered from code via stm_register_value_translatable_field()
     */
    private static $code_registry = [];

    /**
     * Register a value-translatable field from code
     *
     * @param string $field_name Meta key
     * @param array  $args ['post_types' => string[], 'label' => string]
     */
    public static function register($field_name, $args = []) {
        $field_name = sanitize_key($field_name);
        if (!$field_name) {
            return;
        }
        self::$code_registry[$field_name] = [
            'field'      => $field_name,
            'label'      => !empty($args['label']) ? $args['label'] : $field_name,
            'post_types' => !empty($args['post_types']) ? array_map('sanitize_key', (array) $args['post_types']) : [],
            'source'     => 'code',
        ];
    }

    /**
     * All value-translatable fields: code registry merged with the
     * admin-managed option. Option entries win on label/post_types so the
     * admin can refine code-registered fields.
     *
     * @return array field_name => ['field', 'label', 'post_types', 'source']
     */
    public static function get_registered_fields() {
        $fields = self::$code_registry;

        $stored = get_option(self::OPTION_KEY, []);
        if (is_array($stored)) {
            foreach ($stored as $name => $entry) {
                $name = sanitize_key($name);
                if (!$name) {
                    continue;
                }
                $fields[$name] = [
                    'field'      => $name,
                    'label'      => !empty($entry['label']) ? $entry['label'] : ($fields[$name]['label'] ?? $name),
                    'post_types' => !empty($entry['post_types']) ? array_map('sanitize_key', (array) $entry['post_types']) : ($fields[$name]['post_types'] ?? []),
                    'source'     => isset($fields[$name]) ? 'code+admin' : 'admin',
                ];
            }
        }

        return apply_filters('stm_value_translatable_fields', $fields);
    }

    /**
     * Is this field marked as having translatable values?
     */
    public static function is_value_translatable($field_name) {
        $fields = self::get_registered_fields();
        return isset($fields[sanitize_key($field_name)]);
    }

    /**
     * Add or update a field in the admin-managed registry
     */
    public static function save_field($field_name, $args = []) {
        $field_name = sanitize_key($field_name);
        if (!$field_name) {
            return false;
        }
        $stored = get_option(self::OPTION_KEY, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        $stored[$field_name] = [
            'label'      => sanitize_text_field($args['label'] ?? $field_name),
            'post_types' => array_filter(array_map('sanitize_key', (array) ($args['post_types'] ?? []))),
        ];
        return update_option(self::OPTION_KEY, $stored);
    }

    /**
     * Remove a field from the admin-managed registry.
     * Stored value translations are kept (harmless rows, restored on re-add).
     */
    public static function remove_field($field_name) {
        $field_name = sanitize_key($field_name);
        $stored = get_option(self::OPTION_KEY, []);
        if (is_array($stored) && isset($stored[$field_name])) {
            unset($stored[$field_name]);
            update_option(self::OPTION_KEY, $stored);
            return true;
        }
        return false;
    }

    /**
     * Distinct values currently in use for a field, merged with values that
     * already have translation rows (so legacy values remain manageable).
     *
     * @return array of ['value' => string, 'hash' => string, 'in_use' => bool, 'post_count' => int]
     */
    public static function get_distinct_values($field_name) {
        global $wpdb;

        $field_name = sanitize_key($field_name);
        $fields = self::get_registered_fields();
        $post_types = $fields[$field_name]['post_types'] ?? [];

        $type_sql = '';
        $params = [$field_name];
        if (!empty($post_types)) {
            $placeholders = implode(',', array_fill(0, count($post_types), '%s'));
            $type_sql = "AND p.post_type IN ({$placeholders})";
            $params = array_merge($params, $post_types);
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT pm.meta_value AS value, COUNT(DISTINCT pm.post_id) AS post_count
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = %s
             AND pm.meta_value != ''
             AND p.post_status NOT IN ('trash', 'auto-draft', 'inherit')
             {$type_sql}
             GROUP BY pm.meta_value
             ORDER BY pm.meta_value ASC",
            $params
        ));

        $values = [];
        foreach ($rows as $row) {
            $hash = md5($row->value);
            $values[$hash] = [
                'value'      => $row->value,
                'hash'       => $hash,
                'in_use'     => true,
                'post_count' => (int) $row->post_count,
            ];
        }

        // Values that only exist in the translations table (no longer in use)
        $table = $wpdb->prefix . 'stm_field_value_translations';
        $orphans = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT value_hash, source_value FROM {$table} WHERE field_name = %s",
            $field_name
        ));
        foreach ($orphans as $row) {
            if (!isset($values[$row->value_hash])) {
                $values[$row->value_hash] = [
                    'value'      => $row->source_value,
                    'hash'       => $row->value_hash,
                    'in_use'     => false,
                    'post_count' => 0,
                ];
            }
        }

        return array_values($values);
    }

    /**
     * All stored translations for a field
     *
     * @return array value_hash => [language_code => translation]
     */
    public static function get_translations_for_field($field_name) {
        global $wpdb;
        $table = $wpdb->prefix . 'stm_field_value_translations';

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT value_hash, language_code, translation FROM {$table} WHERE field_name = %s",
            sanitize_key($field_name)
        ));

        $map = [];
        foreach ($rows as $row) {
            $map[$row->value_hash][$row->language_code] = $row->translation;
        }
        return $map;
    }

    /**
     * Upsert one value translation. Empty translation deletes the row.
     *
     * @return bool
     */
    public static function save_translation($field_name, $source_value, $language_code, $translation) {
        global $wpdb;
        $table = $wpdb->prefix . 'stm_field_value_translations';

        $field_name = sanitize_key($field_name);
        $hash = md5($source_value);
        $translation = trim($translation);

        if ($translation === '') {
            $wpdb->delete($table, [
                'field_name'    => $field_name,
                'value_hash'    => $hash,
                'language_code' => $language_code,
            ]);
            Cache::invalidate_field_value($field_name, $source_value);
            return true;
        }

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE field_name = %s AND value_hash = %s AND language_code = %s",
            $field_name,
            $hash,
            $language_code
        ));

        $data = [
            'field_name'    => $field_name,
            'value_hash'    => $hash,
            'source_value'  => $source_value,
            'language_code' => $language_code,
            'translation'   => $translation,
        ];

        if ($existing) {
            $result = $wpdb->update($table, $data, ['id' => $existing]);
        } else {
            $result = $wpdb->insert($table, $data);
        }

        Cache::invalidate_field_value($field_name, $source_value);
        return $result !== false;
    }

    /**
     * Translate a field value for a language. Returns the original value for
     * the default language or when no translation exists.
     *
     * @param string $field_name
     * @param string $value Raw (default-language) value
     * @param string|null $lang Target language, defaults to current
     * @return string
     */
    public static function translate_value($field_name, $value, $lang = null) {
        if ($value === '' || $value === null) {
            return $value;
        }

        if (!$lang) {
            $lang = Frontend::get_current_language();
        }

        $default = Database::get_default_language();
        if ($default && $lang === $default->code) {
            return $value;
        }

        $translation = Cache::get_field_value_translation(sanitize_key($field_name), $value, $lang);
        return $translation !== '' ? $translation : $value;
    }

    /**
     * Coverage stats per language for a field
     *
     * @return array ['total' => int, 'languages' => [code => translated_count]]
     */
    public static function get_coverage($field_name) {
        $values = self::get_distinct_values($field_name);
        $translations = self::get_translations_for_field($field_name);
        $languages = Database::get_languages();
        $default = Database::get_default_language();
        $default_code = $default ? $default->code : 'en';

        $coverage = ['total' => count($values), 'languages' => []];
        foreach ($languages as $language) {
            if ($language->code === $default_code) {
                continue;
            }
            $count = 0;
            foreach ($values as $value) {
                if (!empty($translations[$value['hash']][$language->code])) {
                    $count++;
                }
            }
            $coverage['languages'][$language->code] = $count;
        }
        return $coverage;
    }
}
