<?php
/**
 * PHPUnit tests: SeoGodIntegration::provide_current_language() — the
 * seo_god_current_language filter hook that tells SEO God which language
 * is being served, used for og:locale and per-language FAQ schema.
 */

namespace STM\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use STM\SeoGodIntegration;

class SeoGodIntegrationTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        $_GET    = [];
        $_COOKIE = [];

        Functions\when('get_query_var')->justReturn('');
        Functions\when('sanitize_text_field')->returnArg(1);
        Functions\when('get_option')->justReturn('en');
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_non_stm_plugin_is_passed_through_unchanged(): void {
        $_COOKIE['stm_lang'] = 'fr';

        $result = SeoGodIntegration::provide_current_language('incoming', 'some-other-plugin');

        $this->assertSame('incoming', $result);
    }

    public function test_translated_page_returns_visited_language(): void {
        $_COOKIE['stm_lang'] = 'fr';

        $result = SeoGodIntegration::provide_current_language('', 'stm');

        $this->assertSame('fr', $result);
    }

    public function test_default_language_page_preserves_incoming_value(): void {
        // No cookie/query/GET override → Frontend::get_current_language() falls
        // back to Settings::get_default_language(), i.e. the site default ('en').
        $result = SeoGodIntegration::provide_current_language('en_US', 'stm');

        $this->assertSame(
            'en_US',
            $result,
            'On default-language pages, the pre-hook value (e.g. get_locale() fallback) must be preserved unchanged.'
        );
    }
}
