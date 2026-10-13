<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.test.wpprovenancetooltest

/**
 * PHPUnit tests: the reusable provenance check in tools/wp-provenance (task 5184).
 *
 * What is pinned here:
 *  - a missing SPDX/copyright header, a lost registered marker or an unconfirmed rights holder each give a
 *    finding with file, rule and fix, and the exit code is non-zero on errors;
 *  - findings carry the same fingerprint as the Plugin Check findings of task 5144, and scanning twice
 *    produces a delta with nothing new (so an importer creates no duplicate Work tasks);
 *  - the release ZIP is judged by its listing, built reproducibly, and the release record holds commit,
 *    exact-ZIP SHA-256, timestamp, publication and signing status;
 *  - private outputs refuse to land inside the repository and refuse text that looks like a secret;
 *  - comparison mode flags a literal and a prefix-renamed copy, and does not flag an independent
 *    implementation, a mere brand name, the same feature or standard WordPress boilerplate.
 *
 * All fixtures are invented (tests/fixtures/wp-provenance, stored as *.php.fixture).
 */

namespace STM\Tests;

use PHPUnit\Framework\TestCase;
use WpProvenance\Checker;
use WpProvenance\Cli;
use WpProvenance\Compare;
use WpProvenance\Config;
use WpProvenance\ConfigException;
use WpProvenance\Demo;
use WpProvenance\Finding;
use WpProvenance\Git;
use WpProvenance\Headers;
use WpProvenance\Manifest;
use WpProvenance\PrivateOutput;
use WpProvenance\Release;
use WpProvenance\Report;
use WpProvenance\Tree;
use WpProvenance\Zip;

require_once dirname(__DIR__) . '/tools/wp-provenance/src/autoload.php';

class WpProvenanceToolTest extends TestCase {

    /** @var string[] temp directories to remove */
    private $temp = [];

    protected function tearDown(): void {
        foreach ($this->temp as $dir) {
            Demo::removeTree($dir);
        }
        $this->temp = [];
    }

    private function repo(): string {
        return str_replace('\\', '/', dirname(__DIR__));
    }

    private function fixtures(): string {
        return $this->repo() . '/tests/fixtures/wp-provenance';
    }

    private function temp(string $label): string {
        $dir          = Demo::tempDir($label);
        $this->temp[] = $dir;
        return $dir;
    }

    /** A fixture plugin copied (with real file names) into a temp directory. */
    private function materialize(string $fixture): string {
        $dir = $this->temp('fx') . '/plugin';
        Demo::copyTree($this->fixtures() . '/' . $fixture, $dir);
        return $dir;
    }

    private function git(): Git {
        $git = new Git($this->repo());
        if (!$git->isRepository()) {
            $this->markTestSkipped('needs a git checkout');
        }
        return $git;
    }

    /** @return array<string,mixed>|null */
    private function find(array $report, string $rule, ?string $file = null): ?array {
        foreach ($report['findings'] as $f) {
            if ($f['rule'] === $rule && ($file === null || $f['file'] === $file)) {
                return $f;
            }
        }
        return null;
    }

    private function stm(): Config {
        return Config::load($this->repo() . '/provenance.json');
    }

    // -- STM itself ---------------------------------------------------------------------------------------

    public function test_stm_passes_its_own_check_with_no_errors() {
        $report = Checker::run($this->stm(), null);

        $errors = array_filter($report['findings'], static function (array $f): bool {
            return $f['type'] === Finding::ERROR;
        });
        $this->assertSame([], array_values($errors), 'STM must satisfy its own provenance config');
        $this->assertTrue($report['result']['clean']);
        $this->assertSame(0, Report::exitCode($report, false));
    }

    public function test_the_tools_own_source_carries_the_same_headers_it_demands() {
        $report = Checker::run($this->stm(), null);
        $tools  = array_filter($report['findings'], static function (array $f): bool {
            return strpos($f['file'], 'tools/wp-provenance/') === 0;
        });
        $this->assertSame([], array_values($tools));
    }

    // -- findings: file, rule, fix ------------------------------------------------------------------------

