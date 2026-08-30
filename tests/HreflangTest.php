<?php
/**
 * PHPUnit tests: Hreflang::inject() — only advertise a language alternate
 * when translated content actually exists behind it.
 *
 * Task 958: martiendejong.nl's homepage declared hreflang="nl" pointing at
 * /nl/, but the front page is a dynamic posts archive with no translated
 * equivalent — visitors following that tag land back on English content.
 * The same fake-signal shape was confirmed on ordinary untranslated blog
 * posts too. Hreflang::inject() must skip a non-default-language alternate
 * unless a real translation (or a post natively written in that language)
 * exists for the exact page being rendered.
 */

namespace STM\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use STM\Hreflang;
use STM\Tests\Fakes\FakeWpdb;

class HreflangTest extends TestCase {

    /** @var FakeWpdb */
    private $wpdb;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        global $wpdb;
        $wpdb = new FakeWpdb();
        $this->wpdb = $wpdb;

        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === \STM\Settings::OPTION_ENABLE_URL_ROUTING) {
                return true;
            }
            return 'en';
        });
        Functions\when('wp_cache_get')->justReturn(false);
        Functions\when('wp_cache_set')->justReturn(true);
        Functions\when('esc_attr')->returnArg(1);
        Functions\when('esc_url')->returnArg(1);
        Functions\when('home_url')->alias(function ($path = '') {
            return 'https://martiendejong.nl' . $path;
        });
        Functions\when('trailingslashit')->alias(function ($s) {
            return rtrim($s, '/') . '/';
        });
        Functions\when('add_query_arg')->alias(function ($args, $url = null) {
            return $url ?? '';
        });
        Functions\when('remove_query_arg')->returnArg(2);
        Functions\when('wp_parse_url')->alias(function ($url) {
            return parse_url($url);
        });
        Functions\when('remove_filter')->justReturn(true);
        Functions\when('add_filter')->justReturn(true);

        $this->wpdb->seed('wp_stm_languages', [
            'code' => 'en', 'name' => 'English', 'native_name' => 'English',
            'flag_emoji' => '', 'is_active' => 1, 'is_default' => 1, 'order_index' => 1,
        ]);
        $this->wpdb->seed('wp_stm_languages', [
            'code' => 'nl', 'name' => 'Dutch', 'native_name' => 'Nederlands',
            'flag_emoji' => '', 'is_active' => 1, 'is_default' => 0, 'order_index' => 2,
        ]);
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function stubSingularPost(int $postId, string $permalink) {
        Functions\when('is_singular')->justReturn(true);
        Functions\when('get_permalink')->justReturn($permalink);
        // get_queried_object() must return a real WP_Post instance for the
        // `instanceof \WP_Post` check in Hreflang::inject() — Brain Monkey
        // can't fake instanceof, so define a minimal stand-in once per run.
        if (!class_exists('WP_Post', false)) {
            eval('class WP_Post { public $ID; public $post_name = ""; }');
        }
        $post = new \WP_Post();
        $post->ID = $postId;
        Functions\when('get_queried_object')->justReturn($post);
    }

    private function stubNonSingularArchive() {
        Functions\when('is_singular')->justReturn(false);
    }

    // -----------------------------------------------------------------
    // The bug: front page / archive with no post to translate
    // -----------------------------------------------------------------

    public function test_front_page_omits_alternate_language_with_no_translated_equivalent() {
        $this->stubNonSingularArchive();

        ob_start();
        Hreflang::inject();
        $html = ob_get_clean();

        $this->assertStringContainsString('hreflang="en"', $html);
        $this->assertStringContainsString('hreflang="x-default"', $html);
        $this->assertStringNotContainsString('hreflang="nl"', $html, 'The front page has no translated Dutch version — it must not claim one exists.');
    }

    // -----------------------------------------------------------------
    // Singular content: only advertise a language with real content
    // -----------------------------------------------------------------

    public function test_singular_post_without_translation_omits_alternate_language() {
        $this->stubSingularPost(42, 'https://martiendejong.nl/some-post/');

        $this->wpdb->seed('wp_stm_post_associations', [
            'post_id' => 42, 'language_code' => 'en', 'translation_group' => 'g1', 'is_original' => 1,
        ]);

        ob_start();
        Hreflang::inject();
        $html = ob_get_clean();

        $this->assertStringContainsString('hreflang="en"', $html);
        $this->assertStringNotContainsString('hreflang="nl"', $html, 'An untranslated post must not advertise a Dutch alternate.');
    }

    public function test_singular_post_with_saved_translation_includes_alternate_language() {
        $this->stubSingularPost(42, 'https://martiendejong.nl/some-post/');

        $this->wpdb->seed('wp_stm_post_associations', [
            'post_id' => 42, 'language_code' => 'en', 'translation_group' => 'g1', 'is_original' => 1,
        ]);
        $this->wpdb->seed('wp_stm_post_translations', [
            'post_id' => 42, 'field_name' => 'post_title', 'language_code' => 'nl', 'translation' => 'Nederlandse titel',
        ]);

        ob_start();
        Hreflang::inject();
        $html = ob_get_clean();

        $this->assertStringContainsString('hreflang="en"', $html);
        $this->assertStringContainsString('hreflang="nl"', $html);
    }

    public function test_singular_post_natively_in_non_default_language_includes_alternate() {
        $this->stubSingularPost(99, 'https://martiendejong.nl/nl/nederlands-artikel/');

        $this->wpdb->seed('wp_stm_post_associations', [
            'post_id' => 99, 'language_code' => 'nl', 'translation_group' => 'g2', 'is_original' => 1,
        ]);

        ob_start();
        Hreflang::inject();
        $html = ob_get_clean();

        $this->assertStringContainsString('hreflang="nl"', $html, 'A post natively written in Dutch is real Dutch content, not a fake signal.');
    }
}
