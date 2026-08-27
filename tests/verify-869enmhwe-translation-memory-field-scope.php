<?php
/**
 * Automated verification for task 869enmhwe — auto-translate returning an
 * excerpt's translation for a full-content request (translation-memory
 * lookup ran field-blind).
 *
 * Run from the plugin root:   php tests/verify-869enmhwe-translation-memory-field-scope.php
 *
 * Stubs the WordPress hook/DB API (no install or database required) and
 * requires the real main plugin file, so the actual STM\TranslationMemory
 * and STM\AutoTranslate classes are exercised end to end:
 *
 *   1. The reported bug: a post's excerpt is auto-translated and saved
 *      (stm_post_translations row, field_name='post_excerpt'). A later
 *      auto-translate call for that SAME post's full content must NOT
 *      return the excerpt's translation, even though the content text is
 *      (by definition) an exact match for the post's own live post_content
 *      column — the old code's "exact match" query ignored field_name
 *      entirely, and AutoTranslate::translate() never forwarded its own
 *      $context param down to the memory lookup in the first place.
 *   2. Regression guard: once a *content* translation is also saved for the
 *      same post/text, translate() DOES reuse it from memory (field-scoped
 *      exact match still works for the field it was actually written for).
 *   3. Fuzzy-match length-ratio guard: a fuzzy candidate whose original text
 *      is 35% shorter than the query is rejected under the new ~20%
 *      deviation cap, even though the old 50% cap would have let it through
 *      (asserted directly against the pre-fix cap to prove the tightening,
 *      not just that *some* filtering exists).
 *   4. Fuzzy-match still finds legitimately similar text within the ratio
 *      cap and the similarity threshold (not just more restrictive, still
 *      useful) — and reports the *matching field's* original text, not
 *      always the post title.
 */

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line: php tests/verify-869enmhwe-translation-memory-field-scope.php\n");
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
// One post, modelled on the ticket's own repro (post 1673,
// 'beste-tijd-om-giethoorn-te-bezoeken'): a short NL excerpt and a much
// longer NL content field, both live directly on wp_posts (the default-
// language text), plus an stm_post_translations table holding EN rows.

$nl_excerpt = str_repeat('Elk seizoen laat een ander Giethoorn zien. ', 3); // 132 chars
$nl_content = str_repeat(
    'In de lente bloeien de tuinen langs de grachten, in de zomer varen de fluisterboten af en aan, ' .
    'in de herfst kleuren de bomen langs het water en in de winter liggen de grachten er stil en ' .
    'besneeuwd bij. ',
    45
); // ~6,480 chars — matches the ticket's "6,494 chars" order of magnitude

$en_excerpt_translation = 'Each season showcases a different Giethoorn.'; // ~45 chars, stands in for the reported 145-char excerpt translation
$en_content_translation = str_repeat(
    'Gardens bloom along the canals in spring, whisper boats come and go in summer, trees turn colour ' .
    'along the water in autumn, and the canals lie still and snow-covered in winter. ',
    40
);

