<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.test.languagepreferenceredirecttest
/**
 * PHPUnit tests: Frontend::get_language_preference_redirect() — the
 * language-preference redirect decision (URL routing mode).
 *
 * The stm_lang cookie stores the language the visitor last EXPLICITLY chose
 * (a /{lang}/ URL, ?lang=, or a typed /{default}/ prefix). A bare URL is
 * redirected to that stored preference; an explicit language in the URL
 * always wins over the cookie. These tests pin down the decision matrix,
 * including the guards that keep 404s, non-GET requests and disabled URL
 * routing out of the redirect.
 */

namespace STM\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use STM\Frontend;
use STM\Tests\Fakes\FakeWpdb;

class LanguagePreferenceRedirectTest extends TestCase {

    /** @var FakeWpdb */
    private $wpdb;

    /** @var bool Toggled per test via the get_option alias in setUp() */
    private static $url_routing_enabled = true;

    /** @var string Return value of the get_query_var('lang') stub */
    private static $lang_query_var = '';

    /** @var bool Return value of the is_404() stub */
    private static $is_404 = false;

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
        self::$is_404              = false;

        Functions\when('sanitize_text_field')->returnArg(1);
        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('wp_cache_get')->justReturn(false);
        Functions\when('wp_cache_set')->justReturn(true);
        Functions\when('wp_parse_url')->alias('parse_url');
        Functions\when('wp_list_pluck')->alias(function ($list, $field) {
            return array_map(function ($item) use ($field) {
                return is_object($item) ? $item->$field : $item[$field];
            }, (array) $list);
        });
        Functions\when('home_url')->alias(function ($path = '') {
            return 'https://example.test' . $path;
        });
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
        Functions\when('is_404')->alias(function () {
            return self::$is_404;
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
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Case 3: bare URL + stored preference → enter the preferred language
    // -----------------------------------------------------------------

    public function test_bare_url_with_stored_preference_redirects_to_prefixed_url() {
        $_SERVER['REQUEST_URI'] = '/locatie/molen/?p=2';
        $_COOKIE['stm_lang']    = 'nl';

        $redirect = Frontend::get_language_preference_redirect();

        $this->assertSame('nl', $redirect['lang']);
        $this->assertSame('https://example.test/nl/locatie/molen/?p=2', $redirect['url']);
    }

    public function test_bare_homepage_with_stored_preference_redirects_to_language_root() {
        $_SERVER['REQUEST_URI'] = '/';
        $_COOKIE['stm_lang']    = 'nl';

        $redirect = Frontend::get_language_preference_redirect();

        $this->assertSame('https://example.test/nl/', $redirect['url']);
    }

    public function test_stored_default_language_does_not_redirect() {
        $_SERVER['REQUEST_URI'] = '/locatie/molen/';
        $_COOKIE['stm_lang']    = 'en';

        $this->assertNull(Frontend::get_language_preference_redirect());
    }

    public function test_stored_inactive_language_does_not_redirect() {
        $_SERVER['REQUEST_URI'] = '/locatie/molen/';
        $_COOKIE['stm_lang']    = 'de';

        $this->assertNull(Frontend::get_language_preference_redirect());
    }

    public function test_stored_invalid_language_code_does_not_redirect() {
        $_SERVER['REQUEST_URI'] = '/locatie/molen/';
        $_COOKIE['stm_lang']    = 'xx';

        $this->assertNull(Frontend::get_language_preference_redirect());
    }

    // -----------------------------------------------------------------
    // Explicit language in the URL always wins over the cookie
    // -----------------------------------------------------------------

    public function test_prefixed_url_is_never_redirected() {
        self::$lang_query_var   = 'nl';
        $_SERVER['REQUEST_URI'] = '/nl/locatie/molen/';
        $_COOKIE['stm_lang']    = 'nl';

        $this->assertNull(Frontend::get_language_preference_redirect());
    }

    public function test_prefixed_url_beats_a_different_stored_preference() {
        // Deep link to /nl/ while the cookie still says... the cookie is
        // simply ignored: the URL's language is explicit.
        self::$lang_query_var   = 'nl';
        $_SERVER['REQUEST_URI'] = '/nl/locatie/molen/';
        $_COOKIE['stm_lang']    = 'en';

        $this->assertNull(Frontend::get_language_preference_redirect());
    }

    // -----------------------------------------------------------------
    // Case 1: ?lang= — explicit choice, canonicalized
    // -----------------------------------------------------------------

    public function test_lang_param_redirects_to_prefixed_canonical_url() {
        $_SERVER['REQUEST_URI'] = '/locatie/molen/?lang=nl&p=2';
        $_GET['lang']           = 'nl';

        $redirect = Frontend::get_language_preference_redirect();

        $this->assertSame('nl', $redirect['lang']);
        $this->assertSame('https://example.test/nl/locatie/molen/?p=2', $redirect['url']);
    }

    public function test_lang_param_for_default_language_redirects_to_bare_url() {
        // The language switcher's default-language link: /page?lang=en
        // (a bare link would bounce straight back to the cookie language).
        $_SERVER['REQUEST_URI'] = '/locatie/molen/?lang=en';
        $_GET['lang']           = 'en';
        $_COOKIE['stm_lang']    = 'nl';

        $redirect = Frontend::get_language_preference_redirect();

        $this->assertSame('en', $redirect['lang']);
        $this->assertSame('https://example.test/locatie/molen/', $redirect['url']);
    }

    public function test_lang_param_default_on_prefixed_path_strips_the_prefix() {
        $_SERVER['REQUEST_URI'] = '/nl/locatie/molen/?lang=en';
        $_GET['lang']           = 'en';
        self::$lang_query_var   = 'nl';

        $redirect = Frontend::get_language_preference_redirect();

        $this->assertSame('en', $redirect['lang']);
        $this->assertSame('https://example.test/locatie/molen/', $redirect['url']);
    }

    public function test_inactive_lang_param_does_not_redirect() {
        $_SERVER['REQUEST_URI'] = '/locatie/molen/?lang=de';
        $_GET['lang']           = 'de';

        $this->assertNull(Frontend::get_language_preference_redirect());
    }

    // -----------------------------------------------------------------
    // Case 2: typed /{default}/ prefix — explicit default choice
    // -----------------------------------------------------------------

    public function test_typed_default_prefix_redirects_to_bare_url() {
        // No rewrite rule exists for the default language, so the query var
        // stays empty — the raw path is the only signal.
        $_SERVER['REQUEST_URI'] = '/en/locatie/molen/';
        $_COOKIE['stm_lang']    = 'nl';

        $redirect = Frontend::get_language_preference_redirect();

        $this->assertSame('en', $redirect['lang']);
        $this->assertSame('https://example.test/locatie/molen/', $redirect['url']);
    }

    // -----------------------------------------------------------------
    // Guards
    // -----------------------------------------------------------------

    public function test_404_with_stored_preference_is_not_redirected() {
        $_SERVER['REQUEST_URI'] = '/bestaat-niet/';
        $_COOKIE['stm_lang']    = 'nl';
        self::$is_404           = true;

        $this->assertNull(Frontend::get_language_preference_redirect());
    }

    public function test_disabled_url_routing_never_redirects() {
        self::$url_routing_enabled = false;
        $_SERVER['REQUEST_URI']    = '/locatie/molen/';
        $_COOKIE['stm_lang']       = 'nl';

        $this->assertNull(Frontend::get_language_preference_redirect());
    }

    public function test_post_request_is_not_redirected() {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI']    = '/locatie/molen/';
        $_COOKIE['stm_lang']       = 'nl';

        $this->assertNull(Frontend::get_language_preference_redirect());
    }
}
