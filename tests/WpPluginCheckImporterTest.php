<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.test.wpplugincheckimportertest


/**
 * PHPUnit tests: importing a Plugin Check report into Jengo Work tasks (task 5144, phase B).
 *
 * The board is an in-memory fake (tests/Fakes/FakeJengoWorkBoard.php), so these tests run the real
 * importer, board client, status screen and report store end to end without a network. They pin the
 * promises of phase B: one bounded task per root cause, a fingerprint that survives moved code,
 * an identical re-import that writes nothing, a failed scan that closes nothing, and a vanished
 * finding that becomes a Review candidate and never a closed task.
 */

namespace STM\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use STM\Tests\Fakes\FakeJengoWorkBoard;
use STM\Tests\Fakes\ZipBuilder;
use WpPluginCheck\CheckCommand;
use WpPluginCheck\Fingerprint;
use WpPluginCheck\ImportCommand;
use WpPluginCheck\Importer;
use WpPluginCheck\JengoWork;
use WpPluginCheck\ReportStore;
use WpPluginCheck\Scanner;
use WpPluginCheck\StatusCommand;

require_once dirname( __DIR__ ) . '/tools/wp-plugin-check/src/autoload.php';

class WpPluginCheckImporterTest extends TestCase {

    private const SLUG = 'sample-plugin';

    private const A_PHP = "<?php\nclass Repo {\n    public function find( \$id ) {\n        global \$wpdb;\n        return \$wpdb->get_row( \"SELECT * FROM t WHERE id = \$id\" );\n    }\n\n    public function all() {\n        global \$wpdb;\n        return \$wpdb->get_results( \"SELECT * FROM t\" );\n    }\n}\n";
    private const B_PHP = "<?php\nfunction render() {\n    echo \$_GET['x'];\n}\n";

    private FakeJengoWorkBoard $board;
    private Importer $importer;
    /** @var list<string> */
    private array $cleanup = [];

    protected function setUp(): void {
        $this->board    = new FakeJengoWorkBoard();
        $this->importer = new Importer( new JengoWork( 'https://tasks.example.test/api/v2', 'test-key', $this->board, static function (): void {} ), '47' );
    }

    protected function tearDown(): void {
        foreach ( $this->cleanup as $path ) {
            if ( is_dir( $path ) ) {
                $this->removeTree( $path );
            } elseif ( is_file( $path ) ) {
                unlink( $path );
            }
        }
    }

    // --- helpers ---------------------------------------------------------------------------------------

    private function removeTree( string $dir ): void {
        $it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
        foreach ( $it as $file ) {
            $file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
        }
        rmdir( $dir );
    }

    private function finding( string $check, string $file, int $line, string $message = 'Something to fix', string $type = 'WARNING' ): array {
        return [ 'check' => $check, 'type' => $type, 'severity' => 5, 'file' => $file, 'line' => $line, 'column' => 3, 'message' => $message, 'docs' => 'https://docs.example.test/' . $check ];
    }

    /**
     * A complete report like `scan` writes it.
     *
     * @param list<array<string,mixed>> $findings
     * @param array<string,string>      $sources
     */
    private function report( array $findings, array $sources, string $scanId = 'scan-1', string $status = 'complete', bool $partial = false ): array {
        $findings = Fingerprint::apply( self::SLUG, $findings, $sources );
        return [
            'schema'      => Scanner::REPORT_SCHEMA,
            'scan'        => [ 'id' => $scanId, 'started_at' => '2026-10-13T10:00:0' . ( (int) substr( $scanId, -1 ) ) . '+00:00', 'status' => $status, 'status_reason' => $status === 'complete' ? 'All checks ran.' : 'It broke.', 'partial' => $partial ],
            'subject'     => [ 'slug' => self::SLUG, 'plugin_name' => 'Sample Plugin', 'plugin_version' => '1.0.0', 'zip_sha256' => str_repeat( 'ab', 32 ), 'commit' => 'abc1234' ],
            'environment' => [ 'plugin_check' => '2.1.0', 'wordpress' => '7.1.3', 'php' => '8.5.6' ],
            'result'      => [ 'errors' => 0, 'warnings' => count( $findings ), 'clean' => $findings === [] ],
            'findings'    => $findings,
            'suppressions' => [ 'problems' => [] ],
            'hygiene'     => [],
        ];
    }

    private function sources(): array {
        return [ 'a.php' => self::A_PHP, 'b.php' => self::B_PHP, 'readme.txt' => "=== Sample ===\n" ];
    }

    /** Three root causes: Rule.A in a.php (two findings), Rule.B in b.php (error), Rule.C in readme.txt. */
    private function threeCauses( string $scanId = 'scan-1' ): array {
        return $this->report( [
            $this->finding( 'Rule.A', 'a.php', 5 ),
            $this->finding( 'Rule.A', 'a.php', 10 ),
            $this->finding( 'Rule.B', 'b.php', 3, 'Unescaped output', 'ERROR' ),
            $this->finding( 'Rule.C', 'readme.txt', 0, 'Readme problem' ),
        ], $this->sources(), $scanId );
    }