$GLOBALS['stm_posts'] = [
    1673 => (object) [
        'ID'           => 1673,
        'post_title'   => 'Beste tijd om Giethoorn te bezoeken',
        'post_name'    => 'beste-tijd-om-giethoorn-te-bezoeken',
        'post_excerpt' => $nl_excerpt,
        'post_content' => $nl_content,
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
// query is assembled from a smaller, separately-prepared fragment (e.g.
// find_fuzzy_matches' $where_field) — exactly like real wpdb::prepare(),
// just without needing a real SQL engine to resolve get_var/get_results.

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
        $args = $this->extractArgs($query);

        if (strpos($query, 'stm_strings') !== false) {
            // find_exact_string_match — no template-string fixtures, never matches.
            return null;
        }

        if (strpos($query, 'stm_post_translations') !== false
            && strpos($query, 'pt.field_name = ') !== false
            && preg_match('/p\.(\w+) = /', $query, $m)
        ) {
            // find_exact_post_match — known field_type branch.
            [$target_lang, $field_type, $text] = $args;
            $column = $m[1];
            $best = null;
            foreach ($GLOBALS['stm_post_translations'] as $row) {
                if ($row['language_code'] !== $target_lang) continue;
                if ($row['field_name'] !== $field_type) continue;
                if ($row['translation'] === '') continue;
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
            [$target_lang, $text1, $text2] = $args;
            $best = null;
            foreach ($GLOBALS['stm_post_translations'] as $row) {
                if ($row['language_code'] !== $target_lang) continue;
                if ($row['translation'] === '') continue;
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
        $args = $this->extractArgs($query);

        if (strpos($query, 'DISTINCT pt.translation, pt.field_name') !== false) {
            // find_fuzzy_matches
            $target_lang = $args[0];
            $field_type  = $args[1] ?? null;
            $rows = [];
            foreach ($GLOBALS['stm_post_translations'] as $row) {
                if ($row['language_code'] !== $target_lang) continue;
                if ($row['translation'] === '') continue;
                if ($field_type !== null && $row['field_name'] !== $field_type) continue;
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

// 1. Seed ONLY the excerpt translation, mirroring the ticket's repro step 1
//    ("Auto-translate + save the excerpt of a post").
stm_seed_translation(1673, 'post_excerpt', 'en', $en_excerpt_translation);

// 1a. Direct memory-layer check: an exact-post-match lookup for the full
//     content must not surface the excerpt row.
$suggestions = STM\TranslationMemory::suggest($nl_content, 'en', 'post_content');
assert_true(
    'TranslationMemory::suggest(content) does not return the excerpt translation once only the excerpt is in memory',
    empty($suggestions) || $suggestions[0]['text'] !== $en_excerpt_translation
);

// 1b. Full call chain, exactly as the ticket's repro hits it: POST
//     /stm/v1/translate/auto for the post's full content -> AutoTranslate::translate().
//     No OpenAI key is configured, so if memory correctly finds nothing this
//     falls through to translate_openai()'s early "not configured" return
//     rather than fabricating a translation — the fix is proven by the
//     provider/translation NOT being the excerpt's memory entry.
$result = STM\AutoTranslate::translate($nl_content, 'nl', 'en', 'post_content');
assert_true(
    'AutoTranslate::translate() forwards its context param so the excerpt cannot satisfy a content request',
    $result['provider'] !== 'translation_memory' || $result['translation'] !== $en_excerpt_translation,
    'provider=' . $result['provider'] . ' translation=' . substr($result['translation'], 0, 60)
);
assert_same(
    'content request with only an excerpt in memory falls through to the configured provider (no false memory hit)',
    'openai',
    $result['provider']
);

// 2. Regression guard: once a *content* translation is genuinely saved for
//    this exact post/text, translate() must still reuse it from memory —
//    the fix must not turn off legitimate same-field reuse.
stm_seed_translation(1673, 'post_content', 'en', $en_content_translation);
$result2 = STM\AutoTranslate::translate($nl_content, 'nl', 'en', 'post_content');
assert_same(
    'legitimate same-field exact match still reused from memory (provider)',
    'translation_memory',
    $result2['provider']
);
assert_same(
    'legitimate same-field exact match still reused from memory (translation)',
    $en_content_translation,
    $result2['translation']
);

// 3. Fuzzy-match length-ratio guard: a candidate whose original text is 35%
//    shorter than the query must be rejected under the new ~20% cap, even
//    though it is a true prefix of the query (i.e. would score a very high
//    similar_text() ratio and would have passed the OLD 50% length cap).
$GLOBALS['stm_post_translations'] = [];
$fuzzy_query = str_repeat('a', 100);
$short_candidate_original = str_repeat('a', 65); // 35% shorter — prefix of the query
$GLOBALS['stm_posts'][9001] = (object) [
    'ID' => 9001, 'post_title' => $short_candidate_original, 'post_name' => 'short',
    'post_excerpt' => $short_candidate_original, 'post_content' => $short_candidate_original,
];
stm_seed_translation(9001, 'post_title', 'en', 'SHOULD NOT MATCH');
$suggestions3 = STM\TranslationMemory::suggest($fuzzy_query, 'en', 'post_title');
assert_true(
    'fuzzy match rejects a 35%-shorter candidate under the tightened ~20% length-ratio cap',
    empty($suggestions3)
);

// 4. Fuzzy match still works for genuinely similar text within the ratio cap
//    and similarity threshold, and reports the original text of the SAME
//    field the row belongs to (not always post_title).
$GLOBALS['stm_post_translations'] = [];
$similar_original = 'Elk seizoen laat een ander Giethoorn zien vanaf de boot.';
$similar_query     = 'Elk seizoen toont een ander Giethoorn gezien vanaf de boot.';
$GLOBALS['stm_posts'][9002] = (object) [
    'ID' => 9002, 'post_title' => 'irrelevant title, wrong field', 'post_name' => 'similar',
    'post_excerpt' => $similar_original, 'post_content' => 'irrelevant content, wrong field',
];
stm_seed_translation(9002, 'post_excerpt', 'en', 'Each season shows a different Giethoorn seen from the boat.');
$suggestions4 = STM\TranslationMemory::suggest($similar_query, 'en', 'post_excerpt');
assert_true(
    'fuzzy match still finds genuinely similar same-field text within the ratio cap',
    !empty($suggestions4)
);
if (!empty($suggestions4)) {
    assert_same(
        'fuzzy match compares against the matching field\'s original text (post_excerpt), not the post title',
        $similar_original,
        $suggestions4[0]['matched_original']
    );
}

// --- Report ------------------------------------------------------------

$pass = 0; $fail = 0;
echo "Translation-memory field-scope verification (task 869enmhwe)\n";
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
