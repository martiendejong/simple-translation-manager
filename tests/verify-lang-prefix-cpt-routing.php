<?php
/**
 * Automated verification for task 869ecwc03 — path-prefixed language URLs
 * (/en/, /de/) on pages owned by another plugin's own routing (locations,
 * offerings, the homepage), instead of falling back to the default language.
 *
 * Run from the plugin root:   php tests/verify-lang-prefix-cpt-routing.php
 *
 * Stubs the WordPress hook/DB API (no install or database required) and
 * requires the real main plugin file, so the actual registered `request`
 * filters are exercised end to end — a regression here fails a real
 * assertion instead of only "looking right" on read-through:
 *
 *   1. stm_strip_lang_prefix_from_request() strips a recognised, non-default
 *      language segment off the raw $wp->request path *before* a mock
 *      third-party plugin's own request-based routing (modelled on
 *      visit-platform's Permalinks::resolve(), which reads $wp->request
 *      directly rather than the query vars) gets a chance to run — this is
 *      the actual bug: without the strip, such a plugin sees the language
 *      segment as the first segment of its own routing scheme and fails.
 *   2. It leaves the request alone for the default language, an unknown
 *      first segment (ordinary content), and admin/URL-routing-disabled
 *      contexts — the existing ?lang=, cookie and /en/aanbod/ routes.
 *   3. It also matches the single-segment homepage case (/en/ -> "").
 *   4. stm_front_page_request() only substitutes the front page when the
 *      request path is genuinely empty after stripping — it must not steal
 *      a request a CPT-routing plugin still needs to resolve.
 *   5. stm_prevent_lang_canonical_redirect() still blocks WordPress's own
 *      canonical redirect for a language-prefixed path (unchanged by this
 *      task, asserted here as a regression guard since it is load-bearing
 *      for the same done-when criteria).
 *   6. Round-2 regression (task 869eec0wf, section 1b below):
 *      STM\Frontend::resolve_translated_slug_request() — registered at
 *      priority 2, right after the strip above — rewrites $wp->request
 *      itself when a path segment is a translated slug, so the SAME mock
 *      third-party plugin resolves it via its real post_name instead of
 *      404ing. Live-confirmed bug: /en/{translated-slug}/ 404'd on
 *      test.portofgiethoorn.com while /en/{real-slug}/ worked, because
 *      visit-platform's own routing never heard about the translation.
 */

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line: php tests/verify-lang-prefix-cpt-routing.php\n");
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

// --- Fixtures ----------------------------------------------------------
//
// Default language 'nl', two path-prefixed languages 'en' and 'de' — mirrors
// the ticket's own examples (/en/de-dames-van-de-jonge/, /de/...).

$GLOBALS['stm_languages'] = [
    (object) [ 'code' => 'nl', 'is_active' => 1, 'order_index' => 0 ],
    (object) [ 'code' => 'en', 'is_active' => 1, 'order_index' => 1 ],
    (object) [ 'code' => 'de', 'is_active' => 1, 'order_index' => 2 ],
];

$GLOBALS['stm_options'] = [
    'stm_default_language'    => 'nl',
    'stm_enable_url_routing'  => true,
];

$GLOBALS['stm_is_admin'] = false;

// A location post with a translated 'en' slug — for the round-2 (869eec0wf)
// cross-plugin bypass scenario below: the mock CPT plugin must see the REAL
// post_name, not the translated one, once STM's own reverse lookup has run.
$GLOBALS['stm_posts'] = [
    99 => (object) [ 'ID' => 99, 'post_name' => 'de-dames-van-de-jonge' ],
];
$GLOBALS['stm_post_translations'] = [
    [ 'post_id' => 99, 'field_name' => 'post_name', 'language_code' => 'en', 'translation' => 'the-young-ladies' ],
];

// --- WordPress stubs -----------------------------------------------------

$GLOBALS['stm_filters'] = [];

