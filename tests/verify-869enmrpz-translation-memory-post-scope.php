<?php
/**
 * Automated verification for task 869enmrpz — auto-translate's translation
 * memory reusing one post's stored translation for a DIFFERENT post, because
 * find_exact_post_match()/find_fuzzy_matches() joined across ALL posts with
 * no post_id restriction. Confirmed live: a bulk auto-translate pass swapped
 * translations onto 4 of 7 near-duplicate-template blog articles (869enmmvq).
 *
 * Run from the plugin root:   php tests/verify-869enmrpz-translation-memory-post-scope.php
 *
 * Stubs the WordPress hook/DB API (no install or database required) and
 * requires the real main plugin file, so the actual STM\TranslationMemory
 * and STM\AutoTranslate classes are exercised end to end:
 *
 *   1. Reproduces the ticket's own repro shape: two "seasonal guide" posts
 *      built from the same template (identical headings/paragraph
 *      structure, only the season word differs) — near-duplicate enough to
 *      score well above the fuzzy-match similarity threshold and within the
 *      length-ratio cap. Post A's content translation is saved; auto-
 *      translating post B's own (different) content must NOT return post
 *      A's translation, however similar the two posts' text is.
 *   2. Proves the fix is the post_id scoping specifically, not a coincidence
 *      of the similarity threshold: the SAME near-duplicate pair, looked up
 *      WITHOUT a post_id (the pre-fix call shape), DOES cross-match — i.e.
 *      this is a real, reproducible defect the post-scoped call structurally
 *      closes, not just an unlikely edge case.
 *   3. Exact-match variant: two posts sharing a byte-identical excerpt
 *      (e.g. a shared disclaimer paragraph) must not cross-match either,
 *      since find_exact_post_match() joined across all posts too.
 *   4. Regression guard: legitimate SAME-post memory reuse (a post's own,
 *      previously-saved translation for the same field) still works with
 *      post_id scoping active.
 *   5. Regression guard: PR #209's per-field scoping (869enmhwe) still
 *      works — a content request for a post never returns that SAME post's
 *      own excerpt translation, with post_id scoping layered on top.
 *   6. Full call-chain check via AutoTranslate::translate(), exactly as the
 *      real bulk auto-translate flow hits it (post editor -> /translate/auto
 *      -> AutoTranslate::translate() -> TranslationMemory::suggest()).
 *   7. Wiring guard: deploy/visit-translate-all.php — the actual bulk
 *      auto-translate script that produced the live incident (869enmmvq) —
 *      forwards $post_id into AutoTranslate::translate(). This caller is a
 *      standalone WP-CLI-style script (does its own wp-load.php + get_posts()),
 *      so it can't be exercised through the stubbed classes above; this is a
 *      static source check that the fix actually reaches every caller, not
 *      just the post-editor REST path already covered by assertions 1-6.
 */

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line: php tests/verify-869enmrpz-translation-memory-post-scope.php\n");
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
function current_time($type) { return '2026-08-22 12:00:00'; }

$GLOBALS['stm_options'] = [];
function get_option($name, $default = false) { return $GLOBALS['stm_options'][$name] ?? $default; }
function update_option($name, $value)        { $GLOBALS['stm_options'][$name] = $value; return true; }

function wp_cache_get($key, $group = '')             { return false; }
function wp_cache_set($key, $data, $group = '', $e = 0) {}

// Minimal base classes so class-language-switcher.php / class-sitemap.php
// can be declared (never instantiated in this test).
class WP_Widget {}
class WP_Sitemaps_Provider {}

// --- Fixtures --------------------------------------------------------------
//
// Two posts built from the SAME seasonal-guide template — identical heading
// and paragraph structure, only the season word swapped — modelled on the
// ticket's own description of the live incident (869enmmvq).

function build_seasonal_content($season) {
    return str_repeat(
        "In de {$season} is Giethoorn op zijn mooist. Huur een fluisterboot en vaar rustig langs de " .
        "grachten, geniet van de rietgedekte boerderijen en stop onderweg bij een van de lokale " .
        "terrasjes. ",
        40
    ); // ~6,000+ chars, matches the ticket's real-world content length order of magnitude
}

$nl_content_a = build_seasonal_content('lente');   // Post A: spring guide
$nl_content_b = build_seasonal_content('herfst');  // Post B: autumn guide — near-duplicate template

$en_translation_a = 'In spring, Giethoorn is at its most beautiful. Rent a whisper boat...'; // Post A's OWN translation
$en_translation_b_own = 'In autumn, Giethoorn is at its most beautiful. Rent a whisper boat...'; // Post B's OWN translation (for the regression guard)

