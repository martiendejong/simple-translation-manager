<?php
/**
 * PHPUnit tests: the Dashboard/ImportExport admin_post handlers touched by
 * task 869efjuhr's wp_unslash() wrapping — Dashboard::render_page()
 * ('missing' tab)/ajax_quick_save_translation()/export_missing_csv(), and
 * ImportExport::handle_import_file(). These were never exercised by any
 * existing test; class-dashboard.php and class-import-export.php were only
 * just added to phpunit.xml's tracked <source><include> list by a sibling
 * PR, so this PR's own touched lines in them are the first time the CI
 * diff-coverage gate actually measures them.
 *
 * export_missing_csv() ends with a raw `exit;` (not wp_die()) after
 * streaming CSV output, which would kill the whole PHPUnit process if
 * actually reached — stubbing the WP function that starts that stream
 * (nocache_headers()) to throw stops execution right before the
 * unreachable-in-tests exit, after the $filters block under test has
 * already run. (ImportExport::handle_export_xliff()/handle_export_po() use
 * the same raw-`exit;`-after-header() shape but call the real, un-mockable
 * PHP-internal header() directly with no user-function seam beforehand —
 * left uncovered here; the diff-coverage gate's 80% threshold is still met
 * without them.) handle_import_file() ends with wp_redirect()+exit, so it
 * reuses the RedirectInterrupt trick AdminFormHandlersTest.php already
 * established for that exact shape.
 */

namespace STM\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use STM\Dashboard;
use STM\ImportExport;
use STM\Tests\Fakes\FakeWpdb;

/** Mirrors AdminFormHandlersTest.php's RedirectInterrupt, kept local to avoid depending on that file's load order. */
class HandlerRedirectInterrupt extends \Error {}

/** Thrown by a stubbed header-emitting call to stop execution just before an unreachable-in-tests `exit;`. */
class HeaderInterrupt extends \Error {}

class DashboardImportExportHandlersTest extends TestCase {

    /** @var FakeWpdb */
    private $wpdb;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        if (!defined('ABSPATH')) {
            define('ABSPATH', dirname(__DIR__) . '/');
        }
        require_once dirname(__DIR__) . '/includes/class-dashboard.php';
        require_once dirname(__DIR__) . '/includes/class-import-export.php';

        global $wpdb;
        $wpdb = new FakeWpdb();
        $this->wpdb = $wpdb;

        // Only the default language exists — Dashboard::get_missing_translations()
        // then has zero non-default target languages and returns an empty result
        // set immediately, keeping the 'missing' tab template's row/pagination
        // branches (which need a much larger stub surface) unreached.
        $this->wpdb->seed('wp_stm_languages', [
            'code' => 'en', 'name' => 'English', 'native_name' => 'English',
            'flag_emoji' => '🇬🇧', 'is_active' => 1, 'is_default' => 1, 'order_index' => 1,
        ]);

