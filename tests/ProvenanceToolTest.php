<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.test.provenancetooltest

/**
 * PHPUnit tests: the source-provenance tooling in bin/provenance.php (task 5182).
 *
 * What is pinned here:
 *  - every own PHP/JS source file carries SPDX, copyright and a unique Source-Id;
 *  - the comparison report tells a literal copy, a prefix-only copy and an
 *    independent implementation apart, and never turns "independent code
 *    behaves the same" into an accusation;
 *  - the release ZIP is built from an allowlist, keeps tests/fixtures/tooling
 *    out, and is byte-reproducible from the commit;
 *  - private outputs (manifest, release record, ZIP) refuse to land inside the
 *    repository.
 */

namespace STM\Tests;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/bin/provenance.php';

class ProvenanceToolTest extends TestCase {

    private function root(): string {
        return dirname(__DIR__);
    }

    public function test_every_own_source_file_has_header_and_unique_source_id() {
        $this->assertSame([], stm_prov_check($this->root()));
    }

    public function test_apply_header_is_idempotent_and_keeps_the_plugin_header_first() {
        $plugin = "<?php\n/**\n * Plugin Name: Sample\n * Version: 1.0.0\n */\n\nnamespace Sample;\n";
        $once   = stm_prov_apply_header($plugin, 'simple-translation-manager.php');

        $this->assertLessThan(strpos($once, 'SPDX-License-Identifier'), strpos($once, 'Plugin Name:'), 'WordPress parses the plugin header from the top of the file');
        $this->assertStringContainsString('Source-Id: stm.bootstrap', $once);
        $this->assertSame($once, stm_prov_apply_header($once, 'simple-translation-manager.php'));

        $js = "#!/usr/bin/env node\nconsole.log('x');\n";
        $this->assertStringStartsWith("#!/usr/bin/env node\n// SPDX-License-Identifier", stm_prov_apply_header($js, 'bin/tool.js'));
    }

    public function test_literal_prefix_renamed_and_independent_copies_are_told_apart() {
        $reports = stm_prov_demo($this->root());

        $this->assertSame('verbatim-indicators', $reports['literal']['classification']);
        $this->assertGreaterThan(0, $reports['literal']['summary']['files_byte_identical']);

        $this->assertSame('prefix-renamed-indicators', $reports['renamed']['classification']);
        $this->assertSame(0, $reports['renamed']['summary']['files_byte_identical']);
        $this->assertSame(0, $reports['renamed']['summary']['markers_verbatim'], 'a prefix-only change leaves no marker spelled the way it was');
        $this->assertGreaterThan(0, $reports['renamed']['summary']['markers_renamed']);
        $this->assertSame($reports['renamed']['summary']['files_checked'], $reports['renamed']['summary']['files_renamed_or_edited']);

        // Same observable behaviour, written independently: nothing to report, and no accusation.
        $independent = $reports['independent'];
        $this->assertSame('no-recognition-points-found', $independent['classification']);
        $this->assertSame(0, $independent['summary']['markers_verbatim'] + $independent['summary']['markers_renamed'] + $independent['summary']['markers_prose_only']);
        $this->assertSame(0, $independent['summary']['files_partial_overlap'] + $independent['summary']['files_renamed_or_edited'] + $independent['summary']['files_byte_identical']);
    }

    public function test_every_report_says_it_is_not_proof() {
        foreach (stm_prov_demo($this->root()) as $report) {
            $this->assertStringContainsString('not proof of copying, intent, AI use or infringement', $report['notice']);
            $this->assertStringContainsString('GPL-licensed forks are permitted', $report['notice']);
            $this->assertStringNotContainsString('infring', strtolower($report['classification']));
        }
    }

