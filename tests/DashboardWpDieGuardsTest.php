<?php
/**
 * PHPUnit tests: the wp_die() permission/nonce-guard branches in
 * Dashboard::render_page(), Dashboard::export_coverage_csv() and
 * Dashboard::export_missing_csv() touched by task 869efjuhd (wrapping the
 * wp_die() message in esc_html__() for Plugin Check's OutputNotEscaped
 * rule). None of the existing tests for these methods drive the
 * guard-failure path — they all stub check_admin_referer()/
 * current_user_can() to succeed so they can reach the code beyond the
 * guard — so these 3 touched lines had zero coverage once
 * class-dashboard.php was added to the CI diff-coverage gate.
 */

namespace STM\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use STM\Dashboard;

class DashboardWpDieGuardsTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when('esc_html__')->returnArg(1);
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function stubWpDieToThrow() {
        Functions\when('wp_die')->alias(function ($message) {
            throw new \RuntimeException('wp_die:' . $message);
        });
    }

    public function test_render_page_dies_when_user_cannot_manage_translations() {
        Functions\when('current_user_can')->justReturn(false);
        $this->stubWpDieToThrow();

        try {
            Dashboard::render_page();
            $this->fail('Expected wp_die to interrupt execution.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('wp_die:Insufficient permissions.', $e->getMessage());
        }
    }

    public function test_export_coverage_csv_dies_on_failed_nonce_or_capability_check() {
        Functions\when('check_admin_referer')->justReturn(false);
        Functions\when('current_user_can')->justReturn(true);
        $this->stubWpDieToThrow();

        try {
            Dashboard::export_coverage_csv();
            $this->fail('Expected wp_die to interrupt execution.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('wp_die:Unauthorized', $e->getMessage());
        }
    }

    public function test_export_missing_csv_dies_on_failed_nonce_or_capability_check() {
        Functions\when('check_admin_referer')->justReturn(false);
        Functions\when('current_user_can')->justReturn(true);
        $this->stubWpDieToThrow();

        try {
            Dashboard::export_missing_csv();
            $this->fail('Expected wp_die to interrupt execution.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('wp_die:Unauthorized', $e->getMessage());
        }
    }
}