    public function test_broken_plugin_gives_a_finding_with_file_rule_and_fix_for_each_problem() {
        $report = Demo::checkBroken($this->fixtures());

        $expected = [
            ['provenance.header.spdx-missing', 'includes/class-no-header.php'],
            ['provenance.header.copyright-missing', 'includes/class-no-header.php'],
            ['provenance.header.source-id-missing', 'includes/class-no-header.php'],
            ['provenance.header.spdx-mismatch', 'includes/class-wrong-holder.php'],
            ['provenance.header.copyright-mismatch', 'includes/class-wrong-holder.php'],
            ['provenance.header.source-id-duplicate', 'includes/class-ok.php'],
            ['provenance.header.source-id-prefix', 'includes/class-bad-prefix.php'],
            ['provenance.rights.holder-unconfirmed', 'provenance.json'],
            ['provenance.marker.kind-absent', 'provenance.json'],
            ['provenance.marker.registered-missing', 'includes/class-ok.php'],
            ['provenance.marker.registered-moved', 'includes/class-no-header.php'],
            ['provenance.license.plugin-header-mismatch', 'broken-fixture.php'],
            ['provenance.zip.development-file', 'tests/BrokenTest.php'],
            ['provenance.zip.development-file', 'bin/build.sh'],
            ['provenance.zip.development-file', 'fixtures/sample.json'],
            ['provenance.zip.development-file', 'AGENT_PROGRESS.md'],
            ['provenance.zip.development-file', 'provenance.json'],
            ['provenance.zip.not-allowlisted', 'notes.txt'],
        ];
        foreach ($expected as [$rule, $file]) {
            $finding = $this->find($report, $rule, $file);
            $this->assertNotNull($finding, "{$rule} on {$file}");
            $this->assertNotSame('', trim($finding['message']), "{$rule}: message");
            $this->assertNotSame('', trim($finding['fix']), "{$rule}: a finding must say how to fix it");
            $this->assertSame($finding['rule'], $finding['check'], 'rule is repeated as check for the #5144 importer');
        }

        $this->assertFalse($report['result']['clean']);
        $this->assertSame(1, Report::exitCode($report, false), 'non-zero exit on errors');
    }

    public function test_the_fix_for_an_unconfirmed_holder_forbids_adding_our_copyright() {
        $report  = Demo::checkBroken($this->fixtures());
        $finding = $this->find($report, 'provenance.rights.holder-unconfirmed');
        $this->assertStringContainsString('do not add our copyright', $finding['fix']);
    }

    public function test_warnings_alone_do_not_fail_unless_strict() {
        $report = ['result' => ['errors' => 0, 'warnings' => 2]];
        $this->assertSame(0, Report::exitCode($report, false));
        $this->assertSame(1, Report::exitCode($report, true));
        $this->assertSame(1, Report::exitCode(['result' => ['errors' => 1, 'warnings' => 0]], false));
    }

    public function test_a_check_that_could_not_run_is_listed_not_hidden() {
        $report = Checker::run($this->stm(), null);
        $skipped = array_column($report['checks_skipped'], 'check');
        $this->assertContains('registered markers', $skipped);
        $this->assertContains('release ZIP', $skipped);
    }

    public function test_cli_exit_codes() {
        $broken = $this->materialize('broken');
        ob_start();
        $codeBroken = Cli::main(['wp-provenance', 'check', '--config=' . $broken . '/provenance.json', '--no-git']);
        $out = (string) ob_get_clean();
        $this->assertSame(1, $codeBroken);
        $this->assertStringContainsString('provenance.header.spdx-missing', $out);
        $this->assertStringContainsString('fix:', $out);

        ob_start();
        $codeStm = Cli::main(['wp-provenance', 'check', '--config=' . $this->repo() . '/provenance.json', '--no-git']);
        ob_end_clean();
        $this->assertSame(0, $codeStm);

        $this->assertSame(2, (function () {
            ob_start();
            $code = Cli::main(['wp-provenance', 'check', '--config=' . $this->repo() . '/does-not-exist.json']);
            ob_end_clean();
            return $code;
        })());
    }

