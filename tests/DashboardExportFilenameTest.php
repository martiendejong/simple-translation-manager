<?php
/**
 * PHPUnit test: Dashboard::export_filename() (task 869efjuhj).
 *
 * export_coverage_csv()/export_missing_csv() both end in `exit;`, so they
 * can't be exercised directly under plain PHPUnit; the UTC-dated filename
 * logic (`gmdate('Y-m-d')`, replacing the Plugin-Check-flagged `date()`
 * call) is extracted into this private static helper specifically so it's
 * unit-testable in isolation via reflection.
 */

namespace STM\Tests;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;
use STM\Dashboard;

class DashboardExportFilenameTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function invoke($prefix) {
        // No setAccessible() call needed — private methods have been reflection-
        // invokable without it since PHP 8.1.
        $method = new \ReflectionMethod(Dashboard::class, 'export_filename');
        return $method->invoke(null, $prefix);
    }

    public function test_export_filename_uses_utc_date_and_csv_extension() {
        $filename = $this->invoke('stm-coverage');

        $this->assertMatchesRegularExpression('/^stm-coverage-\d{4}-\d{2}-\d{2}\.csv$/', $filename);
        $this->assertStringContainsString(gmdate('Y-m-d'), $filename);
    }

    public function test_export_filename_is_utc_not_server_local_time() {
        $originalTz = date_default_timezone_get();

        // Two opposite-direction extreme offsets: if export_filename() used the
        // server-local `date()` instead of `gmdate()`, at least one of these
        // would diverge from gmdate('Y-m-d') around UTC midnight.
        foreach (['Pacific/Kiritimati', 'Etc/GMT+12'] as $tz) {
            date_default_timezone_set($tz);
            try {
                $filename = $this->invoke('stm-missing');
            } finally {
                date_default_timezone_set($originalTz);
            }
            $this->assertSame('stm-missing-' . gmdate('Y-m-d') . '.csv', $filename);
        }
    }

    public function test_export_filename_prefix_is_preserved_for_both_export_types() {
        $this->assertSame('stm-coverage-' . gmdate('Y-m-d') . '.csv', $this->invoke('stm-coverage'));
        $this->assertSame('stm-missing-' . gmdate('Y-m-d') . '.csv', $this->invoke('stm-missing'));
    }
}