    /** @return list<array<string,mixed>> comment POSTs made so far */
    private function commentWrites(): array {
        return array_values( array_filter( $this->board->writes(), static fn( array $r ): bool => substr( $r['path'], -8 ) === '/comment' ) );
    }

    // --- one bounded task per root cause --------------------------------------------------------------

    public function test_one_task_is_created_per_root_cause_not_per_finding() {
        $summary = $this->importer->run( $this->threeCauses(), true );

        $this->assertTrue( $summary['accepted'] );
        $this->assertSame( 3, $summary['groups'] );
        $this->assertCount( 3, $summary['created'] );
        $this->assertCount( 3, $this->board->tasks );
        $this->assertSame( 4, array_sum( array_column( $summary['created'], 'findings' ) ), 'all four findings are covered by the three tasks' );
    }

    public function test_a_new_task_starts_in_intake_with_the_marker_evidence_and_no_priority() {
        $this->importer->run( $this->report( [ $this->finding( 'Rule.B', 'b.php', 3, 'Unescaped output', 'ERROR' ) ], $this->sources() ), true );

        $task = array_values( $this->board->tasks )[0];
        $this->assertSame( 'backlog', $task['status']['status'] );
        $this->assertMatchesRegularExpression( Importer::TASK_MARKER, $task['description'] );
        $this->assertMatchesRegularExpression( Importer::FP_MARKER, $task['description'] );
        $this->assertStringContainsString( str_repeat( 'ab', 32 ), $task['description'], 'the ZIP hash is in the task' );
        $this->assertStringContainsString( 'Plugin Check 2.1.0', $task['description'] );
        $this->assertStringContainsString( 'Do not add a global suppression', $task['description'] );
        $this->assertStringContainsString( 'never closes it', $task['description'] );
        $this->assertMatchesRegularExpression( '/^[\x20-\x7E\n]+$/', $task['description'], 'plain ASCII: the board corrupts typographic characters' );
        $this->assertStringStartsWith( 'Plugin Check [sample-plugin]: Rule.B in b.php', $task['name'] );

        $post = array_values( array_filter( $this->board->writes(), static fn( array $r ): bool => $r['method'] === 'POST' ) )[0];
        $this->assertSame( [ 'name', 'description', 'status' ], array_keys( $post['body'] ), 'no priority, no assignee, no due date is sent' );
    }

    public function test_a_huge_root_cause_becomes_several_bounded_tasks_listing_at_most_twenty_findings_each() {
        $src      = "<?php\n";
        $findings = [];
        foreach ( [ 'one', 'two', 'three' ] as $fn ) {
            $src .= "function {$fn}() {\n";
            for ( $i = 0; $i < 30; $i++ ) {
                $src .= "    \$v{$i} = \$_GET['k{$i}'];\n";
                $findings[] = $this->finding( 'Rule.Big', 'big.php', count( explode( "\n", $src ) ) - 1, 'Input not sanitised ' . $fn . $i );
            }
            $src .= "}\n";
        }
        $summary = $this->importer->run( $this->report( $findings, [ 'big.php' => $src ] ), true );

        $this->assertSame( 90, array_sum( array_column( $summary['created'], 'findings' ) ) );
        $this->assertCount( 3, $summary['created'], 'split by function, not one task of 90 findings' );
        foreach ( $this->board->tasks as $task ) {
            $this->assertLessThanOrEqual( Importer::MAX_LISTED, substr_count( $task['description'], "\n| `big.php:" ) );
            $this->assertStringContainsString( 'first 20 of 30', $task['description'] );
        }
    }

    public function test_the_number_of_new_tasks_per_run_is_capped_and_the_rest_is_deferred() {
        $report = $this->threeCauses();
        $first  = $this->importer->run( $report, true, 2 );
        $this->assertCount( 2, $first['created'] );
        $this->assertSame( 1, $first['deferred'] );

        $second = $this->importer->run( $report, true, 2 );
        $this->assertCount( 1, $second['created'], 'the deferred one is created on the next run, the first two are found again' );
        $this->assertSame( 0, $second['deferred'] );
        $this->assertCount( 3, $this->board->tasks );
    }

    public function test_a_dry_run_describes_the_plan_and_writes_nothing() {
        $summary = $this->importer->run( $this->threeCauses(), false );

        $this->assertSame( 'dry-run', $summary['mode'] );
        $this->assertCount( 3, $summary['would_create'] );
        $this->assertSame( [], $summary['created'] );
        $this->assertSame( [], $this->board->writes() );
        $this->assertSame( [], $this->board->tasks );
    }

    // --- an identical rescan adds nothing -----------------------------------------------------------------

