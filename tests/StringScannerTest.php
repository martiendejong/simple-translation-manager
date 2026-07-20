<?php
/**
 * PHPUnit tests: StringScanner — detects __stm()/_e_stm() calls in the
 * active theme and the plugin's own templates, and registers them as
 * wp_stm_strings rows with the discovered text seeded as the
 * default-language translation.
 */

namespace STM\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use STM\StringScanner;
use STM\Tests\Fakes\FakeWpdb;

class StringScannerTest extends TestCase {

    /** @var FakeWpdb */
    private $wpdb;

    /** @var string */
    private $tmpDir;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        global $wpdb;
        $wpdb = new FakeWpdb();
        $this->wpdb = $wpdb;

        $this->wpdb->seed('wp_stm_languages', [
            'code' => 'en', 'name' => 'English', 'native_name' => 'English',
            'is_default' => 1, 'is_active' => 1, 'order_index' => 1,
        ]);

        Functions\when('wp_cache_get')->justReturn(false);
        Functions\when('wp_cache_set')->justReturn(true);
        Functions\when('current_time')->justReturn('2026-07-20 00:00:00');
        Functions\when('apply_filters')->returnArg(2);

        $this->tmpDir = sys_get_temp_dir() . '/stm-scanner-test-' . uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
        $this->removeDir($this->tmpDir);
    }

    private function removeDir($dir) {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $path = $dir . '/' . $f;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function writeFile($relative, $contents) {
        $path = $this->tmpDir . '/' . $relative;
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $contents);
        return $path;
    }

    // -----------------------------------------------------------------
    // extract_calls_from_file — token-level parsing
    // -----------------------------------------------------------------

    public function test_extract_calls_from_file_finds_calls_with_all_arg_shapes() {
        $file = $this->writeFile('header.php', <<<'PHP'
<?php
_e_stm('nav.home', 'Home', 'nav');
$greeting = __stm('hero.title', 'Welcome to our site', 'homepage');
__stm('no.fallback');
PHP
        );

        $entries = StringScanner::extract_calls_from_file($file);

        $this->assertSame([
            ['key' => 'nav.home', 'fallback' => 'Home', 'context' => 'nav'],
            ['key' => 'hero.title', 'fallback' => 'Welcome to our site', 'context' => 'homepage'],
            ['key' => 'no.fallback', 'fallback' => '', 'context' => 'general'],
        ], $entries);
    }

    public function test_extract_calls_from_file_ignores_commented_out_calls() {
        $file = $this->writeFile('header.php', <<<'PHP'
<?php
// _e_stm('commented.line', 'Should not appear', 'nav');
/* __stm('commented.block', 'Should not appear either', 'nav'); */
_e_stm('nav.home', 'Home', 'nav');
PHP
        );

        $entries = StringScanner::extract_calls_from_file($file);

        $this->assertCount(1, $entries);
        $this->assertSame('nav.home', $entries[0]['key']);
    }

    public function test_extract_calls_from_file_ignores_dynamic_key_arguments() {
        $file = $this->writeFile('header.php', <<<'PHP'
<?php
$key = 'runtime.key';
_e_stm($key, 'Should not be captured, key is not a literal', 'nav');
_e_stm('nav.about', "Interpolated $key not a literal fallback either", 'nav');
PHP
        );

        $entries = StringScanner::extract_calls_from_file($file);

        // First call: dynamic key -> skipped entirely.
        // Second call: literal key but non-literal (interpolated) fallback -> key kept, fallback defaults to ''.
        $this->assertSame([
            ['key' => 'nav.about', 'fallback' => '', 'context' => 'nav'],
        ], $entries);
    }

    public function test_extract_calls_from_file_returns_empty_for_non_php_content() {
        $file = $this->writeFile('readme.txt', "_e_stm('not.php', 'Should be skipped', 'x')");

        $this->assertSame([], StringScanner::extract_calls_from_file($file));
    }

    // -----------------------------------------------------------------
    // register_strings — DB writes, idempotency, manual-edit preservation
    // -----------------------------------------------------------------

    public function test_register_strings_creates_string_and_seeds_default_translation() {
        $result = StringScanner::register_strings([
            ['key' => 'nav.home', 'fallback' => 'Home', 'context' => 'nav'],
        ]);

        $this->assertSame(1, $result['added']);

        $strings = $this->wpdb->all('wp_stm_strings');
        $this->assertCount(1, $strings);
        $this->assertSame('nav.home', $strings[0]['string_key']);
        $this->assertSame('nav', $strings[0]['context']);

        $translations = $this->wpdb->all('wp_stm_translations');
        $this->assertCount(1, $translations);
        $this->assertSame('Home', $translations[0]['translation']);
        $this->assertSame('en', $translations[0]['language_code']);
        $this->assertSame('published', $translations[0]['status']);
    }

    public function test_register_strings_uses_key_as_translation_when_fallback_empty() {
        StringScanner::register_strings([
            ['key' => 'no.fallback', 'fallback' => '', 'context' => 'general'],
        ]);

        $translations = $this->wpdb->all('wp_stm_translations');
        $this->assertSame('no.fallback', $translations[0]['translation']);
    }

    public function test_register_strings_is_idempotent_no_duplicates_on_rerun() {
        $entries = [['key' => 'nav.home', 'fallback' => 'Home', 'context' => 'nav']];

        $first = StringScanner::register_strings($entries);
        $second = StringScanner::register_strings($entries);

        $this->assertSame(1, $first['added']);
        $this->assertSame(0, $second['added']);
        $this->assertCount(1, $this->wpdb->all('wp_stm_strings'));
        $this->assertCount(1, $this->wpdb->all('wp_stm_translations'));
    }

    public function test_register_strings_preserves_manually_edited_translation_on_rerun() {
        StringScanner::register_strings([
            ['key' => 'nav.home', 'fallback' => 'Home', 'context' => 'nav'],
        ]);

        $translations = $this->wpdb->all('wp_stm_translations');
        $this->wpdb->update('wp_stm_translations', ['translation' => 'Homepage (edited by human)'], ['id' => $translations[0]['id']]);

        StringScanner::register_strings([
            ['key' => 'nav.home', 'fallback' => 'Home', 'context' => 'nav'],
        ]);

        $translations = $this->wpdb->all('wp_stm_translations');
        $this->assertCount(1, $translations);
        $this->assertSame('Homepage (edited by human)', $translations[0]['translation']);
    }

    public function test_register_strings_treats_same_key_in_different_contexts_as_distinct() {
        StringScanner::register_strings([
            ['key' => 'title', 'fallback' => 'Services', 'context' => 'nav'],
            ['key' => 'title', 'fallback' => 'Our Services', 'context' => 'footer'],
        ]);

        $strings = $this->wpdb->all('wp_stm_strings');
        $this->assertCount(2, $strings);
    }

    public function test_register_strings_skips_invalid_keys() {
        $result = StringScanner::register_strings([
            ['key' => '', 'fallback' => 'Empty key', 'context' => 'nav'],
        ]);

        $this->assertSame(0, $result['added']);
        $this->assertSame([], $this->wpdb->all('wp_stm_strings'));
    }

    // -----------------------------------------------------------------
    // scan_and_register — directory walking, dedupe, exclusions
    // -----------------------------------------------------------------

    public function test_scan_and_register_walks_theme_and_skips_vendor_and_non_php_files() {
        $this->writeFile('theme/header.php', "<?php _e_stm('nav.home', 'Home', 'nav');");
        $this->writeFile('theme/footer.php', "<?php __stm('footer.copy', 'All rights reserved', 'footer');");
        $this->writeFile('theme/vendor/lib.php', "<?php _e_stm('vendor.string', 'Should be skipped', 'vendor');");
        $this->writeFile('theme/readme.txt', "_e_stm('not.php', 'Should be skipped', 'x')");

        Functions\when('get_stylesheet_directory')->justReturn($this->tmpDir . '/theme');
        Functions\when('get_template_directory')->justReturn($this->tmpDir . '/theme');

        $result = StringScanner::scan_and_register();

        $this->assertSame(2, $result['added']);

        $keys = array_column($this->wpdb->all('wp_stm_strings'), 'string_key');
        sort($keys);
        $this->assertSame(['footer.copy', 'nav.home'], $keys);
    }

    public function test_scan_and_register_dedupes_same_string_found_in_multiple_files() {
        $this->writeFile('theme/header.php', "<?php _e_stm('nav.home', 'Home', 'nav');");
        $this->writeFile('theme/sidebar.php', "<?php _e_stm('nav.home', 'Home', 'nav');");

        Functions\when('get_stylesheet_directory')->justReturn($this->tmpDir . '/theme');
        Functions\when('get_template_directory')->justReturn($this->tmpDir . '/theme');

        $result = StringScanner::scan_and_register();

        $this->assertSame(1, $result['unique_found']);
        $this->assertSame(1, $result['added']);
        $this->assertCount(1, $this->wpdb->all('wp_stm_strings'));
    }

    public function test_scan_and_register_second_run_adds_nothing_new() {
        $this->writeFile('theme/header.php', "<?php _e_stm('nav.home', 'Home', 'nav');");

        Functions\when('get_stylesheet_directory')->justReturn($this->tmpDir . '/theme');
        Functions\when('get_template_directory')->justReturn($this->tmpDir . '/theme');

        $first = StringScanner::scan_and_register();
        $second = StringScanner::scan_and_register();

        $this->assertSame(1, $first['added']);
        $this->assertSame(0, $second['added']);
        $this->assertCount(1, $this->wpdb->all('wp_stm_strings'));
    }
}