$shared_disclaimer_nl = 'Prijzen en openingstijden kunnen wijzigen; controleer altijd de actuele informatie ter plaatse.';
$shared_disclaimer_en = 'Prices and opening hours are subject to change; always check current information locally.';

$GLOBALS['stm_posts'] = [
    1700 => (object) [
        'ID' => 1700, 'post_title' => 'Giethoorn in de lente', 'post_name' => 'giethoorn-lente',
        'post_excerpt' => $shared_disclaimer_nl, 'post_content' => $nl_content_a,
    ],
    1701 => (object) [
        'ID' => 1701, 'post_title' => 'Giethoorn in de herfst', 'post_name' => 'giethoorn-herfst',
        'post_excerpt' => $shared_disclaimer_nl, 'post_content' => $nl_content_b,
    ],
];

$GLOBALS['stm_post_translations'] = [];
$GLOBALS['stm_updated_at_counter'] = 0;
function stm_seed_translation($post_id, $field_name, $language_code, $translation) {
    $GLOBALS['stm_post_translations'][] = [
        'post_id'       => $post_id,
        'field_name'    => $field_name,
        'language_code' => $language_code,
        'translation'   => $translation,
        'updated_at'    => ++$GLOBALS['stm_updated_at_counter'],
    ];
}

$GLOBALS['stm_default_language'] = (object) ['code' => 'nl', 'is_default' => 1];

// --- $wpdb stub ------------------------------------------------------------
//
// prepare() substitutes each %s/%d in place with a unique \x01<id>\x01
// token (not appended at the end) so positional order survives even when a
// query is assembled from smaller, separately-prepared fragments (field_name
// AND/OR post_id scoping) — exactly like real wpdb::prepare(), just without
// needing a real SQL engine. get_var()/get_results() locate each fragment's
// resolved value by searching for its literal marker text next to the
// token, rather than assuming a fixed argument position — this stays
// correct regardless of which optional fragments (field_name, post_id) are
// present in a given query.

$wpdb = new class {
    public $prefix = 'wp_';
    public $posts = 'wp_posts';
    private $argStore = [];
    private $argCounter = 0;

    public function prepare($query, ...$args) {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        $i = 0;
        return preg_replace_callback('/%[sd]/', function ($m) use (&$i, $args) {
            $id = $this->argCounter++;
            $this->argStore[$id] = $args[$i];
            $i++;
            return "\x01{$id}\x01";
        }, $query);
    }

    private function argAfter($query, $marker) {
        if (!preg_match('/' . preg_quote($marker, '/') . '\x01(\d+)\x01/', $query, $m)) {
            return null;
        }
        return $this->argStore[(int) $m[1]];
    }

    private function extractArgs($query) {
        preg_match_all("/\x01(\d+)\x01/", $query, $m);
        return array_map(fn($id) => $this->argStore[(int) $id], $m[1]);
    }

    public function get_row($query, $output = ARRAY_A) {
        if (strpos($query, 'stm_languages') !== false && strpos($query, 'is_default') !== false) {
            return $GLOBALS['stm_default_language'];
        }
        return null;
    }

    public function get_var($query) {
        if (strpos($query, 'stm_strings') !== false) {
            // find_exact_string_match — no template-string fixtures, never matches.
            return null;
        }

        $post_id_filter = $this->argAfter($query, 'pt.post_id = ');

        if (strpos($query, 'stm_post_translations') !== false
            && strpos($query, 'pt.field_name = ') !== false
            && preg_match('/p\.(\w+) = /', $query, $cm)
        ) {
            // find_exact_post_match — known field_type branch.
            $args = $this->extractArgs($query);
            [$target_lang, $field_type, $text] = $args;
            $column = $cm[1];
            $best = null;
            foreach ($GLOBALS['stm_post_translations'] as $row) {
                if ($row['language_code'] !== $target_lang) continue;
                if ($row['field_name'] !== $field_type) continue;
                if ($row['translation'] === '') continue;
                if ($post_id_filter !== null && $row['post_id'] !== $post_id_filter) continue;
                $post = $GLOBALS['stm_posts'][$row['post_id']] ?? null;
                if (!$post || ($post->$column ?? null) !== $text) continue;
                if ($best === null || $row['updated_at'] > $best['updated_at']) {
                    $best = $row;
                }
            }
            return $best ? $best['translation'] : null;
        }

        if (strpos($query, 'stm_post_translations') !== false
            && strpos($query, 'p.post_title = ') !== false
            && strpos($query, 'p.post_content = ') !== false
        ) {
            // find_exact_post_match — legacy/unknown field_type fallback branch.
            $args = $this->extractArgs($query);
            [$target_lang, $text1, $text2] = $args;
            $best = null;
            foreach ($GLOBALS['stm_post_translations'] as $row) {
                if ($row['language_code'] !== $target_lang) continue;
                if ($row['translation'] === '') continue;
                if ($post_id_filter !== null && $row['post_id'] !== $post_id_filter) continue;
                $post = $GLOBALS['stm_posts'][$row['post_id']] ?? null;
                if (!$post) continue;
                if ($post->post_title !== $text1 && $post->post_content !== $text2) continue;
                if ($best === null || $row['updated_at'] > $best['updated_at']) {
                    $best = $row;
                }
            }
            return $best ? $best['translation'] : null;
        }

        return null;
    }

    public function get_results($query, $output = ARRAY_A) {
        if (strpos($query, 'DISTINCT pt.translation, pt.field_name') !== false) {
            // find_fuzzy_matches
            $args = $this->extractArgs($query);
            $target_lang = $args[0];
            $field_type_filter = $this->argAfter($query, 'pt.field_name = ');
            $post_id_filter = $this->argAfter($query, 'pt.post_id = ');

            $rows = [];
            foreach ($GLOBALS['stm_post_translations'] as $row) {
                if ($row['language_code'] !== $target_lang) continue;
                if ($row['translation'] === '') continue;
                if ($field_type_filter !== null && $row['field_name'] !== $field_type_filter) continue;
                if ($post_id_filter !== null && $row['post_id'] !== $post_id_filter) continue;
                $post = $GLOBALS['stm_posts'][$row['post_id']] ?? null;
                if (!$post) continue;
                $rows[] = (object) [
                    'translation'  => $row['translation'],
                    'field_name'   => $row['field_name'],
                    'post_title'   => $post->post_title,
                    'post_excerpt' => $post->post_excerpt,
                    'post_content' => $post->post_content,
                    'post_name'    => $post->post_name,
                    '_updated_at'  => $row['updated_at'],
                ];
            }
            usort($rows, fn($a, $b) => $b->_updated_at <=> $a->_updated_at);
            return array_slice($rows, 0, 200);
        }

        return [];
    }
};

