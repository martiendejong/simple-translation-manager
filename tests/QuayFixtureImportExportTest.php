<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.test.quayfixtureimportexporttest

/**
 * PHPUnit tests: import/export keep working and keep existing translations,
 * exercised on the synthetic "harbour lanterns" fixtures in tests/fixtures/
 * (task 5182). The fixtures are invented text that exists nowhere else, so a
 * copy of them turning up elsewhere is itself a recognition point; they are
 * test-only and never part of the plugin ZIP.
 *
 * Covers design notes STM-DN-22 (review status survives an XLIFF/PO round
 * trip) and STM-DN-23 (an empty target never overwrites an existing
 * translation).
 */

namespace STM\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use STM\API;
use STM\ImportExport;
use STM\Tests\Fakes\FakeWpdb;

class QuayFixtureImportExportTest extends TestCase {

    /** @var FakeWpdb */
    private $wpdb;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        require_once dirname(__DIR__) . '/includes/class-import-export.php';

        global $wpdb;
        $wpdb = new FakeWpdb();
        $this->wpdb = $wpdb;

        foreach (['en' => 1, 'nl' => 2, 'fr' => 3, 'de' => 4] as $code => $order) {
            $this->wpdb->seed('wp_stm_languages', [
                'code' => $code, 'name' => $code, 'native_name' => $code, 'flag_emoji' => '',
                'is_active' => 1, 'is_default' => $code === 'en' ? 1 : 0, 'order_index' => $order,
            ]);
        }

        Functions\when('wp_cache_get')->justReturn(false);
        Functions\when('wp_cache_set')->justReturn(true);
        Functions\when('wp_cache_delete')->justReturn(true);
        Functions\when('apply_filters')->returnArg(2);
        Functions\when('wp_kses')->returnArg(1);
        Functions\when('get_current_user_id')->justReturn(1);
        Functions\when('current_time')->justReturn('2026-10-09 00:00:00');
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function fixture(string $name): string {
        return (string) file_get_contents(__DIR__ . '/fixtures/stm-fixture-harbour-lanterns.' . $name);
    }

    /** translation row for ($key, $lang), or null */
    private function translation(string $key, string $lang): ?array {
        foreach ($this->wpdb->all('stm_strings') as $string) {
            if ($string['string_key'] !== $key) {
                continue;
            }
            foreach ($this->wpdb->all('stm_translations') as $row) {
                if ((int) $row['string_id'] === (int) $string['id'] && $row['language_code'] === $lang) {
                    return $row;
                }
            }
        }
        return null;
    }

    public function test_xliff_fixture_maps_review_state_to_status_and_skips_empty_targets() {
        $result = ImportExport::import_xliff($this->fixture('xlf'));

        $this->assertSame([], $result['errors']);
        $this->assertSame(4, $result['created']);
        $this->assertSame(1, $result['skipped'], 'the untranslated weather vane has an empty target');

        $this->assertSame('published', $this->translation('fx.quay.lamplighter.count', 'nl')['status']);
        $this->assertSame('published', $this->translation('fx.quay.ferry.timetable', 'nl')['status'], 'state=translated counts as reviewed');
        $this->assertSame('draft', $this->translation('fx.quay.market.rule', 'nl')['status'], 'needs-review stays a draft');
        $this->assertNull($this->translation('fx.quay.untranslated.sign', 'nl'));

        foreach ($this->wpdb->all('stm_strings') as $string) {
            $this->assertSame('quayfx', $string['context']);
        }
    }

    public function test_xliff_reimport_updates_in_place_and_never_blanks_an_existing_translation() {
        // A human already translated the string that the file leaves empty.
        $this->wpdb->seed('wp_stm_strings', ['id' => 50, 'string_key' => 'fx.quay.untranslated.sign', 'context' => 'quayfx']);
        $this->wpdb->seed('wp_stm_translations', [
            'id' => 90, 'string_id' => 50, 'language_code' => 'nl',
            'translation' => 'Handmatig vertaald voordat het bestand binnenkwam.', 'status' => 'published',
        ]);

        $first  = ImportExport::import_xliff($this->fixture('xlf'));
        $second = ImportExport::import_xliff($this->fixture('xlf'));

        $this->assertSame(4, $first['created']);
        $this->assertSame(0, $second['created'], 'second pass creates nothing');
        $this->assertSame(4, $second['updated'], 'second pass updates the same four rows in place');
        $this->assertSame(5 /* 4 imported + 1 pre-existing */, count($this->wpdb->all('stm_translations')));

        $kept = $this->translation('fx.quay.untranslated.sign', 'nl');
        $this->assertSame('Handmatig vertaald voordat het bestand binnenkwam.', $kept['translation']);
        $this->assertSame('published', $kept['status']);
    }

