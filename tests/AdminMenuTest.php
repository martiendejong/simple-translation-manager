<?php
/**
 * PHPUnit tests: Admin::add_menu_pages() registers the Documentation
 * submenu (stm-documentation) alongside the existing STM submenus.
 */

namespace STM\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use STM\Admin;

class AdminMenuTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        if (!defined('ABSPATH')) {
            define('ABSPATH', dirname(__DIR__) . '/');
        }
        require_once dirname(__DIR__) . '/includes/class-admin.php';
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_add_menu_pages_registers_documentation_submenu() {
        Functions\when('add_menu_page')->justReturn('toplevel_page_stm-translations');

        $registeredSlugs = [];
        Functions\when('add_submenu_page')->alias(function (...$args) use (&$registeredSlugs) {
            $registeredSlugs[] = $args[4]; // menu_slug argument
            return 'stm-submenu';
        });

        Admin::add_menu_pages();

        $this->assertContains('stm-documentation', $registeredSlugs);
    }

    public function test_init_registers_the_toggle_language_active_admin_post_hook() {
        Functions\when('is_admin')->justReturn(true);

        $registeredHooks = [];
        Functions\when('add_action')->alias(function (...$args) use (&$registeredHooks) {
            $registeredHooks[$args[0]] = $args[1];
        });

        Admin::init();

        $this->assertArrayHasKey('admin_post_stm_toggle_language_active', $registeredHooks);
        $this->assertSame([Admin::class, 'toggle_language_active'], $registeredHooks['admin_post_stm_toggle_language_active']);
    }
}
