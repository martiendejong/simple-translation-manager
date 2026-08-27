<?php
/**
 * Standalone verification for ClickUp task 869e8cekd (follow-up):
 * the admin Languages screen must list every language, including hidden
 * (is_active = 0) ones, so the admin can see and re-toggle/delete them.
 *
 * Root cause fixed: Admin::page_languages() called Database::get_languages(),
 * which is intentionally active-only (it backs the public switcher, hreflang
 * tags and the React frontend). Using it for the admin management screen too
 * meant a hidden language's row never rendered, so its existing
 * "Active"/"Inactive" toggle button and Delete button were unreachable —
 * exactly the bug reported live: "the french language is still not visible
 * as inactive and i cannot add it again in the interface".
 *
 * Run from the plugin root:   php tests/verify-869e8cekd-admin-shows-inactive-languages.php
 * No WordPress install or database is required.
 */

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line: php tests/verify-869e8cekd-admin-shows-inactive-languages.php\n");
}

// --- Minimal WordPress stubs (only what class-database.php needs) --------

$GLOBALS['stm_cache'] = [];
function wp_cache_get($key, $group = '') {
    return $GLOBALS['stm_cache'][$group . '|' . $key] ?? false;
}
function wp_cache_set($key, $value, $group = '', $expire = 0) {
    $GLOBALS['stm_cache'][$group . '|' . $key] = $value;
}
function wp_cache_delete($key, $group = '') {
    unset($GLOBALS['stm_cache'][$group . '|' . $key]);
}

/**
 * Fake $wpdb: seeds a fixed set of language rows and serves get_results()
 * by inspecting the SQL — enough to prove get_all_languages() returns every
 * row while get_languages() stays filtered to is_active = 1, without needing
 * a real database.
 */
class FakeWpdb {
    public $prefix = 'wp_';
    public $queries = [];

    private $rows;

    public function __construct() {
        $this->rows = [
            (object) ['id' => 1, 'code' => 'nl', 'name' => 'Dutch',   'is_active' => 1, 'is_default' => 1, 'order_index' => 0],
            (object) ['id' => 2, 'code' => 'en', 'name' => 'English', 'is_active' => 1, 'is_default' => 0, 'order_index' => 1],
            (object) ['id' => 3, 'code' => 'de', 'name' => 'German',  'is_active' => 1, 'is_default' => 0, 'order_index' => 2],
            (object) ['id' => 4, 'code' => 'fr', 'name' => 'French',  'is_active' => 0, 'is_default' => 0, 'order_index' => 3],
            (object) ['id' => 5, 'code' => 'zh', 'name' => 'Chinese', 'is_active' => 0, 'is_default' => 0, 'order_index' => 4],
            (object) ['id' => 6, 'code' => 'ar', 'name' => 'Arabic',  'is_active' => 0, 'is_default' => 0, 'order_index' => 5],
        ];
    }

    public function get_results($sql) {
        $this->queries[] = $sql;
        if (stripos($sql, 'WHERE is_active = 1') !== false) {
            return array_values(array_filter($this->rows, fn($r) => $r->is_active === 1));
        }
        return $this->rows;
    }
}

$results = [];
function check($name, $cond, $detail = '') {
    global $results;
    $results[] = ['name' => $name, 'pass' => (bool) $cond, 'detail' => $detail];
}

// --- Load the plugin source under test ------------------------------------

$pluginRoot = dirname(__DIR__);
require_once $pluginRoot . '/includes/class-database.php';

global $wpdb;
$wpdb = new FakeWpdb();

// Check 1: get_all_languages() returns every row, active and inactive.
$all = \STM\Database::get_all_languages();
$allCodes = array_map(fn($r) => $r->code, $all);
check(
    'get_all_languages() returns all 6 seeded languages including inactive ones',
    count($all) === 6 && in_array('fr', $allCodes, true) && in_array('zh', $allCodes, true) && in_array('ar', $allCodes, true),
    'got codes: ' . implode(',', $allCodes)
);

// Check 2: get_all_languages() query has no is_active filter (regression guard).
$lastQuery = end($wpdb->queries);
check(
    'get_all_languages() SQL has no WHERE is_active filter',
    stripos($lastQuery, 'is_active') === false,
    $lastQuery
);

// Check 3: get_languages() is untouched — still active-only (public paths
// rely on this: switcher, hreflang, REST GET /languages, sitemap).
$active = \STM\Database::get_languages();
$activeCodes = array_map(fn($r) => $r->code, $active);
check(
    'get_languages() still returns only the 3 active languages (nl/en/de)',
    count($active) === 3 && !in_array('fr', $activeCodes, true) && !in_array('zh', $activeCodes, true) && !in_array('ar', $activeCodes, true),
    'got codes: ' . implode(',', $activeCodes)
);

// Check 4: Admin::page_languages() calls get_all_languages(), not the
// active-only get_languages() — source-level check (the method includes a
// template and calls exit-free WP admin globals we don't want to stub here).
$adminSrc = file_get_contents($pluginRoot . '/includes/class-admin.php');
if (preg_match('/function page_languages\(\)\s*\{(.*?)\n    \}/s', $adminSrc, $m)) {
    $body = $m[1];
    check(
        'Admin::page_languages() calls Database::get_all_languages()',
        strpos($body, 'Database::get_all_languages()') !== false,
        trim($body)
    );
} else {
    check('Admin::page_languages() found in source', false, 'method not found');
}

// Check 5: the public-facing consumers were NOT changed to the all-languages
// path — they must stay active-only (frontend switcher, hreflang, sitemap,
// public REST GET /languages).
$publicFiles = [
    'class-frontend.php',
    'class-hreflang.php',
    'class-language-switcher.php',
    'class-sitemap.php',
];
$publicOk = true;
$publicDetail = [];
foreach ($publicFiles as $file) {
    $path = $pluginRoot . '/includes/' . $file;
    if (!file_exists($path)) {
        continue;
    }
    $src = file_get_contents($path);
    if (strpos($src, 'Database::get_all_languages()') !== false) {
        $publicOk = false;
        $publicDetail[] = "$file unexpectedly calls get_all_languages()";
    }
}
check(
    'Public-facing consumers (frontend/hreflang/switcher/sitemap) still use active-only get_languages()',
    $publicOk,
    implode('; ', $publicDetail) ?: 'ok'
);

// Check 6: the admin template's toggle button still targets $lang->is_active
// (the wiring this fix depends on to actually let the admin reactivate).
$templatePath = $pluginRoot . '/templates/admin-languages.php';
$templateSrc = file_exists($templatePath) ? file_get_contents($templatePath) : '';
check(
    'admin-languages.php template still renders the is_active toggle button',
    strpos($templateSrc, 'stm-lang-toggle-active') !== false && strpos($templateSrc, 'is_active') !== false,
    'template found: ' . ($templateSrc !== '' ? 'yes' : 'no')
);

// --- Report -----------------------------------------------------------

$pass = 0;
$fail = 0;
foreach ($results as $r) {
    $status = $r['pass'] ? 'PASS' : 'FAIL';
    printf("[%s] %s\n", $status, $r['name']);
    if (!$r['pass']) {
        printf("       %s\n", $r['detail']);
        $fail++;
    } else {
        $pass++;
    }
}

printf("\n%d/%d checks passed\n", $pass, count($results));
exit($fail > 0 ? 1 : 0);
