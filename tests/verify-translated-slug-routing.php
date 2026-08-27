<?php
/**
 * Automated verification for task 869eec0wf — per-language slug URLs.
 *
 * Run from the plugin root:   php tests/verify-translated-slug-routing.php
 *
 * Stubs the WordPress hook/DB API (no install or database required) and
 * exercises the actual lookup code so a regression here fails a real
 * assertion instead of only "looking right" on read-through:
 *
 *   1. Frontend::resolve_translated_slug_request() swaps an incoming
 *      translated slug back to the post's real post_name (the routing fix).
 *   2. It leaves query vars alone for the default language, when no lang
 *      is set, and when the requested slug has no translation (untranslated
 *      pages must keep working exactly as before).
 *   3. Frontend::localize_permalink() — the single shared lookup — both
 *      substitutes a translated slug and injects the /{lang}/ prefix, and
 *      leaves an untranslated post's URL on its default slug.
 *   4. Frontend::get_base_permalink() suppresses STM's own permalink
 *      filter so it always returns the default-language URL, regardless
 *      of which language is active for the current request.
 *   5. PostEditor::get_post_id_by_translated_slug() resolves the reverse
 *      lookup directly against the stm_post_translations storage.
 *   6. Source-level check that class-hreflang.php and class-sitemap.php
 *      call into Frontend's shared lookup rather than re-implementing
 *      their own slug substitution.
 */

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line: php tests/verify-translated-slug-routing.php\n");
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

// --- Fixtures --------------------------------------------------------------
//
// One post ('locatie' CPT), default language 'en', with a translated slug
// set for 'nl' only — mirrors the ticket's own example (valsuani-plek).

$GLOBALS['stm_posts'] = [
    42 => (object) [ 'ID' => 42, 'post_name' => 'valsuani-place', 'post_type' => 'visit_location' ],
    // No translated slug at all — must keep resolving via its own post_name.
    43 => (object) [ 'ID' => 43, 'post_name' => 'untouched-page', 'post_type' => 'page' ],
];

$GLOBALS['stm_post_translations'] = [
    [ 'post_id' => 42, 'field_name' => 'post_name',  'language_code' => 'nl', 'translation' => 'valsuani-plek' ],
    [ 'post_id' => 42, 'field_name' => 'post_title', 'language_code' => 'nl', 'translation' => 'Valsuani plek' ],
];

// One active non-default language, for get_current_language()'s active-code check.
$GLOBALS['stm_languages'] = [
    (object) [ 'code' => 'nl', 'is_active' => 1 ],
];

// Overridden by test 4 to simulate browsing the site with /nl/ active.
$GLOBALS['stm_test_lang'] = '';

// --- WordPress stubs ---------------------------------------------------

$GLOBALS['stm_filters'] = [];

function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {}
function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
    $GLOBALS['stm_filters'][$hook][] = $callback;
}
function remove_filter($hook, $callback, $priority = 10) {
    if (empty($GLOBALS['stm_filters'][$hook])) {
        return false;
    }
    foreach ($GLOBALS['stm_filters'][$hook] as $i => $cb) {
        if ($cb == $callback) {
            unset($GLOBALS['stm_filters'][$hook][$i]);
            return true;
        }
    }
    return false;
}
function apply_filters($hook, $value, ...$more) {
    foreach ($GLOBALS['stm_filters'][$hook] ?? [] as $cb) {
        $value = call_user_func($cb, $value, ...$more);
    }
    return $value;
}
function has_filter($hook, $cb = false) {
    if (empty($GLOBALS['stm_filters'][$hook])) {
        return false;
    }
    if ($cb === false) {
        return true;
    }
    foreach ($GLOBALS['stm_filters'][$hook] as $registered) {
        if ($registered == $cb) {
            return true;
        }
    }
    return false;
}
function register_activation_hook($file, $callback) {}
function register_deactivation_hook($file, $callback) {}

function plugin_dir_path($f) { return dirname($f) . '/'; }
function plugin_dir_url($f)  { return STM_TEST_BASE_URL . 'wp-content/plugins/simple-translation-manager/'; }