    public function test_importing_the_same_report_twice_adds_no_task_and_no_comment() {
        $this->importer->run( $this->threeCauses(), true );
        $this->board->clearRequests();

        $again = $this->importer->run( $this->threeCauses(), true );

        $this->assertSame( [], $again['created'] );
        $this->assertSame( [], $again['updated'] );
        $this->assertSame( 3, $again['unchanged'] );
        $this->assertSame( [], $this->board->writes(), 'not a single write request' );
        $this->assertCount( 3, $this->board->tasks );
    }

    public function test_a_second_scan_of_the_same_zip_with_a_new_scan_id_adds_nothing() {
        $this->importer->run( $this->threeCauses( 'scan-1' ), true );
        $this->board->clearRequests();

        $again = $this->importer->run( $this->threeCauses( 'scan-2' ), true );

        $this->assertSame( [], $this->board->writes() );
        $this->assertSame( [], $again['created'] );
    }

    public function test_code_that_only_moved_keeps_its_tasks_and_adds_nothing() {
        $this->importer->run( $this->threeCauses(), true );
        $this->board->clearRequests();

        // Same code, ten new comment lines on top of both PHP files: every flagged line moves down by ten.
        $pad     = str_repeat( "// added comment\n", 10 );
        $moved   = [ 'a.php' => "<?php\n" . $pad . substr( self::A_PHP, 6 ), 'b.php' => "<?php\n" . $pad . substr( self::B_PHP, 6 ), 'readme.txt' => "=== Sample ===\n" ];
        $report2 = $this->report( [
            $this->finding( 'Rule.A', 'a.php', 15 ),
            $this->finding( 'Rule.A', 'a.php', 20 ),
            $this->finding( 'Rule.B', 'b.php', 13, 'Unescaped output', 'ERROR' ),
            $this->finding( 'Rule.C', 'readme.txt', 0, 'Readme problem' ),
        ], $moved, 'scan-2' );

        $summary = $this->importer->run( $report2, true );

        $this->assertSame( [], $this->board->writes() );
        $this->assertSame( [], $summary['created'] );
        $this->assertSame( 3, $summary['unchanged'] );
    }

    public function test_tasks_are_found_by_their_marker_even_when_renamed_or_when_no_state_file_exists() {
        $this->importer->run( $this->threeCauses(), true );
        foreach ( $this->board->tasks as $id => $task ) {
            $this->board->tasks[ $id ]['name'] = 'Someone renamed this to something else entirely';
        }
        $this->board->clearRequests();

        $again = $this->importer->run( $this->threeCauses( 'scan-2' ), true );

        $this->assertSame( [], $again['created'] );
        $this->assertSame( [], $this->board->writes() );
    }

    public function test_a_task_of_another_plugin_is_never_matched() {
        $this->importer->run( $this->threeCauses(), true );
        $other = $this->threeCauses();
        $other['subject']['slug'] = 'other-plugin';
        foreach ( $other['findings'] as $i => $f ) {
            $other['findings'][ $i ] = Fingerprint::apply( 'other-plugin', [ $f ], $this->sources() )[0];
        }
        $summary = $this->importer->run( $other, true );

        $this->assertCount( 3, $summary['created'], 'the same root causes in another plugin are new tasks' );
        $this->assertCount( 6, $this->board->tasks );
    }

    // --- a finding that changes -------------------------------------------------------------------------------

    public function test_a_changed_finding_list_adds_one_comment_and_the_comment_is_not_repeated() {
        $this->importer->run( $this->threeCauses(), true );
        $this->board->clearRequests();

        $report2 = $this->report( [
            $this->finding( 'Rule.A', 'a.php', 5 ),
            $this->finding( 'Rule.A', 'a.php', 10 ),
            $this->finding( 'Rule.A', 'a.php', 10, 'A second, different problem on that line' ),
            $this->finding( 'Rule.B', 'b.php', 3, 'Unescaped output', 'ERROR' ),
            $this->finding( 'Rule.C', 'readme.txt', 0, 'Readme problem' ),
        ], $this->sources(), 'scan-2' );
        $summary = $this->importer->run( $report2, true );

        $this->assertCount( 1, $summary['updated'] );
        $this->assertSame( [], $summary['created'] );
        $this->assertCount( 1, $this->commentWrites() );
        $this->assertStringContainsString( 'now has 3 finding(s)', $this->commentWrites()[0]['body']['comment_text'] );

        $this->board->clearRequests();
        $this->importer->run( $report2, true );
        $this->assertSame( [], $this->board->writes(), 'importing the new list again writes nothing' );
    }

    // --- a failed or partial scan touches nothing ---------------------------------------------------------------

    public function test_a_failed_scan_is_not_imported_and_makes_no_request_at_all() {
        $this->importer->run( $this->threeCauses(), true );
        $this->board->clearRequests();
        $failed = $this->report( [], $this->sources(), 'scan-2', 'failed' );

        $summary = $this->importer->run( $failed, true );

        $this->assertFalse( $summary['accepted'] );
        $this->assertStringContainsString( 'says nothing about what is fixed', $summary['reason'] );
        $this->assertSame( [], $this->board->requests, 'not even a read of the board' );
        $this->assertSame( [], $summary['review_candidates'] );
        foreach ( $this->board->tasks as $task ) {
            $this->assertSame( 'backlog', $task['status']['status'], 'no task was closed' );
        }
    }

