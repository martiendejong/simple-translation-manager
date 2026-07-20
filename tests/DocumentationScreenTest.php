<?php
/**
 * PHPUnit tests: the admin Documentation screen (Admin::page_documentation())
 * links the shipped editor PDFs (docs/editors/pdf/*.pdf) using STM_PLUGIN_URL,
 * so they are reachable from wp-admin instead of only existing on disk.
 */

namespace STM\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use STM\Admin;

class DocumentationScreenTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        if (!defined('ABSPATH')) {
            define('ABSPATH', dirname(__DIR__) . '/');
        }
        require_once dirname(__DIR__) . '/includes/class-admin.php';

        Functions\when('esc_html')->returnArg(1);
        Functions\when('esc_attr')->returnArg(1);
        Functions\when('esc_url')->returnArg(1);
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_page_documentation_links_editors_guide_pdf() {
        ob_start();
        Admin::page_documentation();
        $html = ob_get_clean();

        $this->assertStringContainsString(
            STM_PLUGIN_URL . 'docs/editors/pdf/editors-guide.pdf',
            $html
        );
    }

    public function test_page_documentation_links_troubleshooting_pdf() {
        ob_start();
        Admin::page_documentation();
        $html = ob_get_clean();

        $this->assertStringContainsString(
            STM_PLUGIN_URL . 'docs/editors/pdf/troubleshooting.pdf',
            $html
        );
    }
}