function is_admin()                   { return false; }
function current_user_can($cap)       { return true; }
function sanitize_text_field($s)      { return is_string($s) ? trim($s) : ''; }
function sanitize_key($s)             { return is_string($s) ? strtolower(preg_replace('/[^a-z0-9_]/i', '', $s)) : ''; }
function sanitize_title($s)           { return is_string($s) ? strtolower(preg_replace('/[^a-z0-9-]/i', '-', $s)) : ''; }
function wp_unslash($s)               { return $s; }
function __($t, $d = '')              { return $t; }
function esc_html($s)                 { return is_string($s) ? $s : ''; }
function esc_attr($s)                 { return is_string($s) ? $s : ''; }
function esc_url($s)                  { return is_string($s) ? $s : ''; }
function admin_url($p = '')           { return 'https://example.test/wp-admin/' . ltrim($p, '/'); }
function home_url($p = '')            { return 'https://example.test' . $p; }
function trailingslashit($s)          { return rtrim($s, '/') . '/'; }
function get_option($name, $d = false){ return $d; }
function update_option()              { return true; }
function add_option()                 { return true; }
function wp_create_nonce($a)          { return 'nonce'; }
function wp_nonce_field()             {}
function wp_verify_nonce()            { return 1; }
function wp_enqueue_script()          {}
function wp_enqueue_style()           {}
function wp_localize_script()         {}
function wp_list_pluck($list, $field) { return array_map(fn($o) => is_object($o) ? $o->$field : $o[$field], $list); }
function do_action($t)                {}
function get_query_var($k, $d = '')   { return $k === 'lang' && $GLOBALS['stm_test_lang'] !== '' ? $GLOBALS['stm_test_lang'] : $d; }
function wp_cache_get($key, $group = '') { return false; }
function wp_cache_set($key, $data, $group = '', $expire = 0) {}

function get_post_types($args = [], $output = 'names') {
    // Mirrors visit-platform's registrations: pages use 'pagename' (their
    // own query_var is false, per WP core), the CPT registers its own.
    $types = [
        'page'          => (object) [ 'name' => 'page', 'public' => true, 'query_var' => false ],
        'visit_location' => (object) [ 'name' => 'visit_location', 'public' => true, 'query_var' => 'visit_location' ],
    ];
    return $output === 'objects' ? $types : array_keys($types);
}

function get_post($post) {
    if (is_object($post)) {
        return $post;
    }
    return $GLOBALS['stm_posts'][(int) $post] ?? null;
}

function get_permalink($post) {
    $post = get_post($post);
    if (!$post) {
        return false;
    }
    $slugs = [ 'visit_location' => 'locatie', 'page' => '' ];
    $prefix = $slugs[$post->post_type] ?? $post->post_type;
    $raw = 'https://example.test/' . trim($prefix . '/' . $post->post_name, '/') . '/';

    // Real WP applies 'post_type_link' for non-page/non-post types on the
    // way out of get_permalink() — reproduce that so get_base_permalink()'s
    // remove_filter()/add_filter() dance is actually exercised.
    if ($post->post_type !== 'page' && $post->post_type !== 'post') {
        $raw = apply_filters('post_type_link', $raw, $post);
    }

    return $raw;
}

