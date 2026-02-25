<?php
/**
 * Frontend Integration
 *
 * Filters post content to show translations
 * Handles language-specific URLs
 *
 * @package SimpleTranslationManager
 */

namespace STM;

class Frontend {

    /**
     * Initialize frontend hooks
     */
    public static function init() {
        // Content filters
        add_filter('the_title', [__CLASS__, 'filter_title'], 10, 2);
        add_filter('the_content', [__CLASS__, 'filter_content'], 10);
        add_filter('the_excerpt', [__CLASS__, 'filter_excerpt'], 10);
        add_filter('post_type_link', [__CLASS__, 'filter_permalink'], 10, 2);

        // Query modifications
        add_action('pre_get_posts', [__CLASS__, 'filter_query']);

        // Term translations
        add_filter('get_term', [__CLASS__, 'filter_term'], 10, 2);
    }

    /**
     * Get current language
     */
    public static function get_current_language() {
        // Priority: URL parameter > Cookie > Session > Default
        if (isset($_GET['lang'])) {
            $lang = sanitize_text_field($_GET['lang']);
            if (Security::validate_language_code($lang)) {
                // Store in cookie and session
                if (session_status() === PHP_SESSION_NONE) {
                    session_start();
                }
                $_SESSION['stm_lang'] = $lang;
                setcookie('stm_lang', $lang, time() + (86400 * 30), '/');
                return $lang;
            }
        }

        // Check session
        if (session_status() !== PHP_SESSION_NONE && isset($_SESSION['stm_lang'])) {
            return $_SESSION['stm_lang'];
        }

        // Check cookie
        if (isset($_COOKIE['stm_lang'])) {
            return sanitize_text_field($_COOKIE['stm_lang']);
        }

        // Default language
        return Settings::get_default_language();
    }

    /**
     * Filter post title
     */
    public static function filter_title($title, $post_id = null) {
        if (!$post_id || is_admin()) {
            return $title;
        }

        $current_lang = self::get_current_language();
        $post_lang = PostEditor::get_post_language($post_id);

        // If post is in current language, return as-is
        if ($post_lang === $current_lang) {
            return $title;
        }

        // Get translation
        $translation = PostEditor::get_post_translation($post_id, $current_lang);

        if (!empty($translation['post_title'])) {
            return $translation['post_title'];
        }

        return $title;
    }

    /**
     * Filter post content
     */
    public static function filter_content($content) {
        if (is_admin()) {
            return $content;
        }

        $post_id = get_the_ID();
        if (!$post_id) {
            return $content;
        }

        $current_lang = self::get_current_language();
        $post_lang = PostEditor::get_post_language($post_id);

        // If post is in current language, return as-is
        if ($post_lang === $current_lang) {
            return $content;
        }

        // Get translation
        $translation = PostEditor::get_post_translation($post_id, $current_lang);

        if (!empty($translation['post_content'])) {
            return $translation['post_content'];
        }

        return $content;
    }

    /**
     * Filter post excerpt
     */
    public static function filter_excerpt($excerpt) {
        if (is_admin()) {
            return $excerpt;
        }

        $post_id = get_the_ID();
        if (!$post_id) {
            return $excerpt;
        }

        $current_lang = self::get_current_language();
        $post_lang = PostEditor::get_post_language($post_id);

        // If post is in current language, return as-is
        if ($post_lang === $current_lang) {
            return $excerpt;
        }

        // Get translation
        $translation = PostEditor::get_post_translation($post_id, $current_lang);

        if (!empty($translation['post_excerpt'])) {
            return $translation['post_excerpt'];
        }

        return $excerpt;
    }

    /**
     * Filter permalink
     */
    public static function filter_permalink($permalink, $post) {
        if (is_admin()) {
            return $permalink;
        }

        $current_lang = self::get_current_language();
        $default_lang = Settings::get_default_language();

        // If on default language, return as-is
        if ($current_lang === $default_lang) {
            return $permalink;
        }

        $post_lang = PostEditor::get_post_language($post->ID);

        // Get translation slug
        $translation = PostEditor::get_post_translation($post->ID, $current_lang);

        if (!empty($translation['post_name'])) {
            // Replace slug in permalink
            $original_slug = $post->post_name;
            $translated_slug = $translation['post_name'];
            $permalink = str_replace('/' . $original_slug . '/', '/' . $translated_slug . '/', $permalink);
        }

        // Add language parameter
        $separator = (strpos($permalink, '?') !== false) ? '&' : '?';
        $permalink = $permalink . $separator . 'lang=' . $current_lang;

        return $permalink;
    }

    /**
     * Filter query
     *
     * Optionally filter posts by language
     */
    public static function filter_query($query) {
        // Only filter main query on frontend
        if (is_admin() || !$query->is_main_query()) {
            return;
        }

        // Check if language filtering is enabled in settings
        // For now, we don't filter - show all posts but display translated content
        // This can be made configurable later

        return;
    }

    /**
     * Filter term (category/tag)
     */
    public static function filter_term($term, $taxonomy) {
        if (is_admin()) {
            return $term;
        }

        $current_lang = self::get_current_language();
        $default_lang = Settings::get_default_language();

        // If on default language, return as-is
        if ($current_lang === $default_lang) {
            return $term;
        }

        // Get term translation
        global $wpdb;
        $table = $wpdb->prefix . 'stm_term_translations';

        $translation = $wpdb->get_row($wpdb->prepare(
            "SELECT name, slug, description FROM {$table} WHERE term_id = %d AND language_code = %s",
            $term->term_id,
            $current_lang
        ));

        if ($translation) {
            $term->name = $translation->name;
            $term->slug = $translation->slug;
            if ($translation->description) {
                $term->description = $translation->description;
            }
        }

        return $term;
    }
}
