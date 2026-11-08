<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.bin.releasecheck

/**
 * One command for a pull request check or a release check of Simple Translation Manager (task 5144).
 *
 * Development-only: excluded from the plugin ZIP. It runs the same steps a person runs by hand, in
 * this order, and prints one JSON verdict:
 *
 *   1. build the release ZIP of a commit            (bin/provenance.php release)
 *   2. official Plugin Check on exactly that ZIP    (tools/wp-plugin-check/scan.php check)
 *   3. install, update and runtime test of that ZIP (tools/wp-plugin-check/scan.php lifecycle), the
 *      update path starts from the older release tags that can still be built
 *
 * A Jengo action, a pull request check and a release check all call this script, so they run the
 * same execution as a person at the keyboard.
 *
 * Usage:
 *   php bin/release-check.php [--commit=REF] [--reports-dir=DIR] [--output-dir=DIR] [--work-dir=DIR] [--from=v1.3.0,v1.3.1|auto|none]
 *                             [--skip-lifecycle] [--php=BIN] [--mariadb-bin=DIR]
 *                             [--board=ID --key-file=FILE [--api=URL] [--apply] [--max-new=N]]
 *
 * Exit code: 0 only when the scan is complete and clean AND the lifecycle test passed (or was skipped
 * on request). 1 findings or a failed lifecycle test, 2 the scan itself failed, 3 partial, 64 usage.
 * A skipped lifecycle test is stated in the verdict and is never called "ok" for a release.
 */

require_once dirname( __DIR__ ) . '/tools/wp-plugin-check/src/autoload.php';

/**
 * Release tags older than the current version whose tree can still be built into a ZIP (a tag without
 * readme.txt cannot). Newest first, at most $limit.
 *
 * @param callable $run fn(string[] $argv, string $cwd): array{code:int,stdout:string,stderr:string}
 * @return string[]
 */
function stm_rc_older_release_tags( string $root, string $currentVersion, callable $run, int $limit = 2 ): array {
    $list = $run( [ 'git', 'tag', '--list', 'v*', '--sort=-version:refname' ], $root );
    $tags = [];
    foreach ( preg_split( '/\R/', trim( $list['stdout'] ) ) ?: [] as $tag ) {
        $tag = trim( $tag );
        if ( ! preg_match( '/^v(\d+\.\d+\.\d+)$/', $tag, $m ) || version_compare( $m[1], $currentVersion, '>=' ) ) {
            continue;
        }
        if ( 0 !== $run( [ 'git', 'cat-file', '-e', $tag . ':readme.txt' ], $root )['code'] ) {
            continue;
        }
        $tags[] = $tag;
        if ( count( $tags ) >= $limit ) {
            break;
        }
    }
    return $tags;
}

/**
 * Build the release ZIP of $ref into $outDir. Returns the release record (zip.file, zip.sha256, commit).
 *
 * @param callable $run fn(string[] $argv, string $cwd): array{code:int,stdout:string,stderr:string}
 * @return array{zip:string,sha256:string,commit:string,version:string}
 */
function stm_rc_build( string $root, string $ref, string $outDir, string $published, callable $run ): array {
    if ( ! is_dir( $outDir ) && ! mkdir( $outDir, 0777, true ) ) {
        throw new RuntimeException( "Cannot create {$outDir}" );
    }
    $before = glob( $outDir . '/release-record-*.json' ) ?: [];
    $result = $run( [ PHP_BINARY, $root . '/bin/provenance.php', 'release', $root, '--out-dir=' . $outDir, '--commit=' . $ref, '--published=' . $published ], $root );
    if ( 0 !== $result['code'] ) {
        throw new RuntimeException( "Building the release ZIP of {$ref} failed: " . trim( $result['stderr'] . $result['stdout'] ) );
    }
    $after = array_values( array_diff( glob( $outDir . '/release-record-*.json' ) ?: [], $before ) );
    if ( 1 !== count( $after ) ) {
        throw new RuntimeException( "Expected one new release record in {$outDir} after building {$ref}." );
    }
    $record = json_decode( (string) file_get_contents( $after[0] ), true );
    if ( ! is_array( $record ) || empty( $record['zip']['file'] ) || empty( $record['zip']['sha256'] ) ) {
        throw new RuntimeException( "Unreadable release record {$after[0]}" );
    }
    return [
        'zip'     => $outDir . '/' . $record['zip']['file'],
        'sha256'  => (string) $record['zip']['sha256'],
        'commit'  => (string) ( $record['commit'] ?? '' ),
        'version' => (string) ( $record['plugin']['version'] ?? '' ),
    ];
}

