<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.test.restpublicroutestest

/**
 * PHPUnit tests: what the REST API shows to a caller who is not logged in (task 5144).
 *
 *  - The routes that use __return_true as permission_callback are exactly the known
 *    read-only GET routes. Nothing that writes, imports, exports or auto-translates is public.
 *  - Translations and slugs of drafts, private and password-protected posts are not returned
 *    to the public, but are returned to a user who can edit the post.
 *  - Translations that are not published yet are only listed for administrators.
 */

namespace STM\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use STM\API;
use STM\AutoTranslate;
use STM\ImportExport;
use STM\TranslationMemory;
use STM\ElementorIntegration;
use STM\Tests\Fakes\RecordingWpdb;

class RestPublicRoutesTest extends TestCase {

    /** @var RecordingWpdb */
    private $wpdb;

    /** @var array<int,array{route:string,args:array}> */
    private $routes = [];

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        if (!defined('ABSPATH')) {
            define('ABSPATH', dirname(__DIR__) . '/');
        }
        require_once dirname(__DIR__) . '/includes/class-auto-translate.php';
        require_once dirname(__DIR__) . '/includes/class-translation-memory.php';
        require_once dirname(__DIR__) . '/includes/class-import-export.php';

        global $wpdb;
        $wpdb = new RecordingWpdb();
        $this->wpdb = $wpdb;

        Functions\when('rest_ensure_response')->alias(function ($data) {
            return new RestRoutesFakeResponse($data);
        });
        Functions\when('sanitize_text_field')->returnArg(1);
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Which routes are public
    // -----------------------------------------------------------------

    private function collectRoutes(): void {
        $this->routes = [];
        Functions\when('register_rest_route')->alias(function ($namespace, $route, $args) {
            $this->routes[] = ['route' => $route, 'args' => $args];
            return true;
        });
        Functions\when('add_action')->justReturn(true);
        Functions\when('current_user_can')->justReturn(false);

        API::register_routes();
        AutoTranslate::register_routes();
        TranslationMemory::register_routes();
        ImportExport::register_routes();
        if (method_exists(ElementorIntegration::class, 'register_routes')) {
            ElementorIntegration::register_routes();
        }
    }

    public function test_only_the_known_read_only_get_routes_are_public() {
        $this->collectRoutes();

        $public = [];
        foreach ($this->routes as $r) {
            if (($r['args']['permission_callback'] ?? null) === '__return_true') {
                $public[] = $r['args']['methods'] . ' ' . $r['route'];
            }
        }
        sort($public);

        $this->assertSame([
            'GET /languages',
            'GET /posts/(?P<id>\d+)/slugs',
            'GET /posts/(?P<id>\d+)/translations',
            'GET /strings',
            'GET /strings/(?P<id>\d+)',
            'GET /translations',
        ], $public, 'A route became public (or stopped being public). Review it, then update this list on purpose.');
    }

    public function test_every_other_route_has_a_real_permission_callback() {
        $this->collectRoutes();

        $checked = 0;
        foreach ($this->routes as $r) {
            $callback = $r['args']['permission_callback'] ?? null;
            if ($callback === '__return_true') {
                continue;
            }
            $this->assertIsCallable($callback, $r['args']['methods'] . ' ' . $r['route'] . ' has no permission callback');
            $checked++;
        }
        $this->assertGreaterThan(20, $checked);
    }

    public function test_no_public_route_can_change_anything() {
        $this->collectRoutes();

        foreach ($this->routes as $r) {
            if (($r['args']['permission_callback'] ?? null) === '__return_true') {
                $this->assertSame('GET', $r['args']['methods'], $r['route'] . ' is public and not read-only');
            }
        }
    }

    public function test_management_callbacks_refuse_a_visitor() {
        Functions\when('current_user_can')->justReturn(false);
        $this->assertFalse(API::check_permissions());
    }

    // -----------------------------------------------------------------
    // Post translations / slugs of non-public posts
    // -----------------------------------------------------------------

    private function post(string $status, string $password = '', int $id = 7) {
        return (object) ['ID' => $id, 'post_status' => $status, 'post_password' => $password, 'post_type' => 'post'];
    }

    private function stubPost($post, bool $canEdit): void {
        Functions\when('get_post')->justReturn($post);
        Functions\when('current_user_can')->alias(function ($cap, $id = null) use ($canEdit) {
            return $cap === 'edit_post' ? $canEdit : false;
        });
        Functions\when('is_post_publicly_viewable')->alias(function ($p) {
            return $p->post_status === 'publish';
        });
        $this->wpdb->insert('wp_stm_post_translations', ['post_id' => 7, 'field_name' => 'post_title', 'language_code' => 'nl', 'translation' => 'Geheime titel']);
        $this->wpdb->insert('wp_stm_post_translations', ['post_id' => 7, 'field_name' => 'post_name', 'language_code' => 'nl', 'translation' => 'geheime-slug']);
    }

