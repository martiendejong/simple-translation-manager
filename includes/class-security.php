<?php
/**
 * Security Helper Class
 *
 * Centralized security functions for validation, sanitization, and authorization.
 *
 * @package SimpleTranslationManager
 */

namespace STM;

class Security {

    /**
     * Verify user can manage translations
     *
     * @return bool
     */
    public static function can_manage_translations() {
        return current_user_can('manage_options');
    }

    /**
     * Verify nonce and capability for admin actions
     *
     * @param string $nonce_name Nonce name
     * @param string $capability Required capability (default: manage_options)
     * @return bool
     */
    public static function verify_admin_action($nonce_name, $capability = 'manage_options') {
        // Check nonce
        if (!check_admin_referer($nonce_name)) {
            return false;
        }

        // Check capability
        if (!current_user_can($capability)) {
            return false;
        }

        return true;
    }

    /**
     * Validate language code
     *
     * Must be 2-3 letter code (ISO 639-1 or ISO 639-2)
     *
     * @param string $code Language code
     * @return bool
     */
    public static function validate_language_code($code) {
        return preg_match('/^[a-z]{2,3}$/i', $code);
    }

    /**
     * Validate translation key
     *
     * Allowed: alphanumeric, dots, underscores, hyphens
     * Format: section.subsection.key
     *
     * @param string $key Translation key
     * @return bool
     */
    public static function validate_translation_key($key) {
        return preg_match('/^[a-z0-9._-]+$/i', $key);
    }

    /**
     * Validate context name
     *
     * Allowed: alphanumeric, underscores, hyphens
     *
     * @param string $context Context name
     * @return bool
     */
    public static function validate_context($context) {
        return preg_match('/^[a-z0-9_-]+$/i', $context);
    }

    /**
     * Validate flag emoji
     *
     * Must be a valid Unicode emoji (regional indicator symbols)
     *
     * @param string $emoji Flag emoji
     * @return bool
     */
    public static function validate_flag_emoji($emoji) {
        // Regional indicator symbols (flags) are in range U+1F1E6 to U+1F1FF
        return preg_match('/^[\x{1F1E6}-\x{1F1FF}]{2}$/u', $emoji);
    }

    /**
     * Sanitize translation key
     *
     * Removes invalid characters, converts to lowercase
     *
     * @param string $key Translation key
     * @return string
     */
    public static function sanitize_translation_key($key) {
        // Convert to lowercase
        $key = strtolower($key);

        // Remove invalid characters
        $key = preg_replace('/[^a-z0-9._-]/', '', $key);

        // Remove consecutive dots
        $key = preg_replace('/\.+/', '.', $key);

        // Remove leading/trailing dots
        $key = trim($key, '.');

        return $key;
    }

    /**
     * Sanitize context name
     *
     * @param string $context Context name
     * @return string
     */
    public static function sanitize_context($context) {
        // Convert to lowercase
        $context = strtolower($context);

        // Remove invalid characters
        $context = preg_replace('/[^a-z0-9_-]/', '', $context);

        // Default to 'general' if empty
        return $context ?: 'general';
    }

    /**
     * Sanitize translation text
     *
     * Allows HTML tags that are safe for translation content
     *
     * @param string $text Translation text
     * @return string
     */
    public static function sanitize_translation($text) {
        // Allow basic formatting tags
        $allowed_tags = [
            'a' => ['href' => [], 'title' => [], 'target' => []],
            'strong' => [],
            'em' => [],
            'b' => [],
            'i' => [],
            'br' => [],
            'p' => [],
            'span' => ['class' => []],
        ];

        return wp_kses($text, $allowed_tags);
    }

    /**
     * Check if request is AJAX
     *
     * @return bool
     */
    public static function is_ajax_request() {
        return defined('DOING_AJAX') && DOING_AJAX;
    }

    /**
     * Send JSON error response (for AJAX)
     *
     * @param string $message Error message
     * @param int $code Error code
     */
    public static function ajax_error($message, $code = 400) {
        wp_send_json_error(['message' => $message], $code);
    }

    /**
     * Send JSON success response (for AJAX)
     *
     * @param mixed $data Response data
     */
    public static function ajax_success($data = null) {
        wp_send_json_success($data);
    }

    /**
     * Log security event (if WP_DEBUG enabled)
     *
     * @param string $message Log message
     * @param string $level Log level (error, warning, info)
     */
    public static function log($message, $level = 'info') {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf('[STM Security %s] %s', strtoupper($level), $message));
        }
    }
}
