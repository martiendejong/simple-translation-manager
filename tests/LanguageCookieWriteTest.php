<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.test.languagecookiewritetest
/**
 * PHPUnit tests: who may write the stm_lang cookie (task 6324).
 *
 * The cookie means "the language the visitor last EXPLICITLY chose". It is
 * written from Frontend::maybe_redirect_to_preferred_language()
 * (template_redirect) only, and only for an ACTIVE language on a plain page
 * navigation. Found live on test.portofgiethoorn.com while testing 5008:
 *
 *  - GET /wp-json/visit/v1/site?lang=nl with stm_lang=en answered
 *    Set-Cookie: stm_lang=nl (the React front-end sends ?lang=<page
 *    language> on every REST call, so a stale NL tab flipped the stored
 *    preference);
 *  - GET /?lang=xx (not an active language) with stm_lang=en answered
 *    Set-Cookie: stm_lang=xx, wiping the stored preference;
 *  - GET /?elementor-preview=... with stm_lang=en was 302'd to /en/...
 *    because only WordPress' own ?preview=true was treated as a preview.
 *
 * The CLI SAPI exposes no headers_list() and Patchwork cannot redefine
 * setcookie() on this PHP, so the tests read Frontend::$cookie_written
 * instead: false = nothing written, otherwise the language code the
 * setcookie() call carried.
 */

namespace STM\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use STM\Frontend;
use STM\Tests\Fakes\FakeWpdb;

class LanguageCookieWriteTest extends TestCase {

    /** @var FakeWpdb */
    private $wpdb;

    /** @var bool Toggled per test via the get_option alias in setUp() */
    private static $url_routing_enabled = true;

    /** @var string Return value of the get_query_var('lang') stub */
    private static $lang_query_var = '';

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        global $wpdb;
        $wpdb = new FakeWpdb();
        $this->wpdb = $wpdb;

        $_GET    = [];
        $_COOKIE = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI']    = '/';

        self::$url_routing_enabled = true;
        self::$lang_query_var      = '';

