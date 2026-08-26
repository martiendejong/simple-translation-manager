<?php
/**
 * PHPUnit test: Dashboard::export_coverage_csv() (task 869efjuhj — date()
 * -> gmdate() at includes/class-dashboard.php:432/473).
 *
 * export_coverage_csv() ends with a raw `exit;` after streaming CSV
 * output, which would kill the whole PHPUnit process if actually reached.
 * Reuses the exact technique DashboardImportExportHandlersTest.php already
 * established for the sibling export_missing_csv() handler: stubbing
 * nocache_headers() (the first call inside stream_csv_headers()) to throw
 * stops execution right after the gmdate()-built filename argument has
 * already been fully evaluated, but before the unreachable-in-tests exit.
 */

namespace STM\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use STM\Dashboard;
use STM\Tests\Fakes\FakeWpdb;

/** Thrown by a stubbed header-emitting call to stop execution just before an unreachable-in-tests `exit;`. */
class CoverageCsvHeaderInterrupt extends \Error {}

class DashboardExportCoverageCsvTest extends TestCase {

    /** @var FakeWpdb */
    private $wpdb;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        global $wpdb;
        $wpdb = new FakeWpdb();
        $this->wpdb = $wpdb;

        // Only the default language exists, so get_coverage_stats()'s
        // by-language loop body never runs — keeps the stub surface small,
        // same simplification DashboardImportExportHandlersTest.php uses.
        $this->wpdb->seed('wp_stm_languages', [
            'code' => 'en', 'name' => 'English', 'native_name' => 'English',
            'flag_emoji' => '🇬🇧', 'is_active' => 1, 'is_default' => 1, 'order_index' => 1,
        ]);

        Functions\when('check_admin_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('wp_cache_get')->justReturn(false);
        Functions\when('wp_cache_set')->justReturn(true);
        Functions\when('get_post_types')->justReturn(['post', 'page']);
        Functions\when('apply_filters')->returnArg(2);
        Functions\when('current_time')->justReturn('2026-08-08 00:00:00');
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_export_coverage_csv_builds_utc_dated_filename_before_streaming() {
        Functions\when('nocache_headers')->alias(function () {
            throw new CoverageCsvHeaderInterrupt('stream started');
        });

        try {
            Dashboard::export_coverage_csv();
            $this->fail('Expected the header-stream stub to interrupt execution before exit.');
        } catch (CoverageCsvHeaderInterrupt $e) {
            // expected — get_coverage_stats() and the gmdate()-built filename
            // argument have already run by this point.
        }

        $this->assertTrue(true);
    }
}