// --- Load the real plugin code -------------------------------------------

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
function assert_true($name, $cond, $detail = '') {
    global $results;
    $results[] = ['name' => $name, 'pass' => (bool) $cond, 'detail' => $detail];
}

// Sanity check: confirm the two seasonal contents are actually similar
// enough (same template) to trigger the fuzzy-match strategy at all —
// otherwise this test would pass vacuously.
similar_text(strtolower($nl_content_a), strtolower($nl_content_b), $sanity_percent);
assert_true(
    'sanity: the two near-duplicate-template contents score above the fuzzy similarity threshold (0.6)',
    ($sanity_percent / 100) >= 0.6,
    'similarity=' . round($sanity_percent / 100, 3)
);

// 1. Seed ONLY post A's content translation.
stm_seed_translation(1700, 'post_content', 'en', $en_translation_a);

// 1a. WITHOUT post_id (the pre-fix call shape) the near-duplicate content
//     DOES cross-match — proving this is a real, reproducible defect and
//     not a hypothetical one.
$unscoped = STM\TranslationMemory::suggest($nl_content_b, 'en', 'post_content');
assert_true(
    'pre-fix shape (no post_id): near-duplicate-template fuzzy match DOES cross post A\'s translation onto post B\'s query',
    !empty($unscoped) && $unscoped[0]['text'] === $en_translation_a,
    !empty($unscoped) ? ('got: ' . substr($unscoped[0]['text'], 0, 40)) : 'no suggestions'
);

// 1b. WITH post B's own post_id, the same near-duplicate query must NOT
//     return post A's translation — the core fix.
$scoped = STM\TranslationMemory::suggest($nl_content_b, 'en', 'post_content', 1701);
assert_true(
    'TranslationMemory::suggest() with post_id=1701 never returns post A\'s (1700) stored translation, however similar',
    empty($scoped) || $scoped[0]['text'] !== $en_translation_a,
    !empty($scoped) ? ('got: ' . substr($scoped[0]['text'], 0, 40)) : 'empty (correct)'
);