    public function test_a_partial_scan_is_not_imported_even_when_it_says_complete() {
        $this->importer->run( $this->threeCauses(), true );
        $this->board->clearRequests();
        $partial = $this->report( [], $this->sources(), 'scan-2', 'complete', true );

        $summary = $this->importer->run( $partial, true );

        $this->assertFalse( $summary['accepted'] );
        $this->assertStringContainsString( 'partial', $summary['reason'] );
        $this->assertSame( [], $this->board->requests );
    }

    public function test_reports_that_are_not_ours_or_have_no_fingerprints_are_refused() {
        $this->assertStringContainsString( 'Not a wp-plugin-check report', (string) Importer::rejectReason( [ 'schema' => 'something-else' ] ) );

        $noSlug = $this->threeCauses();
        unset( $noSlug['subject']['slug'] );
        $this->assertStringContainsString( 'no usable plugin slug', (string) Importer::rejectReason( $noSlug ) );

        $old = $this->threeCauses();
        unset( $old['findings'][0]['fingerprint'] );
        $this->assertStringContainsString( 're-run the scan', (string) Importer::rejectReason( $old ) );

        $this->assertNull( Importer::rejectReason( $this->threeCauses() ) );
    }

    public function test_when_the_board_cannot_be_read_nothing_is_written() {
        $this->board->queuedBodies = [ '{"err":"Board not found"}' ];

        try {
            $this->importer->run( $this->threeCauses(), true );
            $this->fail( 'Expected the importer to stop' );
        } catch ( RuntimeException $e ) {
            $this->assertStringContainsString( 'refused', $e->getMessage() );
        }
        $this->assertSame( [], $this->board->writes() );
    }

    // --- a vanished finding is a Review candidate, nothing more -----------------------------------------------

    public function test_a_finding_that_vanishes_gets_one_review_comment_and_the_task_stays_open() {
        $this->importer->run( $this->threeCauses(), true );
        $this->board->clearRequests();

        $report2 = $this->report( [
            $this->finding( 'Rule.A', 'a.php', 5 ),
            $this->finding( 'Rule.A', 'a.php', 10 ),
            $this->finding( 'Rule.C', 'readme.txt', 0, 'Readme problem' ),
        ], $this->sources(), 'scan-2' );
        $summary = $this->importer->run( $report2, true );

        $this->assertCount( 1, $summary['review_candidates'] );
        $this->assertSame( 'review', $summary['review_candidates'][0]['kind'] );
        $this->assertCount( 1, $this->board->writes(), 'exactly one write: the comment' );
        $this->assertSame( 'POST', $this->board->writes()[0]['method'] );
        $this->assertStringContainsString( '/comment', $this->board->writes()[0]['path'] );
        $this->assertStringContainsString( 'Review candidate', $this->commentWrites()[0]['body']['comment_text'] );
        $this->assertStringContainsString( 'never moves a task to done', $this->commentWrites()[0]['body']['comment_text'] );
        foreach ( $this->board->tasks as $task ) {
            $this->assertSame( 'backlog', $task['status']['status'], 'the importer never changes a status' );
        }
    }

    public function test_the_review_candidate_note_is_posted_once_not_on_every_import() {
        $this->importer->run( $this->threeCauses(), true );
        $report2 = $this->report( [ $this->finding( 'Rule.A', 'a.php', 5 ), $this->finding( 'Rule.A', 'a.php', 10 ), $this->finding( 'Rule.C', 'readme.txt', 0, 'Readme problem' ) ], $this->sources(), 'scan-2' );
        $this->importer->run( $report2, true );
        $this->board->clearRequests();

        $this->importer->run( $report2, true );
        $this->importer->run( $report2, true );

        $this->assertSame( [], $this->board->writes() );
    }

    public function test_a_review_candidate_that_comes_back_is_noted_once_and_not_duplicated() {
        $this->importer->run( $this->threeCauses(), true );
        $without = $this->report( [ $this->finding( 'Rule.A', 'a.php', 5 ), $this->finding( 'Rule.A', 'a.php', 10 ), $this->finding( 'Rule.C', 'readme.txt', 0, 'Readme problem' ) ], $this->sources(), 'scan-2' );
        $this->importer->run( $without, true );
        $this->board->clearRequests();

        $back    = $this->threeCauses( 'scan-3' );
        $summary = $this->importer->run( $back, true );

        $this->assertSame( [], $summary['created'], 'the open task is reused' );
        $this->assertCount( 1, $summary['reappeared'] );
        $this->assertCount( 1, $this->commentWrites() );
        $this->assertStringContainsString( 'Reappeared', $this->commentWrites()[0]['body']['comment_text'] );

        $this->board->clearRequests();
        $this->importer->run( $back, true );
        $this->assertSame( [], $this->board->writes() );
    }

