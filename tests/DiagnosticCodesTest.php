<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.test.diagnosticcodestest

/**
 * PHPUnit tests: diagnostic codes (task 5182).
 *
 * Every REST error built in includes/ carries data.stm_diag next to the
 * original code, message and HTTP status, and every code in the plugin is
 * unique and well-formed. The original REST contract (error code, message,
 * status) must not change - stm_diag is additive.
 */

namespace STM\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use STM\API;
use STM\ImportExport;

class DiagnosticCodesTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        require_once dirname(__DIR__) . '/includes/class-import-export.php';
        Functions\when('sanitize_text_field')->returnArg(1);
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /** @return array<string, string> file => contents */
    private function includeSources(): array {
        $out = [];
        foreach (glob(dirname(__DIR__) . '/includes/*.php') as $file) {
            $out[basename($file)] = (string) file_get_contents($file);
        }
        return $out;
    }

    public function test_every_wp_error_in_the_plugin_carries_a_diagnostic_code() {
        $checked = 0;
        foreach ($this->includeSources() as $file => $source) {
            foreach (preg_split('/\R/', $source) as $n => $line) {
                if (strpos($line, 'new \\WP_Error') === false) {
                    continue;
                }
                $checked++;
                $this->assertStringContainsString("'stm_diag'", $line, "{$file}:" . ($n + 1) . ' builds a WP_Error without stm_diag');
                $this->assertStringContainsString("'status'", $line, "{$file}:" . ($n + 1) . ' lost its HTTP status');
            }
        }
        $this->assertGreaterThanOrEqual(47, $checked);
    }

    public function test_diagnostic_codes_are_well_formed_and_unique() {
        $seen = [];
        foreach ($this->includeSources() as $file => $source) {
            preg_match_all('/STM-[EWI]-[A-Z0-9]+(?:-[A-Z0-9]+)+/', $source, $m);
            foreach ($m[0] as $code) {
                $this->assertMatchesRegularExpression('/^STM-[EWI]-[A-Z]+(?:-[A-Z0-9]+){2,}$/', $code, "{$file}: malformed code {$code}");
                $this->assertArrayNotHasKey($code, $seen, "{$code} appears in {$file} and " . ($seen[$code] ?? ''));
                $seen[$code] = $file;
            }
        }
        $this->assertGreaterThanOrEqual(53, count($seen));
    }

    public function test_rest_error_keeps_original_code_message_and_status_and_adds_the_diag() {
        $request = new \ArrayObject(['code' => 'x1', 'flag_emoji' => '']);

        $error = API::create_language($request);

        $this->assertInstanceOf(\WP_Error::class, $error);
        $this->assertSame('invalid_code', $error->get_error_code());
        $this->assertSame('Invalid language code (must be 2-3 letters)', $error->get_error_message());
        $this->assertSame(
            ['status' => 400, 'stm_diag' => 'STM-E-API-CREATE-LANGUAGE-INVALID-CODE'],
            $error->get_error_data()
        );
    }

    public function test_import_endpoint_error_keeps_its_contract_too() {
        $request = new class {
            public function get_file_params() { return []; }
            public function get_json_params() { return []; }
            public function get_body_params() { return []; }
        };

        $error = ImportExport::rest_import_file($request);

        $this->assertSame('no_file', $error->get_error_code());
        $this->assertSame('No file uploaded', $error->get_error_message());
        $this->assertSame(400, $error->get_error_data()['status']);
        $this->assertSame('STM-E-IMPEX-REST-IMPORT-FILE-NO-FILE', $error->get_error_data()['stm_diag']);
    }
}