// 1c. Full call chain, exactly as the real bulk auto-translate flow hits
//     it: post editor -> /translate/auto -> AutoTranslate::translate().
//     No OpenAI key is configured, so if memory correctly finds nothing
//     this falls through to translate_openai()'s "not configured" return
//     rather than fabricating a translation.
$result = STM\AutoTranslate::translate($nl_content_b, 'nl', 'en', 'post_content', 1701);
assert_true(
    'AutoTranslate::translate() forwards post_id so post B never receives post A\'s memory-matched translation',
    $result['provider'] !== 'translation_memory' || $result['translation'] !== $en_translation_a,
    'provider=' . $result['provider'] . ' translation=' . substr($result['translation'], 0, 60)
);
assert_same(
    'post B content request with only post A\'s translation in memory falls through to the configured provider (no false cross-post hit)',
    'openai',
    $result['provider']
);

// 2. Exact-match cross-post variant: both posts share a byte-identical
//    excerpt (a common disclaimer paragraph). Post A's excerpt translation
//    must not satisfy post B's excerpt request either.
$GLOBALS['stm_post_translations'] = [];
stm_seed_translation(1700, 'post_excerpt', 'en', $shared_disclaimer_en);
$exact_scoped = STM\TranslationMemory::suggest($shared_disclaimer_nl, 'en', 'post_excerpt', 1701);
assert_true(
    'find_exact_post_match(): a byte-identical excerpt shared with another post does not cross-match with post_id scoping active',
    empty($exact_scoped),
    !empty($exact_scoped) ? ('got: ' . substr($exact_scoped[0]['text'], 0, 40)) : 'empty (correct)'
);
$exact_unscoped = STM\TranslationMemory::suggest($shared_disclaimer_nl, 'en', 'post_excerpt');
assert_true(
    'sanity: WITHOUT post_id, the shared-excerpt exact match DOES cross-match (confirms this branch was exploitable too)',
    !empty($exact_unscoped) && $exact_unscoped[0]['text'] === $shared_disclaimer_en
);

// 3. Regression guard: legitimate SAME-post reuse still works once post B
//    genuinely has its own saved content translation.
$GLOBALS['stm_post_translations'] = [];
stm_seed_translation(1700, 'post_content', 'en', $en_translation_a);
stm_seed_translation(1701, 'post_content', 'en', $en_translation_b_own);
$own = STM\TranslationMemory::suggest($nl_content_b, 'en', 'post_content', 1701);
assert_true(
    'legitimate same-post exact match still reused from memory when post_id scoping is active',
    !empty($own) && $own[0]['text'] === $en_translation_b_own,
    !empty($own) ? ('got: ' . substr($own[0]['text'], 0, 40)) : 'empty'
);

// 4. Regression guard: PR #209's per-field scoping (869enmhwe) still works
//    layered on top of post_id scoping — post A's own excerpt translation
//    must not satisfy post A's own content request.
$GLOBALS['stm_post_translations'] = [];
stm_seed_translation(1700, 'post_excerpt', 'en', $shared_disclaimer_en);
$field_scoped = STM\TranslationMemory::suggest($nl_content_a, 'en', 'post_content', 1700);
assert_true(
    'field-name scoping (869enmhwe) still holds: post A\'s own excerpt translation does not satisfy post A\'s content request',
    empty($field_scoped) || $field_scoped[0]['text'] !== $shared_disclaimer_en
);

// 5. Wiring guard: the bulk auto-translate deploy script must forward
//    $post_id — it's a separate caller of AutoTranslate::translate() from
//    the post-editor REST path, and it's the one that actually produced the
//    live cross-post-swap incident (869enmmvq bulk pass).
$bulkScript = dirname(__DIR__, 3) . '/deploy/visit-translate-all.php';
if (file_exists($bulkScript)) {
    $src = file_get_contents($bulkScript);
    preg_match('/AutoTranslate::translate\(\s*([^)]*)\)/', $src, $callMatch);
    $argCount = $callMatch ? count(array_filter(explode(',', $callMatch[1]), fn($a) => trim($a) !== '')) : 0;
    assert_true(
        'deploy/visit-translate-all.php forwards $post_id (5th arg) into AutoTranslate::translate()',
        $argCount >= 5 && strpos($callMatch[1] ?? '', '$post_id') !== false,
        $callMatch ? ('call: ' . trim($callMatch[1])) : 'AutoTranslate::translate() call not found'
    );
} else {
    assert_true('deploy/visit-translate-all.php exists (skipped: file not found at ' . $bulkScript . ')', false);
}

// --- Report ------------------------------------------------------------

$pass = 0; $fail = 0;
echo "Translation-memory post-scope verification (task 869enmrpz)\n";
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