    public function test_comment_order_comes_from_the_date_not_from_the_list_order() {
        $task = [ 'id' => '1', 'description' => 'x [wpc:sample-plugin:0123456789abcdef] [wpc-fp:aaaaaaaaaaaa]' ];
        $newer = [ 'id' => 2, 'date' => '2000', 'comment_text' => 'Review candidate ... [wpc-review:scan-2]' ];
        $older = [ 'id' => 1, 'date' => '1000', 'comment_text' => 'changed [wpc-fp:bbbbbbbbbbbb]' ];

        $this->assertSame( [ 'fp' => 'bbbbbbbbbbbb', 'last' => 'review' ], Importer::stateFromComments( $task, [ $newer, $older ] ), 'newest first in the list' );
        $this->assertSame( [ 'fp' => 'bbbbbbbbbbbb', 'last' => 'review' ], Importer::stateFromComments( $task, [ $older, $newer ] ), 'oldest first in the list' );
    }

    public function test_a_closed_task_is_not_a_review_candidate_and_is_left_alone() {
        $this->importer->run( $this->threeCauses(), true );
        foreach ( $this->board->tasks as $id => $task ) {
            $this->board->setStatus( $id, 'done' );
        }
        $this->board->clearRequests();

        $summary = $this->importer->run( $this->report( [], $this->sources(), 'scan-2' ), true );

        $this->assertSame( [], $summary['review_candidates'] );
        $this->assertSame( [], $this->board->writes() );
    }

    // --- a closed root cause that is reported again --------------------------------------------------------------

    public function test_a_closed_task_whose_root_cause_is_reported_again_gets_a_new_task_that_points_back() {
        $first = $this->importer->run( $this->threeCauses(), true );
        $closedId = $first['created'][0]['id'];
        $this->board->setStatus( $closedId, 'closed' );

        $summary = $this->importer->run( $this->threeCauses( 'scan-2' ), true );

        $this->assertCount( 1, $summary['created'] );
        $this->assertSame( $closedId, $summary['created'][0]['regression_of'] );
        $new = $this->board->tasks[ $summary['created'][0]['id'] ];
        $this->assertStringContainsString( "Regression:** an earlier task (#{$closedId})", $new['description'] );
        $this->assertCount( 4, $this->board->tasks );

        $this->board->clearRequests();
        $this->importer->run( $this->threeCauses( 'scan-3' ), true );
        $this->assertSame( [], $this->board->writes(), 'the regression task is found on the next run, no second one' );
    }

    // --- the scanner's own problems are findings too -----------------------------------------------------------------

    public function test_unjustified_suppressions_and_zip_hygiene_problems_become_findings_with_fingerprints() {
        $report = $this->threeCauses();
        $report['suppressions']['problems'] = [ [ 'file' => 'a.php', 'line' => 5, 'problem' => 'no-reason', 'detail' => 'phpcs:ignore without a reason', 'rules' => [ 'WordPress.DB.DirectDatabaseQuery' ] ] ];
        $report['hygiene']                  = [ [ 'kind' => 'development-file', 'file' => '.env', 'detail' => 'Development file in the ZIP' ] ];

        $all    = Importer::allFindings( $report );
        $checks = array_column( $all, 'check' );

        $this->assertContains( 'scanner.suppression.no-reason', $checks );
        $this->assertContains( 'scanner.zip.development-file', $checks );
        $this->assertCount( 6, $all );
        foreach ( $all as $f ) {
            $this->assertNotEmpty( $f['fingerprint'] );
        }
        $summary = $this->importer->run( $report, true );
        $this->assertSame( 5, $summary['groups'] );
    }

    // --- the Jengo Work client ---------------------------------------------------------------------------------------

    public function test_the_client_pages_through_the_whole_board() {
        for ( $i = 1; $i <= 8; $i++ ) {
            $this->board->addTask( "task {$i}" );
        }
        $api = new JengoWork( 'https://tasks.example.test/api/v2', 'k', $this->board, static function (): void {} );

        $this->assertCount( 8, $api->listTasks( '47' ) );
    }

    public function test_an_err_body_with_http_200_is_an_error_not_a_success() {
        $api = new JengoWork( 'https://tasks.example.test/api/v2', 'k', static fn(): array => [ 'status' => 200, 'body' => '{"err":"agents cannot move or create tasks in done lanes"}' ], static function (): void {} );
        $this->expectException( RuntimeException::class );
        $this->expectExceptionMessage( 'refused' );
        $api->createTask( '47', 'n', 'd', 'done' );
    }

