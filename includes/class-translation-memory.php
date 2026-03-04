<?php
/**
 * Translation Memory System
 *
 * Reuses existing translations for similar content.
 * When translating "AI Integration" → "AI Integratie" for one post,
 * suggests the same translation for similar strings elsewhere.
 *
 * Uses the existing stm_translations and stm_post_translations tables
 * as the memory source - no additional database table needed.
 *
 * @package SimpleTranslationManager
 */

namespace STM;

class TranslationMemory {

    /**
     * Minimum similarity threshold (0.0 - 1.0)
     */
    const SIMILARITY_THRESHOLD = 0.6;

    /**
     * Maximum suggestions to return
     */
    const MAX_SUGGESTIONS = 5;

    /**
     * Initialize hooks
     */
    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    /**
     * Register REST API routes
     */
    public static function register_routes() {
        $namespace = 'stm/v1';

        register_rest_route($namespace, '/memory/suggest', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'rest_suggest'],
            'permission_callback' => [API::class, 'check_permissions'],
        ]);

        register_rest_route($namespace, '/memory/stats', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'rest_stats'],
            'permission_callback' => [API::class, 'check_permissions'],
        ]);
    }

    /**
     * Find translation suggestions for a source text
     *
     * @param string $source_text Text to find translations for
     * @param string $target_lang Target language code
     * @param string $field_type Type of field (title, content, excerpt, etc.)
     * @return array Array of suggestions with similarity scores
     */
    public static function suggest($source_text, $target_lang, $field_type = '') {
        if (empty($source_text) || strlen($source_text) < 2) {
            return [];
        }

        $suggestions = [];

        // Strategy 1: Exact match from string translations
        $exact = self::find_exact_string_match($source_text, $target_lang);
        if ($exact) {
            return [['text' => $exact, 'similarity' => 1.0, 'source' => 'exact_match']];
        }

        // Strategy 2: Exact match from post translations
        $post_exact = self::find_exact_post_match($source_text, $target_lang, $field_type);
        if ($post_exact) {
            return [['text' => $post_exact, 'similarity' => 1.0, 'source' => 'post_exact_match']];
        }

        // Strategy 3: Fuzzy match from existing post translations
        $fuzzy = self::find_fuzzy_matches($source_text, $target_lang, $field_type);
        $suggestions = array_merge($suggestions, $fuzzy);

        // Strategy 4: Substring/segment matching for longer texts
        if (strlen($source_text) > 50) {
            $segments = self::find_segment_matches($source_text, $target_lang);
            $suggestions = array_merge($suggestions, $segments);
        }

        // Sort by similarity score descending
        usort($suggestions, function($a, $b) {
            return $b['similarity'] <=> $a['similarity'];
        });

        // Deduplicate and limit
        $seen = [];
        $unique = [];
        foreach ($suggestions as $s) {
            $key = md5($s['text']);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $s;
            }
            if (count($unique) >= self::MAX_SUGGESTIONS) {
                break;
            }
        }

        return $unique;
    }

    /**
     * Find exact match in template string translations
     */
    private static function find_exact_string_match($text, $target_lang) {
        global $wpdb;

        $table_strings = $wpdb->prefix . 'stm_strings';
        $table_translations = $wpdb->prefix . 'stm_translations';

        // Check if the source text matches any existing string key or translation in default language
        return $wpdb->get_var($wpdb->prepare(
            "SELECT t.translation
             FROM {$table_translations} t
             INNER JOIN {$table_strings} s ON t.string_id = s.id
             WHERE t.language_code = %s
               AND t.status = 'published'
               AND (s.string_key = %s OR EXISTS (
                   SELECT 1 FROM {$table_translations} src
                   WHERE src.string_id = s.id AND src.translation = %s AND src.language_code != %s
               ))
             LIMIT 1",
            $target_lang, $text, $text, $target_lang
        ));
    }

    /**
     * Find exact match in post translations
     */
    private static function find_exact_post_match($text, $target_lang, $field_type) {
        global $wpdb;
        $table = $wpdb->prefix . 'stm_post_translations';

        // Find posts where the original field value matches, and a translation exists
        $default_lang = Database::get_default_language();
        $default_code = $default_lang ? $default_lang->code : 'en';

        if ($target_lang === $default_code) {
            return null;
        }

        // Look for other posts with same original title/content that already have translations
        $where_field = $field_type ? $wpdb->prepare(' AND pt.field_name = %s', $field_type) : '';

        return $wpdb->get_var($wpdb->prepare(
            "SELECT pt.translation
             FROM {$table} pt
             INNER JOIN {$wpdb->posts} p ON pt.post_id = p.ID
             WHERE pt.language_code = %s
               {$where_field}
               AND (p.post_title = %s OR p.post_content = %s)
               AND pt.translation != ''
             ORDER BY pt.updated_at DESC
             LIMIT 1",
            $target_lang, $text, $text
        ));
    }

    /**
     * Find fuzzy matches using PHP similar_text
     */
    private static function find_fuzzy_matches($text, $target_lang, $field_type) {
        global $wpdb;
        $table = $wpdb->prefix . 'stm_post_translations';

        // Get recent translations to compare against
        $where_field = $field_type ? $wpdb->prepare(' AND field_name = %s', $field_type) : '';

        $existing = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT pt.translation, p.post_title
             FROM {$table} pt
             INNER JOIN {$wpdb->posts} p ON pt.post_id = p.ID
             WHERE pt.language_code = %s
               AND pt.translation != ''
               {$where_field}
             ORDER BY pt.updated_at DESC
             LIMIT 200",
            $target_lang
        ));

        $matches = [];
        $text_lower = strtolower($text);
        $text_len = strlen($text_lower);

        foreach ($existing as $row) {
            $compare = strtolower($row->post_title);

            // Quick length filter - skip if too different in length
            $compare_len = strlen($compare);
            if ($compare_len === 0 || abs($text_len - $compare_len) > max($text_len, $compare_len) * 0.5) {
                continue;
            }

            // Calculate similarity
            similar_text($text_lower, $compare, $percent);
            $similarity = $percent / 100;

            if ($similarity >= self::SIMILARITY_THRESHOLD) {
                $matches[] = [
                    'text' => $row->translation,
                    'similarity' => round($similarity, 3),
                    'source' => 'fuzzy_match',
                    'matched_original' => $row->post_title,
                ];
            }
        }

        return $matches;
    }

    /**
     * Find segment matches for longer texts (break into sentences)
     */
    private static function find_segment_matches($text, $target_lang) {
        // Split into sentences
        $sentences = preg_split('/(?<=[.!?])\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);

        $matches = [];

        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if (strlen($sentence) < 10) {
                continue;
            }

            $exact = self::find_exact_string_match($sentence, $target_lang);
            if ($exact) {
                $matches[] = [
                    'text' => $exact,
                    'similarity' => 0.85,
                    'source' => 'segment_match',
                    'matched_segment' => $sentence,
                ];
            }
        }

        return $matches;
    }

    /**
     * Get translation memory statistics
     */
    public static function get_stats() {
        global $wpdb;

        $table_translations = $wpdb->prefix . 'stm_translations';
        $table_post_translations = $wpdb->prefix . 'stm_post_translations';

        $string_count = $wpdb->get_var("SELECT COUNT(*) FROM {$table_translations} WHERE status = 'published'");
        $post_count = $wpdb->get_var("SELECT COUNT(*) FROM {$table_post_translations}");
        $unique_strings = $wpdb->get_var("SELECT COUNT(DISTINCT translation) FROM {$table_translations} WHERE status = 'published'");
        $unique_posts = $wpdb->get_var("SELECT COUNT(DISTINCT CONCAT(post_id, '-', field_name)) FROM {$table_post_translations}");

        $languages = Database::get_languages();
        $per_lang = [];
        foreach ($languages as $lang) {
            $per_lang[$lang->code] = [
                'strings' => (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table_translations} WHERE language_code = %s AND status = 'published'",
                    $lang->code
                )),
                'posts' => (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(DISTINCT post_id) FROM {$table_post_translations} WHERE language_code = %s",
                    $lang->code
                )),
            ];
        }

        return [
            'total_string_translations' => (int) $string_count,
            'total_post_translations' => (int) $post_count,
            'unique_string_translations' => (int) $unique_strings,
            'unique_post_entries' => (int) $unique_posts,
            'memory_entries' => (int) $string_count + (int) $post_count,
            'per_language' => $per_lang,
        ];
    }

    // =========================================================================
    // REST Handlers
    // =========================================================================

    public static function rest_suggest($request) {
        $params = $request->get_json_params();
        $text = sanitize_text_field($params['text'] ?? '');
        $lang = sanitize_text_field($params['target_lang'] ?? 'nl');
        $field = sanitize_text_field($params['field_type'] ?? '');

        if (empty($text)) {
            return new \WP_Error('empty_text', 'Text parameter is required', ['status' => 400]);
        }

        $suggestions = self::suggest($text, $lang, $field);

        return rest_ensure_response([
            'source_text' => $text,
            'target_lang' => $lang,
            'suggestions' => $suggestions,
            'count' => count($suggestions),
        ]);
    }

    public static function rest_stats($request) {
        return rest_ensure_response(self::get_stats());
    }
}
