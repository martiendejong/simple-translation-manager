<?php
/**
 * Template Functions
 *
 * These are the public API functions that themes use
 */

if (!function_exists('stm_get_current_language')) {
    /**
     * Get current language code
     *
     * Detection order:
     * 1. URL path (/nl/, /en/)
     * 2. URL parameter (?lang=nl)
     * 3. Session
     * 4. Cookie
     * 5. Default language
     *
     * @return string Language code (e.g., 'en', 'nl')
     */
    function stm_get_current_language() {
        // Check URL path
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        if (preg_match('#^/([a-z]{2})(/|$|\?)#', $request_uri, $matches)) {
            $lang = $matches[1];
            $_SESSION['stm_lang'] = $lang;
            setcookie('stm_lang', $lang, time() + (86400 * 30), '/');
            return $lang;
        }

        // Check URL parameter
        if (isset($_GET['lang']) && strlen($_GET['lang']) === 2) {
            $lang = sanitize_text_field($_GET['lang']);
            $_SESSION['stm_lang'] = $lang;
            setcookie('stm_lang', $lang, time() + (86400 * 30), '/');
            return $lang;
        }

        // Check session
        if (isset($_SESSION['stm_lang'])) {
            return $_SESSION['stm_lang'];
        }

        // Check cookie
        if (isset($_COOKIE['stm_lang'])) {
            return sanitize_text_field($_COOKIE['stm_lang']);
        }

        // Default language
        $default = STM\Database::get_default_language();
        return $default ? $default->code : 'en';
    }
}

if (!function_exists('__stm')) {
    /**
     * Get translation for template string
     *
     * @param string $key Translation key (e.g., 'nav.home')
     * @param string $fallback Fallback value if translation not found
     * @param string $context Context for disambiguation (default: 'general')
     * @return string Translated string
     */
    function __stm($key, $fallback = '', $context = 'general') {
        $lang = stm_get_current_language();
        $translation = STM\Cache::get_translation($key, $lang, $context);

        if ($translation) {
            return $translation;
        }

        // Fallback
        return $fallback ?: $key;
    }
}

if (!function_exists('_e_stm')) {
    /**
     * Echo translation
     *
     * @param string $key Translation key
     * @param string $fallback Fallback value
     * @param string $context Context
     */
    function _e_stm($key, $fallback = '', $context = 'general') {
        echo __stm($key, $fallback, $context);
    }
}

if (!function_exists('stm_get_post_translation')) {
    /**
     * Get translation for post/page field
     *
     * @param int $post_id Post ID (defaults to current post)
     * @param string $field Field name (e.g., 'title', 'excerpt', 'description')
     * @param string $lang Language code (defaults to current language)
     * @param mixed $fallback Fallback value if translation not found
     * @return string Translated content
     */
    function stm_get_post_translation($post_id = null, $field = 'title', $lang = null, $fallback = '') {
        if (!$post_id) {
            $post_id = get_the_ID();
        }

        if (!$lang) {
            $lang = stm_get_current_language();
        }

        $translation = STM\Cache::get_post_translation($post_id, $field, $lang);

        if ($translation) {
            return $translation;
        }

        // Auto-fallback based on field
        if ($fallback) {
            return $fallback;
        }

        switch ($field) {
            case 'title':
                return get_the_title($post_id);
            case 'excerpt':
                return get_the_excerpt($post_id);
            case 'content':
                return get_post_field('post_content', $post_id);
            default:
                return get_post_meta($post_id, $field, true);
        }
    }
}

if (!function_exists('stm_get_languages')) {
    /**
     * Get all active languages
     *
     * @return array Array of language objects
     */
    function stm_get_languages() {
        return STM\Database::get_languages();
    }
}

if (!function_exists('stm_language_switcher')) {
    /**
     * Display language switcher
     *
     * @param string $format 'dropdown', 'list', or 'flags'
     * @param array $args Additional arguments
     */
    function stm_language_switcher($format = 'dropdown', $args = []) {
        $languages = stm_get_languages();
        $current_lang = stm_get_current_language();
        $current_url = strtok($_SERVER['REQUEST_URI'], '?');

        // Remove language prefix from URL
        $clean_url = preg_replace('#^/[a-z]{2}(/|$)#', '/', $current_url);

        switch ($format) {
            case 'dropdown':
                echo '<select id="stm-lang-switcher" onchange="window.location.href=this.value">';
                foreach ($languages as $lang) {
                    $url = '/' . $lang->code . $clean_url;
                    $selected = ($lang->code === $current_lang) ? 'selected' : '';
                    echo '<option value="' . esc_url($url) . '" ' . $selected . '>';
                    echo esc_html($lang->native_name);
                    echo '</option>';
                }
                echo '</select>';
                break;

            case 'flags':
                echo '<div class="stm-flag-switcher">';
                foreach ($languages as $lang) {
                    $url = '/' . $lang->code . $clean_url;
                    $active = ($lang->code === $current_lang) ? 'active' : '';
                    echo '<a href="' . esc_url($url) . '" class="' . $active . '" title="' . esc_attr($lang->name) . '">';
                    echo esc_html($lang->flag_emoji);
                    echo '</a>';
                }
                echo '</div>';
                break;

            case 'list':
            default:
                echo '<ul class="stm-lang-list">';
                foreach ($languages as $lang) {
                    $url = '/' . $lang->code . $clean_url;
                    $active = ($lang->code === $current_lang) ? 'active' : '';
                    echo '<li class="' . $active . '">';
                    echo '<a href="' . esc_url($url) . '">' . esc_html($lang->native_name) . '</a>';
                    echo '</li>';
                }
                echo '</ul>';
                break;
        }
    }
}