    public function test_a_rate_limit_answer_is_retried_and_a_rate_limited_comment_list_is_never_read_as_empty() {
        $sleeps = [];
        $this->board->queuedBodies = [ '{"err":"Rate limit reached","ECODE":"APP_002"}' ];
        $api = new JengoWork( 'https://tasks.example.test/api/v2', 'k', $this->board, static function ( int $s ) use ( &$sleeps ): void {
            $sleeps[] = $s;
        } );
        $this->assertSame( [], $api->comments( '1' ) );
        $this->assertSame( [ 10 ], $sleeps );

        $api = new JengoWork( 'https://tasks.example.test/api/v2', 'k', static fn(): array => [ 'status' => 200, 'body' => '{"unexpected":true}' ], static function (): void {} );
        $this->expectException( RuntimeException::class );
        $this->expectExceptionMessage( 'no comments key' );
        $api->comments( '1' );
    }

    public function test_a_server_error_stops_the_import_instead_of_carrying_on() {
        $api = new JengoWork( 'https://tasks.example.test/api/v2', 'k', static fn(): array => [ 'status' => 500, 'body' => 'oops' ], static function (): void {} );
        $this->expectException( RuntimeException::class );
        $this->expectExceptionMessage( 'HTTP 500' );
        $api->listTasks( '47' );
    }

    // --- the command line ----------------------------------------------------------------------------------------------

    private function tempFile( string $content, string $suffix = '.json' ): string {
        $path = sys_get_temp_dir() . '/stm-import-test-' . bin2hex( random_bytes( 4 ) ) . $suffix;
        file_put_contents( $path, $content );
        $this->cleanup[] = $path;
        return $path;
    }

    /** @return array{0:int,1:array<string,mixed>} */
    private function runImport( array $positional, array $options ): array {
        ob_start();
        $code = ImportCommand::run( $positional, $options, $this->board );
        $out  = (string) ob_get_clean();
        return [ $code, json_decode( $out, true ) ?? [] ];
    }

    public function test_the_import_command_dry_runs_by_default_and_applies_only_with_the_flag() {
        $report = $this->tempFile( json_encode( $this->threeCauses() ) );
        $key    = $this->tempFile( "secret-key\nignored second line\n", '.key' );

        [ $code, $out ] = $this->runImport( [ $report ], [ 'board' => '47', 'key-file' => $key ] );
        $this->assertSame( 0, $code );
        $this->assertSame( 'dry-run', $out['mode'] );
        $this->assertSame( [], $this->board->tasks );

        [ $code, $out ] = $this->runImport( [ $report ], [ 'board' => '47', 'key-file' => $key, 'apply' => true ] );
        $this->assertSame( 0, $code );
        $this->assertCount( 3, $out['created'] );

        [ , $out ] = $this->runImport( [ $report ], [ 'board' => '47', 'key-file' => $key, 'apply' => true ] );
        $this->assertSame( [], $out['created'], 'the second import of the same report adds no task' );
        $this->assertCount( 3, $this->board->tasks );
    }

    public function test_the_import_command_refuses_a_failed_scan_with_exit_3_before_it_needs_a_key_or_a_network() {
        $report = $this->tempFile( json_encode( $this->report( [], $this->sources(), 'scan-2', 'failed' ) ) );
        $state  = $this->tempFile( '', '.jsonl' );
        putenv( 'JENGOWORK_API_KEY' );

        [ $code, $out ] = $this->runImport( [ $report ], [ 'board' => '47', 'apply' => true, 'state' => $state ] );

        $this->assertSame( 3, $code );
        $this->assertFalse( $out['accepted'] );
        $this->assertSame( [], $this->board->requests );
        $line = json_decode( trim( (string) file_get_contents( $state ) ), true );
        $this->assertFalse( $line['accepted'], 'the refusal is in the audit file' );
        $this->assertSame( 'scan-2', $line['scan_id'] );
    }

    public function test_the_import_command_audit_file_gets_one_line_per_run() {
        $report = $this->tempFile( json_encode( $this->threeCauses() ) );
        $key    = $this->tempFile( "k\n", '.key' );
        $state  = $this->tempFile( '', '.jsonl' );

        $this->runImport( [ $report ], [ 'board' => '47', 'key-file' => $key, 'apply' => true, 'state' => $state ] );
        $this->runImport( [ $report ], [ 'board' => '47', 'key-file' => $key, 'apply' => true, 'state' => $state ] );

        $lines = array_values( array_filter( explode( "\n", (string) file_get_contents( $state ) ) ) );
        $this->assertCount( 2, $lines );
        $this->assertCount( 3, json_decode( $lines[0], true )['created'] );
        $this->assertCount( 0, json_decode( $lines[1], true )['created'] );
    }

    public function test_the_key_is_read_from_a_file_or_the_environment_never_from_an_argument() {
        $this->expectException( RuntimeException::class );
        $this->expectExceptionMessage( 'No Jengo Work key' );
        putenv( 'JENGOWORK_API_KEY' );
        Importer::readKey( null );
    }

    // --- the one entry point: check -------------------------------------------------------------------------------------