    public function test_published_post_translations_are_public() {
        $this->stubPost($this->post('publish'), false);

        $response = API::get_post_translations(new \ArrayObject(['id' => 7]));

        $this->assertInstanceOf(RestRoutesFakeResponse::class, $response);
        $this->assertSame('Geheime titel', $response->data['nl']['post_title']);
    }

    public function test_draft_post_translations_look_like_a_missing_post_to_a_visitor() {
        $this->stubPost($this->post('draft'), false);

        $response = API::get_post_translations(new \ArrayObject(['id' => 7]));

        $this->assertInstanceOf(\WP_Error::class, $response);
        $this->assertSame('not_found', $response->get_error_code());
        $this->assertSame(404, $response->get_error_data()['status']);
        $this->assertSame('STM-E-API-GET-POST-TRANSLATIONS-NOT-PUBLIC', $response->get_error_data()['stm_diag']);
    }

    public function test_private_and_password_protected_posts_are_not_public() {
        foreach ([$this->post('private'), $this->post('publish', 'secret')] as $post) {
            $this->stubPost($post, false);
            $response = API::get_post_translations(new \ArrayObject(['id' => 7]));
            $this->assertInstanceOf(\WP_Error::class, $response, $post->post_status . '/' . $post->post_password);
        }
    }

    public function test_a_user_who_can_edit_the_draft_still_gets_its_translations() {
        $this->stubPost($this->post('draft'), true);

        $response = API::get_post_translations(new \ArrayObject(['id' => 7]));

        $this->assertInstanceOf(RestRoutesFakeResponse::class, $response);
        $this->assertSame('Geheime titel', $response->data['nl']['post_title']);
    }

    public function test_slugs_of_a_draft_are_empty_for_a_visitor_and_present_for_an_editor() {
        $this->stubPost($this->post('draft'), false);
        $this->assertSame([], API::get_post_slugs(new \ArrayObject(['id' => 7]))->data);

        $this->stubPost($this->post('draft'), true);
        $this->assertSame(['nl' => 'geheime-slug'], API::get_post_slugs(new \ArrayObject(['id' => 7]))->data);

        $this->stubPost($this->post('publish'), false);
        $this->assertSame(['nl' => 'geheime-slug'], API::get_post_slugs(new \ArrayObject(['id' => 7]))->data);
    }

    public function test_slugs_of_a_post_that_does_not_exist_are_empty() {
        Functions\when('get_post')->justReturn(null);
        $this->assertSame([], API::get_post_slugs(new \ArrayObject(['id' => 999]))->data);
    }

    // -----------------------------------------------------------------
    // Unpublished UI-string translations
    // -----------------------------------------------------------------

    private function queryFor(bool $manager, callable $call): string {
        Functions\when('current_user_can')->justReturn($manager);
        $this->wpdb->queries = [];
        $call();
        return implode("\n", $this->wpdb->queries);
    }

    public function test_translation_list_hides_unpublished_rows_from_a_visitor_only() {
        $call = function () {
            API::get_translations(new RestRoutesFakeRequest(['string_id' => 0, 'lang' => '', 'per_page' => 10, 'page' => 1]));
        };

        $visitor = $this->queryFor(false, $call);
        $this->assertStringContainsString("(0 = 1 OR t.status = 'published')", $visitor);

        $admin = $this->queryFor(true, $call);
        $this->assertStringContainsString("(1 = 1 OR t.status = 'published')", $admin);
    }

    public function test_string_list_and_single_string_hide_unpublished_rows_from_a_visitor_only() {
        $list = function () {
            API::get_strings(new RestRoutesFakeRequest(['context' => '']));
        };
        $this->assertStringContainsString("AND (0 = 1 OR t.status = 'published')", $this->queryFor(false, $list));
        $this->assertStringContainsString("AND (1 = 1 OR t.status = 'published')", $this->queryFor(true, $list));

        $single = function () {
            API::get_string(new \ArrayObject(['id' => 3]));
        };
        $this->assertStringContainsString("AND (0 = 1 OR t.status = 'published')", $this->queryFor(false, $single));
        $this->assertStringContainsString("AND (1 = 1 OR t.status = 'published')", $this->queryFor(true, $single));
    }
}

/** Minimal WP_REST_Request stand-in: the callbacks under test only call get_param(). */
class RestRoutesFakeRequest {
    private $params;

    public function __construct(array $params) {
        $this->params = $params;
    }

    public function get_param($key) {
        return $this->params[$key] ?? null;
    }
}

/** Minimal WP_REST_Response stand-in: keeps the data and the headers. */
class RestRoutesFakeResponse {
    public $data;
    public $headers = [];

    public function __construct($data) {
        $this->data = $data;
    }

    public function header($key, $value) {
        $this->headers[$key] = $value;
    }
}
