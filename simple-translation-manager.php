<?php
/**
 * Plugin Name: Simple Translation Manager
 * Plugin URI: https://martiendejong.nl
 * Description: Lightweight multilingual plugin with database storage and WordPress caching
 * Version: 1.2.0
 * Author: Martien de Jong
 * Author URI: https://martiendejong.nl
 * License: GPL v2 or later
 * Text Domain: simple-translation-manager
 * Requires at least: 5.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('STM_VERSION', '1.2.0');
define('STM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('STM_PLUGIN_URL', plugin_dir_url(__FILE__));
define('STM_PLUGIN_FILE', __FILE__);

// Autoloader (simple version for now)
spl_autoload_register(function($class) {
    if (strpos($class, 'STM\\') !== 0) {
        return;
    }

    $file = STM_PLUGIN_DIR . 'includes/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// Core includes
require_once STM_PLUGIN_DIR . 'includes/functions.php';
require_once STM_PLUGIN_DIR . 'includes/class-security.php';
require_once STM_PLUGIN_DIR . 'includes/class-settings.php';
require_once STM_PLUGIN_DIR . 'includes/class-database.php';
require_once STM_PLUGIN_DIR . 'includes/class-string-scanner.php';
require_once STM_PLUGIN_DIR . 'includes/class-cache.php';
require_once STM_PLUGIN_DIR . 'includes/class-admin.php';
require_once STM_PLUGIN_DIR . 'includes/class-api.php';
require_once STM_PLUGIN_DIR . 'includes/class-post-editor.php';
require_once STM_PLUGIN_DIR . 'includes/class-frontend.php';
require_once STM_PLUGIN_DIR . 'includes/class-language-switcher.php';
require_once STM_PLUGIN_DIR . 'includes/class-import-export.php';
require_once STM_PLUGIN_DIR . 'includes/class-translation-memory.php';
require_once STM_PLUGIN_DIR . 'includes/class-auto-translate.php';
require_once STM_PLUGIN_DIR . 'includes/class-dashboard.php';
require_once STM_PLUGIN_DIR . 'includes/class-hreflang.php';
require_once STM_PLUGIN_DIR . 'includes/class-seo-god-integration.php';
require_once STM_PLUGIN_DIR . 'includes/class-elementor-integration.php';

// WP-CLI commands (only loaded if WP-CLI is available)
if (defined('WP_CLI') && WP_CLI) {
    require_once STM_PLUGIN_DIR . 'includes/class-cli.php';
}

/**
 * Plugin activation
 */
function stm_activate() {
    STM\Database::create_tables();
    STM\Database::seed_default_languages();
    STM\StringScanner::scan_and_register();
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'stm_activate');

/**
 * Plugin deactivation
 */
function stm_deactivate() {
    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'stm_deactivate');

/**
 * Inject language-prefixed rewrite rules for every non-default language.
 *
 * For each existing WordPress rewrite rule we add a clone prefixed with the
 * language code, e.g.:
 *   topic/([^/]+)/?$  →  fr/topic/([^/]+)/?$  (with &lang=fr appended)
 *
 * This means /fr/topic/valsuani/ is served directly by WordPress as the
 * French version of that post — no redirect, fully indexable by Google.
 *
 * Call flush_rewrite_rules() after enabling/disabling a language for the
 * new rules to take effect (activation/deactivation hooks already do this).
 */
function stm_rewrite_rules_array( array $rules ): array {
    if ( ! STM\Settings::is_url_routing_enabled() ) {
        return $rules;
    }

    $languages = STM\Database::get_languages();
    $default   = STM\Settings::get_default_language();
    $prefixed  = [];

    foreach ( $languages as $lang ) {
        if ( $lang->code === $default ) {
            continue;
        }

        $code = preg_quote( $lang->code, '#' );

        foreach ( $rules as $pattern => $rewrite ) {
            // Strip the leading ^ that WordPress adds — we re-add it with the prefix
            $clean   = ltrim( $pattern, '^' );
            $sep     = ( strpos( $rewrite, '?' ) !== false ) ? '&' : '?';
            $prefixed[ '^' . $code . '/' . $clean ] = $rewrite . $sep . 'lang=' . $lang->code;
        }

        // Root URL for this language: /fr/ → homepage with lang=fr
        $prefixed[ '^' . $code . '/?$' ] = 'index.php?lang=' . $lang->code;
    }

    // Prepend so language rules take priority over default rules
    return array_merge( $prefixed, $rules );
}
add_filter( 'rewrite_rules_array', 'stm_rewrite_rules_array' );

/**
 * Register query vars
 */
function stm_query_vars($vars) {
    $vars[] = 'lang';
    return $vars;
}
add_filter('query_vars', 'stm_query_vars');

/**
 * Initialize plugin
 */
function stm_init() {
    STM\Admin::init();
    STM\API::init();
    STM\PostEditor::init();
    STM\Frontend::init();
    STM\LanguageSwitcher::init();
    STM\ImportExport::init();
    STM\TranslationMemory::init();
    STM\AutoTranslate::init();
    STM\Dashboard::init();
    STM\Hreflang::init();
    STM\SeoGodIntegration::init();
    STM\ElementorIntegration::init();
}
add_action('plugins_loaded', 'stm_init');