    public function test_a_kept_copyright_notice_alone_is_not_an_indicator() {
        $base = sys_get_temp_dir() . '/stm-prov-test-' . bin2hex(random_bytes(4));
        mkdir($base . '/ref/includes', 0777, true);
        mkdir($base . '/cand/includes', 0777, true);
        try {
            copy($this->root() . '/includes/class-security.php', $base . '/ref/includes/class-security.php');
            file_put_contents(
                $base . '/cand/includes/other.php',
                "<?php\n// SPDX-License-Identifier: GPL-2.0-or-later\n// SPDX-FileCopyrightText: 2026 Martien de Jong\n// Source-Id: fork.other\nfunction fork_other() { return 1; }\n"
            );
            $report = stm_prov_compare($base . '/ref', $base . '/cand');
            $this->assertSame('copyright-notice-only', $report['classification']);
            $this->assertSame(1, $report['summary']['notices_retained']);
        } finally {
            stm_prov_rrmdir($base);
        }
    }

    public function test_zip_check_rejects_development_files_and_a_missing_plugin_file() {
        $good = ['simple-translation-manager/', 'simple-translation-manager/simple-translation-manager.php', 'simple-translation-manager/includes/class-api.php'];
        $this->assertSame([], stm_prov_zip_problems($good));

        foreach (['tests/fixtures/x.po', 'bin/provenance.php', 'vendor/autoload.php', 'AGENT_PROGRESS.md', 'composer.json', 'tests/ProvenanceToolTest.php'] as $dev) {
            $this->assertNotSame([], stm_prov_zip_problems(array_merge($good, ['simple-translation-manager/' . $dev])), "{$dev} must not ship");
        }
        $this->assertNotSame([], stm_prov_zip_problems(['simple-translation-manager/includes/class-api.php']));
    }

    public function test_release_zip_from_head_is_clean_and_reproducible() {
        if (stm_prov_git($this->root(), 'rev-parse --verify HEAD') === null) {
            $this->markTestSkipped('Not a git checkout.');
        }
        $dir = sys_get_temp_dir() . '/stm-prov-zip-' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        try {
            $first  = stm_prov_release($this->root(), 'HEAD', $dir . '/a.zip', '2026-01-01');
            $second = stm_prov_release($this->root(), 'HEAD', $dir . '/b.zip', '2026-01-01');

            $this->assertSame($first['zip']['sha256'], $second['zip']['sha256']);
            $this->assertSame(64, strlen($first['zip']['sha256']));
            $this->assertSame([], stm_prov_zip_problems(stm_prov_zip_entries($dir . '/a.zip')));
            $this->assertSame('2026-01-01', $first['published']);
            $this->assertIsBool($first['signing']['available']);
            $this->assertStringContainsString('says nothing about a rewritten', $first['notice']);

            $entries = stm_prov_zip_entries($dir . '/a.zip');
            foreach ($entries as $entry) {
                $this->assertStringNotContainsString('fixture', $entry);
            }
            $this->assertContains('simple-translation-manager/docs/editors/pdf/editors-guide.pdf', $entries, 'the Documentation screen links these PDFs');

            $verify = stm_prov_verify_release($this->root(), $first);
            $this->assertTrue($verify['match']);
        } finally {
            stm_prov_rrmdir($dir);
        }
    }