function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {}
function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
    $GLOBALS['stm_filters'][$hook][] = [
        'cb'       => $callback,
        'priority' => $priority,
        'order'    => count($GLOBALS['stm_filters'][$hook] ?? []),
    ];
}
function apply_filters($hook, $value, ...$more) {
    $filters = $GLOBALS['stm_filters'][$hook] ?? [];
    usort($filters, function ($a, $b) {
        return $a['priority'] <=> $b['priority'] ?: $a['order'] <=> $b['order'];
    });
    foreach ($filters as $f) {
        $value = call_user_func($f['cb'], $value, ...$more);
    }
    return $value;
}
function has_filter($hook, $cb = false) { return !empty($GLOBALS['stm_filters'][$hook]); }
function remove_filter($hook, $callback, $priority = 10) { return false; }
function register_activation_hook($file, $callback) {}
function register_deactivation_hook($file, $callback) {}

function plugin_dir_path($f) { return dirname($f) . '/'; }
function plugin_dir_url($f)  { return STM_TEST_BASE_URL . 'wp-content/plugins/simple-translation-manager/'; }

function is_admin()              { return $GLOBALS['stm_is_admin']; }
function sanitize_text_field($s) { return is_string($s) ? trim($s) : ''; }
function home_url($p = '')       { return 'https://example.test' . $p; }
function trailingslashit($s)     { return rtrim($s, '/') . '/'; }
function wp_list_pluck($list, $field) { return array_map(fn($o) => is_object($o) ? $o->$field : $o[$field], $list); }
function wp_cache_get($key, $group = '')             { return false; }
function wp_cache_set($key, $data, $group = '', $e = 0) {}
function get_option($name, $default = false) { return $GLOBALS['stm_options'][$name] ?? $default; }
function update_option($name, $value)        { $GLOBALS['stm_options'][$name] = $value; return true; }
function get_post($post) {
    if (is_object($post)) {
        return $post;
    }
    return $GLOBALS['stm_posts'][(int) $post] ?? null;
}
function get_post_types($args = [], $output = 'names') {
    // No public post type owns 'name'/'pagename' in this fixture — the mock
    // CPT plugin below resolves entirely off the raw $wp->request path, not
    // a registered query_var, exactly like the real VISIT_Permalinks does.
    return $output === 'objects' ? [] : [];
}

// Minimal base classes so class-language-switcher.php / class-sitemap.php
// can be declared (never instantiated in this test).
class WP_Widget {}
class WP_Sitemaps_Provider {}

// Minimal $wpdb stub backed by the fixtures above — only what
// Database::get_languages() and Settings::get_default_language() touch.
$wpdb = new class {
    public $prefix = 'wp_';

    public function get_results($query, $output = ARRAY_A) {
        if (strpos($query, 'stm_languages') !== false) {
            return array_values(array_filter($GLOBALS['stm_languages'], fn($l) => (int) $l->is_active === 1));
        }
        return [];
    }

    public function get_var($query) {
        [$sql, $argsJson] = array_pad(explode('::', $query, 2), 2, '[]');
        $args = json_decode($argsJson, true) ?: [];

        if (strpos($sql, 'stm_post_translations') !== false && strpos($sql, 'SELECT post_id') !== false) {
            [$language_code, $slug] = array_pad($args, 2, null);
            foreach ($GLOBALS['stm_post_translations'] ?? [] as $row) {
                if ($row['field_name'] === 'post_name' && $row['language_code'] === $language_code && $row['translation'] === $slug) {
                    return $row['post_id'];
                }
            }
        }

        return null;
    }
    public function prepare($query, ...$args) { return $query . '::' . json_encode($args); }
};

// $wp global — the raw-path property both the fix and the mock third-party
// plugin below read/mutate, exactly like WordPress's own $wp->request.
$GLOBALS['wp'] = new class { public $request = ''; };

// --- Load the real plugin code -------------------------------------------

$pluginRoot = dirname(__DIR__);
$pluginFile = $pluginRoot . '/simple-translation-manager.php';
require_once $pluginFile;

