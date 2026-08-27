<?php
/**
 * Post Editor Integration
 *
 * Adds translation meta box to post/page editor
 * Handles saving translations for post content
 *
 * @package SimpleTranslationManager
 */

namespace STM;

class PostEditor {

    /** Pre-loaded translation data for the current admin post list page (eliminates N+1) */
    private static $list_cache = [];

    /**
     * Initialize post editor hooks
     */
    public static function init() {
        if (!is_admin()) {
            return;
        }

        // Add meta box to post/page editor
        add_action('add_meta_boxes', [__CLASS__, 'add_meta_box']);

        // Save post translations
        add_action('save_post', [__CLASS__, 'save_translations'], 10, 2);

        // Add language column to post list
        add_filter('manage_posts_columns', [__CLASS__, 'add_language_column']);
        add_filter('manage_pages_columns', [__CLASS__, 'add_language_column']);
        add_action('manage_posts_custom_column', [__CLASS__, 'display_language_column'], 10, 2);
        add_action('manage_pages_custom_column', [__CLASS__, 'display_language_column'], 10, 2);

        // Batch-load translation data for the post list to avoid N+1 queries
        add_filter('the_posts', [__CLASS__, 'preload_list_translations'], 10, 2);

        // Enqueue assets
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
    }

    /**
     * Add translation meta box
     */
    public static function add_meta_box() {
        $post_types = get_post_types(['public' => true], 'names');

        foreach ($post_types as $post_type) {
            add_meta_box(
                'stm_translations',
                'Translations',
                [__CLASS__, 'render_meta_box'],
                $post_type,
                'normal',
                'high'
            );
        }
    }

    /**
     * Render translation meta box
     */
    public static function render_meta_box($post) {
        wp_nonce_field('stm_save_translations', 'stm_translations_nonce');

        $languages = Database::get_languages();
        $current_lang = self::get_post_language($post->ID);
        $translation_group = self::get_translation_group($post->ID);

        // Get existing translations
        $translations = [];
        foreach ($languages as $lang) {
            if ($lang->code === $current_lang) {
                continue; // Skip current language
            }

            $translations[$lang->code] = self::get_post_translation($post->ID, $lang->code);
        }

        include STM_PLUGIN_DIR . 'templates/meta-box-translations.php';
    }

    /**
     * Enqueue admin assets
     */
    public static function enqueue_assets($hook) {
        if (!in_array($hook, ['post.php', 'post-new.php'])) {
            return;
        }

        wp_enqueue_style('dashicons');

        wp_enqueue_style(
            'stm-post-editor',
            STM_PLUGIN_URL . 'assets/admin-post-editor.css',
            ['dashicons'],
            STM_VERSION
        );

        wp_enqueue_script(
            'stm-post-editor',
            STM_PLUGIN_URL . 'assets/admin-post-editor.js',
            ['jquery', 'wp-i18n', 'wp-editor'],
            STM_VERSION,
            true
        );

        $post_id = isset($_GET['post']) ? (int) $_GET['post'] : 0;
        $current_lang = $post_id ? self::get_post_language($post_id) : Settings::get_default_language();

        wp_localize_script('stm-post-editor', 'stmPostEditor', [
            'ajaxUrl'     => admin_url('admin-ajax.php'),
            'nonce'       => wp_create_nonce('stm_post_editor_nonce'),
            'restUrl'     => esc_url_raw(rest_url('stm/v1/translate/auto')),
            'restNonce'   => wp_create_nonce('wp_rest'),
            'sourceLang'  => $current_lang,
            'defaultLang' => Settings::get_default_language(),
            'i18n' => [
                'translating'        => __('Translating…', 'simple-translation-manager'),
                'translated'         => __('Translation complete', 'simple-translation-manager'),
                'translateFailed'    => __('Auto-translate failed', 'simple-translation-manager'),
                'nothingToTranslate' => __('No source content to translate — fill in the post first.', 'simple-translation-manager'),
                'overwriteConfirm'   => __('This tab already has translations. Overwrite them with auto-translated content?', 'simple-translation-manager'),
                'saved'              => __('Translations saved', 'simple-translation-manager'),
            ],
        ]);
    }

