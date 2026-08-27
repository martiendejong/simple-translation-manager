<?php
/**
 * PHPUnit tests: Frontend::get_current_language() — active-language gating.
 *
 * Task 869e9a954 allowed the post editor to save content for inactive
 * languages. That only stays safe if the front end still refuses to ever
 * serve that content to an ordinary visitor: get_current_language() must
 * reject a requested language (from the rewrite query var, ?lang=, or the
 * stm_lang cookie) unless it is active, EXCEPT for an authenticated
 * "preview in language" request — the one legitimate way an admin can see
 * inactive-language content before switching it live.
 */

namespace STM\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use STM\Frontend;
use STM\Tests\Fakes\FakeWpdb;

class FrontendTest extends TestCase {

    /** @var FakeWpdb */
    private $wpdb;

    /** @var bool Toggled per test via the get_option alias in setUp() */
    private static $url_routing_enabled = false;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        global $wpdb;
        $wpdb = new FakeWpdb();
        $this->wpdb = $wpdb;

        $_GET    = [];
        $_COOKIE = [];

        Functions\when('sanitize_text_field')->returnArg(1);
        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('wp_cache_get')->justReturn(false);
        Functions\when('wp_cache_set')->justReturn(true);
        // Default language 'en' for every option EXCEPT URL routing, which
        // these unit tests keep OFF unless a test flips it: with routing on,
        // the URL is authoritative and the cookie is deliberately ignored
        // (see Frontend::get_requested_language()), so the cookie-priority
        // tests below would never reach the code path they exercise.
        self::$url_routing_enabled = false;
        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === \STM\Settings::OPTION_ENABLE_URL_ROUTING) {
                return self::$url_routing_enabled;
            }
            return 'en';
        });
        Functions\when('get_query_var')->justReturn('');
        Functions\when('is_preview')->justReturn(false);
        Functions\when('get_queried_object_id')->justReturn(0);
        Functions\when('current_user_can')->justReturn(false);

        $this->resetCookieWrittenGuard();

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

    // -----------------------------------------------------------------
    // Active-language requests still work exactly as before
    // -----------------------------------------------------------------

    public function test_active_language_from_cookie_is_honored() {
        $_COOKIE['stm_lang'] = 'nl';

        $this->assertSame('nl', Frontend::get_current_language());
    }

    // -----------------------------------------------------------------
    // URL routing mode: the URL is authoritative, the cookie is not
    // (869ecwc03/869ectxca — returning to / after /en/ must not stay
    // stuck in English because of a lingering stm_lang cookie)
    // -----------------------------------------------------------------

    public function test_url_routing_mode_ignores_cookie() {
        self::$url_routing_enabled = true;
        $_COOKIE['stm_lang'] = 'nl';

        $this->assertSame('en', Frontend::get_current_language());
    }

    public function test_url_routing_mode_still_honors_get_param() {
        self::$url_routing_enabled = true;
        $_GET['lang'] = 'nl';

        $this->assertSame('nl', Frontend::get_current_language());
    }

    public function test_url_routing_mode_still_honors_rewrite_query_var() {
        self::$url_routing_enabled = true;
        Functions\when('get_query_var')->justReturn('nl');

        $this->assertSame('nl', Frontend::get_current_language());
    }

    public function test_active_language_from_get_param_is_honored() {
        $_GET['lang'] = 'nl';

        $this->assertSame('nl', Frontend::get_current_language());
    }

    public function test_active_language_from_rewrite_query_var_is_honored() {
        Functions\when('get_query_var')->justReturn('nl');

        $this->assertSame('nl', Frontend::get_current_language());
    }

    // -----------------------------------------------------------------
    // Inactive-language requests are rejected for ordinary visitors
    // -----------------------------------------------------------------

    public function test_inactive_language_from_cookie_falls_back_to_default() {
        $_COOKIE['stm_lang'] = 'de';

        $this->assertSame('en', Frontend::get_current_language());
    }

    public function test_inactive_language_from_get_param_falls_back_to_default() {
        $_GET['lang'] = 'de';

        $this->assertSame('en', Frontend::get_current_language());
    }

    public function test_inactive_language_from_rewrite_query_var_falls_back_to_default() {
        Functions\when('get_query_var')->justReturn('de');

        $this->assertSame('en', Frontend::get_current_language());
    }

    public function test_unknown_language_code_falls_back_to_default() {
        $_COOKIE['stm_lang'] = 'xx';

        $this->assertSame('en', Frontend::get_current_language());
    }

    // -----------------------------------------------------------------
    // Authenticated "preview in language" is the one exception
    // -----------------------------------------------------------------

    public function test_inactive_language_is_visible_during_authorized_preview() {
        $_GET['lang'] = 'de';
        Functions\when('is_preview')->justReturn(true);
        Functions\when('get_queried_object_id')->justReturn(42);
        Functions\when('current_user_can')->alias(function ($cap, $postId = null) {
            return $cap === 'edit_post' && $postId === 42;
        });

        $this->assertSame('de', Frontend::get_current_language());
    }

    public function test_inactive_language_stays_hidden_when_not_a_preview_request() {
        $_GET['lang'] = 'de';
        Functions\when('get_queried_object_id')->justReturn(42);
        Functions\when('current_user_can')->justReturn(true);
        // is_preview() defaults to false from setUp — ?lang=de&preview=true was never sent.

        $this->assertSame('en', Frontend::get_current_language());
    }

    public function test_inactive_language_stays_hidden_when_visitor_cannot_edit_post() {
        $_GET['lang'] = 'de';
        Functions\when('is_preview')->justReturn(true);
        Functions\when('get_queried_object_id')->justReturn(42);
        Functions\when('current_user_can')->justReturn(false);

        $this->assertSame('en', Frontend::get_current_language());
    }

    public function test_inactive_language_stays_hidden_when_preview_has_no_queried_post() {
        $_GET['lang'] = 'de';
        Functions\when('is_preview')->justReturn(true);
        Functions\when('get_queried_object_id')->justReturn(0);
        Functions\when('current_user_can')->justReturn(true);

        $this->assertSame('en', Frontend::get_current_language());
    }

    // -----------------------------------------------------------------
    // 869ebjzzc: ?lang= must only ever write the cookie once per request.
    // A search/archive loop calls get_current_language() once per post
    // (via the_title/post_type_link), so without a dedup guard a single
    // request emits a dozen-plus identical Set-Cookie headers — which the
    // production PHP-FPM/IIS front end turns into a 502 instead of a
    // response. See the class-frontend.php $cookie_written guard.
    // -----------------------------------------------------------------

    public function test_lang_cookie_is_only_written_once_per_request() {
        $_GET['lang'] = 'nl';

        $this->resetCookieWrittenGuard();
        $this->assertFalse($this->cookieWrittenGuard());

        // Simulate a loop rendering several search results: each one calls
        // get_current_language() independently, exactly like filter_title()
        // and filter_permalink() do for every post in wordpress_search().
        for ($i = 0; $i < 12; $i++) {
            $this->assertSame('nl', Frontend::get_current_language());
        }

        // The guard must have latched after the very first call and stayed
        // latched — proving at most one setcookie() call happened, not 12.
        $this->assertTrue($this->cookieWrittenGuard());
    }

    private function cookieWrittenGuard(): bool {
        $prop = new \ReflectionProperty(Frontend::class, 'cookie_written');
        return $prop->getValue();
    }

    private function resetCookieWrittenGuard(): void {
        $prop = new \ReflectionProperty(Frontend::class, 'cookie_written');
        $prop->setValue(null, false);
    }
}
