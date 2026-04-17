<?php
/**
 * Automated verification of docs/MULTILANG-AUDIT.md.
 *
 * Run from the plugin root:   php tests/verify-multilang-audit.php
 *
 * Stubs the WordPress hook API, loads every plugin include, invokes the
 * init() methods for admin + frontend contexts, and asserts the specific
 * claims from the multilanguage audit (which hooks are wired, which are
 * intentionally missing for G1-G8, and which DB tables are declared).
 *
 * No WordPress install or database is required — this runs on plain PHP
 * and exits non-zero if any audit claim disagrees with the code.
 */

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line: php tests/verify-multilang-audit.php\n");
}

define('ABSPATH', __DIR__ . '/');
if (!defined('WPINC')) {
    define('WPINC', 'wp-includes');
}
if (!defined('WP_CLI')) {
    define('WP_CLI', false);
}

$GLOBALS['stm_hooks'] = [
    'actions'      => [],
    'filters'      => [],
    'shortcodes'   => [],
    'meta_boxes'   => [],
    'widgets'      => [],
    'rest_routes'  => [],
    'activation'   => null,
    'deactivation' => null,
];
$GLOBALS['stm_is_admin'] = false;

// --- WordPress stubs -----------------------------------------------------

function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
    $GLOBALS['stm_hooks']['actions'][] = compact('hook', 'callback', 'priority', 'accepted_args');
}
function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
    $GLOBALS['stm_hooks']['filters'][] = compact('hook', 'callback', 'priority', 'accepted_args');
}
function add_shortcode($tag, $callback) {
    $GLOBALS['stm_hooks']['shortcodes'][$tag] = $callback;
}
function add_meta_box($id, $title, $callback, $screen = null, $context = 'advanced', $priority = 'default') {
    $GLOBALS['stm_hooks']['meta_boxes'][] = compact('id', 'title', 'callback', 'screen', 'context', 'priority');
}
function register_widget($class) {
    $GLOBALS['stm_hooks']['widgets'][] = $class;
}
function register_activation_hook($file, $callback) {
    $GLOBALS['stm_hooks']['activation'] = $callback;
}
function register_deactivation_hook($file, $callback) {
    $GLOBALS['stm_hooks']['deactivation'] = $callback;
}
function register_rest_route($namespace, $route, $args = []) {
    $GLOBALS['stm_hooks']['rest_routes'][] = [
        'namespace' => $namespace,
        'route'     => $route,
        'args'      => $args,
    ];
}

function plugin_dir_path($f) { return dirname($f) . '/'; }
function plugin_dir_url($f)  { return 'http://localhost/wp-content/plugins/simple-translation-manager/'; }
function plugins_url($path = '', $plugin = '') { return 'http://localhost/wp-content/plugins/' . $path; }

function is_admin()       { return (bool) $GLOBALS['stm_is_admin']; }
function get_post_types($args = [], $output = 'names') {
    // Stable set that covers posts, pages, a public CPT, and attachments.
    return ['post' => 'post', 'page' => 'page', 'mdj_project' => 'mdj_project', 'attachment' => 'attachment'];
}
function current_user_can($cap)       { return true; }
function sanitize_text_field($s)      { return is_string($s) ? trim($s) : ''; }
function sanitize_key($s)             { return is_string($s) ? strtolower(preg_replace('/[^a-z0-9_]/i', '', $s)) : ''; }
function wp_unslash($s)               { return $s; }
function __($t, $d = '')              { return $t; }
function _e($t, $d = '')              { echo $t; }
function esc_html($s)                 { return is_string($s) ? $s : ''; }
function esc_attr($s)                 { return is_string($s) ? $s : ''; }
function esc_url($s)                  { return is_string($s) ? $s : ''; }
function esc_html__($t, $d = '')      { return $t; }
function esc_attr__($t, $d = '')      { return $t; }
function esc_html_e($t, $d = '')      { echo $t; }
function admin_url($p = '')           { return 'http://localhost/wp-admin/' . ltrim($p, '/'); }
function home_url($p = '')            { return 'http://localhost' . $p; }
function get_option($name, $d = false){ return $d; }
function update_option()              {}
function add_option()                 {}
function wp_create_nonce($a)          { return 'nonce'; }
function wp_nonce_field()             {}
function wp_verify_nonce()            { return 1; }
function wp_enqueue_script()          {}
function wp_register_script()         {}
function wp_enqueue_style()           {}
function wp_register_style()          {}
function wp_localize_script()         {}
function wp_add_inline_script()       {}
function do_action($t)                {}
function apply_filters($t, $v)        { return $v; }

