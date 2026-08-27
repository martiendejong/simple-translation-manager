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
     * @param int    $post_id     ID of the post being translated, if any. When
     *                            given, the per-post strategies (2 and 3, which
     *                            read from stm_post_translations) are hard-
     *                            restricted to translations that were written
     *                            for THIS post — a stored translation whose
     *                            source text belongs to a different post can
     *                            never be returned, no matter how similar the
     *                            two posts' text is (869enmrpz). Strategy 1
     *                            (generic template/UI string memory) is
     *                            intentionally NOT post-scoped: it exists
     *                            precisely to reuse short strings across posts.
     *                            0/omitted preserves the pre-fix, unscoped
     *                            behaviour for callers translating text that
     *                            isn't tied to a specific post (e.g. the
     *                            settings "test connection" button) or for the
     *                            /memory/suggest admin browse tool, where a
     *                            human reviews cross-post suggestions before
     *                            applying them rather than an auto-apply path
     *                            accepting them silently.
     * @return array Array of suggestions with similarity scores
     */
    public static function suggest($source_text, $target_lang, $field_type = '', $post_id = 0) {
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
        $post_exact = self::find_exact_post_match($source_text, $target_lang, $field_type, $post_id);
        if ($post_exact) {
            return [['text' => $post_exact, 'similarity' => 1.0, 'source' => 'post_exact_match']];
        }

        // Strategy 3: Fuzzy match from existing post translations
        $fuzzy = self::find_fuzzy_matches($source_text, $target_lang, $field_type, $post_id);
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
    private static function find_exact_post_match($text, $target_lang, $field_type, $post_id = 0) {
        global $wpdb;
        $table = $wpdb->prefix . 'stm_post_translations';

        // Find posts where the original field value matches, and a translation exists
        $default_lang = Database::get_default_language();
        $default_code = $default_lang ? $default_lang->code : 'en';

        if ($target_lang === $default_code) {
            return null;
        }

        // Cross-post restriction: when the caller knows which post it is
        // translating, never return a translation row belonging to a
        // DIFFERENT post — an identical-looking title/excerpt/content string
        // is still the wrong post's translation once saved under the wrong
        // post ID (869enmrpz). No-op when $post_id is unknown (0).
        $post_scope = $post_id > 0 ? $wpdb->prepare(' AND pt.post_id = %d', $post_id) : '';

        $column = self::field_name_to_column($field_type);

        if ($column) {
            // Known field type: compare against ONLY the matching WordPress
            // column, and restrict candidate translations to that same
            // field_name. Without this, any translation row for a post whose
            // current post_content/post_excerpt/post_title happens to equal
            // $text would match — e.g. a saved excerpt translation "exact
            // matching" a full-content lookup for the same post, since the
            // content being translated is by definition equal to the post's
            // own live post_content (869enmhwe).
            return $wpdb->get_var($wpdb->prepare(
                "SELECT pt.translation
                 FROM {$table} pt
                 INNER JOIN {$wpdb->posts} p ON pt.post_id = p.ID
                 WHERE pt.language_code = %s
                   AND pt.field_name = %s
                   AND p.{$column} = %s
                   AND pt.translation != ''
                   {$post_scope}
                 ORDER BY pt.updated_at DESC
                 LIMIT 1",
                $target_lang, $field_type, $text
            ));
        }

        // Unknown/legacy field type — no live caller hits this today (every
        // caller of TranslationMemory::suggest() passes a field type), kept
        // as a fallback for direct/future callers of memory/suggest that
        // don't. Preserves the pre-fix behaviour: match either title or
        // content, across any field_name (still post-scoped when $post_id
        // is known).
        return $wpdb->get_var($wpdb->prepare(
            "SELECT pt.translation
             FROM {$table} pt
             INNER JOIN {$wpdb->posts} p ON pt.post_id = p.ID
             WHERE pt.language_code = %s
               AND (p.post_title = %s OR p.post_content = %s)
               AND pt.translation != ''
               {$post_scope}
             ORDER BY pt.updated_at DESC
             LIMIT 1",
            $target_lang, $text, $text
        ));
    }

    /**
     * Map a stored field_name (post_title/post_excerpt/post_content/post_name
     * — see translateField() in admin-post-editor.js and save_post_translation()
     * in class-api.php) to the matching wp_posts column holding the original,
     * default-language text for that field. Returns null for anything else so
     * callers can fall back to the pre-fix, field-blind behaviour.
     */
    private static function field_name_to_column($field_type) {
        switch ($field_type) {
            case 'post_title':
            case 'post_excerpt':
            case 'post_content':
            case 'post_name':
                return $field_type;
            default:
                return null;
        }
    }

    /**
     * Find fuzzy matches using PHP similar_text
     */
    private static function find_fuzzy_matches($text, $target_lang, $field_type, $post_id = 0) {
        global $wpdb;
        $table = $wpdb->prefix . 'stm_post_translations';

        // Get recent translations to compare against
        $where_field = $field_type ? $wpdb->prepare(' AND pt.field_name = %s', $field_type) : '';

        // Cross-post restriction (869enmrpz): a near-duplicate-template post
        // (same headings/paragraphs, different specifics) can score well
        // above SIMILARITY_THRESHOLD against similar_text() while still being
        // a completely different article. Tightening the threshold only
        // reduces the odds of that happening; it can never rule it out
        // structurally. When the caller knows which post it is translating,
        // only that post's own past translations are eligible candidates —
        // no other post's translation can ever come back, regardless of how
        // similar the two posts' text is. No-op when $post_id is unknown (0),
        // e.g. the /memory/suggest admin browse tool, where a human reviews
        // cross-post suggestions before applying them.
        $post_scope = $post_id > 0 ? $wpdb->prepare(' AND pt.post_id = %d', $post_id) : '';

        $existing = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT pt.translation, pt.field_name, p.post_title, p.post_excerpt, p.post_content, p.post_name
             FROM {$table} pt
             INNER JOIN {$wpdb->posts} p ON pt.post_id = p.ID
             WHERE pt.language_code = %s
               AND pt.translation != ''
               {$where_field}
               {$post_scope}
             ORDER BY pt.updated_at DESC
             LIMIT 200",
            $target_lang
        ));

        $matches = [];
        $text_lower = strtolower($text);
        $text_len = strlen($text_lower);

        foreach ($existing as $row) {
            // Compare against the original text of the SAME field the row's
            // translation belongs to, not always the post title — otherwise a
            // content-length query is compared against an unrelated short
            // title string (869enmhwe).
            $original = self::original_field_value($row);
            $compare = strtolower($original);

            // Quick length filter - skip if too different in length. Tightened
            // from a 50% to a 20% deviation cap: the old 50% cap was loose
            // enough that a 145-char row could still be considered against a
            // 6,494-char query (869enmhwe).
            $compare_len = strlen($compare);
            if ($compare_len === 0 || abs($text_len - $compare_len) > max($text_len, $compare_len) * 0.2) {
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
                    'matched_original' => $original,
                ];
            }
        }

        return $matches;
    }

    /**
     * Extract the original, default-language text a stored translation row
     * was translated from, based on its field_name. Falls back to post_title
     * for legacy/unrecognised field names (matches the pre-fix behaviour).
     */
    private static function original_field_value($row) {
        switch ($row->field_name) {
            case 'post_excerpt':
                return (string) $row->post_excerpt;
            case 'post_content':
                return (string) $row->post_content;
            case 'post_name':
                return (string) $row->post_name;
            case 'post_title':
            default:
                return (string) $row->post_title;
        }
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
