<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.test.wppluginchecktooltest


/**
 * PHPUnit tests: the Plugin Check scanner tooling in tools/wp-plugin-check (task 5144).
 *
 * The scan itself (official Plugin Check in a throwaway WordPress) is exercised by running
 * `php tools/wp-plugin-check/scan.php scan <zip>`; these tests pin the parts that decide
 * whether a result can be trusted: reading the ZIP, the suppression inventory, finding
 * fingerprints that survive moved code, and the comparison between two scans.
 */

namespace STM\Tests;

use PHPUnit\Framework\TestCase;
use STM\Tests\Fakes\ZipBuilder;
use WpPluginCheck\Delta;
use WpPluginCheck\Fetcher;
use WpPluginCheck\Fingerprint;
use WpPluginCheck\Pins;
use WpPluginCheck\Scanner;
use WpPluginCheck\Suppressions;
use WpPluginCheck\ZipContent;
use WpPluginCheck\ZipFile;

require_once dirname( __DIR__ ) . '/tools/wp-plugin-check/src/autoload.php';

class WpPluginCheckToolTest extends TestCase {

    private function samplePlugin( array $extra = [] ): string {
        return ZipBuilder::file( array_merge( [
            'sample/'                => '',
            'sample/sample.php'      => "<?php\n/**\n * Plugin Name: Sample Thing\n * Version: 2.4.1\n */\nif ( ! defined( 'ABSPATH' ) ) { exit; }\n",
            'sample/readme.txt'      => "=== Sample Thing ===\nStable tag: 2.4.1\n",
        ], $extra ) );
    }

    // --- ZIP reading -----------------------------------------------------------------------

    public function test_zip_reader_returns_content_for_stored_and_deflated_entries() {
        foreach ( [ true, false ] as $deflate ) {
            $path = ZipBuilder::file( [ 'p/' => '', 'p/a.txt' => str_repeat( 'abc', 500 ), 'p/b.txt' => 'tiny' ], $deflate );
            $zip  = new ZipFile( $path );
            $this->assertSame( [ 'p/a.txt', 'p/b.txt' ], $zip->fileNames() );
            $this->assertSame( str_repeat( 'abc', 500 ), $zip->read( 'p/a.txt' ) );
            $this->assertSame( hash( 'sha256', 'tiny' ), $zip->hashes()['p/b.txt'] );
            $this->assertSame( 'p', $zip->topLevelDirectory() );
            unlink( $path );
        }
    }

    public function test_zip_must_have_exactly_one_plugin_folder() {
        $path = ZipBuilder::file( [ 'one/a.php' => 'x', 'two/b.php' => 'y' ] );
        $this->expectException( \RuntimeException::class );
        try {
            ( new ZipFile( $path ) )->topLevelDirectory();
        } finally {
            unlink( $path );
        }
    }

    public function test_a_file_at_the_zip_root_is_rejected() {
        $path = ZipBuilder::file( [ 'plugin/a.php' => 'x' ] );
        $zip  = new ZipFile( $path );
        unlink( $path );
        $this->assertSame( 'plugin', $zip->topLevelDirectory() );

        $loose = ZipBuilder::file( [ 'readme.txt' => 'x' ] );
        $this->expectException( \RuntimeException::class );
        try {
            ( new ZipFile( $loose ) )->topLevelDirectory();
        } finally {
            unlink( $loose );
        }
    }

    public function test_extraction_refuses_entries_that_escape_the_target_folder() {
        $path = ZipBuilder::file( [ 'plugin/../../evil.php' => 'x' ] );
        $dir  = sys_get_temp_dir() . '/stm-zip-extract-' . bin2hex( random_bytes( 4 ) );
        $this->expectException( \RuntimeException::class );
        try {
            ( new ZipFile( $path ) )->extractTo( $dir );
        } finally {
            unlink( $path );
        }
    }

