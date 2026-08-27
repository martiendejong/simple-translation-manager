<?php
/**
 * Plugin Name: Simple Translation Manager
 * Plugin URI: https://martiendejong.nl
 * Description: Lightweight multilingual plugin with database storage and WordPress caching
 * Version: 1.3.1
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
define('STM_VERSION', '1.3.1');
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
require_once STM_PLUGIN_DIR . 'includes/class-field-values.php';
require_once STM_PLUGIN_DIR . 'includes/class-post-editor.php';
require_once STM_PLUGIN_DIR . 'includes/class-frontend.php';
require_once STM_PLUGIN_DIR . 'includes/class-language-switcher.php';
require_once STM_PLUGIN_DIR . 'includes/class-import-export.php';
require_once STM_PLUGIN_DIR . 'includes/class-translation-memory.php';
require_once STM_PLUGIN_DIR . 'includes/class-auto-translate.php';
require_once STM_PLUGIN_DIR . 'includes/class-dashboard.php';
require_once STM_PLUGIN_DIR . 'includes/class-hreflang.php';
require_once STM_PLUGIN_DIR . 'includes/class-sitemap.php';
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
 * Strip a leading language segment (e.g. "en/") off the raw request path
 * before any other plugin's own `request` filter tries to resolve that path
 * itself.
 *
 * Other plugins that own their own URL structure (a CPT plugin resolving
 * /{location}/{offering}/ style paths, for example) typically read the raw
 * $wp->request path directly rather than the query vars WordPress's rewrite
 * engine produced, because their URL shape isn't expressed as a registered
 * rewrite rule. Left untouched, such a plugin sees the language segment as
 * the first path segment of its own routing scheme, fails to resolve it, and
 * WordPress falls back to a 404 (guessed back to the default-language URL).
 *
 * Running at priority 1 — the earliest 'request' filters run — guarantees
 * this stripping happens before any such plugin's own request-based routing,
 * regardless of plugin load order. It also records the detected language on
 * the query vars so it is available even when no rewrite rule ever matched
 * this path (which is exactly the case for the plugins this exists for).
 */
function stm_strip_lang_prefix_from_request( $qv ) {
    if ( is_admin() || ! STM\Settings::is_url_routing_enabled() || empty( $GLOBALS['wp'] ) ) {
        return $qv;
    }

    $request = (string) $GLOBALS['wp']->request;
    if ( '' === $request ) {
        return $qv;
    }

    $segments   = explode( '/', $request );
    $maybe_lang = $segments[0];
    $default    = STM\Settings::get_default_language();

    if ( '' === $maybe_lang || $maybe_lang === $default ) {
        return $qv;
    }

    $active_codes = wp_list_pluck( STM\Database::get_languages(), 'code' );
    if ( ! in_array( $maybe_lang, $active_codes, true ) ) {
        return $qv;
    }

    array_shift( $segments );
    $GLOBALS['wp']->request = implode( '/', $segments );
    $qv['lang']             = $maybe_lang;

    return $qv;
}
add_filter( 'request', 'stm_strip_lang_prefix_from_request', 1 );

/**
 * Voorpagina-fix voor de taalwissel: /en/ en /?lang=en zetten alleen de
 * lang-query-var. WordPress past de statische-voorpagina-substitutie alleen
 * toe op een "lege" request, dus met lang erin valt de main query terug op de
 * blogindex (de "Updates"-lijst) in plaats van de homepage. Als lang de enige
 * betekenisvolle var is, laden we expliciet de voorpagina; lang blijft staan
 * zodat de taalbepaling (Frontend::get_current_language) blijft werken.
 *
 * Alleen van toepassing als er ook echt geen pad meer over is na het strippen
 * van het taalsegment (stm_strip_lang_prefix_from_request hierboven) — een
 * ander plugin dat zijn eigen resterende pad nog moet oplossen (bv. een
 * locatie-/aanbodpagina) mag hier niet door overschreven worden met de
 * voorpagina.
 */
function stm_front_page_request( $qv ) {
    if ( ! isset( $qv['lang'] ) || ! empty( $GLOBALS['wp']->request ) ) {
        return $qv;
    }

    $ignorable  = array( 'lang', 'preview', 'page', 'paged', 'cpage' );
    $meaningful = array_diff_key( $qv, array_flip( $ignorable ) );

    if ( empty( $meaningful ) && 'page' === get_option( 'show_on_front' ) && get_option( 'page_on_front' ) ) {
        $qv['page_id'] = (int) get_option( 'page_on_front' );
    }

    return $qv;
}
add_filter( 'request', 'stm_front_page_request' );

/**
 * Prevent WordPress from stripping language URL prefixes via redirect_canonical.
 *
 * When URL routing is on, /en/t-zwaantje/ is a valid URL for the English
 * version of that page. Without this hook WordPress's canonical redirect
 * removes the /en/ prefix on every request, sending visitors back to the
 * default-language URL and discarding the language context.
 */
function stm_prevent_lang_canonical_redirect( $redirect_url, $requested_url ) {
    if ( ! STM\Settings::is_url_routing_enabled() ) {
        return $redirect_url;
    }

    $path = parse_url( $requested_url, PHP_URL_PATH );
    if ( ! $path ) {
        return $redirect_url;
    }

    $default = STM\Settings::get_default_language();

    foreach ( STM\Database::get_languages() as $lang ) {
        if ( $lang->code === $default ) {
            continue;
        }
        $prefix = '/' . $lang->code;
        if ( $path === $prefix || $path === $prefix . '/' || 0 === strpos( $path, $prefix . '/' ) ) {
            return false;
        }
    }

    return $redirect_url;
}
add_filter( 'redirect_canonical', 'stm_prevent_lang_canonical_redirect', 10, 2 );

/**
 * Schema upgrades on plugin update without reactivation (e.g. FTP deploys).
 * Runs before stm_init so new tables exist when components initialize.
 */
add_action('plugins_loaded', ['STM\\Database', 'maybe_upgrade'], 5);

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
    STM\Sitemap::init();
    STM\SeoGodIntegration::init();
    STM\ElementorIntegration::init();
}
add_action('plugins_loaded', 'stm_init');

