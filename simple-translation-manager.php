<?php
/**
 * Plugin Name: Simple Translation Manager
 * Plugin URI: https://martiendejong.nl
 * Description: Lightweight multilingual plugin with database storage and WordPress caching
 * Version: 1.0.0
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
define('STM_VERSION', '1.0.0');
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
require_once STM_PLUGIN_DIR . 'includes/class-database.php';
require_once STM_PLUGIN_DIR . 'includes/class-cache.php';
require_once STM_PLUGIN_DIR . 'includes/class-admin.php';
require_once STM_PLUGIN_DIR . 'includes/class-api.php';

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
 * Initialize plugin
 */
function stm_init() {
    // Start session for language switching
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Initialize components
    STM\Admin::init();
    STM\API::init();
}
add_action('plugins_loaded', 'stm_init');
