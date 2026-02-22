<?php
/**
 * Caching Layer
 *
 * Uses WordPress Object Cache (wp_cache_*)
 * Compatible with Redis/Memcached if installed
 */

namespace STM;

class Cache {

    /**
     * Cache group for all translation data
     */
    const GROUP = 'stm_translations';

    /**
     * Cache TTL (1 hour)
     */
    const TTL = 3600;

    /**
     * Get translation from cache or database
     *
     * @param string $key Translation key
     * @param string $lang Language code
     * @param string $context Context (optional)
     * @return string|null
     */
    public static function get_translation($key, $lang, $context = 'general') {
        global $wpdb;

        $cache_key = self::make_cache_key($key, $lang, $context);
        $translation = wp_cache_get($cache_key, self::GROUP);

        if (false !== $translation) {
            return $translation;
        }

        // Cache miss - query database
        $table_strings = $wpdb->prefix . 'stm_strings';
        $table_translations = $wpdb->prefix . 'stm_translations';

        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT t.translation
            FROM {$table_translations} t
            INNER JOIN {$table_strings} s ON t.string_id = s.id
            WHERE s.string_key = %s
            AND s.context = %s
            AND t.language_code = %s
            AND t.status = 'published'
            LIMIT 1",
            $key,
            $context,
            $lang
        ));

        // Store in cache (even if null, to avoid repeated DB queries)
        wp_cache_set($cache_key, $result, self::GROUP, self::TTL);

        return $result;
    }

    /**
     * Get post field translation from cache or database
     *
     * @param int $post_id Post ID
     * @param string $field Field name
     * @param string $lang Language code
     * @return string|null
     */
    public static function get_post_translation($post_id, $field, $lang) {
        global $wpdb;

        $cache_key = "post_{$post_id}_{$field}_{$lang}";
        $translation = wp_cache_get($cache_key, self::GROUP);

        if (false !== $translation) {
            return $translation;
        }

        // Cache miss - query database
        $table = $wpdb->prefix . 'stm_post_translations';

        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT translation FROM {$table}
            WHERE post_id = %d
            AND field_name = %s
            AND language_code = %s
            LIMIT 1",
            $post_id,
            $field,
            $lang
        ));

        // Store in cache
        wp_cache_set($cache_key, $result, self::GROUP, self::TTL);

        return $result;
    }

    /**
     * Invalidate cache for a specific string
     *
     * @param string $key Translation key
     * @param string $context Context
     */
    public static function invalidate_string($key, $context = 'general') {
        $languages = Database::get_languages();

        foreach ($languages as $lang) {
            $cache_key = self::make_cache_key($key, $lang->code, $context);
            wp_cache_delete($cache_key, self::GROUP);
        }
    }

    /**
     * Invalidate cache for a post field
     *
     * @param int $post_id Post ID
     * @param string $field Field name
     */
    public static function invalidate_post($post_id, $field = null) {
        $languages = Database::get_languages();

        if ($field) {
            // Invalidate specific field
            foreach ($languages as $lang) {
                $cache_key = "post_{$post_id}_{$field}_{$lang->code}";
                wp_cache_delete($cache_key, self::GROUP);
            }
        } else {
            // Invalidate all fields for this post (brute force but safe)
            wp_cache_flush();
        }
    }

    /**
     * Make cache key
     */
    private static function make_cache_key($key, $lang, $context) {
        return md5("{$context}:{$key}:{$lang}");
    }

    /**
     * Flush all translation cache
     */
    public static function flush_all() {
        wp_cache_flush();
    }
}