/**
 * @param array<string,string|bool> $options
 * @param callable $run fn(string[] $argv, string $cwd): array{code:int,stdout:string,stderr:string}
 * @return array<string,mixed> the verdict; 'exit_code' is what the script exits with
 */
function stm_release_check( array $options, string $root, callable $run ): array {
    $str = static function ( string $key, string $default = '' ) use ( $options ): string {
        return is_string( $options[ $key ] ?? null ) ? $options[ $key ] : $default;
    };

    $ref     = $str( 'commit', 'HEAD' );
    $work    = rtrim( $str( 'output-dir', sys_get_temp_dir() . '/stm-release-check/' . gmdate( 'Ymd\THis\Z' ) ), '/\\' );
    $reports = $str( 'reports-dir', sys_get_temp_dir() . '/stm-plugin-check-reports' );
    $tool    = $root . '/tools/wp-plugin-check/scan.php';
    $shared  = [];
    foreach ( [ 'php', 'mariadb-bin', 'work-dir' ] as $pass ) {
        if ( '' !== $str( $pass ) ) {
            $shared[] = '--' . $pass . '=' . $str( $pass );
        }
    }

    $verdict = [
        'ok'        => false,
        'commit'    => null,
        'zip'       => null,
        'scan'      => null,
        'lifecycle' => null,
        'problems'  => [],
    ];

    $built = stm_rc_build( $root, $ref, $work . '/release', gmdate( 'Y-m-d' ), $run );
    $verdict['commit'] = $built['commit'];
    $verdict['zip']    = [ 'file' => $built['zip'], 'sha256' => $built['sha256'], 'version' => $built['version'] ];

    // 2. The official Plugin Check, on exactly that ZIP.
    $scanArgs = array_merge( [ PHP_BINARY, $tool, 'check', $built['zip'], '--reports-dir=' . $reports, '--commit=' . $built['commit'] ], $shared );
    foreach ( [ 'board', 'key-file', 'api', 'max-new', 'status' ] as $pass ) {
        if ( '' !== $str( $pass ) ) {
            $scanArgs[] = '--' . $pass . '=' . $str( $pass );
        }
    }
    if ( ! empty( $options['apply'] ) ) {
        $scanArgs[] = '--apply';
    }
    $scan     = $run( $scanArgs, $root );
    $scanJson = json_decode( $scan['stdout'], true );
    $scanExit = $scan['code'];
    $verdict['scan'] = [
        'exit_code' => $scanExit,
        'status'    => $scanJson['scan']['status'] ?? 'failed',
        'reason'    => $scanJson['scan']['reason'] ?? trim( $scan['stderr'] ),
        'errors'    => $scanJson['scan']['errors'] ?? null,
        'warnings'  => $scanJson['scan']['warnings'] ?? null,
        'clean'     => $scanJson['scan']['clean'] ?? false,
        'report'    => $scanJson['scan']['report'] ?? null,
        'delta'     => $scanJson['delta'] ?? null,
        'import'    => $scanJson['import'] ?? null,
    ];
    if ( ! is_array( $scanJson ) ) {
        $verdict['problems'][] = 'The scan printed no result.';
    } elseif ( ( $scanJson['scan']['zip_sha256'] ?? '' ) !== $built['sha256'] ) {
        $verdict['problems'][] = 'The scanned ZIP is not the ZIP that was built.';
    }
    $scanOk = 0 === $scanExit && true === $verdict['scan']['clean'] && 'complete' === $verdict['scan']['status'];
    if ( ! $scanOk ) {
        $verdict['problems'][] = 'Plugin Check is not clean and complete for this ZIP.';
    }

    // 3. Install, update and runtime test of the same ZIP.
    $lifecycleOk   = null;
    $lifecycleExit = 0;
    $from          = $str( 'from', 'auto' );
    if ( ! empty( $options['skip-lifecycle'] ) ) {
        $verdict['lifecycle'] = [ 'status' => 'skipped', 'reason' => 'Skipped on request (--skip-lifecycle); not release evidence.' ];
        $verdict['problems'][] = 'The install, update and runtime test was skipped.';
    } else {
        $tags = 'none' === $from ? [] : ( 'auto' === $from ? stm_rc_older_release_tags( $root, $built['version'], $run ) : array_values( array_filter( explode( ',', $from ) ) ) );
        $old  = [];
        foreach ( $tags as $tag ) {
            $old[] = stm_rc_build( $root, $tag, $work . '/older/' . $tag, gmdate( 'Y-m-d' ), $run )['zip'];
        }
        $lifeArgs = array_merge(
            [ PHP_BINARY, $tool, 'lifecycle', $built['zip'], '--script=' . $root . '/tests/lifecycle/stm-lifecycle.php', '--out-dir=' . $work . '/lifecycle', '--commit=' . $built['commit'] ],
            $old ? [ '--from=' . implode( ',', $old ) ] : [],
            $shared
        );
        $life     = $run( $lifeArgs, $root );
        $lifeJson = json_decode( $life['stdout'], true );
        $lifecycleExit = $life['code'];
        $lifecycleOk   = 0 === $lifecycleExit && is_array( $lifeJson ) && true === ( $lifeJson['result']['ok'] ?? false ) && 'complete' === ( $lifeJson['status'] ?? '' );
        $verdict['lifecycle'] = [
            'exit_code' => $lifecycleExit,
            'status'    => $lifeJson['status'] ?? 'failed',
            'reason'    => $lifeJson['reason'] ?? trim( $life['stderr'] ),
            'checks'    => $lifeJson['result']['checks'] ?? null,
            'failed'    => $lifeJson['result']['failed'] ?? null,
            'updated_from' => $tags,
            'report'    => $lifeJson['report'] ?? null,
        ];
        if ( is_array( $lifeJson ) && ( $lifeJson['zip_sha256'] ?? '' ) !== $built['sha256'] ) {
            $verdict['problems'][] = 'The lifecycle test did not run on the ZIP that was built.';
            $lifecycleOk = false;
        }
        if ( true !== $lifecycleOk ) {
            $verdict['problems'][] = 'The install, update and runtime test did not pass.';
        }
    }

    $verdict['ok'] = $scanOk && true === $lifecycleOk && array() === $verdict['problems'];
    if ( $verdict['ok'] ) {
        $verdict['exit_code'] = 0;
    } elseif ( $scanExit > 1 ) {
        $verdict['exit_code'] = $scanExit; // 2 failed, 3 partial
    } else {
        $verdict['exit_code'] = 1;
    }
    return $verdict;
}

