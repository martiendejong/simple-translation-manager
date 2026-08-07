<?php
/**
 * PHPUnit tests: Dashboard::build_coverage_csv_export() / build_missing_csv_export().
 *
 * Task 869efjuhy — export_coverage_csv()/export_missing_csv() used to stream
 * CSV rows through fopen('php://output')/fputcsv()/fclose(), which Plugin
 * Check flags (WordPress.WP.AlternativeFunctions.file_system_operations_fclose).
 * WP_Filesystem doesn't apply here since the destination is the HTTP response
 * body, not a real file on disk — so the fix builds the CSV content as a
 * plain string (via csv_row()) and echoes it, removing every fopen/fwrite/
 * fclose call instead of misusing WP_Filesystem on a non-file stream.
 */

namespace STM\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use STM\Dashboard;

class DashboardTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_build_coverage_csv_export_formats_header_and_rows() {
        $stats = [
            'by_language' => [
                'nl' => [
                    'code'    => 'nl',
                    'name'    => 'Nederlands',
                    'title'   => ['translated' => 8, 'total' => 10, 'pct' => 80.0],
                    'content' => ['translated' => 5, 'total' => 10, 'pct' => 50.0],
                    'strings' => ['translated' => 20, 'total' => 20, 'pct' => 100.0],
                ],
            ],
        ];

        $csv   = Dashboard::build_coverage_csv_export($stats);
        $lines = explode("\r\n", rtrim($csv, "\r\n"));

        $this->assertCount(4, $lines);
        $this->assertSame('Language Code,Language,Field,Translated,Total,Percent', $lines[0]);
        $this->assertSame('nl,Nederlands,title,8,10,80', $lines[1]);
        $this->assertSame('nl,Nederlands,content,5,10,50', $lines[2]);
        $this->assertSame('nl,Nederlands,strings,20,20,100', $lines[3]);
    }

    public function test_build_coverage_csv_export_handles_no_languages() {
        $csv = Dashboard::build_coverage_csv_export(['by_language' => []]);

        $this->assertSame("Language Code,Language,Field,Translated,Total,Percent\r\n", $csv);
    }

    public function test_build_coverage_csv_export_quotes_fields_needing_escaping() {
        $stats = [
            'by_language' => [
                'nl' => [
                    'code'    => 'nl',
                    'name'    => 'Comma, Quote" End',
                    'title'   => ['translated' => 1, 'total' => 1, 'pct' => 100.0],
                    'content' => ['translated' => 1, 'total' => 1, 'pct' => 100.0],
                    'strings' => ['translated' => 1, 'total' => 1, 'pct' => 100.0],
                ],
            ],
        ];

        $csv   = Dashboard::build_coverage_csv_export($stats);
        $lines = explode("\r\n", rtrim($csv, "\r\n"));

        $this->assertSame('nl,"Comma, Quote"" End",title,1,1,100', $lines[1]);
    }

    public function test_build_missing_csv_export_includes_source_and_skips_deleted_posts() {
        Functions\when('get_post')->alias(function ($id) {
            if ($id === 99) {
                return null; // deleted between the report query and the export request
            }
            return (object) [
                'post_title'   => 'Hello World',
                'post_content' => '<p>Body text</p>',
                'post_excerpt' => 'An excerpt',
            ];
        });
        Functions\when('wp_strip_all_tags')->alias(function ($s) {
            return trim(strip_tags($s));
        });
        Functions\when('get_permalink')->justReturn('http://example.test/hello-world/');
        Functions\when('admin_url')->alias(function ($path) {
            return 'http://example.test/wp-admin/' . $path;
        });

        $rows = [
            ['post_id' => 1, 'post_type' => 'post', 'language_code' => 'nl'],
            ['post_id' => 99, 'post_type' => 'post', 'language_code' => 'nl'],
        ];

        $csv   = Dashboard::build_missing_csv_export($rows);
        $lines = explode("\r\n", rtrim($csv, "\r\n"));

        $this->assertCount(2, $lines, 'post 99 was deleted and must be skipped');
        $this->assertSame(
            'Post ID,Post Type,Target Language,Source Title,Source Content,Source Excerpt,Permalink,Edit Link,Translated Title,Translated Content,Translated Excerpt',
            $lines[0]
        );
        $this->assertSame(
            '1,post,nl,Hello World,Body text,An excerpt,http://example.test/hello-world/,http://example.test/wp-admin/post.php?action=edit&post=1,,,',
            $lines[1]
        );
    }

    public function test_build_missing_csv_export_handles_empty_rows() {
        $csv = Dashboard::build_missing_csv_export([]);

        $this->assertSame(
            "Post ID,Post Type,Target Language,Source Title,Source Content,Source Excerpt,Permalink,Edit Link,Translated Title,Translated Content,Translated Excerpt\r\n",
            $csv
        );
    }
}
