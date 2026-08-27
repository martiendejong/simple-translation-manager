<?php
/**
 * Automated verification for task 869enmhwe follow-up — the translate/auto
 * REST handlers silently defaulted to source_lang='en'/target_lang='nl'
 * whenever a caller sent the more conventional source_language/
 * target_language spelling instead of the endpoint's documented
 * source_lang/target_lang keys. That silent default produced a "the
 * translation equals the input, still in the original language" result
 * indistinguishable at first glance from the original 869enmhwe
 * translation-memory bug — confirmed during a 2026-08-23 live re-test that
 * bounced this task back to `todo` even though the original memory fix
 * (verify-869enmhwe-translation-memory-field-scope.php) was, and remains,
 * fully correct.
 *
 * Run from the plugin root:   php tests/verify-869enmhwe-lang-param-alias.php
 */

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line: php tests/verify-869enmhwe-lang-param-alias.php\n");
}

define('ABSPATH', __DIR__ . '/');
if (!defined('WPINC')) {
    define('WPINC', 'wp-includes');
}
if (!defined('WP_CLI')) {
    define('WP_CLI', false);
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
if (!defined('STM_TEST_BASE_URL')) {
    define('STM_TEST_BASE_URL', getenv('WP_TEST_URL') ?: 'http://localhost/');
}

// --- WordPress stubs -----------------------------------------------------

function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {}
function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {}
function register_activation_hook($file, $callback) {}
function register_deactivation_hook($file, $callback) {}
function register_rest_route(...$args) {}

function plugin_dir_path($f) { return dirname($f) . '/'; }
function plugin_dir_url($f)  { return STM_TEST_BASE_URL . 'wp-content/plugins/simple-translation-manager/'; }

function is_admin() { return false; }
function sanitize_text_field($s) { return is_string($s) ? trim($s) : ''; }
function current_time($type) { return '2026-08-23 12:00:00'; }

$GLOBALS['stm_options'] = [];
function get_option($name, $default = false) { return $GLOBALS['stm_options'][$name] ?? $default; }
function update_option($name, $value)        { $GLOBALS['stm_options'][$name] = $value; return true; }

function wp_cache_get($key, $group = '')             { return false; }
function wp_cache_set($key, $data, $group = '', $e = 0) {}

class WP_Widget {}
class WP_Sitemaps_Provider {}

$pluginRoot = dirname(__DIR__);
$pluginFile = $pluginRoot . '/simple-translation-manager.php';
require_once $pluginFile;

// --- Assertions ------------------------------------------------------------

$results = [];
function assert_same($name, $expected, $actual) {
    global $results;
    $ok = $expected === $actual;
    $detail = $ok ? '' : "expected " . var_export($expected, true) . ", got " . var_export($actual, true);
    $results[] = ['name' => $name, 'pass' => $ok, 'detail' => $detail];
}

// 1. Documented keys (source_lang/target_lang) resolve as-is — the normal,
//    already-correct call shape used by admin-post-editor.js and admin.js.
assert_same(
    'source_lang/target_lang resolve directly',
    ['nl', 'en'],
    STM\AutoTranslate::resolve_lang_params(['source_lang' => 'nl', 'target_lang' => 'en'])
);

// 2. The alias spelling (source_language/target_language) must resolve to
//    the SAME values — this is the exact shape that silently fell back to
//    the 'en'/'nl' defaults before this fix and produced the false-positive
//    "auto-translate broken" report during the 2026-08-23 live re-test.
assert_same(
    'source_language/target_language alias resolves the same as source_lang/target_lang',
    ['nl', 'en'],
    STM\AutoTranslate::resolve_lang_params(['source_language' => 'nl', 'target_language' => 'en'])
);

// 3. Neither key present still falls back to the documented en/nl defaults
//    (unchanged behaviour for a caller that omits language params entirely).
assert_same(
    'missing params fall back to en/nl defaults',
    ['en', 'nl'],
    STM\AutoTranslate::resolve_lang_params([])
);

// 4. If both spellings are present, the documented short key wins (it is
//    the one every real in-repo caller sends; the alias is a fallback,
//    not an override).
assert_same(
    'source_lang takes precedence over source_language when both are present',
    ['nl', 'en'],
    STM\AutoTranslate::resolve_lang_params([
        'source_lang' => 'nl', 'source_language' => 'de',
        'target_lang' => 'en', 'target_language' => 'fr',
    ])
);

// --- Report ------------------------------------------------------------

$pass = 0; $fail = 0;
echo "translate/auto language-param alias verification (task 869enmhwe follow-up)\n";
echo str_repeat('=', 72) . "\n";
foreach ($results as $r) {
    $mark = $r['pass'] ? 'PASS' : 'FAIL';
    if ($r['pass']) $pass++; else $fail++;
    printf("%-5s %s\n", $mark, $r['name']);
    if (!$r['pass'] && $r['detail']) {
        echo "      -> " . $r['detail'] . "\n";
    }
}
echo str_repeat('-', 72) . "\n";
echo "Total: $pass pass, $fail fail (" . count($results) . " assertions)\n";
exit($fail === 0 ? 0 : 1);