// Minimal $wpdb stub backed by the fixtures above.
if (!isset($wpdb)) {
    $wpdb = new class {
        public $prefix = 'wp_';

        public function prepare($query, ...$args) {
            return $query . '::' . json_encode($args);
        }

        private function split($query) {
            [$sql, $argsJson] = array_pad(explode('::', $query, 2), 2, '[]');
            return [$sql, json_decode($argsJson, true) ?: []];
        }

        public function get_var($query) {
            [$sql, $args] = $this->split($query);

            if (strpos($sql, 'stm_post_translations') !== false && strpos($sql, 'SELECT post_id') !== false) {
                [$language_code, $slug] = $args;
                foreach ($GLOBALS['stm_post_translations'] as $row) {
                    if ($row['field_name'] === 'post_name' && $row['language_code'] === $language_code && $row['translation'] === $slug) {
                        return $row['post_id'];
                    }
                }
                return null;
            }

            // Settings::get_default_language()'s fallback lookups — no
            // languages table in this stub, so both return null and the
            // caller falls back to 'en'.
            return null;
        }

        public function get_results($query, $output = ARRAY_A) {
            [$sql, $args] = $this->split($query);

            if (strpos($sql, 'stm_post_translations') !== false) {
                [$post_id, $language_code] = $args;
                $out = [];
                foreach ($GLOBALS['stm_post_translations'] as $row) {
                    if ((int) $row['post_id'] === (int) $post_id && $row['language_code'] === $language_code) {
                        $out[] = [ 'field_name' => $row['field_name'], 'translation' => $row['translation'] ];
                    }
                }
                return $out;
            }

            if (strpos($sql, 'stm_languages') !== false) {
                return $GLOBALS['stm_languages'];
            }

            return [];
        }

        public function get_row($query)       { return null; }
        public function insert()              { return 1; }
        public function update()              { return 1; }
        public function delete()              { return 1; }
        public function get_charset_collate() { return ''; }
    };
}

// --- Load the plugin code ------------------------------------------------

$pluginRoot = dirname(__DIR__);
define('STM_PLUGIN_DIR', $pluginRoot . '/');
define('STM_PLUGIN_URL', plugin_dir_url($pluginRoot));
define('STM_VERSION', '1.1.1-test');

require_once STM_PLUGIN_DIR . 'includes/class-security.php';
require_once STM_PLUGIN_DIR . 'includes/class-settings.php';
require_once STM_PLUGIN_DIR . 'includes/class-database.php';
require_once STM_PLUGIN_DIR . 'includes/class-post-editor.php';
require_once STM_PLUGIN_DIR . 'includes/class-frontend.php';

// No `use` imports — this file has no namespace of its own, so the STM\*
// classes are referenced fully-qualified below.

// --- Assertions ------------------------------------------------------------

$results = [];
function assert_true($name, $cond, $detail = '') {
    global $results;
    $results[] = [ 'name' => $name, 'pass' => (bool) $cond, 'detail' => $detail ];
}
function assert_same($name, $expected, $actual) {
    global $results;
    $ok = $expected === $actual;
    $detail = $ok ? '' : "expected " . var_export($expected, true) . ", got " . var_export($actual, true);
    $results[] = [ 'name' => $name, 'pass' => $ok, 'detail' => $detail ];
}

// 1. Reverse lookup: translated slug -> post ID.
assert_same(
    'PostEditor::get_post_id_by_translated_slug() finds the post for a translated slug',
    42,
    \STM\PostEditor::get_post_id_by_translated_slug('nl', 'valsuani-plek')
);
assert_same(
    'PostEditor::get_post_id_by_translated_slug() returns 0 when nothing matches',
    0,
    \STM\PostEditor::get_post_id_by_translated_slug('nl', 'no-such-slug')
);

// 2. Incoming request resolution — the routing fix itself.
assert_same(
    'resolve_translated_slug_request(): CPT query var swapped from translated to real slug',
    'valsuani-place',
    \STM\Frontend::resolve_translated_slug_request([ 'visit_location' => 'valsuani-plek', 'lang' => 'nl' ])['visit_location']
);
assert_same(
    'resolve_translated_slug_request(): no lang set — untouched',
    'valsuani-plek',
    \STM\Frontend::resolve_translated_slug_request([ 'visit_location' => 'valsuani-plek' ])['visit_location']
);
assert_same(
    'resolve_translated_slug_request(): default language — untouched',
    'valsuani-plek',
    \STM\Frontend::resolve_translated_slug_request([ 'visit_location' => 'valsuani-plek', 'lang' => 'en' ])['visit_location']
);
assert_same(
    'resolve_translated_slug_request(): a post\'s own (untranslated) slug is left alone — done-when criterion 4',
    'untouched-page',
    \STM\Frontend::resolve_translated_slug_request([ 'pagename' => 'untouched-page', 'lang' => 'nl' ])['pagename']
);
assert_same(
    'resolve_translated_slug_request(): unknown slug with no translation falls through unchanged',
    'some-random-slug',
    \STM\Frontend::resolve_translated_slug_request([ 'visit_location' => 'some-random-slug', 'lang' => 'nl' ])['visit_location']
);

