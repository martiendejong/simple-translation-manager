<?php
/**
 * PHPUnit tests: SQL-injection safety for API::get_strings(), API::get_translations()
 * and API::export_json().
 *
 * These three REST callbacks used to build a `$where`/`$query` string from
 * request params and pass it straight to $wpdb->get_results()/get_var()
 * without the call itself going through $wpdb->prepare() (Plugin Check
 * ERROR: WordPress.DB.PreparedSQL.NotPrepared / PluginCheck.Security.DirectDB.UnescapedDBParameter).
 * Assert the *final* SQL string handed to wpdb always has user-supplied
 * values quoted/escaped, never interpolated raw, and never leaves a bare
 * %s/%d placeholder unresolved.
 */

namespace STM\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use STM\API;
use STM\Tests\Fakes\RecordingWpdb;

class ApiSqlSafetyTest extends TestCase {

    /** @var RecordingWpdb */
    private $wpdb;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        global $wpdb;
        $wpdb = new RecordingWpdb();
        $this->wpdb = $wpdb;

        Functions\when('rest_ensure_response')->alias(function ($data) {
            return new FakeRestResponse($data);
        });
        Functions\when('sanitize_text_field')->returnArg(1);
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // API::get_strings()
    // -----------------------------------------------------------------

    public function test_get_strings_escapes_malicious_context_value() {
        $malicious = "x' UNION SELECT user_login,user_pass,1,1 FROM wp_users -- ";
        $request = new FakeApiRequest(['context' => $malicious, 'lang' => '']);

        API::get_strings($request);

        $query = $this->wpdb->lastQuery();
        $this->assertStringNotContainsString("= " . $malicious, $query, 'Raw payload must never appear unquoted in the final SQL.');
        $this->assertStringContainsString("'" . addslashes($malicious) . "'", $query, 'Payload must appear only as an escaped, single-quoted literal.');
        $this->assertStringNotContainsString('%s', $query, 'No placeholder should remain unresolved.');
    }

    public function test_get_strings_without_context_has_no_leftover_placeholder() {
        $request = new FakeApiRequest(['context' => '', 'lang' => '']);

        API::get_strings($request);

        $this->assertStringNotContainsString('%s', $this->wpdb->lastQuery());
        $this->assertStringContainsString('WHERE 1=1', $this->wpdb->lastQuery());
    }

    // -----------------------------------------------------------------
    // API::get_translations()
    // -----------------------------------------------------------------

    public function test_get_translations_escapes_malicious_lang_and_paginates() {
        $malicious = "en'; DROP TABLE wp_stm_strings; -- ";
        $request = new FakeApiRequest([
            'string_id' => 0,
            'lang'      => $malicious,
            'per_page'  => 10,
            'page'      => 2,
        ]);

        API::get_translations($request);

        $this->assertCount(2, $this->wpdb->queries, 'Expected one SELECT (rows) and one SELECT (total count).');
        foreach ($this->wpdb->queries as $query) {
            $this->assertStringNotContainsString("= " . $malicious, $query);
            $this->assertStringContainsString("'" . addslashes($malicious) . "'", $query);
            $this->assertStringNotContainsString('%s', $query);
            $this->assertStringNotContainsString('%d', $query);
        }

        // LIMIT/OFFSET must reflect page 2 @ 10 per page -> LIMIT 10 OFFSET 10.
        $this->assertStringContainsString('LIMIT 10 OFFSET 10', $this->wpdb->queries[0]);
    }

    public function test_get_translations_filters_by_string_id_and_lang_together() {
        $request = new FakeApiRequest([
            'string_id' => 42,
            'lang'      => 'nl',
            'per_page'  => 500,
            'page'      => 1,
        ]);

        API::get_translations($request);

        $rowsQuery = $this->wpdb->queries[0];
        $this->assertStringContainsString("t.string_id = 42", $rowsQuery);
        $this->assertStringContainsString("t.language_code = 'nl'", $rowsQuery);
        $this->assertStringContainsString('LIMIT 500 OFFSET 0', $rowsQuery);

        $totalQuery = $this->wpdb->queries[1];
        $this->assertStringContainsString("t.string_id = 42", $totalQuery);
        $this->assertStringContainsString("t.language_code = 'nl'", $totalQuery);
    }

    // -----------------------------------------------------------------
    // API::export_json()
    // -----------------------------------------------------------------

    public function test_export_json_escapes_malicious_lang_and_context() {
        $maliciousLang = "de' OR '1'='1";
        $maliciousContext = "x'; --";
        $request = new FakeApiRequest(['lang' => $maliciousLang, 'context' => $maliciousContext]);

        API::export_json($request);

        $query = $this->wpdb->lastQuery();
        $this->assertStringContainsString("'" . addslashes($maliciousLang) . "'", $query);
        $this->assertStringContainsString("'" . addslashes($maliciousContext) . "'", $query);
        $this->assertStringNotContainsString("= " . $maliciousLang, $query);
        $this->assertStringNotContainsString("= " . $maliciousContext, $query);
        $this->assertStringNotContainsString('%s', $query);
    }

    public function test_export_json_without_filters_has_no_leftover_placeholder() {
        $request = new FakeApiRequest(['lang' => '', 'context' => '']);

        API::export_json($request);

        $query = $this->wpdb->lastQuery();
        $this->assertStringNotContainsString('%s', $query);
        $this->assertStringContainsString('t.status = "published"', $query);
    }
}

/**
 * Minimal WP_REST_Request stand-in — these callbacks only ever call get_param().
 */
class FakeApiRequest {
    private $params;

    public function __construct(array $params) {
        $this->params = $params;
    }

    public function get_param($key) {
        return $this->params[$key] ?? null;
    }
}

/** Minimal WP_REST_Response stand-in — supports the ->header() calls get_translations() makes. */
class FakeRestResponse {
    public $data;
    public $headers = [];

    public function __construct($data) {
        $this->data = $data;
    }

    public function header($key, $value) {
        $this->headers[$key] = $value;
    }
}