    public function test_apply_headers_refuses_for_an_unconfirmed_holder_and_changes_nothing() {
        $broken = $this->materialize('broken');
        $before = file_get_contents($broken . '/includes/class-no-header.php');

        ob_start();
        $code = Cli::main(['wp-provenance', 'apply-headers', '--config=' . $broken . '/provenance.json']);
        ob_end_clean();

        $this->assertSame(1, $code);
        $this->assertSame($before, file_get_contents($broken . '/includes/class-no-header.php'));
    }

    public function test_apply_headers_adds_only_what_is_missing_and_is_idempotent() {
        $dir = $this->materialize('reference');
        $file = $dir . '/includes/class-registry.php';
        // Remove the whole header and the plugin header docblock stays untouched in the main file.
        file_put_contents($file, preg_replace('#^// (SPDX|Source-Id).*\R#m', '', (string) file_get_contents($file)));
        $this->assertNotNull($this->find(Checker::run(Config::load($dir . '/provenance.json'), null), 'provenance.header.spdx-missing', 'includes/class-registry.php'));

        ob_start();
        $code = Cli::main(['wp-provenance', 'apply-headers', '--config=' . $dir . '/provenance.json']);
        ob_end_clean();
        $this->assertSame(0, $code);

        $report = Checker::run(Config::load($dir . '/provenance.json'), null);
        $this->assertSame([], array_values(array_filter($report['findings'], static function (array $f): bool {
            return strpos($f['rule'], 'provenance.header.') === 0;
        })));
        $this->assertStringContainsString('Source-Id: fls.registry', (string) file_get_contents($file), 'a derived id, written once');

        $after = (string) file_get_contents($file);
        ob_start();
        Cli::main(['wp-provenance', 'apply-headers', '--config=' . $dir . '/provenance.json']);
        ob_end_clean();
        $this->assertSame($after, (string) file_get_contents($file), 'second run changes nothing');
    }

    public function test_the_main_plugin_file_keeps_its_wordpress_header_first() {
        $config   = Config::load($this->fixtures() . '/reference/provenance.json');
        $plugin   = "<?php\n/**\n * Plugin Name: Sample\n * Version: 1.0.0\n */\n\nnamespace Sample;\n";
        $withHead = Headers::apply($config, $plugin, 'fixture-lantern-sync.php');

        $this->assertLessThan(strpos($withHead, 'SPDX-License-Identifier'), strpos($withHead, 'Plugin Name:'));
        $this->assertSame($withHead, Headers::apply($config, $withHead, 'fixture-lantern-sync.php'));
    }

    // -- fingerprints and "scan twice" --------------------------------------------------------------------

    public function test_fingerprints_equal_those_of_the_5144_importer_for_the_same_input() {
        // Expected values were computed with Fingerprint::apply() of PR #45 (task 5144, tools/wp-plugin-check)
        // for the same slug, check codes, files, messages and flagged line text.
        $message = 'Copyright line names "Someone Else" instead of "Martien de Jong" on line 3.';
        $line    = '// SPDX-FileCopyrightText: 2026 Someone Else';
        $sealed  = Finding::seal('demo-plugin', [
            Finding::make('provenance.header.spdx-missing', Finding::ERROR, 'includes/a.php', 0, 'SPDX-License-Identifier is missing.', 'fix'),
            Finding::make('provenance.header.copyright-mismatch', Finding::ERROR, 'includes/b.php', 3, $message, 'fix', '(file scope)', $line),
            Finding::make('provenance.header.copyright-mismatch', Finding::ERROR, 'includes/b.php', 3, $message, 'fix', '(file scope)', $line),
        ]);

        $this->assertSame(['ae65880e2f28fdea', 'f1f7d76b51788447', '779225466591f7f9'], array_column($sealed, 'fingerprint'));
        $this->assertSame('provenance.header.copyright-mismatch @ includes/b.php', $sealed[1]['root_cause']);
    }