    /**
     * Save post translations
     */
    public static function save_translations($post_id, $post) {
        // Security checks
        if (!isset($_POST['stm_translations_nonce']) || !wp_verify_nonce($_POST['stm_translations_nonce'], 'stm_save_translations')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // Get or create translation group
        $translation_group = self::get_translation_group($post_id);
        if (!$translation_group) {
            $translation_group = wp_generate_uuid4();
        }

        // Save post language
        $post_language = isset($_POST['stm_post_language']) ? sanitize_text_field($_POST['stm_post_language']) : Settings::get_default_language();
        self::set_post_language($post_id, $post_language, $translation_group);

        // Save translations
        if (isset($_POST['stm_translations']) && is_array($_POST['stm_translations'])) {
            global $wpdb;
            $table = $wpdb->prefix . 'stm_post_translations';

            foreach ($_POST['stm_translations'] as $lang_code => $fields) {
                if (!Security::validate_language_code($lang_code)) {
                    continue;
                }

                // Save each field
                foreach ($fields as $field_name => $value) {
                    $field_name = sanitize_text_field($field_name);

                    // Sanitize based on field type
                    if ($field_name === 'post_content') {
                        $value = wp_kses_post($value);
                    } elseif ($field_name === 'post_name') {
                        $value = sanitize_title($value);
                    } else {
                        $value = sanitize_text_field($value);
                    }

                    $existing = $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM {$table} WHERE post_id = %d AND field_name = %s AND language_code = %s",
                        $post_id,
                        $field_name,
                        $lang_code
                    ));

                    // Empty value — delete existing row if present
                    if (empty($value)) {
                        if ($existing) {
                            $wpdb->delete($table, ['id' => $existing]);
                        }
                        continue;
                    }

                    $data = [
                        'post_id' => $post_id,
                        'field_name' => $field_name,
                        'language_code' => $lang_code,
                        'translation' => $value,
                    ];

                    if ($existing) {
                        $wpdb->update($table, $data, ['id' => $existing]);
                    } else {
                        $wpdb->insert($table, $data);
                    }
                }
            }

            // Invalidate cached translations for this post across all languages
            Cache::invalidate_post($post_id);
        }
    }

    /**
     * Batch-load all translation statuses for the visible post list in one query.
     * Fires on the_posts filter so it runs once before any column callbacks.
     */
    public static function preload_list_translations($posts, $query) {
        if (!$query->is_main_query() || !is_admin()) {
            return $posts;
        }

        $post_ids = array_map(function($p) { return $p->ID; }, $posts);
        if (empty($post_ids)) {
            return $posts;
        }

        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($post_ids), '%d'));
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_id, language_code, field_name, translation
                 FROM {$wpdb->prefix}stm_post_translations
                 WHERE post_id IN ($placeholders)
                   AND field_name IN ('post_title','post_content')",
                ...$post_ids
            ),
            ARRAY_A
        );

        foreach ($rows as $row) {
            self::$list_cache[$row['post_id']][$row['language_code']][$row['field_name']] = $row['translation'];
        }

        return $posts;
    }

    /**
     * Add language column to post list
     */
    public static function add_language_column($columns) {
        $new_columns = [];
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            if ($key === 'title') {
                $new_columns['stm_language'] = 'Language';
                $new_columns['stm_translations'] = 'Translations';
            }
        }
        return $new_columns;
    }

    /**
     * Display language column content
     */
    public static function display_language_column($column, $post_id) {
        if ($column === 'stm_language') {
            $lang_code = self::get_post_language($post_id);
            $languages = Database::get_languages();

            foreach ($languages as $lang) {
                if ($lang->code === $lang_code) {
                    echo esc_html($lang->flag_emoji . ' ' . $lang->code);
                    return;
                }
            }

            echo esc_html(strtoupper($lang_code));
        }

        if ($column === 'stm_translations') {
            $languages    = Database::get_languages();
            $current_lang = self::get_post_language($post_id);
            $has_translations = false;

            foreach ($languages as $lang) {
                if ($lang->code === $current_lang) {
                    continue;
                }

                // Use preloaded data when available (avoids N+1)
                $has_title = isset(self::$list_cache[$post_id][$lang->code]['post_title'])
                    && self::$list_cache[$post_id][$lang->code]['post_title'] !== '';

                if (!$has_title && !isset(self::$list_cache[$post_id])) {
                    // Fallback for when preload did not fire (e.g., direct page load)
                    $t         = self::get_post_translation($post_id, $lang->code);
                    $has_title = !empty($t['post_title']);
                }

                if ($has_title) {
                    echo esc_html($lang->flag_emoji) . ' ';
                    $has_translations = true;
                }
            }

            if (!$has_translations) {
                echo '—';
            }
        }
    }

    /**
     * Get post language
     */
    public static function get_post_language($post_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'stm_post_associations';

        $lang = $wpdb->get_var($wpdb->prepare(
            "SELECT language_code FROM {$table} WHERE post_id = %d",
            $post_id
        ));

        return $lang ?: Settings::get_default_language();
    }

    /**
     * Set post language
     */
    public static function set_post_language($post_id, $language_code, $translation_group) {
        global $wpdb;
        $table = $wpdb->prefix . 'stm_post_associations';

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE post_id = %d",
            $post_id
        ));

        $data = [
            'post_id' => $post_id,
            'language_code' => $language_code,
            'translation_group' => $translation_group,
            'is_original' => 1,
        ];

        if ($existing) {
            $wpdb->update($table, $data, ['id' => $existing]);
        } else {
            $wpdb->insert($table, $data);
        }
    }

    /**
     * Get translation group
     */
    public static function get_translation_group($post_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'stm_post_associations';

        return $wpdb->get_var($wpdb->prepare(
            "SELECT translation_group FROM {$table} WHERE post_id = %d",
            $post_id
        ));
    }

    /**
     * Get post translation
     */
    public static function get_post_translation($post_id, $language_code) {
        global $wpdb;
        $table = $wpdb->prefix . 'stm_post_translations';

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT field_name, translation FROM {$table} WHERE post_id = %d AND language_code = %s",
            $post_id,
            $language_code
        ), ARRAY_A);

        $translation = [];
        foreach ($results as $row) {
            $translation[$row['field_name']] = $row['translation'];
        }

        return $translation;
    }

    /**
     * Reverse-lookup: which post has $slug as its translated post_name for
     * $language_code? Used to resolve incoming /{lang}/.../{translated-slug}/
     * requests back to the real post — see
     * Frontend::resolve_translated_slug_request().
     *
     * @return int 0 when no translated slug matches.
     */
    public static function get_post_id_by_translated_slug($language_code, $slug) {
        global $wpdb;
        $table = $wpdb->prefix . 'stm_post_translations';

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$table} WHERE field_name = 'post_name' AND language_code = %s AND translation = %s LIMIT 1",
            $language_code,
            $slug
        ));
    }

    /**
     * Get posts in translation group
     */
    public static function get_translation_group_posts($translation_group) {
        global $wpdb;
        $table = $wpdb->prefix . 'stm_post_associations';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, language_code FROM {$table} WHERE translation_group = %s",
            $translation_group
        ), ARRAY_A);
    }
}
