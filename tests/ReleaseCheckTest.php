<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.test.releasechecktest

/**
 * PHPUnit tests: bin/release-check.php, the one command a pull request check, a release check and the
 * Jengo action run (task 5144). The build, the scan and the lifecycle test are replaced by a recording
 * fake, so these tests pin the orchestration: which ZIP is built, that the same ZIP is scanned and
 * tested, which older releases the update test starts from, and when the verdict may say "ok".
 */

namespace STM\Tests;

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/bin/release-check.php';

class ReleaseCheckTest extends TestCase {

    /** @var string[] */
    private $dirs = [];

    /** @var list<list<string>> every command the fake was asked to run */
    private $calls = [];

    protected function tearDown(): void {
        foreach ( $this->dirs as $dir ) {
            $this->removeTree( $dir );
        }
        $this->dirs  = [];
        $this->calls = [];
    }

    private function removeTree( string $dir ): void {
        if ( ! is_dir( $dir ) ) {
            return;
        }
        foreach ( scandir( $dir ) ?: [] as $entry ) {
            if ( '.' === $entry || '..' === $entry ) {
                continue;
            }
            is_dir( "$dir/$entry" ) ? $this->removeTree( "$dir/$entry" ) : unlink( "$dir/$entry" );
        }
        rmdir( $dir );
    }

    private function tempDir(): string {
        $dir = sys_get_temp_dir() . '/stm-rc-test-' . bin2hex( random_bytes( 4 ) );
        mkdir( $dir, 0777, true );
        $this->dirs[] = $dir;
        return $dir;
    }

    /**
     * @param array<string,mixed> $scan      what `scan.php check` prints (merged over a clean result)
     * @param array<string,mixed> $lifecycle what `scan.php lifecycle` prints (merged over a passing result)
     */
    private function fake( array $scan = [], int $scanExit = 0, array $lifecycle = [], int $lifecycleExit = 0, bool $buildFails = false ): callable {
        $zips = [];
        return function ( array $cmd, string $cwd ) use ( $scan, $scanExit, $lifecycle, $lifecycleExit, $buildFails, &$zips ): array {
            $this->calls[] = $cmd;
            $joined        = implode( ' ', $cmd );

            if ( 'git' === $cmd[0] && 'tag' === $cmd[1] ) {
                return [ 'code' => 0, 'stdout' => "v1.3.2\nv1.3.1\nv1.3.0\nv1.2.1\nv1.2.0\nv1.3.10-beta\nrelease-notes\n", 'stderr' => '' ];
            }
            if ( 'git' === $cmd[0] && 'cat-file' === $cmd[1] ) {
                // the 1.2.x tags have no readme.txt, so no ZIP can be built from them
                return [ 'code' => 0 === strpos( $cmd[3], 'v1.2.' ) ? 128 : 0, 'stdout' => '', 'stderr' => '' ];
            }
            if ( false !== strpos( $joined, 'provenance.php release' ) ) {
                if ( $buildFails ) {
                    return [ 'code' => 1, 'stdout' => '', 'stderr' => 'not a git repository' ];
                }
                $out = '';
                $ref = '';
                foreach ( $cmd as $arg ) {
                    if ( 0 === strpos( $arg, '--out-dir=' ) ) {
                        $out = substr( $arg, 10 );
                    }
                    if ( 0 === strpos( $arg, '--commit=' ) ) {
                        $ref = substr( $arg, 9 );
                    }
                }
                $version = 'HEAD' === $ref ? '1.3.2' : ltrim( $ref, 'v' );
                $file    = "simple-translation-manager-{$version}.zip";
                $sha     = hash( 'sha256', $ref );
                file_put_contents( "$out/$file", 'zip ' . $ref );
                file_put_contents( "$out/release-record-{$version}.json", json_encode( [
                    'plugin' => [ 'slug' => 'simple-translation-manager', 'version' => $version ],
                    'commit' => str_repeat( 'a', 40 ),
                    'zip'    => [ 'file' => $file, 'sha256' => $sha ],
                ] ) );
                $zips[ "$out/$file" ] = $sha;
                return [ 'code' => 0, 'stdout' => "ZIP: $file\n", 'stderr' => '' ];
            }
            if ( false !== strpos( $joined, 'scan.php check' ) || ( isset( $cmd[2] ) && 'check' === $cmd[2] ) ) {
                $sha    = $zips[ $cmd[3] ] ?? 'unknown';
                $result = array_replace_recursive( [
                    'scan'   => [ 'status' => 'complete', 'reason' => 'All checks ran.', 'scan_id' => 's1', 'zip_sha256' => $sha, 'errors' => 0, 'warnings' => 0, 'clean' => true, 'report' => '/r/report.json' ],
                    'delta'  => [ 'comparable' => true, 'new' => 0, 'fixed' => 0, 'unchanged' => 0 ],
                    'import' => null,
                ], $scan );
                return [ 'code' => $scanExit, 'stdout' => json_encode( $result ), 'stderr' => '' ];
            }
            if ( isset( $cmd[2] ) && 'lifecycle' === $cmd[2] ) {
                $sha    = $zips[ $cmd[3] ] ?? 'unknown';
                $result = array_replace_recursive( [
                    'status' => 'complete', 'reason' => 'Every scenario ran to the end.', 'zip_sha256' => $sha,
                    'result' => [ 'checks' => 280, 'failed' => 0, 'passed' => 280, 'ok' => true ], 'report' => '/l/lifecycle.json',
                ], $lifecycle );
                return [ 'code' => $lifecycleExit, 'stdout' => json_encode( $result ), 'stderr' => '' ];
            }
            $this->fail( 'Unexpected command: ' . $joined );
        };
    }

