<?php
/**
 * Standalone verification for task 869efjuhx: replace parse_url() with
 * wp_parse_url() in includes/class-language-switcher.php.
 *
 * Stubs a faithful wp_parse_url() (WP core's real behaviour for absolute
 * URLs with a scheme, which is the only shape get_language_url() ever
 * receives — its $base_url always comes from get_current_url(), which
 * prepends protocol + host) and reflection-invokes the private static
 * get_language_url() method to prove URL-routing output is unchanged
 * after the swap.
 *
 * Run: php tests/verify-869efjuhx-wp-parse-url.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);

if (!defined('ABSPATH'))        define('ABSPATH', '/tmp/wp/');
if (!defined('STM_VERSION'))    define('STM_VERSION', '1.1.1');
if (!defined('STM_PLUGIN_URL')) define('STM_PLUGIN_URL', 'http://example.test/wp-content/plugins/simple-translation-manager/');
if (!defined('STM_PLUGIN_DIR')) define('STM_PLUGIN_DIR', dirname(__DIR__) . '/');

// --- Minimal WP stubs needed to load class-language-switcher.php ----------

class WP_Widget {
    public function __construct($id_base, $name, $widget_options = [], $control_options = []) {}
}

function add_action() {}
function add_filter() {}
function add_shortcode() {}
function register_widget() {}
function wp_enqueue_style() {}

// Real WP core behaviour for absolute URLs (has a scheme): wp_parse_url()
// delegates straight to parse_url() and runs the result through a filter.
// The wrapper's extra normalization only matters for schemeless input,
// which get_language_url() never passes (base_url always comes from
// get_current_url(), which prepends protocol + HTTP_HOST).
function wp_parse_url($url, $component = -1) {
    $parts = parse_url($url, $component);
    return apply_filters('wp_parse_url', $parts, $url, $component);
}
function apply_filters($tag, $value, ...$args) { return $value; }

// class-settings.php's is_url_routing_enabled() reads get_option(); force
// the URL-routing branch (the one that calls parse_url/wp_parse_url) on.
function get_option($name, $default = false) { return true; }

require_once STM_PLUGIN_DIR . 'includes/class-settings.php';
require_once STM_PLUGIN_DIR . 'includes/class-language-switcher.php';

// --- Invoke the private static method via reflection -----------------------

$method = new ReflectionMethod('STM\\LanguageSwitcher', 'get_language_url');
$method->setAccessible(true);

$cases = [
    ['nl', 'http://example.test/products?id=1', 'http://example.test/nl/products?id=1'],
    ['en', 'http://example.test/',               'http://example.test/en/'],
    ['de', 'https://example.test/blog/post-1',   'https://example.test/de/blog/post-1'],
];

$pass = 0;
$fail = 0;
foreach ($cases as [$lang, $base, $expected]) {
    $actual = $method->invoke(null, $lang, $base);
    if ($actual === $expected) {
        echo "PASS  get_language_url('$lang', '$base') === '$expected'\n";
        $pass++;
    } else {
        echo "FAIL  get_language_url('$lang', '$base') => '$actual', expected '$expected'\n";
        $fail++;
    }
}

echo "----------------------------------------------------------------\n";
echo "Total: $pass pass, $fail fail\n";
exit($fail === 0 ? 0 : 1);
