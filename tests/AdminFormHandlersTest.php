<?php
/**
 * PHPUnit tests: the Admin::* admin_post form handlers that were never
 * covered before task 869efjuhp inlined check_admin_referer() directly
 * into each of them (previously routed through Security::verify_admin_action(),
 * which PHPCS/Plugin Check cannot see through). One happy-path test and one
 * nonce/capability-denied test per handler, mirroring the existing
 * toggle_language_active coverage in LanguagesScreenTest.php.
 */

namespace STM\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use STM\Admin;
use STM\Tests\Fakes\FakeWpdb;

/**
 * Some handlers (save_translation(), add_string(), scan_strings()) wrap
 * their success-path wp_safe_redirect()/exit in a try { } catch (\Exception $e),
 * so the interrupt signal used to stop execution at wp_safe_redirect() in tests
 * must NOT be an \Exception subclass (it would be silently swallowed and
 * misreported as a DB failure). \Error is never caught by catch (\Exception).
 */
class RedirectInterrupt extends \Error {}

class AdminFormHandlersTest extends TestCase {

    /** @var FakeWpdb */
    private $wpdb;

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

        Functions\when('wp_cache_get')->justReturn(false);
        Functions\when('wp_cache_set')->justReturn(true);
        Functions\when('wp_cache_delete')->justReturn(true);
        Functions\when('sanitize_text_field')->returnArg(1);
        Functions\when('sanitize_textarea_field')->returnArg(1);
        Functions\when('sanitize_file_name')->returnArg(1);
        Functions\when('wp_kses')->returnArg(1);
        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('get_current_user_id')->justReturn(1);
        Functions\when('current_time')->justReturn('2026-08-08 00:00:00');
        Functions\when('update_option')->justReturn(true);