    /** @return list<list<string>> */
    private function callsTo( string $needle ): array {
        return array_values( array_filter( $this->calls, static function ( array $c ) use ( $needle ): bool {
            return false !== strpos( implode( ' ', $c ), $needle );
        } ) );
    }

    private function options( array $extra = [] ): array {
        return array_merge( [ 'output-dir' => $this->tempDir(), 'reports-dir' => $this->tempDir() . '/reports' ], $extra );
    }

    public function test_a_clean_scan_and_a_passing_lifecycle_test_on_the_same_zip_are_ok() {
        $verdict = stm_release_check( $this->options(), dirname( __DIR__ ), $this->fake() );

        $this->assertTrue( $verdict['ok'] );
        $this->assertSame( 0, $verdict['exit_code'] );
        $this->assertSame( [], $verdict['problems'] );
        $this->assertSame( hash( 'sha256', 'HEAD' ), $verdict['zip']['sha256'] );
        $this->assertSame( '1.3.2', $verdict['zip']['version'] );
        $this->assertSame( 280, $verdict['lifecycle']['checks'] );

        $scan = $this->callsTo( 'check' );
        $scan = array_values( array_filter( $scan, static function ( array $c ): bool { return isset( $c[2] ) && 'check' === $c[2]; } ) );
        $this->assertCount( 1, $scan );
        $this->assertSame( $verdict['zip']['file'], $scan[0][3], 'Plugin Check runs on exactly the ZIP that was built' );
        $this->assertContains( '--commit=' . str_repeat( 'a', 40 ), $scan[0] );
        $this->assertNotContains( '--checks', $scan[0], 'no narrowing option is ever passed' );
        foreach ( $scan[0] as $arg ) {
            $this->assertStringNotContainsString( 'ignore-codes', $arg );
            $this->assertStringNotContainsString( 'exclude', $arg );
        }
    }

