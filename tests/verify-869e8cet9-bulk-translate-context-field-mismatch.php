<?php
/**
 * Automated verification for task 869e8cet9 (round 2) — the bulk
 * auto-translate deploy script (deploy/visit-translate-all.php) called
 * AutoTranslate::translate() with a free-text $context description
 * ('title', 'excerpt', 'main content (HTML mag behouden blijven)') instead
 * of the canonical field-name key that field_name_to_column() actually
 * recognizes ('post_title'/'post_excerpt'/'post_content'/'post_name').
 * find_exact_post_match() silently falls back to its field-BLIND legacy
 * query whenever $field_type doesn't map to a known column — so a bulk
 * translate pass for a post whose title was already translated could have
 * its post_content lookup satisfied by that SAME post's OWN title
 * translation, because both "the text being translated" (which is always,
 * trivially, the post's own live post_content or post_title at that
 * moment) match the fallback's field-blind WHERE clause.
 *
 * Confirmed live, staging + production (869e8cet9, 2026-08-23): 't
 * Zwaantje's story post (#1066) got a 26-character DE post_content
 * translation — byte-identical to its own 26-character DE post_title —
 * instead of the real ~190-word story, because visit-translate-all.php
 * processes post_title before post_content for each post and the bulk
 * script's old $context values never matched field_name_to_column()'s
 * switch. admin-post-editor.js's translateField() was never affected — it
 * already passes the canonical field key (context: field) — so this only
 * ever hit posts translated via the bulk one-shot script.
 *
 * Run from the plugin root:   php tests/verify-869e8cet9-bulk-translate-context-field-mismatch.php
 *
 *   1. Reproduces the incident: with the post's own DE post_title already
 *      saved, a post_content lookup using the OLD prose $context
 *      ('main content (...)') incorrectly returns the title's translation.
 *   2. Proves the fix: the SAME lookup using the canonical 'post_content'
 *      $context returns null instead (no legitimate memory match exists
 *      yet), so the caller falls through to a real provider translation
 *      rather than silently reusing the wrong field's text.
 *   3. Wiring guard: deploy/visit-translate-all.php's $fields array passes
 *      one of the four canonical field-name keys as its AutoTranslate
 *      context for post_title/post_content/post_excerpt — not a free-text
 *      description — for every field, so the fallback this test reproduces
 *      can never trigger from that call site again.
 */

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line: php tests/verify-869e8cet9-bulk-translate-context-field-mismatch.php\n");
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

// --- Fixture: one post, modelled on 't Zwaantje's story (#1066) --------

$nl_title   = "Aan het water bij 't Zwaantje";
$nl_content = str_repeat(
    "Bij 't Zwaantje aan de Hylkemaweg begint iedere dag met hetzelfde geluid: water dat zacht tegen " .
    "de steiger klotst. Sinds 1992 runt Jolanda hier samen met haar familie een plek waar varen, eten " .
    "en gastvrijheid vanzelfsprekend samenkomen. ",
    6
); // long enough to be unambiguously distinct from the title

$de_title_translation = "Am Wasser beim 't Zwaantje"; // already bulk-translated and saved, per the real incident

$GLOBALS['stm_posts'] = [
    1066 => (object) [
        'ID' => 1066, 'post_title' => $nl_title, 'post_name' => 'aan-het-water-bij-t-zwaantje',
        'post_excerpt' => 'Excerpt tekst, niet relevant voor deze test.', 'post_content' => $nl_content,
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

// Only the post_title/DE translation exists so far — exactly the DB state
// visit-translate-all.php produces mid-loop, right after translating
// post_title and right before it translates post_content for the same post.
stm_seed_translation(1066, 'post_title', 'de', $de_title_translation);

// --- $wpdb stub (mirrors class-translation-memory.php's real SQL) --------

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
            return null; // find_exact_string_match — no template-string fixtures, never matches.
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
        return []; // find_fuzzy_matches — no fixtures needed for this test.
    }
};

// --- Load the real plugin code -------------------------------------------

$pluginRoot = dirname(__DIR__);
$pluginFile = $pluginRoot . '/simple-translation-manager.php';
require_once $pluginFile;

// --- Assertions ------------------------------------------------------------

$results = [];
function assert_true($name, $cond, $detail = '') {
    global $results;
    $results[] = ['name' => $name, 'pass' => (bool) $cond, 'detail' => $detail];
}

// 1. Reproduces the incident: old free-text context ('main content (...)')
//    doesn't map to a known column, so find_exact_post_match() falls back
//    to the field-blind legacy query and incorrectly surfaces the post's
//    OWN title translation for a content lookup.
$buggy = STM\TranslationMemory::suggest($nl_content, 'de', 'main content (HTML mag behouden blijven)', 1066);
assert_true(
    'incident reproduced: free-text $context lets a content lookup return this post\'s own title translation',
    !empty($buggy) && $buggy[0]['text'] === $de_title_translation,
    $buggy ? ('got: ' . var_export($buggy[0]['text'], true)) : 'suggest() returned no match at all'
);

// 2. Proves the fix: the canonical 'post_content' context does NOT match
//    field_name_to_column()'s known-column branch (no post_content
//    translation saved yet) and — critically — does NOT fall back to the
//    field-blind legacy path either, so it correctly returns nothing rather
//    than the wrong field's translation.
$fixed = STM\TranslationMemory::suggest($nl_content, 'de', 'post_content', 1066);
assert_true(
    'fix holds: canonical \'post_content\' $context never returns the title\'s translation',
    empty($fixed) || $fixed[0]['text'] !== $de_title_translation,
    $fixed ? ('got: ' . var_export($fixed[0]['text'], true)) : 'no match (correct)'
);

// 3. Wiring guard: deploy/visit-translate-all.php's $fields array must use
//    the canonical field-name key as its AutoTranslate context for all
//    three WP-post fields it translates, not a free-text description.
$bulkScript = dirname(__DIR__, 3) . '/deploy/visit-translate-all.php';
if (file_exists($bulkScript)) {
    $src = file_get_contents($bulkScript);
    $canonical = ['post_title', 'post_excerpt', 'post_content'];
    foreach ($canonical as $field) {
        // Each entry is one line: 'post_content' => array( trim( $post->post_content ), 'post_content' ),
        // — the field key must appear again as the trailing context string
        // on its own line, not just once as the array key / property access.
        $ok = false;
        foreach (preg_split('/\R/', $src) as $line) {
            if (strpos($line, "'{$field}'") === false) {
                continue;
            }
            if (substr_count($line, "'{$field}'") >= 2) {
                $ok = true;
                break;
            }
        }
        assert_true(
            "deploy/visit-translate-all.php: \$fields['{$field}'] passes the canonical '{$field}' context (not free text)",
            $ok
        );
    }
} else {
    assert_true('deploy/visit-translate-all.php exists (skipped: file not found at ' . $bulkScript . ')', false);
}

// --- Report ------------------------------------------------------------

$pass = 0; $fail = 0;
echo "Bulk auto-translate context/field-name mismatch verification (task 869e8cet9)\n";
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
