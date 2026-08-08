<?php
/**
 * PHPUnit tests: Admin's admin_post_* form handlers (save_translation,
 * add_string, scan_strings, import_json, add_language, delete_language,
 * save_ai_settings) — added as diff-coverage for the wp_redirect() ->
 * wp_safe_redirect() rename (ClickUp 869efjuhu). Each test exercises a real
 * redirect call site so the sniff-driven rename lands on tested code, not
 * just a mechanical find/replace.
 */

namespace STM\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use STM\Admin;
use STM\Tests\Fakes\FakeWpdb;

/**
 * Marker used to interrupt admin_post handlers at the wp_safe_redirect() call
 * site. Deliberately extends \Error (not \Exception): some handlers (e.g.
 * save_translation(), add_string(), scan_strings()) wrap their redirect call
 * in try/catch(\Exception) to handle real DB errors — a \RuntimeException
 * thrown from inside that try block would be swallowed by the handler's own
 * catch clause (and re-routed into a wp_die() call) instead of reaching the
 * test, since RuntimeException is an \Exception. \Error is not.
 */
class RedirectInterrupt extends \Error {}

class AdminHandlersTest extends TestCase {

    /** @var FakeWpdb */
    private $wpdb;

    /** @var string[] temp files created during a test, removed in tearDown */
    private $tmpFiles = [];

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        if (!defined('ABSPATH')) {
            define('ABSPATH', dirname(__DIR__) . '/');
        }
        require_once dirname(__DIR__) . '/includes/class-admin.php';
        require_once dirname(__DIR__) . '/includes/class-auto-translate.php';

        global $wpdb;
        $wpdb = new FakeWpdb();
        $this->wpdb = $wpdb;

        $this->wpdb->seed('wp_stm_languages', [
            'code' => 'en', 'name' => 'English', 'native_name' => 'English',
            'flag_emoji' => '', 'is_active' => 1, 'is_default' => 1, 'order_index' => 1,
        ]);
        $this->wpdb->seed('wp_stm_languages', [
            'code' => 'de', 'name' => 'German', 'native_name' => 'Deutsch',
            'flag_emoji' => '', 'is_active' => 1, 'is_default' => 0, 'order_index' => 2,
        ]);

        Functions\when('wp_cache_get')->justReturn(false);
        Functions\when('wp_cache_set')->justReturn(true);
        Functions\when('wp_cache_delete')->justReturn(true);
        Functions\when('check_admin_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('sanitize_text_field')->returnArg(1);
        Functions\when('sanitize_textarea_field')->returnArg(1);
        Functions\when('wp_kses')->returnArg(1);
        Functions\when('get_current_user_id')->justReturn(1);
        Functions\when('current_time')->justReturn('2026-08-08 00:00:00');
        Functions\when('update_option')->justReturn(true);
    }

