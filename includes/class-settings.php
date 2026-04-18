<?php
/**
 * Settings Management Class
 *
 * Handles plugin settings and options.
 *
 * @package SimpleTranslationManager
 */

namespace STM;

class Settings {

    /**
     * Option names
     */
    const OPTION_DEFAULT_LANGUAGE   = 'stm_default_language';
    const OPTION_ENABLE_URL_ROUTING = 'stm_enable_url_routing';
    const OPTION_CACHE_DURATION     = 'stm_cache_duration';
    const OPTION_KEEP_DATA          = 'stm_keep_data_on_uninstall';
    const OPTION_DEBUG_MODE         = 'stm_debug_mode';

    // Language switcher
    const OPTION_SWITCHER_STYLE      = 'stm_switcher_style';
    const OPTION_SWITCHER_SHOW_FLAGS = 'stm_switcher_show_flags';
    const OPTION_SWITCHER_SHOW_NAMES = 'stm_switcher_show_names';
    const OPTION_SWITCHER_POSITION   = 'stm_switcher_position';

    /**
     * Get default language code
     *
     * @return string
     */
    public static function get_default_language() {
        $default = get_option(self::OPTION_DEFAULT_LANGUAGE);

        if (!$default) {
            // Find first language marked as default in database
            global $wpdb;
            $table = $wpdb->prefix . 'stm_languages';
            $default = $wpdb->get_var("SELECT code FROM {$table} WHERE is_default = 1 LIMIT 1");

            if (!$default) {
                // Fallback to first language
                $default = $wpdb->get_var("SELECT code FROM {$table} ORDER BY order_index ASC LIMIT 1");
            }

            // Cache it
            if ($default) {
                update_option(self::OPTION_DEFAULT_LANGUAGE, $default);
            }
        }

        return $default ?: 'en';
    }

    /**
     * Set default language
     *
     * @param string $lang_code Language code
     * @return bool
     */
    public static function set_default_language($lang_code) {
        if (!Security::validate_language_code($lang_code)) {
            return false;
        }

        return update_option(self::OPTION_DEFAULT_LANGUAGE, $lang_code);
    }

    /**
     * Check if URL routing is enabled
     *
     * @return bool
     */
    public static function is_url_routing_enabled() {
        return (bool) get_option(self::OPTION_ENABLE_URL_ROUTING, true);
    }

    /**
     * Enable/disable URL routing
     *
     * @param bool $enabled
     * @return bool
     */
    public static function set_url_routing($enabled) {
        return update_option(self::OPTION_ENABLE_URL_ROUTING, (bool) $enabled);
    }

    /**
     * Get cache duration in seconds
     *
     * @return int
     */
    public static function get_cache_duration() {
        return (int) get_option(self::OPTION_CACHE_DURATION, 3600); // Default: 1 hour
    }

    /**
     * Set cache duration
     *
     * @param int $seconds
     * @return bool
     */
    public static function set_cache_duration($seconds) {
        $seconds = max(0, (int) $seconds);
        return update_option(self::OPTION_CACHE_DURATION, $seconds);
    }

    /**
     * Check if data should be kept on uninstall
     *
     * @return bool
     */
    public static function keep_data_on_uninstall() {
        return (bool) get_option(self::OPTION_KEEP_DATA, false);
    }

    /**
     * Set keep data on uninstall
     *
     * @param bool $keep
     * @return bool
     */
    public static function set_keep_data_on_uninstall($keep) {
        return update_option(self::OPTION_KEEP_DATA, (bool) $keep);
    }

    /**
     * Check if debug mode is enabled
     *
     * @return bool
     */
    public static function is_debug_mode() {
        return (bool) get_option(self::OPTION_DEBUG_MODE, false);
    }

    /**
     * Enable/disable debug mode
     *
     * @param bool $enabled
     * @return bool
     */
    public static function set_debug_mode($enabled) {
        return update_option(self::OPTION_DEBUG_MODE, (bool) $enabled);
    }

    /**
     * Get all settings as array
     *
     * @return array
     */
    public static function get_all() {
        return [
            'default_language'      => self::get_default_language(),
            'enable_url_routing'    => self::is_url_routing_enabled(),
            'cache_duration'        => self::get_cache_duration(),
            'keep_data_on_uninstall'=> self::keep_data_on_uninstall(),
            'debug_mode'            => self::is_debug_mode(),
            'switcher_style'        => self::get_switcher_style(),
            'switcher_show_flags'   => self::switcher_show_flags(),
            'switcher_show_names'   => self::switcher_show_names(),
            'switcher_position'     => self::get_switcher_position(),
        ];
    }

    /**
     * Language switcher style — 'list', 'dropdown', 'buttons', 'flags'
     */
    public static function get_switcher_style() {
        return get_option(self::OPTION_SWITCHER_STYLE, 'list');
    }

    public static function set_switcher_style($style) {
        $allowed = ['list', 'dropdown', 'buttons', 'flags'];
        return update_option(self::OPTION_SWITCHER_STYLE, in_array($style, $allowed, true) ? $style : 'list');
    }

    /**
     * Show flag emoji in switcher
     */
    public static function switcher_show_flags() {
        return (bool) get_option(self::OPTION_SWITCHER_SHOW_FLAGS, true);
    }

    public static function set_switcher_show_flags($show) {
        return update_option(self::OPTION_SWITCHER_SHOW_FLAGS, (bool) $show);
    }

    /**
     * Show language name in switcher
     */
    public static function switcher_show_names() {
        return (bool) get_option(self::OPTION_SWITCHER_SHOW_NAMES, true);
    }

    public static function set_switcher_show_names($show) {
        return update_option(self::OPTION_SWITCHER_SHOW_NAMES, (bool) $show);
    }

    /**
     * Auto-inject position — 'none', 'before_content', 'after_content', 'both'
     */
    public static function get_switcher_position() {
        return get_option(self::OPTION_SWITCHER_POSITION, 'none');
    }

    public static function set_switcher_position($position) {
        $allowed = ['none', 'before_content', 'after_content', 'both'];
        return update_option(self::OPTION_SWITCHER_POSITION, in_array($position, $allowed, true) ? $position : 'none');
    }

    /**
     * Reset all settings to defaults
     *
     * @return bool
     */
    public static function reset_to_defaults() {
        delete_option(self::OPTION_DEFAULT_LANGUAGE);
        delete_option(self::OPTION_ENABLE_URL_ROUTING);
        delete_option(self::OPTION_CACHE_DURATION);
        delete_option(self::OPTION_KEEP_DATA);
        delete_option(self::OPTION_DEBUG_MODE);

        return true;
    }
}