// stm_init() (real registration of STM\Frontend's own filters, including the
// round-2 fix below) only ever runs via add_action('plugins_loaded', ...),
// which this file's add_action() stub deliberately no-ops — so call it
// directly, exactly like a real 'plugins_loaded' firing would.
\STM\Frontend::init();

// --- A mock third-party plugin, modelled on visit-platform's own
//     Permalinks::resolve(): it owns URL segments WordPress's rewrite engine
//     never registers a rule for, so it reads $wp->request directly instead
//     of the query vars. Registered at the default priority (10) — same as
//     the real VISIT_Permalinks::resolve(). If stm_strip_lang_prefix_from_request
//     (priority 1) is not running first, this sees the language segment as
//     its own first path segment and fails to resolve, which is the actual
//     bug this task fixes.
$GLOBALS['stm_mock_locations'] = [ 'de-dames-van-de-jonge' ];
function stm_mock_cpt_resolve($qv) {
    $request = (string) $GLOBALS['wp']->request;
    if ('' === $request) {
        return $qv;
    }
    $segments = explode('/', $request);
    if (in_array($segments[0], $GLOBALS['stm_mock_locations'], true)) {
        $qv['stm_mock_location'] = $segments[0];
    }
    return $qv;
}
add_filter('request', 'stm_mock_cpt_resolve', 10);

// --- Assertions ------------------------------------------------------------

$results = [];
function assert_same($name, $expected, $actual) {
    global $results;
    $ok = $expected === $actual;
    $detail = $ok ? '' : "expected " . var_export($expected, true) . ", got " . var_export($actual, true);
    $results[] = [ 'name' => $name, 'pass' => $ok, 'detail' => $detail ];
}
function assert_true($name, $cond, $detail = '') {
    global $results;
    $results[] = [ 'name' => $name, 'pass' => (bool) $cond, 'detail' => $detail ];
}

function run_request($path) {
    $GLOBALS['wp']->request = $path;
    return apply_filters('request', []);
}

// 1. The actual bug: /en/de-dames-van-de-jonge/ must resolve to the mock
//    plugin's own location routing, not get swallowed by the language prefix.
$qv = run_request('en/de-dames-van-de-jonge');
assert_same(
    'strip-then-resolve: mock CPT plugin resolves the location once the /en/ prefix is stripped',
    'de-dames-van-de-jonge',
    $qv['stm_mock_location'] ?? null
);
assert_same(
    'strip-then-resolve: $wp->request no longer carries the language segment',
    'de-dames-van-de-jonge',
    $GLOBALS['wp']->request
);
assert_same(
    'strip-then-resolve: lang recorded on query vars even though no rewrite rule ever matched this path',
    'en',
    $qv['lang'] ?? null
);

// Same for /de/...
$qv = run_request('de/de-dames-van-de-jonge');
assert_same(
    'same fix works for /de/ — not hardcoded to English',
    'de-dames-van-de-jonge',
    $qv['stm_mock_location'] ?? null
);

// 1b. Round-2 regression (task 869eec0wf): the visitor's URL carries a
//     TRANSLATED slug ('the-young-ladies'), not the post's real post_name
//     ('de-dames-van-de-jonge'). The mock CPT plugin only knows the real
//     slug (mirrors visit-platform's own DB, which stores real post_names).
//     STM\Frontend::resolve_translated_slug_request() (priority 2 — see
//     class-frontend.php) must rewrite $wp->request itself, before the mock
//     plugin's own priority-10 lookup ever runs, or this 404s exactly like
//     it did live on test.portofgiethoorn.com.
$qv = run_request('en/the-young-ladies');
assert_same(
    'round-2 fix: mock CPT plugin resolves the location via its translated slug once $wp->request is rewritten back to the real post_name',
    'de-dames-van-de-jonge',
    $qv['stm_mock_location'] ?? null
);
assert_same(
    'round-2 fix: $wp->request itself carries the real post_name, not the translated slug, by the time the mock plugin reads it',
    'de-dames-van-de-jonge',
    $GLOBALS['wp']->request
);