/** @param string[] $argv */
function stm_rc_main( array $argv ): int {
    $options = [];
    foreach ( array_slice( $argv, 1 ) as $arg ) {
        if ( 0 !== strpos( $arg, '--' ) ) {
            fwrite( STDERR, "Unexpected argument: {$arg}\n" );
            return 64;
        }
        $eq = strpos( $arg, '=' );
        if ( false === $eq ) {
            $options[ substr( $arg, 2 ) ] = true;
        } else {
            $options[ substr( $arg, 2, $eq - 2 ) ] = substr( $arg, $eq + 1 );
        }
    }
    if ( isset( $options['help'] ) ) {
        fwrite( STDERR, "Usage: php bin/release-check.php [--commit=REF] [--reports-dir=DIR] [--output-dir=DIR] [--work-dir=DIR] [--from=v1.3.0,v1.3.1|auto|none] [--skip-lifecycle] [--php=BIN] [--mariadb-bin=DIR] [--board=ID --key-file=FILE [--api=URL] [--apply] [--max-new=N]]\n" );
        return 64;
    }

    $root = dirname( __DIR__ );
    $log  = sys_get_temp_dir() . '/stm-release-check-log';
    $run  = static function ( array $cmd, string $cwd ) use ( $log ): array {
        $r = WpPluginCheck\Proc::run( $cmd, $cwd, [], 3600, $log );
        return [ 'code' => $r['code'], 'stdout' => $r['stdout'], 'stderr' => $r['stderr'] ];
    };

    try {
        $verdict = stm_release_check( $options, $root, $run );
    } catch ( Throwable $e ) {
        fwrite( STDERR, 'Error: ' . $e->getMessage() . "\n" );
        return 2;
    }
    $code = $verdict['exit_code'];
    unset( $verdict['exit_code'] );
    echo json_encode( $verdict, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
    return $code;
}

if ( PHP_SAPI === 'cli' && isset( $argv[0] ) && realpath( $argv[0] ) === __FILE__ ) {
    exit( stm_rc_main( $argv ) );
}
