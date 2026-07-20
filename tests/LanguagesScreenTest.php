<?php
/**
 * PHPUnit tests: Database::get_all_languages() vs. get_languages(), and the
 * admin Languages screen (Admin::page_languages()) listing inactive
 * languages using the new method while every other caller keeps using the
 * active-only Database::get_languages().
 */

namespace STM\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use STM\Admin;
use STM\Database;
use STM\Tests\Fakes\FakeWpdb;

class LanguagesScreenTest extends TestCase {

    /** @var FakeWpdb */
    private $wpdb;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        global $wpdb;
        $wpdb = new FakeWpdb();
        $this->wpdb = $wpdb;

        Functions\when('wp_cache_get')->justReturn(false);
        Functions\when('wp_cache_set')->justReturn(true);
        Functions\when('wp_cache_delete')->justReturn(true);

        $this->wpdb->seed('wp_stm_languages', [
            'code' => 'en', 'name' => 'English', 'native_name' => 'English',
            'flag_emoji' => '', 'is_active' => 1, 'is_default' => 1, 'order_index' => 1,
        ]);
        $this->wpdb->seed('wp_stm_languages', [
            'code' => 'nl', 'name' => 'Dutch', 'native_name' => 'Nederlands',
            'flag_emoji' => '', 'is_active' => 1, 'is_default' => 0, 'order_index' => 2,
        ]);
        $this->wpdb->seed('wp_stm_languages', [
            'code' => 'de', 'name' => 'German', 'native_name' => 'Deutsch',
            'flag_emoji' => '', 'is_active' => 0, 'is_default' => 0, 'order_index' => 3,
        ]);
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_get_languages_only_returns_active() {
        $languages = Database::get_languages();
        $codes = array_map(function ($l) { return $l->code; }, $languages);

        $this->assertSame(['en', 'nl'], $codes);
    }

    public function test_get_all_languages_includes_inactive() {
        $languages = Database::get_all_languages();
        $codes = array_map(function ($l) { return $l->code; }, $languages);

        $this->assertSame(['en', 'nl', 'de'], $codes);

        $de = $languages[2];
        $this->assertSame('0', (string) $de->is_active);
    }

    public function test_page_languages_renders_inactive_language() {
        if (!defined('ABSPATH')) {
            define('ABSPATH', dirname(__DIR__) . '/');
        }
        require_once dirname(__DIR__) . '/includes/class-admin.php';

        Functions\when('esc_html')->returnArg(1);
        Functions\when('esc_attr')->returnArg(1);
        Functions\when('esc_js')->returnArg(1);
        Functions\when('esc_url')->returnArg(1);
        Functions\when('admin_url')->justReturn('http://example.test/wp-admin/admin-post.php');
        Functions\when('wp_nonce_field')->justReturn('');

        ob_start();
        Admin::page_languages();
        $html = ob_get_clean();

        // The inactive language (German, is_active=0) must be listed...
        $this->assertStringContainsString('German', $html);
        // ...and the active language must still be listed too.
        $this->assertStringContainsString('English', $html);

        // Split rows to confirm German's row shows the inactive marker, not the active checkmark.
        preg_match('/<tr>\s*<td><code>de<\/code>.*?<\/tr>/s', $html, $match);
        $this->assertNotEmpty($match, 'Expected a table row for the German (de) language');
        $this->assertStringContainsString('—', $match[0]);
        $this->assertStringNotContainsString('✓', $match[0]);
    }
}