        Functions\when('sanitize_text_field')->returnArg(1);
        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('wp_cache_get')->justReturn(false);
        Functions\when('wp_cache_set')->justReturn(true);
        Functions\when('wp_parse_url')->alias('parse_url');
        Functions\when('home_url')->alias(function ($path = '') {
            return 'https://example.test' . $path;
        });
        Functions\when('is_ssl')->justReturn(true);
        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === \STM\Settings::OPTION_ENABLE_URL_ROUTING) {
                return self::$url_routing_enabled;
            }
            return 'en'; // default language
        });
        Functions\when('get_query_var')->alias(function ($var, $default = '') {
            if ($var === 'lang') {
                return self::$lang_query_var;
            }
            return $default;
        });
        Functions\when('is_admin')->justReturn(false);
        Functions\when('wp_doing_ajax')->justReturn(false);
        Functions\when('wp_doing_cron')->justReturn(false);
        Functions\when('is_robots')->justReturn(false);
        Functions\when('is_feed')->justReturn(false);
        Functions\when('is_preview')->justReturn(false);
        Functions\when('is_customize_preview')->justReturn(false);
        Functions\when('is_404')->justReturn(false);
        // wp_safe_redirect() is followed by exit; throwing keeps PHPUnit alive
        // while still proving the redirect was issued.
        Functions\when('wp_safe_redirect')->alias(function ($url) {
            throw new RedirectIssued($url);
        });

        // Default language 'en' (active), 'nl' active non-default,
        // 'de' present but inactive — mirrors FrontendTest.
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

        $this->setCookieWritten(false);
    }

    protected function tearDown(): void {
        $this->setCookieWritten(false);
        Monkey\tearDown();
        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // 1. REST / AJAX / cron: ?lang= never sets or changes stm_lang
    // -----------------------------------------------------------------

    public function test_rest_style_request_with_lang_param_does_not_write_the_cookie_while_rendering() {
        // GET /wp-json/visit/v1/site?lang=nl with stm_lang=en: REST handlers
        // reach the translation filters via get_current_language(), never
        // template_redirect. Rendering alone must not move the preference.
        $_COOKIE['stm_lang'] = 'en';
        $_GET['lang']        = 'nl';

        $this->assertSame('nl', Frontend::get_current_language());
        $this->assertSame('nl', Frontend::get_current_language());

        $this->assertFalse($this->cookieWritten());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_rest_request_never_writes_the_cookie_even_when_template_redirect_runs() {
        // Belt and braces: if something dispatches template_redirect on a
        // REST request, the REST_REQUEST constant still blocks the write.
        // Separate process: the constant cannot be un-defined again.
        define('REST_REQUEST', true);
        $_GET['lang']           = 'nl';
        $_SERVER['REQUEST_URI'] = '/wp-json/visit/v1/site?lang=nl';

        Frontend::maybe_redirect_to_preferred_language();

        $this->assertFalse($this->cookieWritten());
    }

    public function test_ajax_request_with_lang_param_does_not_write_the_cookie() {
        Functions\when('wp_doing_ajax')->justReturn(true);
        self::$url_routing_enabled = false;
        $_GET['lang'] = 'nl';

        Frontend::maybe_redirect_to_preferred_language();

        $this->assertFalse($this->cookieWritten());
    }

    public function test_cron_request_with_lang_param_does_not_write_the_cookie() {
        Functions\when('wp_doing_cron')->justReturn(true);
        self::$url_routing_enabled = false;
        $_GET['lang'] = 'nl';

        Frontend::maybe_redirect_to_preferred_language();

        $this->assertFalse($this->cookieWritten());
    }

    public function test_post_request_with_lang_param_does_not_write_the_cookie() {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        self::$url_routing_enabled = false;
        $_GET['lang'] = 'nl';

        Frontend::maybe_redirect_to_preferred_language();

        $this->assertFalse($this->cookieWritten());
    }

    // -----------------------------------------------------------------
    // 2. Inactive / unknown codes never set stm_lang
    // -----------------------------------------------------------------

    #[DataProvider('strayLangValues')]
    public function test_stray_lang_param_leaves_a_stored_preference_alone($stray) {
        $_COOKIE['stm_lang']    = 'en';
        $_GET['lang']           = $stray;
        $_SERVER['REQUEST_URI'] = '/?lang=' . $stray;

        // URL routing mode: an unknown/inactive code renders as-is, no redirect.
        Frontend::maybe_redirect_to_preferred_language();

        $this->assertFalse($this->cookieWritten());
    }

    #[DataProvider('strayLangValues')]
    public function test_stray_lang_param_in_query_param_mode_leaves_a_stored_preference_alone($stray) {
        self::$url_routing_enabled = false;
        $_COOKIE['stm_lang']       = 'en';
        $_GET['lang']              = $stray;

        Frontend::maybe_redirect_to_preferred_language();

        $this->assertFalse($this->cookieWritten());
    }

    #[DataProvider('strayLangValues')]
    public function test_rendering_with_a_stray_lang_param_does_not_write_the_cookie($stray) {
        // The original defect: ANY format-valid ?lang= (xx, zz, an inactive
        // code) reaching get_current_language() — from a content filter or a
        // REST handler — answered Set-Cookie: stm_lang=<that code>.
        $_COOKIE['stm_lang'] = 'en';
        $_GET['lang']        = $stray;

        $this->assertSame('en', Frontend::get_current_language(), 'a stray code renders the default language');

        $this->assertFalse($this->cookieWritten());
    }

    public static function strayLangValues(): array {
        return [
            'unknown code'               => ['xx'],
            'unknown code, tracking tag' => ['zz'],
            'inactive language'          => ['de'],
            'locale-style code'          => ['en-US'],
        ];
    }

    public function test_inactive_rewrite_query_var_does_not_write_the_cookie() {
        self::$lang_query_var   = 'de';
        $_SERVER['REQUEST_URI'] = '/de/locatie/molen/';
        $_COOKIE['stm_lang']    = 'en';

        Frontend::maybe_redirect_to_preferred_language();

        $this->assertFalse($this->cookieWritten());
    }

    // -----------------------------------------------------------------
    // Explicit choices of an ACTIVE language still stick
    // -----------------------------------------------------------------

    public function test_active_lang_param_writes_the_cookie_and_redirects_to_the_canonical_url() {
        $_COOKIE['stm_lang']    = 'en';
        $_GET['lang']           = 'nl';
        $_SERVER['REQUEST_URI'] = '/locatie/molen/?lang=nl';

        try {
            Frontend::maybe_redirect_to_preferred_language();
            $this->fail('An explicit ?lang=nl is redirected to its canonical URL.');
        } catch (RedirectIssued $redirect) {
            $this->assertSame('https://example.test/nl/locatie/molen/', $redirect->url);
        }

        $this->assertSame('nl', $this->cookieWritten());
    }

    public function test_visiting_an_active_language_prefix_writes_the_cookie() {
        self::$lang_query_var   = 'nl';
        $_SERVER['REQUEST_URI'] = '/nl/locatie/molen/';

        Frontend::maybe_redirect_to_preferred_language();

        $this->assertSame('nl', $this->cookieWritten());
    }

    public function test_active_lang_param_in_query_param_mode_writes_the_cookie() {
        // Without URL routing there is no redirect; the cookie is the only
        // way ?lang= survives to the next bare URL (cookie priority 3).
        self::$url_routing_enabled = false;
        $_GET['lang']              = 'nl';

        Frontend::maybe_redirect_to_preferred_language();

        $this->assertSame('nl', $this->cookieWritten());
    }

    public function test_typed_default_prefix_writes_the_default_language_cookie() {
        $_COOKIE['stm_lang']    = 'nl';
        $_SERVER['REQUEST_URI'] = '/en/locatie/molen/';

        try {
            Frontend::maybe_redirect_to_preferred_language();
            $this->fail('A typed /en/ prefix is redirected to the bare URL.');
        } catch (RedirectIssued $redirect) {
            $this->assertSame('https://example.test/locatie/molen/', $redirect->url);
        }

        $this->assertSame('en', $this->cookieWritten());
    }

    public function test_bare_url_with_stored_preference_keeps_that_preference() {
        $_COOKIE['stm_lang']    = 'nl';
        $_SERVER['REQUEST_URI'] = '/locatie/molen/';

        try {
            Frontend::maybe_redirect_to_preferred_language();
            $this->fail('A bare URL is sent to the stored preference.');
        } catch (RedirectIssued $redirect) {
            $this->assertSame('https://example.test/nl/locatie/molen/', $redirect->url);
        }

        $this->assertSame('nl', $this->cookieWritten());
    }

    public function test_the_cookie_is_written_at_most_once_per_request() {
        self::$url_routing_enabled = false;
        $_GET['lang']              = 'nl';

        Frontend::maybe_redirect_to_preferred_language();
        $_GET['lang'] = 'en';
        Frontend::maybe_redirect_to_preferred_language();

        // The second call was ignored: the latch still holds the first value.
        $this->assertSame('nl', $this->cookieWritten());
    }

    // -----------------------------------------------------------------
    // 3. Elementor preview is not a navigation
    // -----------------------------------------------------------------

    public function test_elementor_preview_url_is_not_redirected_to_the_stored_language() {
        $_SERVER['REQUEST_URI']    = '/?elementor-preview=1073&ver=1';
        $_GET['elementor-preview'] = '1073';
        $_GET['ver']               = '1';
        $_COOKIE['stm_lang']       = 'nl';

        $this->assertNull(Frontend::get_language_preference_redirect());

        // ...and the template_redirect callback leaves the request alone.
        Frontend::maybe_redirect_to_preferred_language();
        $this->assertFalse($this->cookieWritten());
    }

    public function test_elementor_preview_url_does_not_write_the_cookie() {
        $_SERVER['REQUEST_URI']    = '/?elementor-preview=1073&lang=nl';
        $_GET['elementor-preview'] = '1073';
        $_GET['lang']              = 'nl';
        self::$url_routing_enabled = false;

        Frontend::maybe_redirect_to_preferred_language();

        $this->assertFalse($this->cookieWritten());
    }

    public function test_elementor_plugin_preview_mode_is_not_redirected() {
        // The editor reports preview mode through its own API as well; the
        // query arg alone would miss an authenticated preview that arrives
        // without it.
        require_once __DIR__ . '/Fakes/ElementorPluginFake.php';
        \Elementor\Plugin::$instance->preview->preview_mode = true;
        $_SERVER['REQUEST_URI'] = '/locatie/molen/';
        $_COOKIE['stm_lang']    = 'nl';

        try {
            $this->assertNull(Frontend::get_language_preference_redirect());
        } finally {
            \Elementor\Plugin::$instance->preview->preview_mode = false;
        }
    }

    public function test_bare_url_with_elementor_loaded_but_not_previewing_still_redirects() {
        // The guards must not have swallowed the normal case.
        require_once __DIR__ . '/Fakes/ElementorPluginFake.php';
        $_SERVER['REQUEST_URI'] = '/locatie/molen/';
        $_COOKIE['stm_lang']    = 'nl';

        $redirect = Frontend::get_language_preference_redirect();

        $this->assertSame('https://example.test/nl/locatie/molen/', $redirect['url']);
    }

    // -----------------------------------------------------------------
    // helpers
    // -----------------------------------------------------------------

    /**
     * @return string|false The language code the cookie was written with
     *                      during this test, or false when none was.
     */
    private function cookieWritten() {
        $prop = new \ReflectionProperty(Frontend::class, 'cookie_written');
        return $prop->getValue();
    }

    /**
     * @param string|false $value
     */
    private function setCookieWritten($value): void {
        $prop = new \ReflectionProperty(Frontend::class, 'cookie_written');
        $prop->setValue(null, $value);
    }
}

/** Thrown by the wp_safe_redirect() stub in place of the real exit. */
class RedirectIssued extends \Exception {
    /** @var string */
    public $url;

    public function __construct($url) {
        parent::__construct('redirect to ' . $url);
        $this->url = $url;
    }
}