    public function test_po_fixture_marks_fuzzy_entries_as_draft() {
        $result = ImportExport::import_po($this->fixture('po'), 'fr');

        $this->assertSame([], $result['errors']);
        $this->assertSame(3, $result['created']);
        $this->assertSame(1, $result['skipped'], 'only the entry with an empty msgstr; the PO header block is not an entry');

        $this->assertSame('published', $this->translation('fx.quay.lamplighter.count', 'fr')['status']);
        $this->assertSame('published', $this->translation('fx.quay.ferry.timetable', 'fr')['status']);
        $this->assertSame('draft', $this->translation('fx.quay.market.rule', 'fr')['status']);
        $this->assertNull($this->translation('fx.quay.untranslated.sign', 'fr'));
    }

    public function test_json_fixture_imports_then_updates_and_dry_run_writes_nothing() {
        $payload = json_decode($this->fixture('json'), true);

        $dry = API::process_import($payload, true);
        $this->assertSame(3, $dry['created']);
        $this->assertSame([], $this->wpdb->all('stm_translations'), 'dry run must not write');

        $first = API::process_import($payload);
        $this->assertSame(3, $first['created']);
        $this->assertSame([], $first['errors']);

        $second = API::process_import($payload);
        $this->assertSame(0, $second['created']);
        $this->assertSame(3, $second['updated']);
        $this->assertCount(3, $this->wpdb->all('stm_translations'));
        $this->assertSame('published', $this->translation('fx.quay.keeper.greeting', 'de')['status']);
    }

    public function test_exports_carry_review_status_and_round_trip_through_import() {
        $rows = [
            (object) ['id' => 1, 'string_key' => 'fx.quay.lamplighter.count', 'context' => 'quayfx', 'description' => '',
                'source_text' => 'The lamplighter counts.', 'target_text' => 'De opsteker telt.', 'target_status' => 'published',
                'translation' => 'De opsteker telt.', 'status' => 'published'],
            (object) ['id' => 2, 'string_key' => 'fx.quay.market.rule', 'context' => 'quayfx', 'description' => '',
                'source_text' => 'Barnacle pies.', 'target_text' => 'Zeepokkentaarten.', 'target_status' => 'draft',
                'translation' => 'Zeepokkentaarten.', 'status' => 'draft'],
            (object) ['id' => 3, 'string_key' => 'fx.quay.untranslated.sign', 'context' => 'quayfx', 'description' => '',
                'source_text' => 'Weather vane.', 'target_text' => null, 'target_status' => null,
                'translation' => null, 'status' => null],
        ];

        $source = new class($rows) extends FakeWpdb {
            private $rows;
            public function __construct($rows) { $this->rows = $rows; }
            public function get_results($query, $output = OBJECT) { return $this->rows; }
        };
        global $wpdb;
        $wpdb = $source;

        $xliff = ImportExport::export_xliff('en', 'nl');
        $this->assertStringContainsString('state="final"', $xliff, 'published -> final');
        $this->assertStringContainsString('state="needs-review-translation"', $xliff, 'draft -> needs-review');
        $this->assertStringContainsString('state="new"', $xliff, 'missing -> new');

        $po = ImportExport::export_po('nl');
        $this->assertStringContainsString("#, fuzzy\nmsgid \"fx.quay.market.rule\"", $po, 'draft rows are flagged fuzzy');
        $this->assertSame(1, substr_count($po, '#, fuzzy'), 'only the draft row is flagged');

        // Round trip into a fresh database.
        $wpdb = new FakeWpdb();
        $this->wpdb = $wpdb;
        $this->wpdb->seed('wp_stm_languages', ['code' => 'nl', 'name' => 'nl', 'native_name' => 'nl', 'flag_emoji' => '', 'is_active' => 1, 'is_default' => 0, 'order_index' => 2]);

        $result = ImportExport::import_xliff($xliff);
        $this->assertSame(2, $result['created']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame('published', $this->translation('fx.quay.lamplighter.count', 'nl')['status']);
        $this->assertSame('draft', $this->translation('fx.quay.market.rule', 'nl')['status']);
    }
}