    public function test_zip_bytes_only_change_when_a_shipped_file_changes() {
        $repo = sys_get_temp_dir() . '/stm-zipmtime-' . bin2hex(random_bytes(4));
        $out  = sys_get_temp_dir() . '/stm-zipmtime-out-' . bin2hex(random_bytes(4));
        mkdir($repo, 0777, true);
        mkdir($out, 0777, true);
        $commit = function (string $message, string $date) use ($repo) {
            putenv("GIT_AUTHOR_DATE={$date}");
            putenv("GIT_COMMITTER_DATE={$date}");
            stm_prov_run('git -C ' . escapeshellarg($repo) . ' add -A');
            stm_prov_run('git -C ' . escapeshellarg($repo) . ' -c user.name=Test -c user.email=test@example.test commit -q -m ' . escapeshellarg($message), $code);
            putenv('GIT_AUTHOR_DATE');
            putenv('GIT_COMMITTER_DATE');
            $this->assertSame(0, $code, 'commit failed');
        };
        $hash = function (string $name) use ($repo, $out): string {
            stm_prov_build_zip($repo, 'HEAD', $out . '/' . $name . '.zip');
            return hash_file('sha256', $out . '/' . $name . '.zip');
        };
        try {
            stm_prov_run('git -C ' . escapeshellarg($repo) . ' init -q');
            file_put_contents($repo . '/simple-translation-manager.php', "<?php\n// plugin\n");
            file_put_contents($repo . '/readme.txt', "=== STM ===\n");
            file_put_contents($repo . '/VERSION', "1.0.0\n");
            file_put_contents($repo . '/uninstall.php', "<?php\n");
            foreach (['includes/a.php', 'templates/a.php', 'assets/a.css', 'docs/editors/pdf/guide.pdf'] as $shipped) {
                mkdir(dirname($repo . '/' . $shipped), 0777, true);
                file_put_contents($repo . '/' . $shipped, "x\n");
            }
            $commit('ship', '2026-01-01T10:00:00+00:00');
            $first = $hash('first');

            file_put_contents($repo . '/docs/NOTES.md', "notes\n");
            $commit('docs only', '2026-02-01T10:00:00+00:00');
            $this->assertSame($first, $hash('after-docs'), 'a docs-only commit must not change the release ZIP bytes');

            file_put_contents($repo . '/readme.txt', "=== STM ===\nchanged\n");
            $commit('shipped change', '2026-03-01T10:00:00+00:00');
            $this->assertNotSame($first, $hash('after-ship'));
        } finally {
            $this->removeTree($repo);
            $this->removeTree($out);
        }
    }

    /** Remove a tree that may hold read-only git objects (Windows refuses to unlink those). */
    private function removeTree(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            chmod($file->getPathname(), 0666);
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }

    public function test_a_docblock_tag_names_the_declaration_it_documents_not_the_one_above() {
        $src = implode("\n", [
            '<?php',                                    // 1
            'namespace Sample;',                        // 2
            '',                                         // 3
            'class Box {',                              // 4
            '    /**',                                  // 5
            '     * [STM-SYM-99] documents the constant below',  // 6
            '     */',                                  // 7
            '    const A = 1;',                         // 8
            '',                                         // 9
            '    public function run() {',              // 10
            '        // [STM-DN-98] inline note inside run()',   // 11
            '        return 1;',                        // 12
            '    }',                                    // 13
            '',                                         // 14
            '    /**',                                  // 15
            '     * [STM-DN-97] documents second()',    // 16
            '     */',                                  // 17
            '    public function second() {}',          // 18
            '}',                                        // 19
        ]);
        $lines = preg_split('/\R/', $src);
        $this->assertSame('Box::A', stm_prov_symbol_for_line($lines, 6), 'a docblock tag belongs to the declaration after it');
        $this->assertSame('Box::run', stm_prov_symbol_for_line($lines, 11), 'an inline tag belongs to the enclosing function');
        $this->assertSame('Box::second', stm_prov_symbol_for_line($lines, 16));
    }

    public function test_every_symbol_doc_in_the_real_tree_resolves_to_a_constant() {
        $symbolDocs = array_filter(stm_prov_collect_markers($this->root()), static function ($m) {
            return $m['kind'] === 'symbol-doc';
        });
        $this->assertNotEmpty($symbolDocs);
        foreach ($symbolDocs as $marker) {
            $this->assertMatchesRegularExpression('/::[A-Z][A-Z0-9_]+$/', $marker['symbol'], "{$marker['id']} in {$marker['file']} should name the constant it documents");
        }
    }

    public function test_private_outputs_refuse_to_land_inside_the_repository() {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('inside the plugin repository');
        stm_prov_assert_outside_repo($this->root() . '/tests/manifest.json', $this->root());
    }

    public function test_private_outputs_are_allowed_outside_the_repository() {
        $out = sys_get_temp_dir() . '/stm-prov-out-' . bin2hex(random_bytes(4)) . '/manifest.json';
        stm_prov_assert_outside_repo($out, $this->root());
        $this->assertDirectoryExists(dirname($out));
        rmdir(dirname($out));
    }
}