    public function test_extraction_strips_the_prefix_and_writes_every_file() {
        $path = ZipBuilder::file( [ 'wordpress/' => '', 'wordpress/index.php' => '<?php', 'wordpress/wp-admin/a.php' => 'a', 'other/x' => 'x' ] );
        $dir  = sys_get_temp_dir() . '/stm-zip-extract-' . bin2hex( random_bytes( 4 ) );
        try {
            $count = ( new ZipFile( $path ) )->extractTo( $dir, 'wordpress/' );
            $this->assertSame( 2, $count );
            $this->assertSame( '<?php', file_get_contents( $dir . '/index.php' ) );
            $this->assertSame( 'a', file_get_contents( $dir . '/wp-admin/a.php' ) );
            $this->assertFileDoesNotExist( $dir . '/other/x' );
        } finally {
            unlink( $path );
            stm_prov_rrmdir_if_exists( $dir );
        }
    }

    // --- Pins and downloads ------------------------------------------------------------------

    public function test_the_shipped_pins_are_complete() {
        $pins = Pins::load( dirname( __DIR__ ) . '/tools/wp-plugin-check/pins.json' );
        $this->assertMatchesRegularExpression( '/^\d+\.\d+$/', $pins->phpMinor() );
        foreach ( [ 'wordpress', 'wp_cli', 'plugin_check' ] as $tool ) {
            $this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $pins->section( $tool )['sha256'], $tool );
            $this->assertStringStartsWith( 'https://', $pins->section( $tool )['url'], $tool );
        }
    }

    public function test_pins_without_a_hash_are_rejected() {
        $this->expectException( \RuntimeException::class );
        new Pins( [
            'php' => [ 'minor' => '8.3' ], 'runtime_canary' => [],
            'wordpress' => [ 'version' => '1', 'url' => 'https://x/y.zip' ],
            'wp_cli' => [ 'version' => '1', 'url' => 'https://x/y', 'sha256' => str_repeat( 'a', 64 ) ],
            'plugin_check' => [ 'version' => '1', 'url' => 'https://x/z', 'sha256' => str_repeat( 'a', 64 ) ],
        ] );
    }

    public function test_a_download_with_the_wrong_hash_is_never_used() {
        $dir    = sys_get_temp_dir() . '/stm-fetch-' . bin2hex( random_bytes( 4 ) );
        $source = $dir . '-src.bin';
        file_put_contents( $source, 'the real bytes' );
        $fetcher = new Fetcher( $dir );

        $good = $fetcher->fetch( [ 'url' => 'file://' . str_replace( '\\', '/', $source ), 'sha256' => hash( 'sha256', 'the real bytes' ) ] );
        $this->assertSame( 'the real bytes', file_get_contents( $good ) );

        try {
            $fetcher->fetch( [ 'url' => 'file://' . str_replace( '\\', '/', $source ), 'sha256' => str_repeat( '0', 64 ) ] );
            $this->fail( 'A hash mismatch must abort' );
        } catch ( \RuntimeException $e ) {
            $this->assertStringContainsString( 'SHA-256 mismatch', $e->getMessage() );
        }
        $this->assertFileDoesNotExist( $dir . '/' . basename( $source ), 'the bad file is removed, not kept in the cache' );
        unlink( $source );
        stm_prov_rrmdir_if_exists( $dir );
    }

    // --- ZIP contents ------------------------------------------------------------------------

    public function test_main_header_name_version_and_slug_come_from_the_zip() {
        $content = new ZipContent( new ZipFile( $this->samplePlugin() ) );
        $this->assertSame( 'sample', $content->slug() );
        $this->assertSame( [ 'file' => 'sample.php', 'name' => 'Sample Thing', 'version' => '2.4.1' ], $content->mainHeader() );
    }

    public function test_hygiene_flags_development_files_and_secret_like_strings() {
        $path = $this->samplePlugin( [
            'sample/tests/FooTest.php'  => '<?php',
            'sample/.env'               => 'X=1',
            'sample/composer.lock'      => '{}',
            'sample/includes/api.php'   => '<?php $k = "sk-' . str_repeat( 'a1B2', 8 ) . '";',
            'sample/includes/ok.php'    => '<?php echo "fine";',
        ] );
        $problems = ( new ZipContent( new ZipFile( $path ) ) )->hygiene();
        $byFile   = [];
        foreach ( $problems as $p ) {
            $byFile[ $p['file'] ][] = $p['kind'];
        }
        $this->assertArrayHasKey( 'tests/FooTest.php', $byFile );
        $this->assertArrayHasKey( '.env', $byFile );
        $this->assertArrayHasKey( 'composer.lock', $byFile );
        $this->assertSame( [ 'secret-like-string' ], $byFile['includes/api.php'] );
        $this->assertArrayNotHasKey( 'includes/ok.php', $byFile );
        $this->assertArrayNotHasKey( 'sample.php', $byFile );
    }

    public function test_external_hosts_list_the_services_the_code_talks_to() {
        $path = $this->samplePlugin( [
            'sample/includes/net.php' => '<?php wp_remote_post( "https://api.example-ai.test/v1/chat" ); // http://localhost:8080/x',
            'sample/assets/app.js'    => 'fetch("https://api.example-ai.test/v1/other");',
        ] );
        $hosts = ( new ZipContent( new ZipFile( $path ) ) )->externalHosts();
        $this->assertSame( [ 'assets/app.js', 'includes/net.php' ], $hosts['api.example-ai.test'] );
        $this->assertArrayHasKey( 'localhost', $hosts );
    }

    // --- Suppression inventory -----------------------------------------------------------------

    public function test_a_justified_single_line_ignore_is_listed_and_not_a_problem() {
        $inv = Suppressions::inventory( [ 'a.php' => "<?php\n\$x = 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter, no state change\n" ] );
        $this->assertSame( 1, $inv['total'] );
        $this->assertSame( [], $inv['problems'] );
        $this->assertSame( [ 'WordPress.Security.NonceVerification.Recommended' => 1 ], $inv['by_rule'] );
        $this->assertSame( 'read-only filter, no state change', $inv['items'][0]['reason'] );
        $this->assertSame( 2, $inv['items'][0]['line'] );
    }

    public function test_file_wide_blanket_and_unjustified_suppressions_are_problems() {
        $inv = Suppressions::inventory( [
            'a.php' => "<?php\n// phpcs:ignoreFile\n",
            'b.php' => "<?php\n// phpcs:ignore -- because\n\$x = 1;\n",
            'c.php' => "<?php\n// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery\n",
            'd.php' => "<?php\n/* @codingStandardsIgnoreFile */\n",
        ] );
        $kinds = [];
        foreach ( $inv['problems'] as $p ) {
            $kinds[ $p['file'] ][] = $p['problem'];
        }
        $this->assertSame( [ 'file-wide' ], $kinds['a.php'] );
        $this->assertSame( [ 'blanket' ], $kinds['b.php'] );
        $this->assertSame( [ 'unjustified' ], $kinds['c.php'] );
        $this->assertSame( [ 'file-wide' ], $kinds['d.php'] );
    }

    public function test_disable_without_enable_is_unbounded_and_a_long_span_is_wide() {
        $long = "<?php\n// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- reason\n" . str_repeat( "\$a = 1;\n", Suppressions::MAX_SPAN + 5 ) . "// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared\n";
        $inv  = Suppressions::inventory( [
            'open.php'  => "<?php\n// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- reason\n\$a = 1;\n",
            'long.php'  => $long,
            'short.php' => "<?php\n// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- reason\n\$a = 1;\n// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared\n",
        ] );
        $kinds = [];
        foreach ( $inv['problems'] as $p ) {
            $kinds[ $p['file'] ][] = $p['problem'];
        }
        $this->assertSame( [ 'unbounded' ], $kinds['open.php'] );
        $this->assertSame( [ 'wide' ], $kinds['long.php'] );
        $this->assertArrayNotHasKey( 'short.php', $kinds );
    }

    public function test_the_real_plugin_tree_is_inventoried_without_error() {
        $files = [];
        foreach ( glob( dirname( __DIR__ ) . '/includes/*.php' ) as $file ) {
            $files[ 'includes/' . basename( $file ) ] = file_get_contents( $file );
        }
        $inv = Suppressions::inventory( $files );
        $this->assertIsInt( $inv['total'] );
        $this->assertGreaterThanOrEqual( $inv['total'], array_sum( $inv['by_rule'] ), 'an annotation naming two rules counts once per rule' );
    }

    // --- Fingerprints ------------------------------------------------------------------------

    private function finding( string $check, string $file, int $line, string $message = 'Direct database call', string $type = 'WARNING' ): array {
        return [ 'check' => $check, 'type' => $type, 'severity' => 5, 'file' => $file, 'line' => $line, 'column' => 3, 'message' => $message, 'docs' => '' ];
    }

    private const CLASS_SRC = "<?php\nclass Repo {\n    public function find( \$id ) {\n        global \$wpdb;\n        return \$wpdb->get_row( \"SELECT * FROM t WHERE id = \$id\" );\n    }\n\n    public function all() {\n        global \$wpdb;\n        return \$wpdb->get_results( \"SELECT * FROM t\" );\n    }\n}\n";

    public function test_fingerprint_survives_code_that_only_moved() {
        $before = Fingerprint::apply( 'p', [ $this->finding( 'WP.DB.Direct', 'r.php', 5 ) ], [ 'r.php' => self::CLASS_SRC ] );
        $moved  = "<?php\n// a new comment\n// and another\n" . substr( self::CLASS_SRC, strlen( "<?php\n" ) );
        $after  = Fingerprint::apply( 'p', [ $this->finding( 'WP.DB.Direct', 'r.php', 7 ) ], [ 'r.php' => $moved ] );

        $this->assertSame( $before[0]['fingerprint'], $after[0]['fingerprint'] );
        $this->assertSame( 'Repo::find', $before[0]['anchor'] );
        $this->assertSame( 'Repo::find', $after[0]['anchor'] );
    }

    public function test_fingerprint_changes_when_the_flagged_line_or_the_symbol_changes() {
        $base    = Fingerprint::apply( 'p', [ $this->finding( 'WP.DB.Direct', 'r.php', 5 ) ], [ 'r.php' => self::CLASS_SRC ] )[0]['fingerprint'];
        $edited  = Fingerprint::apply( 'p', [ $this->finding( 'WP.DB.Direct', 'r.php', 5 ) ], [ 'r.php' => str_replace( 'WHERE id', 'WHERE uid', self::CLASS_SRC ) ] )[0]['fingerprint'];
        $other   = Fingerprint::apply( 'p', [ $this->finding( 'WP.DB.Direct', 'r.php', 10 ) ], [ 'r.php' => self::CLASS_SRC ] );
        $message = Fingerprint::apply( 'p', [ $this->finding( 'WP.DB.Direct', 'r.php', 5, 'A different problem' ) ], [ 'r.php' => self::CLASS_SRC ] )[0]['fingerprint'];

        $this->assertNotSame( $base, $edited );
        $this->assertNotSame( $base, $other[0]['fingerprint'] );
        $this->assertSame( 'Repo::all', $other[0]['anchor'] );
        $this->assertNotSame( $base, $message );
    }

    public function test_identical_lines_in_one_symbol_get_distinct_but_stable_fingerprints() {
        $src = "<?php\nfunction twice() {\n    \$a = \$_GET['x'];\n    \$a = \$_GET['x'];\n}\n";
        $one = Fingerprint::apply( 'p', [ $this->finding( 'Nonce', 'f.php', 3 ), $this->finding( 'Nonce', 'f.php', 4 ) ], [ 'f.php' => $src ] );
        $two = Fingerprint::apply( 'p', [ $this->finding( 'Nonce', 'f.php', 3 ), $this->finding( 'Nonce', 'f.php', 4 ) ], [ 'f.php' => $src ] );
        $this->assertNotSame( $one[0]['fingerprint'], $one[1]['fingerprint'] );
        $this->assertSame( [ $one[0]['fingerprint'], $one[1]['fingerprint'] ], [ $two[0]['fingerprint'], $two[1]['fingerprint'] ] );
    }

    public function test_fingerprint_ignores_html_entities_and_line_numbers_in_the_message() {
        $a = Fingerprint::apply( 'p', [ $this->finding( 'X', 'readme.txt', 0, '$_GET[&#039;a&#039;] assigned unsafely at line 243.' ) ], [ 'readme.txt' => "x\n" ] );
        $b = Fingerprint::apply( 'p', [ $this->finding( 'X', 'readme.txt', 0, "\$_GET['a'] assigned unsafely at line 251." ) ], [ 'readme.txt' => "x\n" ] );
        $this->assertSame( $a[0]['fingerprint'], $b[0]['fingerprint'] );
    }

    public function test_abstract_and_interface_methods_do_not_swallow_the_following_symbols() {
        $src = "<?php\ninterface Thing {\n    public function a();\n    public function b();\n}\nclass Impl implements Thing {\n    public function a() {\n        return 1;\n    }\n    public function b() {\n        return 2;\n    }\n}\n";
        $out = Fingerprint::apply( 'p', [ $this->finding( 'X', 'i.php', 11 ) ], [ 'i.php' => $src ] );
        $this->assertSame( 'Impl::b', $out[0]['anchor'] );
    }

    public function test_groups_are_one_per_check_and_file_and_bounded() {
        $findings = [];
        for ( $i = 1; $i <= 3; $i++ ) {
            $findings[] = $this->finding( 'Rule.A', 'a.php', $i );
        }
        $findings[] = $this->finding( 'Rule.A', 'b.php', 1 );
        $findings[] = $this->finding( 'Rule.B', 'a.php', 1, 'x', 'ERROR' );
        $findings   = Fingerprint::apply( 'p', $findings, [ 'a.php' => "<?php\n\$a;\n\$b;\n\$c;\n", 'b.php' => "<?php\n\$a;\n" ] );

        $groups = Fingerprint::groups( $findings );
        $this->assertCount( 3, $groups );
        $this->assertSame( 'ERROR', $groups[0]['type'], 'errors first' );
        $this->assertSame( 3, array_values( array_filter( $groups, fn( $g ) => $g['file'] === 'a.php' && $g['check'] === 'Rule.A' ) )[0]['total'] );
    }

    public function test_an_oversized_group_is_split_by_symbol() {
        $src = "<?php\nfunction one() {\n";
        $findings = [];
        for ( $i = 0; $i < Fingerprint::MAX_FINDINGS_PER_TASK + 2; $i++ ) {
            $src .= "    \$v{$i} = 1;\n";
            $findings[] = $this->finding( 'Rule.A', 'big.php', 3 + $i, 'm' . $i );
        }
        $src .= "}\nfunction two() {\n    \$z = 1;\n}\n";
        $findings[] = $this->finding( 'Rule.A', 'big.php', 3 + Fingerprint::MAX_FINDINGS_PER_TASK + 2 + 3, 'z' );
        $groups = Fingerprint::groups( Fingerprint::apply( 'p', $findings, [ 'big.php' => $src ] ) );
        $this->assertCount( 2, $groups );
        $this->assertSame( [ 'one', 'two' ], array_map( fn( $g ) => $g['anchor'], $groups ) );
    }

    // --- Reading Plugin Check output -----------------------------------------------------------

    public function test_findings_are_read_from_strict_json_with_plugin_relative_paths() {
        $json = json_encode( [
            [ 'file' => 'C:\\Temp\\run\\site\\wp-content\\plugins\\sample\\includes\\a.php', 'line' => 4, 'column' => 2, 'type' => 'WARNING', 'code' => 'R.One', 'message' => 'It&#039;s odd', 'docs' => '', 'severity' => 5 ],
            [ 'file' => 'readme.txt', 'line' => 0, 'column' => 0, 'type' => 'ERROR', 'code' => 'outdated', 'message' => 'x', 'docs' => '', 'severity' => 7 ],
            [ 'file' => 'includes/b.php', 'line' => 1, 'column' => 1, 'type' => 'ERROR', 'code' => 'R.Two', 'message' => 'y', 'docs' => '', 'severity' => 7 ],
        ] );
        $found = Scanner::parseFindings( $json, 'C:/Temp/run/site/wp-content/plugins', 'sample' );
        $this->assertSame( [ 'includes/a.php', 'includes/b.php', 'readme.txt' ], array_column( $found, 'file' ) );
        $this->assertSame( "It's odd", $found[0]['message'] );
        $this->assertSame( 'WARNING', $found[0]['type'] );
    }

    public function test_the_no_errors_message_is_a_complete_scan_with_no_findings() {
        $this->assertSame( [], Scanner::parseFindings( "Success: Checks complete. No errors found.\n", '/x', 'sample' ) );
    }

    public function test_output_that_is_neither_json_nor_the_success_message_is_a_failed_scan() {
        $this->expectException( \RuntimeException::class );
        Scanner::parseFindings( "Error: Invalid plugin.\n", '/x', 'sample' );
    }

    public function test_a_result_row_without_a_code_is_rejected() {
        $this->expectException( \RuntimeException::class );
        Scanner::parseFindings( '[{"file":"a.php","line":1}]', '/x', 'sample' );
    }

    public function test_summary_and_exit_codes() {
        $f1 = $this->finding( 'A', 'a.php', 1, 'm', 'ERROR' );
        $f2 = $this->finding( 'B', 'a.php', 2 );
        $none = [ 'problems' => [] ];

        $dirty = Scanner::summarise( [ $f1, $f2 ], $none, [] );
        $this->assertSame( [ 1, 1, false ], [ $dirty['errors'], $dirty['warnings'], $dirty['clean'] ] );

        $clean = Scanner::summarise( [], $none, [] );
        $this->assertTrue( $clean['clean'] );

        $suppressed = Scanner::summarise( [], [ 'problems' => [ [ 'problem' => 'blanket' ] ] ], [] );
        $this->assertFalse( $suppressed['clean'], 'a blanket suppression means a clean scan proves nothing' );

        $this->assertSame( Scanner::EXIT_CLEAN, Scanner::exitCode( [ 'scan' => [ 'status' => 'complete' ], 'result' => $clean ] ) );
        $this->assertSame( Scanner::EXIT_FINDING, Scanner::exitCode( [ 'scan' => [ 'status' => 'complete' ], 'result' => $dirty ] ) );
        $this->assertSame( Scanner::EXIT_PARTIAL, Scanner::exitCode( [ 'scan' => [ 'status' => 'partial' ], 'result' => $clean ] ) );
        $this->assertSame( Scanner::EXIT_FAILED, Scanner::exitCode( [ 'scan' => [ 'status' => 'failed' ], 'result' => null ] ) );
    }

    // --- Comparing two scans -------------------------------------------------------------------

    private function report( string $status, array $fingerprints, string $slug = 'sample' ): array {
        $findings = [];
        foreach ( $fingerprints as $fp ) {
            $findings[] = [ 'fingerprint' => $fp, 'type' => 'WARNING', 'check' => 'R', 'file' => 'a.php', 'line' => 1 ];
        }
        return [
            'scan'     => [ 'id' => 's-' . $status . count( $fingerprints ), 'status' => $status ],
            'subject'  => [ 'slug' => $slug, 'zip_sha256' => str_repeat( 'a', 64 ) ],
            'result'   => [ 'errors' => 0, 'warnings' => count( $findings ) ],
            'findings' => $findings,
        ];
    }

    public function test_delta_lists_new_fixed_and_unchanged_findings() {
        $delta = Delta::compare( $this->report( 'complete', [ 'a', 'b', 'c' ] ), $this->report( 'complete', [ 'b', 'c', 'd' ] ) );
        $this->assertTrue( $delta['comparable'] );
        $this->assertSame( [ 'd' ], array_column( $delta['new_findings'], 'fingerprint' ) );
        $this->assertSame( [ 'a' ], array_column( $delta['fixed_findings'], 'fingerprint' ) );
        $this->assertSame( 2, $delta['unchanged_findings'] );
        $this->assertStringContainsString( 'New: 1, fixed: 1, unchanged: 2', Delta::markdown( $delta ) );
    }

    public function test_a_failed_or_partial_scan_is_not_comparable_so_nothing_looks_fixed() {
        $failed = Delta::compare( $this->report( 'complete', [ 'a', 'b' ] ), $this->report( 'failed', [] ) );
        $this->assertFalse( $failed['comparable'] );
        $this->assertSame( [], $failed['fixed_findings'] );

        $partial = Delta::compare( $this->report( 'complete', [ 'a' ] ), $this->report( 'partial', [] ) );
        $this->assertFalse( $partial['comparable'] );

        $other = Delta::compare( $this->report( 'complete', [ 'a' ] ), $this->report( 'complete', [], 'another-plugin' ) );
        $this->assertFalse( $other['comparable'] );
    }
}

/** Remove a directory tree if it exists (test helper; the plugin tooling has its own copy). */
function stm_prov_rrmdir_if_exists( string $dir ): void {
    if ( !is_dir( $dir ) ) {
        return;
    }
    $it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
    foreach ( $it as $file ) {
        $file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
    }
    rmdir( $dir );
}