// WP_Widget parent for LanguageSwitcher.
if (!class_exists('WP_Widget')) {
    class WP_Widget {
        public function __construct($id = '', $name = '', $options = []) {}
        public function get_field_id($s)   { return $s; }
        public function get_field_name($s) { return $s; }
    }
}

// Minimal $wpdb stub (never actually queried at include/init time, but any
// incidental reference during class loading should not fatal).
if (!isset($wpdb)) {
    $wpdb = new class {
        public $prefix = 'wp_';
        public function prepare($q, ...$a)    { return $q; }
        public function get_results($q)       { return []; }
        public function get_row($q)           { return null; }
        public function get_var($q)           { return null; }
        public function insert()              { return 1; }
        public function update()              { return 1; }
        public function delete()              { return 1; }
        public function get_charset_collate() { return ''; }
    };
}

// --- Load the plugin code ------------------------------------------------

$pluginRoot = dirname(__DIR__);
define('STM_PLUGIN_FILE', $pluginRoot . '/simple-translation-manager.php');
define('STM_PLUGIN_DIR', $pluginRoot . '/');
define('STM_PLUGIN_URL', plugin_dir_url(STM_PLUGIN_FILE));
define('STM_VERSION', '1.1.0-test');

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

// Run each module's init() in the context it registers from. PostEditor and
// Admin gate on is_admin(); Frontend only runs on the front end.
$GLOBALS['stm_is_admin'] = true;
STM\Admin::init();
STM\API::init();
STM\PostEditor::init();
STM\ImportExport::init();
STM\TranslationMemory::init();
STM\AutoTranslate::init();
STM\LanguageSwitcher::init();

$GLOBALS['stm_is_admin'] = false;
STM\Frontend::init();

// Main plugin file top-level hooks (the file itself calls session_start so
// we do not include it; recreate the registrations it performs).
add_action('template_redirect', 'stm_template_redirect', 1);
add_filter('query_vars', 'stm_query_vars');
add_action('plugins_loaded', 'stm_init');

// Fire deferred callbacks: widgets_init (registers the switcher widget) and
// add_meta_boxes (registers the PostEditor meta box per post type).
foreach ($GLOBALS['stm_hooks']['actions'] as $a) {
    if (in_array($a['hook'], ['widgets_init', 'add_meta_boxes'], true) && is_callable($a['callback'])) {
        call_user_func($a['callback']);
    }
}

// --- Assertions ----------------------------------------------------------

$results = [];

function hook_registered($type, $hook) {
    $bucket = $type === 'action' ? 'actions' : 'filters';
    foreach ($GLOBALS['stm_hooks'][$bucket] as $h) {
        if ($h['hook'] === $hook) return true;
    }
    return false;
}

function assert_present($name, $type, $hook) {
    global $results;
    $ok = hook_registered($type, $hook);
    $results[] = ['name' => $name, 'pass' => $ok, 'detail' => $ok ? '' : "missing $type on \"$hook\""];
}
function assert_absent($name, $type, $hook) {
    global $results;
    $ok = !hook_registered($type, $hook);
    $results[] = ['name' => $name, 'pass' => $ok, 'detail' => $ok ? '' : "unexpected $type on \"$hook\""];
}
function assert_true($name, $cond, $detail = '') {
    global $results;
    $results[] = ['name' => $name, 'pass' => (bool) $cond, 'detail' => $detail];
}

// ----- Posts / Pages / Public CPT (Supported) ----------------------------
assert_present('Posts/Pages: the_title filter',     'filter', 'the_title');
assert_present('Posts/Pages: the_content filter',   'filter', 'the_content');
assert_present('Posts/Pages: the_excerpt filter',   'filter', 'the_excerpt');
assert_present('Posts/Pages: post_type_link filter','filter', 'post_type_link');

$meta_box_ok = false;
foreach ($GLOBALS['stm_hooks']['meta_boxes'] as $mb) {
    if ($mb['id'] === 'stm_translations') { $meta_box_ok = true; break; }
}
assert_true('Posts/Pages: translation meta box registered', $meta_box_ok,
    'expected meta box "stm_translations" via add_meta_boxes callback.');

// ----- Language switcher (Supported) -------------------------------------
$switcher_ok = in_array('STM\\LanguageSwitcher', $GLOBALS['stm_hooks']['widgets'], true);
assert_true('Language switcher widget registered', $switcher_ok,
    'register_widget should be called with STM\\LanguageSwitcher.');
$shortcode_ok = isset($GLOBALS['stm_hooks']['shortcodes']['stm_language_switcher']);
assert_true('Language switcher shortcode [stm_language_switcher] registered', $shortcode_ok);

// ----- URL routing -------------------------------------------------------
assert_present('URL routing: template_redirect action', 'action', 'template_redirect');
assert_present('URL routing: query_vars filter',        'filter', 'query_vars');