        $this->wpdb->seed('wp_stm_languages', [
            'code' => 'en', 'name' => 'English', 'native_name' => 'English',
            'flag_emoji' => '', 'is_active' => 1, 'is_default' => 1, 'order_index' => 1,
        ]);
    }

    protected function tearDown(): void {
        $_POST = [];
        $_FILES = [];
        Monkey\tearDown();
        parent::tearDown();
    }

    private function stubRedirectToThrow(&$capturedUrl) {
        Functions\when('wp_get_referer')->justReturn('http://example.test/wp-admin/admin.php?page=stm-translations');
        Functions\when('add_query_arg')->alias(function (...$args) {
            if (count($args) === 2 && is_array($args[0])) {
                list($params, $url) = $args;
                return $url . '?' . http_build_query($params);
            }
            list($key, $value, $url) = $args;
            return $url . '?' . $key . '=' . $value;
        });
        Functions\when('wp_safe_redirect')->alias(function ($url) use (&$capturedUrl) {
            $capturedUrl = $url;
            throw new RedirectInterrupt('redirect:' . $url);
        });
    }

    private function stubWpDieToThrow() {
        Functions\when('wp_die')->alias(function ($message) {
            throw new \RuntimeException('wp_die:' . $message);
        });
    }

    private function assertDeniedWithoutNonce(callable $handler, $nonceName) {
        Functions\when('check_admin_referer')->justReturn(false);
        Functions\when('current_user_can')->justReturn(true);
        $this->stubWpDieToThrow();

        try {
            $handler();
            $this->fail('Expected wp_die to interrupt execution.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('wp_die:Unauthorized', $e->getMessage());
        }
    }

    // =========================================================================
    // save_translation()
    // =========================================================================

    public function test_save_translation_inserts_and_redirects() {
        Functions\when('check_admin_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);

        $captured = null;
        $this->stubRedirectToThrow($captured);

        $_POST['string_id'] = '1';
        $_POST['language_code'] = 'nl';
        $_POST['translation'] = 'Hallo';

        try {
            Admin::save_translation();
            $this->fail('Expected the redirect stub to interrupt execution.');
        } catch (RedirectInterrupt $e) {
            // expected
        }

        $rows = $this->wpdb->all('stm_translations');
        $this->assertCount(1, $rows);
        $this->assertSame('Hallo', $rows[0]['translation']);
        $this->assertStringContainsString('updated=1', $captured);
    }

    public function test_save_translation_dies_without_nonce() {
        $this->assertDeniedWithoutNonce(function () {
            Admin::save_translation();
        }, 'stm_save_translation');
    }

    // =========================================================================
    // add_string()
    // =========================================================================

    public function test_add_string_inserts_and_redirects() {
        Functions\when('check_admin_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);

        $captured = null;
        $this->stubRedirectToThrow($captured);

        $_POST['string_key'] = 'nav.home';
        $_POST['context'] = 'nav';
        $_POST['description'] = '';

        try {
            Admin::add_string();
            $this->fail('Expected the redirect stub to interrupt execution.');
        } catch (RedirectInterrupt $e) {
            // expected
        }

        $rows = $this->wpdb->all('stm_strings');
        $this->assertCount(1, $rows);
        $this->assertSame('nav.home', $rows[0]['string_key']);
        $this->assertStringContainsString('added=1', $captured);
    }

    public function test_add_string_dies_without_nonce() {
        $this->assertDeniedWithoutNonce(function () {
            Admin::add_string();
        }, 'stm_add_string');
    }

    // =========================================================================
    // scan_strings()
    // =========================================================================

    public function test_scan_strings_redirects_with_scan_results() {
        Functions\when('check_admin_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);

        $captured = null;
        $this->stubRedirectToThrow($captured);

        try {
            Admin::scan_strings();
            $this->fail('Expected the redirect stub to interrupt execution.');
        } catch (RedirectInterrupt $e) {
            // expected
        }

        // No WP theme functions are mocked, so StringScanner finds zero
        // directories to scan and reports a clean, empty run.
        $this->assertStringContainsString('stm_scanned=1', $captured);
    }

    public function test_scan_strings_dies_without_nonce() {
        $this->assertDeniedWithoutNonce(function () {
            Admin::scan_strings();
        }, 'stm_scan_strings');
    }

    public function test_scan_strings_redirects_with_error_when_scanning_throws() {
        Functions\when('check_admin_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);

        // get_scan_directories() applies the 'stm_scan_directories' filter
        // *after* its own is_dir() checks, so a filter is the only way to
        // hand it a directory that doesn't exist — which makes
        // find_php_files()'s RecursiveDirectoryIterator throw and exercises
        // the catch (\Exception) branch below.
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

    // =========================================================================
    // import_json()
    // =========================================================================

    public function test_import_json_redirects_with_no_file_error_when_nothing_uploaded() {
        Functions\when('check_admin_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);

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
        Functions\when('check_admin_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);

        $captured = null;
        $this->stubRedirectToThrow($captured);

        $tmpFile = tempnam(sys_get_temp_dir(), 'stm_import');
        file_put_contents($tmpFile, 'irrelevant');

        $_FILES['stm_import_file'] = [
            'tmp_name' => $tmpFile,
            'name'     => 'strings.txt',
        ];

        try {
            Admin::import_json();
            $this->fail('Expected the redirect stub to interrupt execution.');
        } catch (RedirectInterrupt $e) {
            // expected
        } finally {
            unlink($tmpFile);
        }

        $this->assertStringContainsString('stm_error=invalid_type', $captured);
    }

    public function test_import_json_redirects_with_error_for_malformed_json() {
        Functions\when('check_admin_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);

        $captured = null;
        $this->stubRedirectToThrow($captured);

        $tmpFile = tempnam(sys_get_temp_dir(), 'stm_import');
        file_put_contents($tmpFile, '{not valid json');

        $_FILES['stm_import_file'] = [
            'tmp_name' => $tmpFile,
            'name'     => 'strings.json',
        ];

        try {
            Admin::import_json();
            $this->fail('Expected the redirect stub to interrupt execution.');
        } catch (RedirectInterrupt $e) {
            // expected
        } finally {
            unlink($tmpFile);
        }

        $this->assertStringContainsString('stm_error=invalid_json', $captured);
    }

    public function test_import_json_redirects_with_error_when_format_unrecognized() {
        Functions\when('check_admin_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);

        $captured = null;
        $this->stubRedirectToThrow($captured);

        // Valid JSON, but neither the {lang,translations} shape nor a map of
        // valid language codes -> API::process_import() reports it as an error.
        $tmpFile = tempnam(sys_get_temp_dir(), 'stm_import');
        file_put_contents($tmpFile, '[]');

        $_FILES['stm_import_file'] = [
            'tmp_name' => $tmpFile,
            'name'     => 'strings.json',
        ];

        try {
            Admin::import_json();
            $this->fail('Expected the redirect stub to interrupt execution.');
        } catch (RedirectInterrupt $e) {
            // expected
        } finally {
            unlink($tmpFile);
        }

        $this->assertStringContainsString('stm_error=', $captured);
    }

    public function test_import_json_imports_uploaded_translations() {
        Functions\when('check_admin_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);

        $captured = null;
        $this->stubRedirectToThrow($captured);

        $tmpFile = tempnam(sys_get_temp_dir(), 'stm_import');
        file_put_contents($tmpFile, json_encode(['nl' => ['nav.home' => 'Thuis']]));

        $_FILES['stm_import_file'] = [
            'tmp_name' => $tmpFile,
            'name'     => 'translations.json',
        ];

        try {
            Admin::import_json();
            $this->fail('Expected the redirect stub to interrupt execution.');
        } catch (RedirectInterrupt $e) {
            // expected
        } finally {
            unlink($tmpFile);
        }

        $this->assertStringContainsString('imported=1', $captured);
        $rows = $this->wpdb->all('stm_translations');
        $this->assertCount(1, $rows);
        $this->assertSame('Thuis', $rows[0]['translation']);
    }

    public function test_import_json_dies_without_nonce() {
        $this->assertDeniedWithoutNonce(function () {
            Admin::import_json();
        }, 'stm_import_json');
    }

    // =========================================================================
    // add_language()
    // =========================================================================

    public function test_add_language_inserts_and_redirects() {
        Functions\when('check_admin_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);

        $captured = null;
        $this->stubRedirectToThrow($captured);

        $_POST['lang_code'] = 'fr';
        $_POST['lang_name'] = 'French';

        try {
            Admin::add_language();
            $this->fail('Expected the redirect stub to interrupt execution.');
        } catch (RedirectInterrupt $e) {
            // expected
        }

        $rows = array_values(array_filter($this->wpdb->all('stm_languages'), function ($r) {
            return $r['code'] === 'fr';
        }));
        $this->assertCount(1, $rows);
        $this->assertStringContainsString('stm_added=1', $captured);
    }

    public function test_add_language_dies_without_nonce() {
        $this->assertDeniedWithoutNonce(function () {
            Admin::add_language();
        }, 'stm_add_language');
    }

    public function test_add_language_redirects_with_error_for_invalid_fields() {
        Functions\when('check_admin_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);

        $captured = null;
        $this->stubRedirectToThrow($captured);

        $_POST['lang_code'] = 'toolong';
        $_POST['lang_name'] = 'Whatever';

        try {
            Admin::add_language();
            $this->fail('Expected the redirect stub to interrupt execution.');
        } catch (RedirectInterrupt $e) {
            // expected
        }

        $this->assertStringContainsString('stm_error=invalid_fields', $captured);
        $this->assertCount(1, $this->wpdb->all('stm_languages'), 'No language row should have been inserted.');
    }

    public function test_add_language_reactivates_an_existing_inactive_language_instead_of_failing() {
        Functions\when('check_admin_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);

        $inactiveId = $this->wpdb->seed('stm_languages', [
            'code' => 'fr', 'name' => 'French (old)', 'native_name' => 'Français (old)',
            'flag_emoji' => '', 'is_active' => 0, 'is_default' => 0, 'order_index' => 5,
        ]);
        // A translation tied to the existing row, to prove reactivation doesn't touch it.
        $this->wpdb->seed('stm_translations', [
            'language_id' => $inactiveId, 'string_id' => 1, 'translation' => 'Bonjour',
        ]);

        $captured = null;
        $this->stubRedirectToThrow($captured);

        $_POST['lang_code'] = 'fr';
        $_POST['lang_name'] = 'French';

        try {
            Admin::add_language();
            $this->fail('Expected the redirect stub to interrupt execution.');
        } catch (RedirectInterrupt $e) {
            // expected
        }

        $this->assertStringContainsString('stm_reactivated=1', $captured);
        $this->assertStringNotContainsString('stm_added', $captured);

        $rows = array_values(array_filter($this->wpdb->all('stm_languages'), function ($r) {
            return $r['code'] === 'fr';
        }));
        $this->assertCount(1, $rows, 'No duplicate row should have been inserted.');
        $this->assertSame($inactiveId, $rows[0]['id'], 'The existing row must be reused, not replaced.');
        $this->assertSame(1, (int) $rows[0]['is_active']);
        $this->assertSame('French', $rows[0]['name'], 'Display fields should refresh from the form.');

        // The translation tied to the reactivated language's id is still there, untouched.
        $translations = array_values(array_filter($this->wpdb->all('stm_translations'), function ($r) use ($inactiveId) {
            return $r['language_id'] === $inactiveId;
        }));
        $this->assertCount(1, $translations);
        $this->assertSame('Bonjour', $translations[0]['translation']);
    }

    public function test_add_language_redirects_with_specific_error_when_code_already_active() {
        Functions\when('check_admin_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);

        $this->wpdb->seed('stm_languages', [
            'code' => 'fr', 'name' => 'French', 'native_name' => 'Français',
            'flag_emoji' => '', 'is_active' => 1, 'is_default' => 0, 'order_index' => 5,
        ]);

        $captured = null;
        $this->stubRedirectToThrow($captured);

        $_POST['lang_code'] = 'fr';
        $_POST['lang_name'] = 'French Duplicate';

        try {
            Admin::add_language();
            $this->fail('Expected the redirect stub to interrupt execution.');
        } catch (RedirectInterrupt $e) {
            // expected
        }

        $this->assertStringContainsString('stm_error=already_active', $captured);
        $this->assertStringNotContainsString('db_error', $captured);

        $rows = array_values(array_filter($this->wpdb->all('stm_languages'), function ($r) {
            return $r['code'] === 'fr';
        }));
        $this->assertCount(1, $rows, 'No duplicate row should have been inserted.');
        $this->assertSame('French', $rows[0]['name'], 'The existing active row must be left untouched.');
    }

    // =========================================================================
    // delete_language()
    // =========================================================================

    public function test_delete_language_removes_a_non_default_language() {
        Functions\when('check_admin_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);

        $this->wpdb->seed('wp_stm_languages', [
            'code' => 'nl', 'name' => 'Dutch', 'native_name' => 'Nederlands',
            'flag_emoji' => '', 'is_active' => 1, 'is_default' => 0, 'order_index' => 2,
        ]);

        $captured = null;
        $this->stubRedirectToThrow($captured);

        $_POST['lang_code'] = 'nl';

        try {
            Admin::delete_language();
            $this->fail('Expected the redirect stub to interrupt execution.');
        } catch (RedirectInterrupt $e) {
            // expected
        }

        $remaining = array_values(array_filter($this->wpdb->all('stm_languages'), function ($r) {
            return $r['code'] === 'nl';
        }));
        $this->assertCount(0, $remaining);
        $this->assertStringContainsString('stm_deleted=1', $captured);
    }

    public function test_delete_language_dies_without_nonce() {
        $this->assertDeniedWithoutNonce(function () {
            Admin::delete_language();
        }, 'stm_delete_language');
    }

    public function test_delete_language_refuses_to_delete_the_default_language() {
        Functions\when('check_admin_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);

        $captured = null;
        $this->stubRedirectToThrow($captured);

        $_POST['lang_code'] = 'en';

        try {
            Admin::delete_language();
            $this->fail('Expected the redirect stub to interrupt execution.');
        } catch (RedirectInterrupt $e) {
            // expected
        }

        $this->assertStringContainsString('stm_error=cannot_delete_default', $captured);
        $this->assertCount(1, $this->wpdb->all('stm_languages'));
    }

    // =========================================================================
    // save_ai_settings()
    // =========================================================================

    public function test_save_ai_settings_persists_and_redirects() {
        Functions\when('check_admin_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);

        $captured = null;
        $this->stubRedirectToThrow($captured);

        $updated = [];
        Functions\when('update_option')->alias(function ($key, $value) use (&$updated) {
            $updated[$key] = $value;
            return true;
        });

        $_POST['ai_provider'] = 'openai';
        $_POST['openai_key'] = 'sk-test';
        $_POST['deepl_key'] = '';

        try {
            Admin::save_ai_settings();
            $this->fail('Expected the redirect stub to interrupt execution.');
        } catch (RedirectInterrupt $e) {
            // expected
        }

        $this->assertSame('openai', $updated['stm_auto_translate_provider']);
        $this->assertSame('sk-test', $updated['stm_openai_api_key']);
        $this->assertStringContainsString('stm_saved=1', $captured);
    }

    public function test_save_ai_settings_dies_without_nonce() {
        $this->assertDeniedWithoutNonce(function () {
            Admin::save_ai_settings();
        }, 'stm_ai_settings');
    }

    // =========================================================================
    // page_translations() — read-only list page, exercises the $_GET filter
    // wp_unslash() wrapping (task 869efjuhr); never previously covered.
    // =========================================================================

    public function test_page_translations_reads_get_filters() {
        Functions\when('esc_html')->returnArg(1);
        Functions\when('esc_attr')->returnArg(1);
        Functions\when('esc_url')->returnArg(1);
        Functions\when('selected')->justReturn('');
        Functions\when('admin_url')->justReturn('http://example.test/wp-admin/admin-post.php');
        Functions\when('add_query_arg')->alias(function (...$args) {
            return 'http://example.test/wp-admin/admin.php';
        });
        Functions\when('wp_nonce_field')->justReturn('');

        $_GET['lang'] = 'nl';
        $_GET['context'] = 'nav';
        $_GET['status'] = 'missing';

        ob_start();
        Admin::page_translations();
        $html = ob_get_clean();

        $this->assertIsString($html);
        $this->assertStringContainsString('Translation Strings', $html);
    }

    // =========================================================================
    // Status filter (task 869enr72r) — "missing translations" / "fully translated"
    // on the Translation Strings screen. $status_filter was read from $_GET since
    // the plugin's initial commit but never applied to the query or exposed as a
    // control; this adds both.
    // =========================================================================

    public function test_build_status_having_missing_filters_below_total_languages() {
        $this->assertSame('HAVING translated_count < 3', Admin::build_status_having('missing', 3));
    }

    public function test_build_status_having_complete_filters_at_or_above_total_languages() {
        $this->assertSame('HAVING translated_count >= 3', Admin::build_status_having('complete', 3));
    }

    public function test_build_status_having_returns_empty_for_all_and_unknown_values() {
        $this->assertSame('', Admin::build_status_having('', 3));
        $this->assertSame('', Admin::build_status_having('bogus', 3));
    }

    public function test_page_translations_status_dropdown_marks_missing_selected() {
        Functions\when('esc_html')->returnArg(1);
        Functions\when('esc_attr')->returnArg(1);
        Functions\when('esc_url')->returnArg(1);
        Functions\when('admin_url')->justReturn('http://example.test/wp-admin/admin-post.php');
        Functions\when('add_query_arg')->alias(function (...$args) {
            return 'http://example.test/wp-admin/admin.php';
        });
        Functions\when('wp_nonce_field')->justReturn('');
        // Real selected()-shaped behaviour (the other tests above stub it to
        // always return '', which can't distinguish which <option> was picked).
        Functions\when('selected')->alias(function ($selected, $current = true, $echo = true) {
            $result = ((string) $selected === (string) $current) ? ' selected="selected"' : '';
            if ($echo) {
                echo $result;
            }
            return $result;
        });

        $_GET['status'] = 'missing';

        ob_start();
        Admin::page_translations();
        $html = ob_get_clean();

        $this->assertMatchesRegularExpression('/value="missing"\s*selected="selected"/', $html);
        $this->assertDoesNotMatchRegularExpression('/value="complete"\s*selected="selected"/', $html);
    }
}