    private function sampleZip(): string {
        $path = ZipBuilder::file( [
            'sample-plugin/'                  => '',
            'sample-plugin/sample-plugin.php' => "<?php\n/**\n * Plugin Name: Sample Plugin\n * Version: 1.0.0\n */\n",
            'sample-plugin/readme.txt'        => "=== Sample Plugin ===\nStable tag: 1.0.0\n",
        ] );
        $this->cleanup[] = $path;
        return $path;
    }

    /**
     * A stand-in for the real scanner that writes report.json where the real one does.
     *
     * @param list<array<string,mixed>> $reports one report per call, in order
     */
    private function fakeScan( array $reports ): callable {
        return static function ( string $zip, array $options ) use ( &$reports ): array {
            $report = array_shift( $reports );
            mkdir( $options['out_dir'], 0777, true );
            file_put_contents( $options['out_dir'] . '/report.json', json_encode( $report ) );
            return $report;
        };
    }

    /** @return array{0:int,1:array<string,mixed>} */
    private function runCheck( array $options, array $reports ): array {
        ob_start();
        $code = CheckCommand::run( [ $this->sampleZip() ], $options, dirname( __DIR__ ) . '/tools/wp-plugin-check', $this->fakeScan( $reports ), $this->board );
        return [ $code, json_decode( (string) ob_get_clean(), true ) ?? [] ];
    }

    private function reportsDir(): string {
        $dir = sys_get_temp_dir() . '/stm-check-reports-' . bin2hex( random_bytes( 4 ) );
        $this->cleanup[] = $dir;
        return $dir;
    }

    public function test_check_keeps_every_scan_in_the_report_store_and_reports_the_delta_to_the_previous_complete_scan() {
        $dir  = $this->reportsDir();
        $key  = $this->tempFile( "k\n", '.key' );
        $base = [ 'reports-dir' => $dir, 'board' => '47', 'key-file' => $key ];

        [ $code, $first ] = $this->runCheck( $base, [ $this->threeCauses( 'scan-1' ) ] );
        $this->assertSame( 1, $code, 'findings: exit 1' );
        $this->assertNull( $first['delta'], 'nothing to compare with yet' );
        $this->assertSame( 'dry-run', $first['import']['mode'] );
        $this->assertSame( [], $this->board->tasks, 'without --apply nothing is created' );

        $fewer = $this->report( [ $this->finding( 'Rule.A', 'a.php', 5 ), $this->finding( 'Rule.A', 'a.php', 10 ) ], $this->sources(), 'scan-2' );
        [ , $second ] = $this->runCheck( $base + [ 'apply' => true ], [ $fewer ] );

        $this->assertSame( 'scan-1', $second['delta']['since_scan'] );
        $this->assertSame( 0, $second['delta']['new'] );
        $this->assertSame( 2, $second['delta']['fixed'], 'Rule.B and Rule.C are gone' );
        $this->assertSame( 2, $second['delta']['unchanged'] );
        $this->assertSame( 2, $second['status']['reports_found'] );
        $this->assertCount( 1, $second['import']['created'], 'the first run was a dry run, so the board is still empty and Rule.A gets its task now' );
    }

    public function test_check_twice_with_the_same_result_adds_no_task() {
        $dir  = $this->reportsDir();
        $key  = $this->tempFile( "k\n", '.key' );
        $opts = [ 'reports-dir' => $dir, 'board' => '47', 'key-file' => $key, 'apply' => true ];

        $this->runCheck( $opts, [ $this->threeCauses( 'scan-1' ) ] );
        $tasks = count( $this->board->tasks );
        $this->board->clearRequests();
        [ , $again ] = $this->runCheck( $opts, [ $this->threeCauses( 'scan-2' ) ] );

        $this->assertSame( 3, $tasks );
        $this->assertSame( $tasks, count( $this->board->tasks ) );
        $this->assertSame( [], $again['import']['created'] );
        $this->assertSame( [], $this->board->writes() );
        $this->assertSame( 0, $again['delta']['new'] );
        $this->assertSame( 0, $again['delta']['fixed'] );
        $this->assertSame( 4, $again['delta']['unchanged'], 'all four findings are unchanged' );
        $this->assertSame( 3, $again['status']['open_tasks'] );
    }

    public function test_check_with_a_failed_scan_exits_2_closes_nothing_and_keeps_the_last_complete_scan() {
        $dir  = $this->reportsDir();
        $key  = $this->tempFile( "k\n", '.key' );
        $opts = [ 'reports-dir' => $dir, 'board' => '47', 'key-file' => $key, 'apply' => true ];
        $this->runCheck( $opts, [ $this->threeCauses( 'scan-1' ) ] );
        $this->board->clearRequests();

        $failed = $this->report( [], $this->sources(), 'scan-2', 'failed' );
        $failed['subject'] = null;
        $failed['result']  = null;
        [ $code, $out ] = $this->runCheck( $opts, [ $failed ] );

        $this->assertSame( 2, $code );
        $this->assertFalse( $out['import']['accepted'] );
        $this->assertSame( [], $this->board->requests, 'a failed scan makes no request to the board' );
        $this->assertSame( 'failed', $out['status']['last_attempt']['status'] );
        $this->assertSame( 'scan-1', $out['status']['last_complete']['scan_id'] );
        foreach ( $this->board->tasks as $task ) {
            $this->assertSame( 'backlog', $task['status']['status'] );
        }
    }

