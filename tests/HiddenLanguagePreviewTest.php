<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.test.hiddenlanguagepreviewtest

/**
 * PHPUnit tests: the editor's "Preview in language" link for a hidden
 * (inactive) language must survive WordPress's canonical redirect.
 *
 * STM builds /?page_id=91&preview=true&lang=en for a language that is not
 * live yet; core's redirect_canonical used to turn that into /slug/?lang=en
 * (preview dropped), so the preview showed the default language.
 */

namespace STM\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use STM\Frontend;
use STM\Tests\Fakes\FakeWpdb;

class HiddenLanguagePreviewTest extends TestCase {

    /** @var bool Return value of the current_user_can() stub */
    private static $can_edit = true;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        global $wpdb;
        $wpdb = new FakeWpdb();

        $_GET = [];
        self::$can_edit = true;

        Functions\when('sanitize_text_field')->returnArg(1);
        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('wp_cache_get')->justReturn(false);
        Functions\when('wp_cache_set')->justReturn(true);
        Functions\when('get_option')->justReturn('nl');
        Functions\when('current_user_can')->alias(function () {
            return self::$can_edit;
        });

        // nl = default (active), en = active, de = hidden (inactive)
        $wpdb->seed('wp_stm_languages', ['code' => 'nl', 'name' => 'Dutch', 'native_name' => 'Nederlands', 'flag_emoji' => '', 'is_active' => 1, 'is_default' => 1, 'order_index' => 1]);
        $wpdb->seed('wp_stm_languages', ['code' => 'en', 'name' => 'English', 'native_name' => 'English', 'flag_emoji' => '', 'is_active' => 1, 'is_default' => 0, 'order_index' => 2]);
        $wpdb->seed('wp_stm_languages', ['code' => 'de', 'name' => 'German', 'native_name' => 'Deutsch', 'flag_emoji' => '', 'is_active' => 0, 'is_default' => 0, 'order_index' => 3]);
    }

    protected function tearDown(): void {
        $_GET = [];
        Monkey\tearDown();
        parent::tearDown();
    }

    private const REDIRECT = 'https://example.test/kenia/?lang=de';

    public function test_editor_preview_of_a_hidden_language_is_not_redirected() {
        $_GET = ['page_id' => '91', 'preview' => 'true', 'lang' => 'de'];

        $this->assertFalse(Frontend::skip_canonical_redirect_for_language_preview(self::REDIRECT));
    }

    public function test_hidden_language_without_preview_keeps_the_normal_redirect() {
        $_GET = ['page_id' => '91', 'lang' => 'de'];

        $this->assertSame(self::REDIRECT, Frontend::skip_canonical_redirect_for_language_preview(self::REDIRECT));
    }

    public function test_preview_of_an_active_language_keeps_the_normal_redirect() {
        $_GET = ['page_id' => '91', 'preview' => 'true', 'lang' => 'en'];

        $this->assertSame(self::REDIRECT, Frontend::skip_canonical_redirect_for_language_preview(self::REDIRECT));
    }

    public function test_a_visitor_who_cannot_edit_never_skips_the_redirect() {
        self::$can_edit = false;
        $_GET = ['page_id' => '91', 'preview' => 'true', 'lang' => 'de'];

        $this->assertSame(self::REDIRECT, Frontend::skip_canonical_redirect_for_language_preview(self::REDIRECT));
    }

    public function test_garbage_language_code_keeps_the_normal_redirect() {
        $_GET = ['preview' => 'true', 'lang' => '../etc'];

        $this->assertSame(self::REDIRECT, Frontend::skip_canonical_redirect_for_language_preview(self::REDIRECT));
    }

    public function test_a_plain_request_is_untouched() {
        $this->assertSame(self::REDIRECT, Frontend::skip_canonical_redirect_for_language_preview(self::REDIRECT));
    }

    public function test_editor_sees_a_hidden_language_even_when_core_switched_is_preview_off() {
        // core's redirect_canonical() resets is_preview() for a published post without a nonce
        Functions\when('is_preview')->justReturn(false);
        Functions\when('get_queried_object_id')->justReturn(10);
        Functions\when('get_query_var')->alias(function ($var, $default = '') {
            return $var === 'lang' ? 'de' : $default;
        });
        $_GET = ['lang' => 'de', 'preview' => 'true'];

        $this->assertSame('de', Frontend::get_current_language());
    }

    public function test_a_visitor_never_sees_a_hidden_language_through_the_preview_parameter() {
        self::$can_edit = false;
        Functions\when('is_preview')->justReturn(false);
        Functions\when('get_queried_object_id')->justReturn(10);
        Functions\when('get_query_var')->alias(function ($var, $default = '') {
            return $var === 'lang' ? 'de' : $default;
        });
        $_GET = ['lang' => 'de', 'preview' => 'true'];

        $this->assertSame('nl', Frontend::get_current_language());
    }

    public function test_the_lang_parameter_alone_does_not_unlock_a_hidden_language() {
        Functions\when('is_preview')->justReturn(false);
        Functions\when('get_queried_object_id')->justReturn(10);
        Functions\when('get_query_var')->alias(function ($var, $default = '') {
            return $var === 'lang' ? 'de' : $default;
        });
        $_GET = ['lang' => 'de'];

        $this->assertSame('nl', Frontend::get_current_language());
    }
}
