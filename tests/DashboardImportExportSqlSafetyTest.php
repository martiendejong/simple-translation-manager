<?php
/**
 * PHPUnit tests: Dashboard::get_coverage_stats() / get_missing_translations()
 * and ImportExport::export_xliff() / export_po().
 *
 * These queries already went through $wpdb->prepare() before this change,
 * but used the `...$array` spread operator to unpack their replacement
 * values (Plugin Check ERROR: WordPress.DB.PreparedSQL.NotPrepared —
 * the sniff cannot statically verify a spread-unpacked argument list).
 * Fixed by passing the values as a single array to prepare() instead
 * (a form wpdb::prepare() has always supported natively). Since that's a
 * purely mechanical rewrite of how arguments are collected, the real risk
 * is an argument-order bug — these tests assert the exact placeholder
 * substitutions still land in the right place.
 */

namespace STM\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use STM\Dashboard;
use STM\ImportExport;
use STM\Tests\Fakes\RecordingWpdb;

class DashboardImportExportSqlSafetyTest extends TestCase {

    /** @var RecordingWpdb */
    private $wpdb;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        global $wpdb;
        $wpdb = new RecordingWpdb();
        $this->wpdb = $wpdb;

        Functions\when('wp_cache_get')->justReturn(false);
        Functions\when('wp_cache_set')->justReturn(true);
        Functions\when('get_post_types')->justReturn(['post', 'page']);
        Functions\when('apply_filters')->returnArg(2);
        Functions\when('current_time')->justReturn('2026-08-07 00:00:00');
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function seedLanguages() {
        $this->wpdb->seed('wp_stm_languages', [
            'code' => 'en', 'native_name' => 'English', 'flag_emoji' => '🇬🇧', 'is_active' => 1, 'is_default' => 1, 'order_index' => 1,
        ]);
        $this->wpdb->seed('wp_stm_languages', [
            'code' => 'nl', 'native_name' => 'Nederlands', 'flag_emoji' => '🇳🇱', 'is_active' => 1, 'is_default' => 0, 'order_index' => 2,
        ]);
    }

    // -----------------------------------------------------------------
    // Dashboard::get_coverage_stats()
    // -----------------------------------------------------------------

    private function queriesContaining($needle) {
        return array_values(array_filter($this->wpdb->queries, function ($q) use ($needle) {
            return stripos($q, $needle) !== false;
        }));
    }

    public function test_coverage_stats_total_posts_in_clause_has_all_post_types_quoted() {
        $this->seedLanguages();
        $this->wpdb->seed('wp_posts', ['post_status' => 'publish', 'post_type' => 'post']);

        Dashboard::get_coverage_stats(false);

        $totalPostsQueries = $this->queriesContaining('COUNT(*) FROM wp_posts');
        $this->assertCount(1, $totalPostsQueries);
        $totalPostsQuery = $totalPostsQueries[0];
        $this->assertStringContainsString("post_type IN ('post','page')", $totalPostsQuery);
        $this->assertStringNotContainsString('%s', $totalPostsQuery);
    }

    public function test_coverage_stats_title_and_content_queries_put_lang_before_post_types() {
        $this->seedLanguages();
        $this->wpdb->seed('wp_posts', ['post_status' => 'publish', 'post_type' => 'post']);

        Dashboard::get_coverage_stats(false);

        $titleQueries = $this->queriesContaining("field_name = 'title'");
        $contentQueries = $this->queriesContaining("field_name = 'content'");
        $this->assertCount(1, $titleQueries, 'Expected exactly one title-coverage query (one non-default language).');
        $this->assertCount(1, $contentQueries, 'Expected exactly one content-coverage query (one non-default language).');

        foreach ([$titleQueries[0], $contentQueries[0]] as $query) {
            $this->assertStringContainsString("pt.language_code = 'nl'", $query);
            $this->assertStringContainsString("post_type IN ('post','page')", $query);
            $this->assertStringNotContainsString('%s', $query, 'No unresolved placeholder should remain: ' . $query);
        }
    }

    // -----------------------------------------------------------------
    // Dashboard::get_missing_translations()
    // -----------------------------------------------------------------

    public function test_missing_translations_in_clause_and_date_filters_resolve_with_no_leftover_placeholder() {
        $this->seedLanguages();

        $filters = [
            'language'   => '',
            'post_type'  => '',
            'date_from'  => '2026-01-01',
            'date_to'    => '2026-02-01',
            'paged'      => 1,
            'per_page'   => 50,
        ];

        Dashboard::get_missing_translations($filters);

        $this->assertNotEmpty($this->wpdb->queries);
        $query = $this->wpdb->lastQuery();

        $this->assertStringContainsString("post_type IN ('post','page')", $query);
        $this->assertStringContainsString("p.post_date >= '2026-01-01 00:00:00'", $query);
        $this->assertStringContainsString("p.post_date <= '2026-02-01 23:59:59'", $query);
        $this->assertStringContainsString("language_code = 'nl'", $query);
        $this->assertStringNotContainsString('%s', $query);
    }

    // -----------------------------------------------------------------
    // ImportExport::export_xliff()
    // -----------------------------------------------------------------

    public function test_export_xliff_places_source_and_target_lang_before_context() {
        $malicious = "fr' OR '1'='1";

        ImportExport::export_xliff('en', 'nl', $malicious);

        $query = $this->wpdb->lastQuery();
        $this->assertStringContainsString("src.language_code = 'en'", $query);
        $this->assertStringContainsString("tgt.language_code = 'nl'", $query);
        $this->assertStringContainsString("s.context = '" . addslashes($malicious) . "'", $query);
        $this->assertStringNotContainsString("= " . $malicious, $query);
        $this->assertStringNotContainsString('%s', $query);
    }

    public function test_export_xliff_without_context_has_no_leftover_placeholder() {
        ImportExport::export_xliff('en', 'nl');

        $query = $this->wpdb->lastQuery();
        $this->assertStringContainsString("src.language_code = 'en'", $query);
        $this->assertStringContainsString("tgt.language_code = 'nl'", $query);
        $this->assertStringContainsString('WHERE 1=1', $query);
        $this->assertStringNotContainsString('%s', $query);
    }

    // -----------------------------------------------------------------
    // ImportExport::export_po()
    // -----------------------------------------------------------------

    public function test_export_po_places_lang_before_context() {
        $malicious = "de'; --";

        ImportExport::export_po('de', $malicious);

        $query = $this->wpdb->lastQuery();
        $this->assertStringContainsString("t.language_code = 'de'", $query);
        $this->assertStringContainsString("s.context = '" . addslashes($malicious) . "'", $query);
        $this->assertStringNotContainsString('%s', $query);
    }
}