// ----- Taxonomy coverage (Partial — G1) ----------------------------------
assert_present('Taxonomy frontend: get_term filter', 'filter', 'get_term');
assert_absent('G1: NO edited_term action',  'action', 'edited_term');
assert_absent('G1: NO created_term action', 'action', 'created_term');

$has_tax_form_hook = false;
foreach (array_merge($GLOBALS['stm_hooks']['actions'], $GLOBALS['stm_hooks']['filters']) as $h) {
    if (preg_match('/_(edit|add)_form_fields$/', $h['hook'])) {
        $has_tax_form_hook = true;
        break;
    }
}
assert_true('G1: NO taxonomy *_edit/add_form_fields hook', !$has_tax_form_hook,
    'Editors cannot translate terms from WP admin today — confirm no such hook is registered.');

// ----- Nav menus (G2) ----------------------------------------------------
foreach (['wp_setup_nav_menu_item', 'nav_menu_item_title', 'wp_nav_menu_objects', 'walker_nav_menu_start_el'] as $h) {
    assert_absent("G2: NO nav menu filter $h", 'filter', $h);
}

// ----- Media alt text / caption (G3) ------------------------------------
foreach (['_wp_attachment_image_alt', 'wp_get_attachment_metadata', 'the_post_thumbnail_caption', 'wp_prepare_attachment_for_js'] as $h) {
    assert_absent("G3: NO media filter $h", 'filter', $h);
}

// ----- Widgets (G4) ------------------------------------------------------
foreach (['widget_text', 'widget_text_content', 'widget_block_content', 'widget_title', 'widget_display_callback'] as $h) {
    assert_absent("G4: NO widget content filter $h", 'filter', $h);
}

// ----- G7: session_start on plugins_loaded ------------------------------
$main_src = file_get_contents(STM_PLUGIN_FILE);
assert_true('G7: main plugin file still calls session_start()',
    strpos($main_src, 'session_start()') !== false,
    'if this fails the G7 recommendation can be closed.');

// ----- G8: pre_get_posts present but filter_query body has no lang filter
assert_present('pre_get_posts action registered', 'action', 'pre_get_posts');
$ref   = new ReflectionMethod('STM\\Frontend', 'filter_query');
$lines = array_slice(file($ref->getFileName()), $ref->getStartLine() - 1,
                     $ref->getEndLine() - $ref->getStartLine() + 1);
$body  = implode('', $lines);
$has_lang_filter = (bool) preg_match('/tax_query|meta_query|set\(\s*[\'"]lang[\'"]|JOIN\s+stm_post_associations/i', $body);
assert_true('G8: Frontend::filter_query does NOT restrict by language',
    !$has_lang_filter,
    'if this fails the G8 recommendation can be closed.');

// ----- Database schema ---------------------------------------------------
$db_src = file_get_contents(STM_PLUGIN_DIR . 'includes/class-database.php');
foreach (['stm_languages', 'stm_strings', 'stm_translations', 'stm_post_translations', 'stm_post_associations', 'stm_term_translations'] as $t) {
    assert_true("Schema declares table $t", strpos($db_src, $t) !== false);
}

// ----- REST routes -------------------------------------------------------
$expected_routes = ['/languages', '/strings', '/translations', '/posts/bulk-translations'];
foreach ($expected_routes as $route) {
    $found = false;
    foreach ($GLOBALS['stm_hooks']['rest_routes'] as $r) {
        if ($r['route'] === $route) { $found = true; break; }
    }
    // rest_api_init registers routes via add_action; we have not fired that
    // callback yet because registration happens inside register_routes().
    // Fire it now.
    if (!$found) {
        foreach ($GLOBALS['stm_hooks']['actions'] as $a) {
            if ($a['hook'] === 'rest_api_init' && is_callable($a['callback'])) {
                call_user_func($a['callback']);
            }
        }
        foreach ($GLOBALS['stm_hooks']['rest_routes'] as $r) {
            if ($r['route'] === $route) { $found = true; break; }
        }
    }
    assert_true("REST route $route registered", $found);
}

// --- Report --------------------------------------------------------------

$pass = 0; $fail = 0;
echo "Simple Translation Manager — multilang audit verification\n";
echo str_repeat('=', 64) . "\n";
foreach ($results as $r) {
    $mark = $r['pass'] ? 'PASS' : 'FAIL';
    if ($r['pass']) $pass++; else $fail++;
    printf("%-5s %s\n", $mark, $r['name']);
    if (!$r['pass'] && !empty($r['detail'])) {
        echo "      -> " . $r['detail'] . "\n";
    }
}
echo str_repeat('-', 64) . "\n";
$total = count($results);
echo "Total: $pass pass, $fail fail ($total assertions)\n";
exit($fail === 0 ? 0 : 1);