    public function test_scanning_twice_yields_no_new_findings() {
        $dir    = $this->materialize('broken');
        $config = Config::load($dir . '/provenance.json');

        $first  = Checker::run($config, null);
        $second = Checker::run(Config::load($dir . '/provenance.json'), null);
        $delta  = Finding::delta($first, $second);

        $this->assertTrue($delta['comparable']);
        $this->assertSame([], $delta['new_findings'], 'an importer keyed by fingerprint creates no task for these');
        $this->assertSame([], $delta['fixed_findings']);
        $this->assertSame(count($first['findings']), $delta['unchanged_findings']);
        $this->assertSame(array_column($first['findings'], 'fingerprint'), array_column($second['findings'], 'fingerprint'));
        $this->assertGreaterThan(5, count($first['findings']));
        $this->assertSame(count($first['findings']), count(array_unique(array_column($first['findings'], 'fingerprint'))), 'fingerprints are unique within a scan');
    }

    public function test_a_finding_that_only_moves_keeps_its_fingerprint_and_a_fixed_one_shows_as_fixed() {
        $dir    = $this->materialize('broken');
        $file   = $dir . '/includes/class-wrong-holder.php';
        $first  = Checker::run(Config::load($dir . '/provenance.json'), null);

        // Push everything down two lines: the flagged line moves, its text does not.
        file_put_contents($file, preg_replace('/^<\?php\R/', "<?php\n\n\n", (string) file_get_contents($file)));
        $moved = Checker::run(Config::load($dir . '/provenance.json'), null);
        $this->assertSame([], Finding::delta($first, $moved)['new_findings'], 'line numbers are not part of the identity');
        $this->assertSame(4, $this->find($moved, 'provenance.header.spdx-mismatch', 'includes/class-wrong-holder.php')['line']);

        // Fix the copyright line: that finding is fixed, nothing becomes new.
        file_put_contents($file, str_replace('2026 Somebody Else', '2026 Example Fixture Author', (string) file_get_contents($file)));
        $fixed = Checker::run(Config::load($dir . '/provenance.json'), null);
        $delta = Finding::delta($moved, $fixed);
        $this->assertSame([], $delta['new_findings']);
        $this->assertCount(1, $delta['fixed_findings']);
        $this->assertSame('provenance.header.copyright-mismatch', $delta['fixed_findings'][0]['rule']);
    }

    // -- release ZIP and record ---------------------------------------------------------------------------

    public function test_release_zip_holds_only_runtime_files_checked_by_listing_it() {
        $git    = $this->git();
        $config = $this->stm();
        $zip    = $this->temp('zip') . '/stm.zip';

        Zip::build($config, $git, 'HEAD', $zip);
        $entries = array_filter(Zip::entries($zip), static function (string $e): bool {
            return substr($e, -1) !== '/';
        });

        $this->assertContains('simple-translation-manager/simple-translation-manager.php', $entries);
        $this->assertSame([], Zip::problems($config, Zip::entries($zip), 'stm.zip'));
        foreach ($entries as $entry) {
            $this->assertDoesNotMatchRegularExpression('#/(tests|bin|tools|vendor|fixtures)/#', $entry, $entry);
            $this->assertNotContains(basename($entry), ['provenance.json', 'AGENT_PROGRESS.md', 'phpunit.xml', 'composer.json']);
        }
    }

    public function test_release_zip_is_byte_reproducible_from_the_commit() {
        $git    = $this->git();
        $config = $this->stm();
        $dir    = $this->temp('zip');

        Zip::build($config, $git, 'HEAD', $dir . '/a.zip');
        Zip::build($config, $git, 'HEAD', $dir . '/b.zip');

        $this->assertSame(hash_file('sha256', $dir . '/a.zip'), hash_file('sha256', $dir . '/b.zip'));
    }

    public function test_zip_build_refuses_to_write_inside_the_repository() {
        $git = $this->git();
        $this->expectException(\RuntimeException::class);
        Zip::build($this->stm(), $git, 'HEAD', $this->repo() . '/should-not-exist.zip');
    }