    public function test_the_update_test_starts_from_the_newest_older_releases_that_can_be_built() {
        $verdict = stm_release_check( $this->options(), dirname( __DIR__ ), $this->fake() );

        $this->assertSame( [ 'v1.3.1', 'v1.3.0' ], $verdict['lifecycle']['updated_from'], 'not the current version, not 1.2.x (no readme.txt), not a non-release tag' );
        $life = array_values( array_filter( $this->calls, static function ( array $c ): bool { return isset( $c[2] ) && 'lifecycle' === $c[2]; } ) );
        $this->assertCount( 1, $life );
        $from = array_values( array_filter( $life[0], static function ( string $a ): bool { return 0 === strpos( $a, '--from=' ); } ) );
        $this->assertCount( 1, $from );
        $this->assertStringContainsString( 'simple-translation-manager-1.3.1.zip,', $from[0] );
        $this->assertStringContainsString( 'simple-translation-manager-1.3.0.zip', $from[0] );
        $this->assertSame( $verdict['zip']['file'], $life[0][3], 'the lifecycle test runs on the scanned ZIP' );
    }

    public function test_an_explicit_from_list_replaces_the_automatic_one_and_none_means_a_clean_install_only() {
        $verdict = stm_release_check( $this->options( [ 'from' => 'v1.3.0' ] ), dirname( __DIR__ ), $this->fake() );
        $this->assertSame( [ 'v1.3.0' ], $verdict['lifecycle']['updated_from'] );
        $this->assertSame( [], $this->callsTo( 'git tag' ), 'no tag listing when the tags are given' );

        $this->calls = [];
        $verdict     = stm_release_check( $this->options( [ 'from' => 'none' ] ), dirname( __DIR__ ), $this->fake() );
        $this->assertSame( [], $verdict['lifecycle']['updated_from'] );
        $life = array_values( array_filter( $this->calls, static function ( array $c ): bool { return isset( $c[2] ) && 'lifecycle' === $c[2]; } ) );
        foreach ( $life[0] as $arg ) {
            $this->assertStringStartsNotWith( '--from=', $arg );
        }
    }

    public function test_findings_fail_the_check_with_exit_1() {
        $verdict = stm_release_check(
            $this->options(),
            dirname( __DIR__ ),
            $this->fake( [ 'scan' => [ 'errors' => 2, 'warnings' => 3, 'clean' => false ] ], 1 )
        );

        $this->assertFalse( $verdict['ok'] );
        $this->assertSame( 1, $verdict['exit_code'] );
        $this->assertSame( 2, $verdict['scan']['errors'] );
        $this->assertContains( 'Plugin Check is not clean and complete for this ZIP.', $verdict['problems'] );
    }

    public function test_a_failed_scan_is_exit_2_and_never_clean() {
        $verdict = stm_release_check(
            $this->options(),
            dirname( __DIR__ ),
            $this->fake( [ 'scan' => [ 'status' => 'failed', 'reason' => 'canary did not fire', 'errors' => null, 'warnings' => null, 'clean' => false ] ], 2 )
        );

        $this->assertFalse( $verdict['ok'] );
        $this->assertSame( 2, $verdict['exit_code'] );
        $this->assertSame( 'failed', $verdict['scan']['status'] );
        $this->assertSame( 'canary did not fire', $verdict['scan']['reason'] );
    }

    public function test_a_scan_that_prints_nothing_counts_as_failed() {
        $run = function ( array $cmd, string $cwd ): array {
            if ( false !== strpos( implode( ' ', $cmd ), 'provenance.php release' ) ) {
                return $this->fake()( $cmd, $cwd );
            }
            return [ 'code' => 2, 'stdout' => '', 'stderr' => 'Error: no MariaDB found' ];
        };
        $verdict = stm_release_check( $this->options( [ 'skip-lifecycle' => true ] ), dirname( __DIR__ ), $run );

        $this->assertFalse( $verdict['ok'] );
        $this->assertSame( 2, $verdict['exit_code'] );
        $this->assertSame( 'Error: no MariaDB found', $verdict['scan']['reason'] );
        $this->assertContains( 'The scan printed no result.', $verdict['problems'] );
    }

    public function test_a_report_for_another_zip_than_the_one_built_is_a_problem_even_when_it_is_clean() {
        $verdict = stm_release_check(
            $this->options(),
            dirname( __DIR__ ),
            $this->fake( [ 'scan' => [ 'zip_sha256' => str_repeat( 'b', 64 ) ] ] )
        );

        $this->assertFalse( $verdict['ok'] );
        $this->assertContains( 'The scanned ZIP is not the ZIP that was built.', $verdict['problems'] );
    }

