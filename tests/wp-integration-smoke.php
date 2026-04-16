<?php
/**
 * WordPress integration smoke test for Simple Translation Manager.
 *
 * Run from the plugin root (or from anywhere with WP installed):
 *   php tests/wp-integration-smoke.php [/path/to/wp-load.php]
 *
 * Defaults to C:/xampp/htdocs/wp-load.php. Override via the first arg or
 * the STM_WP_LOAD environment variable.
 *
 * What it verifies (read-only — no DB writes, no plugin activation):
 *   1. wp-load.php bootstraps cleanly.
 *   2. The plugin file loads without fatals in a real WP context.
 *   3. Every STM\ class the main plugin file names is resolvable.
 *   4. The main `plugins_loaded`/`template_redirect` hooks are registered.
 *   5. Firing `plugins_loaded` + `rest_api_init` produces all the REST
 *      routes documented in docs/MULTILANG-AUDIT.md.
 *   6. All six DB tables the audit lists appear in the CREATE TABLE SQL.
 *
 * The script explicitly does NOT call dbDelta, seed languages, or add
 * anything to wp_options, so running it on an existing WordPress install
 * is safe.
 */

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line: php tests/wp-integration-smoke.php\n");
}

$wp_load = $argv[1] ?? getenv('STM_WP_LOAD') ?: 'C:/xampp/htdocs/wp-load.php';
if (!file_exists($wp_load)) {
    fwrite(STDERR, "wp-load.php not found at: $wp_load\n");
    fwrite(STDERR, "Pass the path as arg 1 or set STM_WP_LOAD.\n");
    exit(2);
}

define('WP_USE_THEMES', false);
require_once $wp_load;

$pluginRoot = dirname(__DIR__);
$pluginFile = $pluginRoot . '/simple-translation-manager.php';
if (!file_exists($pluginFile)) {
    fwrite(STDERR, "plugin file not found: $pluginFile\n");
    exit(2);
}

// If WordPress already loaded STM (e.g. the wp-content/plugins symlink points
// at a sibling worktree), don't re-require this worktree's copy — the global
// function declarations (stm_activate, stm_init, ...) would collide.
// Schema/source checks below read this worktree's files directly, so they
// still reflect this branch regardless of which copy WP bootstrapped.
if (!defined('STM_VERSION')) {
    require_once $pluginFile;
}

$results = [];
function check($name, $cond, $detail = '') {
    global $results;
    $results[] = ['name' => $name, 'pass' => (bool) $cond, 'detail' => $detail];
}

// 1. wp-load bootstrap
check('WordPress bootstrapped', function_exists('get_bloginfo'),
    'get_bloginfo() should be available after wp-load.php.');

// 2. STM plugin constants from main file
check('STM_VERSION constant defined',     defined('STM_VERSION'));
check('STM_PLUGIN_DIR constant defined',  defined('STM_PLUGIN_DIR'));

// 3. STM namespaced classes load
foreach ([
    'STM\\Admin', 'STM\\API', 'STM\\PostEditor', 'STM\\Frontend',
    'STM\\LanguageSwitcher', 'STM\\Database', 'STM\\Settings',
    'STM\\Security', 'STM\\Cache', 'STM\\ImportExport',
    'STM\\TranslationMemory', 'STM\\AutoTranslate',
] as $class) {
    check("Class $class loads", class_exists($class));
}

// 4. Main hooks
check('plugins_loaded action has stm_init',
    (bool) has_action('plugins_loaded', 'stm_init'));
check('template_redirect action has stm_template_redirect',
    (bool) has_action('template_redirect', 'stm_template_redirect'));
check('query_vars filter has stm_query_vars',
    (bool) has_filter('query_vars', 'stm_query_vars'));

// 5. Fire plugins_loaded and rest_api_init so init() methods register
//    their hooks and REST routes exactly as they would on a real request.
if (!did_action('plugins_loaded')) {
    do_action('plugins_loaded');
}
// Always fire init() paths explicitly; do_action('plugins_loaded') above
// will have invoked stm_init once, but if the hook was already fired
// earlier in wp-load we still want the assertions to work.
if (class_exists('STM\\Frontend')) STM\Frontend::init();
if (class_exists('STM\\API'))      STM\API::init();

check('frontend filter: the_title',      (bool) has_filter('the_title'));
check('frontend filter: the_content',    (bool) has_filter('the_content'));
check('frontend filter: the_excerpt',    (bool) has_filter('the_excerpt'));
check('frontend filter: post_type_link', (bool) has_filter('post_type_link'));
check('frontend filter: get_term',       (bool) has_filter('get_term'));
check('frontend action: pre_get_posts',  (bool) has_action('pre_get_posts'));

// REST routes — probe via the REST server after firing rest_api_init.
do_action('rest_api_init');
$server = rest_get_server();
$routes = array_keys($server->get_routes());

foreach ([
    '/stm/v1/languages',
    '/stm/v1/strings',
    '/stm/v1/translations',
    '/stm/v1/posts/bulk-translations',
] as $r) {
    check("REST route $r registered", in_array($r, $routes, true));
}

// 6. Schema source declares every table in the audit (no DB writes).
$db_src = file_get_contents($pluginRoot . '/includes/class-database.php');
foreach ([
    'stm_languages', 'stm_strings', 'stm_translations',
    'stm_post_translations', 'stm_post_associations', 'stm_term_translations',
] as $t) {
    check("schema source mentions $t", strpos($db_src, $t) !== false);
}

// Report
$pass = 0; $fail = 0;
echo "STM WP integration smoke test (" . get_bloginfo('version') . ")\n";
echo str_repeat('=', 64) . "\n";
foreach ($results as $r) {
    $mark = $r['pass'] ? 'PASS' : 'FAIL';
    if ($r['pass']) $pass++; else $fail++;
    printf("%-5s %s\n", $mark, $r['name']);
    if (!$r['pass'] && $r['detail']) echo "      -> " . $r['detail'] . "\n";
}
echo str_repeat('-', 64) . "\n";
echo "Total: $pass pass, $fail fail (" . count($results) . " assertions)\n";
exit($fail === 0 ? 0 : 1);