    protected function tearDown(): void {
        $_POST = [];
        $_GET = [];
        $_FILES = [];
        Monkey\tearDown();
        parent::tearDown();

        foreach ($this->tmpFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
        $this->tmpFiles = [];
    }

    /**
     * wp_safe_redirect() is a real function call, unlike the `exit;` that always
     * follows it in Admin's admin_post handlers — stubbing it to throw lets a
     * test observe every side effect the handler made without the raw `exit;`
     * killing the PHPUnit process itself. Handles both add_query_arg() call
     * shapes used across these handlers: ('key', 'value', $url) and
     * ([...pairs], $url).
     */
    private function stubRedirectToThrow(&$capturedUrl) {
        Functions\when('wp_get_referer')->justReturn('http://example.test/wp-admin/admin.php?page=stm-translations');
        Functions\when('add_query_arg')->alias(function (...$args) {
            if (count($args) === 2 && is_array($args[0])) {
                [$pairs, $url] = $args;
                $parts = [];
                foreach ($pairs as $k => $v) {
                    $parts[] = $k . '=' . $v;
                }
                return $url . '?' . implode('&', $parts);
            }
            [$key, $value, $url] = $args;
            return $url . '?' . $key . '=' . $value;
        });
        Functions\when('wp_safe_redirect')->alias(function ($url) use (&$capturedUrl) {
            $capturedUrl = $url;
            throw new RedirectInterrupt('redirect:' . $url);
        });
    }

    private function makeTmpFile($contents) {
        $path = tempnam(sys_get_temp_dir(), 'stm-import-');
        file_put_contents($path, $contents);
        $this->tmpFiles[] = $path;
        return $path;
    }

    // -----------------------------------------------------------------
    // save_translation() — class-admin.php:426
    // -----------------------------------------------------------------

    public function test_save_translation_inserts_and_redirects_with_updated_flag() {
        $this->wpdb->seed('wp_stm_strings', [
            'string_key' => 'nav.home', 'context' => 'nav', 'description' => '',
        ]);

        $_POST['string_id'] = 1;
        $_POST['language_code'] = 'de';
        $_POST['translation'] = 'Hallo';

        $captured = null;
        $this->stubRedirectToThrow($captured);

        try {
            Admin::save_translation();
            $this->fail('Expected the redirect stub to interrupt execution.');
        } catch (RedirectInterrupt $e) {
            // expected — see stubRedirectToThrow()
        }

        $translations = $this->wpdb->all('stm_translations');
        $this->assertCount(1, $translations);
        $this->assertSame(1, $translations[0]['string_id']);
        $this->assertSame('de', $translations[0]['language_code']);
        $this->assertSame('Hallo', $translations[0]['translation']);
        $this->assertStringContainsString('updated=1', $captured);
    }

    // -----------------------------------------------------------------
    // add_string() — class-admin.php:472
    // -----------------------------------------------------------------

    public function test_add_string_inserts_and_redirects_with_added_flag() {
        $_POST['string_key'] = 'nav.about';
        $_POST['context'] = 'nav';
        $_POST['description'] = 'About link label';

        $captured = null;
        $this->stubRedirectToThrow($captured);

        try {
            Admin::add_string();
            $this->fail('Expected the redirect stub to interrupt execution.');
        } catch (RedirectInterrupt $e) {
            // expected
        }

        $strings = $this->wpdb->all('stm_strings');
        $this->assertCount(1, $strings);
        $this->assertSame('nav.about', $strings[0]['string_key']);
        $this->assertStringContainsString('added=1', $captured);
    }

    // -----------------------------------------------------------------
    // scan_strings() — class-admin.php:492 (success), 500 (exception)
    // -----------------------------------------------------------------

    public function test_scan_strings_succeeds_and_redirects_with_scan_results() {
        $captured = null;
        $this->stubRedirectToThrow($captured);

        try {
            Admin::scan_strings();
            $this->fail('Expected the redirect stub to interrupt execution.');
        } catch (RedirectInterrupt $e) {
            // expected
        }

        $this->assertStringContainsString('stm_scanned=1', $captured);
        $this->assertStringContainsString('stm_scan_found=', $captured);
        $this->assertStringContainsString('stm_scan_added=', $captured);
    }

    public function test_scan_strings_redirects_with_error_when_scanning_throws() {
        // get_scan_directories() applies 'stm_scan_directories' *after* its own
        // is_dir() checks, so a filter is the only way to hand it a directory
        // that doesn't exist — which makes find_php_files()'s
        // RecursiveDirectoryIterator throw and exercises the catch branch.
        Functions\when('apply_filters')->alias(function ($tag, $value) {
            if ($tag === 'stm_scan_directories') {
                $value[] = sys_get_temp_dir() . '/stm-does-not-exist-' . uniqid();
            }
            return $value;
        });

        $captured = null;
        $this->stubRedirectToThrow($captured);

        try {
            Admin::scan_strings();
            $this->fail('Expected the redirect stub to interrupt execution.');
        } catch (RedirectInterrupt $e) {
            // expected
        }

        $this->assertStringContainsString('stm_error=scan_failed', $captured);
    }

    // -----------------------------------------------------------------
    // import_json() — class-admin.php:514, 523, 531, 538, 542
    // -----------------------------------------------------------------

    public function test_import_json_redirects_with_error_when_no_file_uploaded() {
        $_FILES['stm_import_file'] = ['tmp_name' => '', 'name' => ''];

        $captured = null;
        $this->stubRedirectToThrow($captured);

        try {
            Admin::import_json();
            $this->fail('Expected the redirect stub to interrupt execution.');
        } catch (RedirectInterrupt $e) {
            // expected
        }

        $this->assertStringContainsString('stm_error=no_file', $captured);
    }

    public function test_import_json_redirects_with_error_for_non_json_extension() {
        $_FILES['stm_import_file'] = [
            'tmp_name' => $this->makeTmpFile('irrelevant'),
            'name' => 'strings.txt',
        ];

        $captured = null;
        $this->stubRedirectToThrow($captured);

        try {
            Admin::import_json();
            $this->fail('Expected the redirect stub to interrupt execution.');
        } catch (RedirectInterrupt $e) {
            // expected
        }

        $this->assertStringContainsString('stm_error=invalid_type', $captured);
    }

    public function test_import_json_redirects_with_error_for_malformed_json() {
        $_FILES['stm_import_file'] = [
            'tmp_name' => $this->makeTmpFile('{not valid json'),
            'name' => 'strings.json',
        ];

        $captured = null;
        $this->stubRedirectToThrow($captured);

        try {
            Admin::import_json();
            $this->fail('Expected the redirect stub to interrupt execution.');
        } catch (RedirectInterrupt $e) {
            // expected
        }

        $this->assertStringContainsString('stm_error=invalid_json', $captured);
    }

    public function test_import_json_redirects_with_error_when_format_unrecognized() {
        // Valid JSON, but neither the {lang,translations} shape nor a map of
        // valid language codes -> API::process_import() reports it as an error.
        $_FILES['stm_import_file'] = [
            'tmp_name' => $this->makeTmpFile('[]'),
            'name' => 'strings.json',
        ];

        $captured = null;
        $this->stubRedirectToThrow($captured);

        try {
            Admin::import_json();
            $this->fail('Expected the redirect stub to interrupt execution.');
        } catch (RedirectInterrupt $e) {
            // expected
        }

        $this->assertStringContainsString('stm_error=', $captured);
    }

    public function test_import_json_imports_and_redirects_with_counts_on_success() {
        $_FILES['stm_import_file'] = [
            'tmp_name' => $this->makeTmpFile('{"de":{"nav.home":"Startseite"}}'),
            'name' => 'strings.json',
        ];

        $captured = null;
        $this->stubRedirectToThrow($captured);

        try {
            Admin::import_json();
            $this->fail('Expected the redirect stub to interrupt execution.');
        } catch (RedirectInterrupt $e) {
            // expected
        }

        $strings = $this->wpdb->all('stm_strings');
        $this->assertCount(1, $strings);
        $this->assertSame('nav.home', $strings[0]['string_key']);

        $this->assertStringContainsString('imported=1', $captured);
        $this->assertStringContainsString('stm_errors=0', $captured);
    }

    // -----------------------------------------------------------------
    // add_language() — class-admin.php:566 (invalid), 588 (success)
    // -----------------------------------------------------------------

    public function test_add_language_redirects_with_error_for_invalid_fields() {
        $_POST['lang_code'] = 'toolong';
        $_POST['lang_name'] = 'Whatever';

        $captured = null;
        $this->stubRedirectToThrow($captured);

        try {
            Admin::add_language();
            $this->fail('Expected the redirect stub to interrupt execution.');
        } catch (RedirectInterrupt $e) {
            // expected
        }

        $this->assertStringContainsString('stm_error=invalid_fields', $captured);
        $this->assertCount(2, $this->wpdb->all('stm_languages'), 'No language row should have been inserted.');
    }

    public function test_add_language_inserts_and_redirects_with_added_flag() {
        $_POST['lang_code'] = 'fr';
        $_POST['lang_name'] = 'French';
        $_POST['lang_native'] = 'Français';
        $_POST['lang_flag'] = '';

        $captured = null;
        $this->stubRedirectToThrow($captured);

        try {
            Admin::add_language();
            $this->fail('Expected the redirect stub to interrupt execution.');
        } catch (RedirectInterrupt $e) {
            // expected
        }

        $languages = $this->wpdb->all('stm_languages');
        $this->assertCount(3, $languages);
        $this->assertSame('fr', $languages[2]['code']);
        $this->assertStringContainsString('stm_added=1', $captured);
    }

    // -----------------------------------------------------------------
    // delete_language() — class-admin.php:619 (default), 628 (success)
    // -----------------------------------------------------------------

    public function test_delete_language_refuses_to_delete_the_default_language() {
        $_POST['lang_code'] = 'en';

        $captured = null;
        $this->stubRedirectToThrow($captured);

        try {
            Admin::delete_language();
            $this->fail('Expected the redirect stub to interrupt execution.');
        } catch (RedirectInterrupt $e) {
            // expected
        }

        $this->assertStringContainsString('stm_error=cannot_delete_default', $captured);
        $this->assertCount(2, $this->wpdb->all('stm_languages'));
    }

    public function test_delete_language_deletes_and_redirects_with_deleted_flag() {
        $_POST['lang_code'] = 'de';

        $captured = null;
        $this->stubRedirectToThrow($captured);

        try {
            Admin::delete_language();
            $this->fail('Expected the redirect stub to interrupt execution.');
        } catch (RedirectInterrupt $e) {
            // expected
        }

        $codes = array_column($this->wpdb->all('stm_languages'), 'code');
        $this->assertSame(['en'], $codes);
        $this->assertStringContainsString('stm_deleted=1', $captured);
    }

    // -----------------------------------------------------------------
    // save_ai_settings() — class-admin.php:692
    // -----------------------------------------------------------------

    public function test_save_ai_settings_redirects_with_saved_flag() {
        $_POST['ai_provider'] = 'openai';
        $_POST['openai_key'] = 'sk-test-123';
        $_POST['deepl_key'] = '';

        $captured = null;
        $this->stubRedirectToThrow($captured);

        try {
            Admin::save_ai_settings();
            $this->fail('Expected the redirect stub to interrupt execution.');
        } catch (RedirectInterrupt $e) {
            // expected
        }

        $this->assertStringContainsString('stm_saved=1', $captured);
    }
}