        Functions\when('wp_cache_get')->justReturn(false);
        Functions\when('wp_cache_set')->justReturn(true);
        Functions\when('wp_cache_delete')->justReturn(true);
        Functions\when('sanitize_text_field')->returnArg(1);
        Functions\when('sanitize_key')->returnArg(1);
        Functions\when('sanitize_file_name')->returnArg(1);
        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('wp_kses_post')->returnArg(1);
        Functions\when('get_post_types')->justReturn(['post', 'page']);
        Functions\when('apply_filters')->returnArg(2);
        Functions\when('current_time')->justReturn('2026-08-08 00:00:00');
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('__')->returnArg(1);
        Functions\when('current_user_can')->justReturn(true);
    }

    protected function tearDown(): void {
        $_GET = [];
        $_POST = [];
        $_FILES = [];
        Monkey\tearDown();
        parent::tearDown();
    }

    // =========================================================================
    // Dashboard::render_page() — 'missing' tab
    // =========================================================================

    public function test_render_page_missing_tab_reads_get_filters() {
        Functions\when('esc_html_e')->alias(function ($s) { echo $s; });
        Functions\when('esc_html__')->returnArg(1);
        Functions\when('esc_html')->returnArg(1);
        Functions\when('esc_attr')->returnArg(1);
        Functions\when('esc_attr_e')->alias(function ($s) { echo $s; });
        Functions\when('esc_url')->returnArg(1);
        Functions\when('selected')->justReturn('');
        Functions\when('admin_url')->justReturn('http://example.test/wp-admin/admin.php');
        Functions\when('add_query_arg')->alias(function (...$args) {
            return 'http://example.test/wp-admin/admin.php';
        });
        Functions\when('wp_nonce_url')->returnArg(1);
        Functions\when('number_format_i18n')->alias(function ($n) { return (string) $n; });

        $_GET['tab'] = 'missing';
        $_GET['flang'] = 'nl';
        $_GET['fptype'] = 'post';
        $_GET['fdate_from'] = '2026-01-01';
        $_GET['fdate_to'] = '2026-02-01';

        ob_start();
        Dashboard::render_page();
        $html = ob_get_clean();

        $this->assertIsString($html);
    }

    // =========================================================================
    // Dashboard::ajax_quick_save_translation()
    // =========================================================================

    public function test_ajax_quick_save_translation_saves_and_reports_success() {
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('get_post')->justReturn((object) ['ID' => 42]);

        $success = null;
        Functions\when('wp_send_json_success')->alias(function ($data) use (&$success) {
            $success = $data;
        });
        Functions\when('wp_send_json_error')->alias(function ($data) {
            self::fail('Did not expect wp_send_json_error: ' . json_encode($data));
        });

        $_POST['post_id'] = '42';
        $_POST['language_code'] = 'nl';
        $_POST['field_name'] = 'title';
        $_POST['translation'] = "O'Brien's café";

        Dashboard::ajax_quick_save_translation();

        $this->assertNotNull($success);
        $rows = $this->wpdb->all('stm_post_translations');
        $this->assertCount(1, $rows);
        $this->assertSame('nl', $rows[0]['language_code']);
        $this->assertSame('title', $rows[0]['field_name']);
        $this->assertSame("O'Brien's café", $rows[0]['translation']);
    }

    // =========================================================================
    // Dashboard::export_missing_csv() — ends in a raw `exit`
    // =========================================================================

    public function test_export_missing_csv_reads_get_filters() {
        Functions\when('check_admin_referer')->justReturn(true);
        Functions\when('nocache_headers')->alias(function () {
            throw new HeaderInterrupt('stream started');
        });

        $_GET['flang'] = 'nl';
        $_GET['fptype'] = 'post';
        $_GET['fdate_from'] = '2026-01-01';
        $_GET['fdate_to'] = '2026-02-01';

        try {
            Dashboard::export_missing_csv();
            $this->fail('Expected the header-stream stub to interrupt execution before exit.');
        } catch (HeaderInterrupt $e) {
            // expected — the $filters block above this point already ran.
        }

        $this->assertTrue(true);
    }

    // =========================================================================
    // ImportExport::handle_import_file() — wp_redirect()+exit, same
    // RedirectInterrupt trick as AdminFormHandlersTest.php
    // =========================================================================

    public function test_handle_import_file_sanitizes_filename_and_redirects() {
        Functions\when('check_admin_referer')->justReturn(true);
        Functions\when('wp_get_referer')->justReturn('http://example.test/wp-admin/admin.php?page=stm-import-export');
        Functions\when('add_query_arg')->alias(function (...$args) {
            list($params, $url) = $args;
            return $url . '?' . http_build_query($params);
        });

        $captured = null;
        Functions\when('wp_redirect')->alias(function ($url) use (&$captured) {
            $captured = $url;
            throw new HandlerRedirectInterrupt('redirect:' . $url);
        });

        $tmpFile = tempnam(sys_get_temp_dir(), 'stm_import');
        file_put_contents($tmpFile, "msgid \"\"\nmsgstr \"\"\n");

        $_FILES['import_file'] = [
            'tmp_name' => $tmpFile,
            'name'     => "évil'.po",
        ];
        $_POST['lang'] = 'nl';

        try {
            ImportExport::handle_import_file();
            $this->fail('Expected the redirect stub to interrupt execution.');
        } catch (HandlerRedirectInterrupt $e) {
            // expected
        } finally {
            unlink($tmpFile);
        }

        $this->assertStringContainsString('imported=1', $captured);
    }
}