// 3. Shared forward lookup — substitution + prefix, and untranslated no-op.
$post42 = get_post(42);
assert_same(
    'localize_permalink(): substitutes translated slug and injects /nl/ prefix',
    'https://example.test/nl/locatie/valsuani-plek/',
    \STM\Frontend::localize_permalink('https://example.test/locatie/valsuani-place/', $post42, 'nl')
);
assert_same(
    'localize_permalink(): default language returns the permalink unchanged',
    'https://example.test/locatie/valsuani-place/',
    \STM\Frontend::localize_permalink('https://example.test/locatie/valsuani-place/', $post42, 'en')
);
$post43 = get_post(43);
assert_same(
    'localize_permalink(): no translated slug for this language — default slug still used (done-when criterion 4)',
    'https://example.test/de/untouched-page/',
    \STM\Frontend::localize_permalink('https://example.test/untouched-page/', $post43, 'de')
);

// 4. get_base_permalink() ignores whichever language is "current" and
//    always returns the default-language URL — this is what Hreflang and
//    Sitemap build every language's link from. Attach the REAL
//    filter_permalink and put 'nl' in query_var('lang') (its top-priority
//    source) so a plain get_permalink() would come back nl-prefixed with
//    the translated slug — proving get_base_permalink() actually
//    suppresses it rather than the assertion trivially passing because
//    nothing was ever going to change it.
add_filter('post_type_link', ['STM\\Frontend', 'filter_permalink'], 10, 2);
$GLOBALS['stm_test_lang'] = 'nl';
assert_same(
    'sanity check: with the filter active, a plain get_permalink() IS nl-prefixed with the translated slug',
    'https://example.test/nl/locatie/valsuani-plek/',
    get_permalink($post42)
);
assert_same(
    'get_base_permalink() suppresses STM\'s own filter and returns the un-prefixed, default-slug URL',
    'https://example.test/locatie/valsuani-place/',
    \STM\Frontend::get_base_permalink($post42)
);
$GLOBALS['stm_test_lang'] = '';

// 5. Source-level: hreflang/sitemap route through the shared lookup instead
//    of re-implementing slug substitution inline.
$hreflang_src = file_get_contents(STM_PLUGIN_DIR . 'includes/class-hreflang.php');
assert_true(
    'class-hreflang.php calls Frontend::localize_permalink() (shared lookup)',
    strpos($hreflang_src, 'Frontend::localize_permalink') !== false
);
assert_true(
    'class-hreflang.php calls Frontend::get_base_permalink() rather than a raw current-request URL for singular content',
    strpos($hreflang_src, 'Frontend::get_base_permalink') !== false
);
assert_true(
    'class-hreflang.php does not duplicate the slug str_replace substitution itself',
    strpos($hreflang_src, "post->post_name") === false
);

$sitemap_src = file_get_contents(STM_PLUGIN_DIR . 'includes/class-sitemap.php');
assert_true(
    'class-sitemap.php calls Frontend::localize_permalink() (shared lookup)',
    strpos($sitemap_src, 'Frontend::localize_permalink') !== false
);
assert_true(
    'class-sitemap.php calls Frontend::get_base_permalink() rather than a raw get_permalink()',
    strpos($sitemap_src, 'Frontend::get_base_permalink') !== false
);
assert_true(
    'class-sitemap.php no longer hand-strips a language prefix (dead code from the old direct-URL approach)',
    strpos($sitemap_src, 'prefix_re') === false
);

$frontend_src = file_get_contents(STM_PLUGIN_DIR . 'includes/class-frontend.php');
assert_true(
    'class-frontend.php registers resolve_translated_slug_request() on the request filter',
    (bool) preg_match("/add_filter\\(\\s*'request',\\s*\\[__CLASS__,\\s*'resolve_translated_slug_request'\\]/", $frontend_src)
);

// --- Report ------------------------------------------------------------

$pass = 0; $fail = 0;
echo "Translated-slug routing verification (task 869eec0wf)\n";
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