    public function test_release_record_holds_commit_zip_hash_timestamp_publication_and_signing_status() {
        $git    = $this->git();
        $config = $this->stm();
        $zip    = $this->temp('zip') . '/stm.zip';
        Zip::build($config, $git, 'HEAD', $zip);

        $record = Release::record($config, $git, 'HEAD', $zip, true, '2026-10-10', 'https://example.test/stm/1.3.2', []);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $record['commit']);
        $this->assertSame(hash_file('sha256', $zip), $record['zip']['sha256']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $record['recorded_at']);
        $this->assertSame('https://example.test/stm/1.3.2', $record['publication']['url']);
        $this->assertSame('published', $record['publication']['status']);
        $this->assertContains($record['signing']['status'], ['signed', 'unsigned']);
        $this->assertTrue($record['zip_check']['passed']);
        $this->assertStringContainsString('says nothing about a rewritten', $record['notice']);

        $unpublished = Release::record($config, $git, 'HEAD', $zip, true, null, null, []);
        $this->assertNull($unpublished['publication']['url']);
        $this->assertSame('not recorded', $unpublished['publication']['status']);
    }

    public function test_an_unsigned_release_says_unsigned_and_why() {
        $git    = $this->git();
        $config = $this->stm();
        $sha    = (string) $git->commitOf('HEAD');
        $signing = Release::signing($config, $git, 'no.such.version', $sha);

        $this->assertSame('unsigned', $signing['status']);
        $this->assertStringContainsString('No tag', $signing['detail']);
    }

    public function test_verify_release_rebuilds_the_same_bytes() {
        $git    = $this->git();
        $config = $this->stm();
        $zip    = $this->temp('zip') . '/stm.zip';
        Zip::build($config, $git, 'HEAD', $zip);
        $record = Release::record($config, $git, 'HEAD', $zip, true, null, null, []);

        $this->assertTrue(Release::verify($config, $git, $record)['match']);

        $record['zip']['sha256'] = str_repeat('0', 64);
        $this->assertFalse(Release::verify($config, $git, $record)['match']);

        $record['zip']['built_by'] = 'not built by this tool: an existing ZIP was recorded as given';
        $this->assertNull(Release::verify($config, $git, $record)['match']);
    }

    // -- private outputs ----------------------------------------------------------------------------------

    public function test_private_outputs_refuse_to_land_inside_the_repository() {
        $repo = $this->repo();
        foreach ([$repo . '/manifest.json', $repo . '/new-dir/deeper/manifest.json', $repo . '/includes/../manifest.json', $repo . '/tools/x.json'] as $path) {
            try {
                PrivateOutput::assertOutside($path, $repo);
                $this->fail("expected a refusal for {$path}");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('inside the plugin repository', $e->getMessage());
            }
        }
        $this->assertFalse(is_dir($repo . '/new-dir'), 'refusing must not create directories in the repository');

        PrivateOutput::assertOutside($this->temp('out') . '/manifest.json', $repo);
        $this->addToAssertionCount(1);
    }

    public function test_private_outputs_refuse_text_that_looks_like_a_secret_without_echoing_it() {
        $secret = 'gh' . 'p_' . str_repeat('a1B2', 8);
        $dir    = $this->temp('out');
        try {
            PrivateOutput::write($dir . '/m.json', '{"context":"token ' . $secret . '"}', $this->repo(), 'the manifest');
            $this->fail('expected a refusal');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('looks like a secret', $e->getMessage());
            $this->assertStringNotContainsString($secret, $e->getMessage());
        }
        $this->assertFileDoesNotExist($dir . '/m.json');
        $this->assertNotSame([], PrivateOutput::secretsIn('password = hunter2hunter2'));
        $this->assertSame([], PrivateOutput::secretsIn('A design note about passwords and tokens in general.'));
    }

    public function test_manifest_registers_markers_holds_no_secret_and_can_be_written_privately() {
        $config   = $this->stm();
        $manifest = Manifest::build(new Tree($config), null, false);

        $this->assertSame('wp-provenance-manifest/1', $manifest['schema']);
        $this->assertSame('verified', $manifest['rights_holder']['status']);
        $kinds = array_unique(array_column($manifest['markers'], 'kind'));
        foreach (['source-id', 'copyright-header', 'diag-code', 'design-note', 'symbol-doc'] as $kind) {
            $this->assertContains($kind, $kinds);
        }
        $json = json_encode($manifest, JSON_UNESCAPED_SLASHES);
        $this->assertSame([], PrivateOutput::secretsIn($json), 'the manifest must hold no secrets');

        $out = $this->temp('out') . '/manifest.json';
        PrivateOutput::write($out, $json, $this->repo(), 'the manifest');
        $this->assertFileExists($out);
    }

    public function test_the_manifest_of_a_checkout_satisfies_its_own_registered_marker_check() {
        $config   = $this->stm();
        $manifest = Manifest::build(new Tree($config), null, false);
        $path     = $this->temp('out') . '/manifest.json';
        PrivateOutput::write($path, json_encode($manifest, JSON_UNESCAPED_SLASHES), $this->repo(), 'the manifest');

        $report = Checker::run($config, null, ['manifest' => $path]);

        $this->assertNull($this->find($report, 'provenance.marker.registered-missing'));
        $this->assertContains('registered markers against the private manifest', $report['checks_run']);
    }

    // -- config -------------------------------------------------------------------------------------------

    public function test_config_reports_every_problem_at_once() {
        try {
            Config::fromArray(['schema' => 'wrong'], '/tmp/provenance.json', '/tmp');
            $this->fail('expected a ConfigException');
        } catch (ConfigException $e) {
            $this->assertGreaterThan(4, count($e->problems));
            $this->assertStringContainsString('plugin.slug', implode("\n", $e->problems));
            $this->assertStringContainsString('rights.holder', implode("\n", $e->problems));
        }
    }

    public function test_rights_holder_and_license_come_from_each_plugins_own_config() {
        $stm = $this->stm();
        $fx  = Config::load($this->fixtures() . '/reference/provenance.json');
        $this->assertSame('Martien de Jong', $stm->holder);
        $this->assertSame('Example Fixture Author', $fx->holder);
        $this->assertNotSame($stm->holder, $fx->holder);
    }

    public function test_license_names_are_compared_by_meaning_not_spelling() {
        foreach (['GPL v2 or later', 'GPLv2+', 'GPL-2.0-or-later', 'GPL-2.0+', 'gpl_2.0_or_later'] as $spelling) {
            $this->assertSame('gpl-2.0-or-later', Checker::normaliseLicense($spelling), $spelling);
        }
        $this->assertNotSame(Checker::normaliseLicense('GPL-2.0-or-later'), Checker::normaliseLicense('GPL-2.0-only'));
        $this->assertNotSame(Checker::normaliseLicense('MIT'), Checker::normaliseLicense('GPL-2.0-or-later'));
    }

    // -- comparison mode ----------------------------------------------------------------------------------

    public function test_every_scenario_gets_its_expected_assessment() {
        $reports = Demo::compareAll($this->fixtures());

        foreach (Demo::EXPECTED as $name => $expected) {
            $this->assertSame($expected, $reports[$name]['assessment'], $name);
        }
    }

    public function test_a_literal_copy_is_flagged_with_locations_and_several_kinds_of_match() {
        $report = Demo::compareAll($this->fixtures())['literal-copy'];

        $this->assertContains('registered-marker-verbatim', $report['kinds']['strong']);
        $this->assertContains('file-byte-identical', $report['kinds']['strong']);
        $this->assertContains('copyright-notice-retained', $report['kinds']['weak']);
        $this->assertGreaterThanOrEqual(3, $report['summary']['files_byte_identical']);

        $hit = null;
        foreach ($report['markers'] as $m) {
            if ($m['id'] === 'FLS-E-REGISTRY-DUPLICATE') {
                $hit = $m;
            }
        }
        $this->assertSame('verbatim', $hit['match']);
        $this->assertSame('includes/class-registry.php', $hit['candidate'][0]['file'], 'where it is found');
        $this->assertGreaterThan(0, $hit['candidate'][0]['line']);
        $this->assertSame('FLS_Registry::register', $hit['reference']['symbol'], 'where it is in the reference');
    }

    public function test_a_prefix_only_rename_is_flagged_although_no_marker_is_spelled_the_same() {
        $report = Demo::compareAll($this->fixtures())['prefix-renamed-copy'];

        $this->assertSame(0, $report['summary']['markers_verbatim']);
        $this->assertGreaterThanOrEqual(2, $report['summary']['markers_renamed']);
        $this->assertSame(0, $report['summary']['files_byte_identical']);
        $this->assertContains('registered-marker-prefix-renamed', $report['kinds']['strong']);
        $this->assertContains('file-lightly-edited', $report['kinds']['strong']);
    }

    public function test_an_independent_implementation_is_not_flagged() {
        $report = Demo::compareAll($this->fixtures())['independent'];

        $this->assertSame('none', $report['assessment']);
        $this->assertSame([], $report['kinds']['strong']);
        $this->assertSame([], $report['kinds']['weak']);
        $this->assertSame(0, $report['summary']['markers_verbatim'] + $report['summary']['markers_renamed'] + $report['summary']['markers_prose_only']);
    }

    public function test_the_same_feature_with_the_same_generic_names_is_not_flagged() {
        $report = Demo::compareAll($this->fixtures())['same-feature'];

        $this->assertSame('none', $report['assessment']);
        foreach ($report['files'] as $file) {
            $this->assertLessThan(Compare::PARTIAL, $file['similarity'], $file['reference_file']);
        }
    }

    public function test_a_brand_name_alone_never_changes_the_assessment_but_is_still_shown_with_its_location() {
        $report = Demo::compareAll($this->fixtures())['brand-name-only'];

        $this->assertSame('none', $report['assessment']);
        $this->assertContains('brand-name', $report['kinds']['informational']);
        $this->assertSame('Fixture Lantern Sync', $report['brand_name'][0]['name']);
        $files = array_column($report['brand_name'][0]['locations'], 'file');
        $this->assertContains('readme.txt', $files);
        $this->assertContains('dashboard-extras.php', $files);
    }

    public function test_standard_wordpress_boilerplate_alone_never_changes_the_assessment() {
        $report = Demo::compareAll($this->fixtures())['wordpress-boilerplate-only'];

        $this->assertSame('none', $report['assessment']);
        $main = array_values(array_filter($report['files'], static function (array $f): bool {
            return $f['reference_file'] === 'fixture-lantern-sync.php';
        }))[0];
        $this->assertSame('too-small-or-boilerplate', $main['status']);
    }

    public function test_the_boilerplate_rules_are_what_keeps_the_skeleton_from_matching() {
        $without = Demo::compareAll($this->fixtures(), ['boilerplate' => false])['wordpress-boilerplate-only'];

        $this->assertSame('weak-indicators', $without['assessment'], 'with the boilerplate left in, the skeleton overlaps the main file');
        $this->assertSame(['file-partial-overlap'], $without['kinds']['weak']);
    }

    public function test_no_report_claims_copying_and_every_report_says_it_is_not_proof() {
        foreach (Demo::compareAll($this->fixtures()) as $name => $report) {
            $this->assertContains($report['assessment'], ['strong-indicators', 'weak-indicators', 'none'], $name);
            $this->assertStringContainsString('not proof of copying', $report['notice'], $name);
            $this->assertStringContainsString('never changes the assessment', $report['notice'], $name);
            $this->assertArrayNotHasKey('conclusion', $report, $name);
        }
    }

    public function test_comparison_markdown_shows_locations_and_the_brand_section() {
        $report = Demo::compareAll($this->fixtures())['brand-name-only'];
        $md     = Compare::markdown($report);

        $this->assertStringContainsString('Assessment: **none**', $md);
        $this->assertStringContainsString('Brand name (informational, never an indicator)', $md);
        $this->assertStringContainsString('readme.txt:', $md);
    }

    public function test_demo_cli_runs_the_broken_plugin_and_all_six_scenarios_and_passes() {
        ob_start();
        $code = Cli::main(['wp-provenance', 'demo', '--fixtures=' . $this->fixtures()]);
        $out  = (string) ob_get_clean();

        $this->assertSame(0, $code, $out);
        foreach (array_keys(Demo::EXPECTED) as $name) {
            $this->assertStringContainsString($name, $out);
        }
        $this->assertStringNotContainsString('UNEXPECTED', $out);
    }
}
