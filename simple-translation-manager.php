<?php
/**
 * Plugin Name: Simple Translation Manager
 * Plugin URI: https://martiendejong.nl
 * Description: Lightweight multilingual plugin with database storage and WordPress caching
 * Version: 1.1.1
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
define('STM_VERSION', '1.1.1');
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
 * Handle language URL redirects
 */
function stm_template_redirect() {
    if (!STM\Settings::is_url_routing_enabled()) {
        return;
    }

    $request_uri = $_SERVER['REQUEST_URI'];

    // Require /{lang}/ with a subsequent path to avoid hijacking bare 2-letter page slugs (e.g. /de, /nl)
    if (!preg_match('#^/([a-z]{2,3})/(.*)$#', $request_uri, $matches)) {
        return;
    }

    $lang_code = $matches[1];
    $path = '/' . $matches[2];

    $languages = STM\Database::get_languages();
    $valid_codes = array_map(function($lang) { return $lang->code; }, $languages);

    if (!in_array($lang_code, $valid_codes)) {
        return;
    }

    if ($path === '/') {
        setcookie('stm_lang', $lang_code, time() + (86400 * 30), '/');
        $redirect_url = home_url('/');
    } else {
        $redirect_url = home_url($path . '?lang=' . $lang_code);
    }

    wp_safe_redirect($redirect_url, 301);
    exit;
}
add_action('template_redirect', 'stm_template_redirect', 1);

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
    // Initialize components
    STM\Admin::init();
    STM\API::init();
    STM\PostEditor::init();
    STM\Frontend::init();
    STM\LanguageSwitcher::init();
    STM\ImportExport::init();
    STM\TranslationMemory::init();
    STM\AutoTranslate::init();
    STM\Dashboard::init();
}
add_action('plugins_loaded', 'stm_init');