    public function test_check_needs_a_reports_dir() {
        $this->assertSame( 64, CheckCommand::run( [ $this->sampleZip() ], [], dirname( __DIR__ ) . '/tools/wp-plugin-check', $this->fakeScan( [] ) ) );
    }

    // --- report store and status screen ---------------------------------------------------------------------------------

    private function storeWith( array $reports ): ReportStore {
        $root = sys_get_temp_dir() . '/stm-report-store-' . bin2hex( random_bytes( 4 ) );
        $this->cleanup[] = $root;
        $store = new ReportStore( $root );
        foreach ( $reports as $r ) {
            $dir = $store->reportDir( self::SLUG, 'x' . $r['scan']['id'], $r['subject']['zip_sha256'] );
            mkdir( $dir, 0777, true );
            file_put_contents( $dir . '/report.json', json_encode( $r ) );
        }
        mkdir( $root . '/' . self::SLUG . '/broken', 0777, true );
        file_put_contents( $root . '/' . self::SLUG . '/broken/report.json', '{not json' );
        return $store;
    }

    public function test_the_report_store_lists_scans_oldest_first_and_skips_unreadable_folders() {
        $store = $this->storeWith( [ $this->threeCauses( 'scan-3' ), $this->threeCauses( 'scan-1' ), $this->threeCauses( 'scan-2' ) ] );

        $ids = array_map( static fn( array $e ): string => $e['report']['scan']['id'], $store->reports( self::SLUG ) );

        $this->assertSame( [ 'scan-1', 'scan-2', 'scan-3' ], $ids );
        $this->assertSame( [], $store->reports( 'no-such-plugin' ) );
    }

    public function test_the_status_shows_the_last_attempt_the_last_complete_scan_and_the_delta() {
        $s1 = $this->threeCauses( 'scan-1' );
        $s2 = $this->report( [ $this->finding( 'Rule.A', 'a.php', 5 ), $this->finding( 'Rule.A', 'a.php', 10 ), $this->finding( 'Rule.D', 'b.php', 3, 'New problem' ) ], $this->sources(), 'scan-2' );
        $s3 = $this->report( [], $this->sources(), 'scan-3', 'failed' );
        $store = $this->storeWith( [ $s1, $s2, $s3 ] );

        $status = StatusCommand::build( $store->reports( self::SLUG ), self::SLUG, null );

        $this->assertSame( 3, $status['reports_found'] );
        $this->assertSame( 'failed', $status['last_attempt']['status'] );
        $this->assertSame( 'scan-2', $status['last_complete']['scan_id'], 'a failed scan does not replace the last complete one' );
        $this->assertSame( 'scan-1', $status['previous_complete']['scan_id'] );
        $this->assertTrue( $status['attempt_is_newer_than_complete'] );
        $this->assertSame( 'scan-1', $status['delta']['since_scan'] );
        $this->assertSame( 1, $status['delta']['new'], 'Rule.D' );
        $this->assertSame( 2, $status['delta']['fixed'], 'Rule.B and Rule.C' );
        $this->assertSame( 2, $status['delta']['unchanged'] );
        $this->assertNull( $status['tasks'] );
        $this->assertStringContainsString( 'Last attempt: scan `scan-3` **failed**', StatusCommand::markdown( $status ) );
    }

    public function test_the_status_lists_the_linked_tasks_of_this_plugin_only() {
        $this->importer->run( $this->threeCauses(), true );
        $this->board->addTask( 'Unrelated task about something else', 'todo' );
        $ids = array_keys( $this->board->tasks );
        $this->board->setStatus( $ids[0], 'done' );
        $store = $this->storeWith( [ $this->threeCauses( 'scan-1' ) ] );

        $api    = new JengoWork( 'https://tasks.example.test/api/v2', 'k', $this->board, static function (): void {} );
        $status = StatusCommand::build( $store->reports( self::SLUG ), self::SLUG, $api->listTasks( '47' ) );

        $this->assertCount( 3, $status['tasks'] );
        $this->assertSame( 2, $status['open_tasks'] );
        $this->assertStringContainsString( 'Tasks: 2 open of 3', StatusCommand::markdown( $status ) );
    }

    public function test_the_status_without_any_report_says_so() {
        $status = StatusCommand::build( [], self::SLUG, null );
        $this->assertNull( $status['last_attempt'] );
        $this->assertStringContainsString( 'No scan report found', StatusCommand::markdown( $status ) );
    }
}