// 2. Homepage: /en/ alone strips to an empty path and stm_front_page_request
//    substitutes the front page — it must not fire when a CPT plugin still
//    has a real path left to resolve (case 1 above).
$qv = run_request('en');
assert_same(
    'homepage: /en/ strips to an empty request path',
    '',
    $GLOBALS['wp']->request
);
assert_same(
    'homepage: no CPT match leaks through for a bare language prefix',
    null,
    $qv['stm_mock_location'] ?? null
);
assert_same(
    'homepage: lang is still recorded for a bare /en/',
    'en',
    $qv['lang'] ?? null
);

// 3. stm_front_page_request must not steal a request a CPT plugin still
//    needs — re-assert directly against the two functions together for the
//    3-segment case (location + offering), where the mock plugin only
//    understands its own 1-segment fixture and legitimately finds nothing,
//    but the point is stm_front_page_request still must not treat this as
//    "homepage" once the path was non-empty after stripping.
$qv = run_request('en/de-dames-van-de-jonge/rondvaart');
assert_true(
    'non-homepage 3-segment path after stripping: front-page substitution not applied',
    !isset($qv['page_id'])
);
assert_same(
    '3-segment path strips only the language segment, keeps the rest intact',
    'de-dames-van-de-jonge/rondvaart',
    $GLOBALS['wp']->request
);

// 4. Default language is never treated as a prefix (no rewrite rule exists
//    for it — stm_rewrite_rules_array() skips the default on purpose).
$qv = run_request('nl/de-dames-van-de-jonge');
assert_same(
    'default-language segment is left alone (no prefix routing for the default language)',
    'nl/de-dames-van-de-jonge',
    $GLOBALS['wp']->request
);
assert_same(
    'default-language segment does not get recorded as a lang override',
    null,
    $qv['lang'] ?? null
);

// 5. Ordinary, unprefixed content is untouched — existing /en/aanbod/-style
//    archive routes rely on WordPress's own rewrite match already having
//    set the right query vars; this filter must not clobber them when the
//    first segment isn't a language code at all.
$qv = run_request('de-dames-van-de-jonge');
assert_same(
    'plain default-language content path is untouched',
    'de-dames-van-de-jonge',
    $GLOBALS['wp']->request
);
assert_same(
    'plain content path does not set lang',
    null,
    $qv['lang'] ?? null
);

// 6. Disabled URL routing: no stripping at all, even for a segment that
//    would otherwise match an active language code.
$GLOBALS['stm_options']['stm_enable_url_routing'] = false;
$qv = run_request('en/de-dames-van-de-jonge');
assert_same(
    'URL routing disabled: request path left completely alone',
    'en/de-dames-van-de-jonge',
    $GLOBALS['wp']->request
);
$GLOBALS['stm_options']['stm_enable_url_routing'] = true;

// 7. Admin context: never touched.
$GLOBALS['stm_is_admin'] = true;
$qv = run_request('en/de-dames-van-de-jonge');
assert_same(
    'admin context: request path left completely alone',
    'en/de-dames-van-de-jonge',
    $GLOBALS['wp']->request
);
$GLOBALS['stm_is_admin'] = false;

// 8. redirect_canonical guard (unchanged by this task, asserted as a
//    regression guard since it is load-bearing for the same done-when list).
assert_same(
    'stm_prevent_lang_canonical_redirect(): blocks the redirect for a language-prefixed path',
    false,
    stm_prevent_lang_canonical_redirect('https://example.test/de-dames-van-de-jonge/', 'https://example.test/en/de-dames-van-de-jonge/')
);
assert_same(
    'stm_prevent_lang_canonical_redirect(): leaves an ordinary redirect alone',
    'https://example.test/somewhere-else/',
    stm_prevent_lang_canonical_redirect('https://example.test/somewhere-else/', 'https://example.test/de-dames-van-de-jonge/')
);

// --- Report ------------------------------------------------------------

$pass = 0; $fail = 0;
echo "Language-prefix CPT/homepage routing verification (task 869ecwc03)\n";
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