    public function test_a_failing_lifecycle_test_fails_the_check_even_when_the_scan_is_clean() {
        $verdict = stm_release_check(
            $this->options(),
            dirname( __DIR__ ),
            $this->fake( [], 0, [ 'result' => [ 'checks' => 280, 'failed' => 3, 'passed' => 277, 'ok' => false ] ], 1 )
        );

        $this->assertFalse( $verdict['ok'] );
        $this->assertSame( 1, $verdict['exit_code'] );
        $this->assertSame( 3, $verdict['lifecycle']['failed'] );
        $this->assertContains( 'The install, update and runtime test did not pass.', $verdict['problems'] );
    }

    public function test_a_lifecycle_run_on_another_zip_does_not_count() {
        $verdict = stm_release_check(
            $this->options(),
            dirname( __DIR__ ),
            $this->fake( [], 0, [ 'zip_sha256' => str_repeat( 'c', 64 ) ] )
        );

        $this->assertFalse( $verdict['ok'] );
        $this->assertContains( 'The lifecycle test did not run on the ZIP that was built.', $verdict['problems'] );
    }

    public function test_skipping_the_lifecycle_test_is_never_a_release_ok() {
        $verdict = stm_release_check( $this->options( [ 'skip-lifecycle' => true ] ), dirname( __DIR__ ), $this->fake() );

        $this->assertFalse( $verdict['ok'] );
        $this->assertSame( 1, $verdict['exit_code'] );
        $this->assertSame( 'skipped', $verdict['lifecycle']['status'] );
        $this->assertContains( 'The install, update and runtime test was skipped.', $verdict['problems'] );
        $this->assertSame( [], array_values( array_filter( $this->calls, static function ( array $c ): bool { return isset( $c[2] ) && 'lifecycle' === $c[2]; } ) ) );
    }

    public function test_a_build_failure_stops_before_anything_is_scanned() {
        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'Building the release ZIP of HEAD failed: not a git repository' );
        try {
            stm_release_check( $this->options(), dirname( __DIR__ ), $this->fake( [], 0, [], 0, true ) );
        } finally {
            $this->assertSame( [], $this->callsTo( 'scan.php' ), 'nothing is scanned without a built ZIP' );
        }
    }

    public function test_board_options_are_handed_to_the_scan_and_nothing_is_written_without_apply() {
        $keyFile = $this->tempDir() . '/key.txt';
        stm_release_check(
            $this->options( [ 'board' => '47', 'key-file' => $keyFile, 'max-new' => '5' ] ),
            dirname( __DIR__ ),
            $this->fake()
        );
        $scan = array_values( array_filter( $this->calls, static function ( array $c ): bool { return isset( $c[2] ) && 'check' === $c[2]; } ) );
        $this->assertContains( '--board=47', $scan[0] );
        $this->assertContains( '--key-file=' . $keyFile, $scan[0] );
        $this->assertContains( '--max-new=5', $scan[0] );
        $this->assertNotContains( '--apply', $scan[0], 'a dry run unless the caller asks for --apply' );

        $this->calls = [];
        stm_release_check( $this->options( [ 'board' => '47', 'apply' => true ] ), dirname( __DIR__ ), $this->fake() );
        $scan = array_values( array_filter( $this->calls, static function ( array $c ): bool { return isset( $c[2] ) && 'check' === $c[2]; } ) );
        $this->assertContains( '--apply', $scan[0] );
    }

    public function test_the_script_is_not_part_of_the_release_zip() {
        $paths = file_get_contents( dirname( __DIR__ ) . '/bin/provenance.php' );
        $this->assertMatchesRegularExpression( '/STM_PROV_DIST_PATHS/', (string) $paths );
        $this->assertDoesNotMatchRegularExpression( "/'bin\\/release-check\\.php'/", (string) $paths, 'dev tooling stays out of the ZIP' );
    }
}
