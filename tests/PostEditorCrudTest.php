<?php
/**
 * PHPUnit tests: post/page editor translation CRUD (title, content,
 * excerpt, slug) — save (create/update), read, and delete, including the
 * new DELETE /stm/v1/posts/{id}/translations/{lang} route and its
 * edit_post permission check.
 */

namespace STM\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use STM\API;
use STM\PostEditor;
use STM\Tests\Fakes\FakeWpdb;

class PostEditorCrudTest extends TestCase {

    /** @var FakeWpdb */
    private $wpdb;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        global $wpdb;
        $wpdb = new FakeWpdb();
        $this->wpdb = $wpdb;

        $_POST = [];

        Functions\when('sanitize_text_field')->returnArg(1);
        Functions\when('sanitize_textarea_field')->returnArg(1);
        Functions\when('sanitize_title')->alias(function ($v) {
            return strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', (string) $v), '-'));
        });
        Functions\when('wp_kses_post')->returnArg(1);
        Functions\when('wp_kses')->returnArg(1);
        Functions\when('current_time')->justReturn('2026-07-17 00:00:00');
        Functions\when('get_current_user_id')->justReturn(1);
        Functions\when('wp_generate_uuid4')->justReturn('fixed-uuid');
        Functions\when('rest_ensure_response')->returnArg(1);
        Functions\when('wp_cache_get')->justReturn(false);
        Functions\when('wp_cache_set')->justReturn(true);
        Functions\when('wp_cache_delete')->justReturn(true);
        Functions\when('get_option')->justReturn(false);
        Functions\when('__')->returnArg(1);

        // stm_translations_nonce check inside PostEditor::save_translations()
        $_POST['stm_translations_nonce'] = 'valid-nonce';
        Functions\when('wp_verify_nonce')->justReturn(true);
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function seedLanguages() {
        $this->wpdb->seed('wp_stm_languages', [
            'code' => 'en', 'name' => 'English', 'is_active' => 1, 'is_default' => 1, 'order_index' => 1,
        ]);
        $this->wpdb->seed('wp_stm_languages', [
            'code' => 'nl', 'name' => 'Dutch', 'is_active' => 1, 'is_default' => 0, 'order_index' => 2,
        ]);
    }

    // -----------------------------------------------------------------
    // Create + read (PostEditor::save_translations / get_post_translation)
    // -----------------------------------------------------------------

    public function test_save_translations_creates_new_rows_for_each_field() {
        Functions\when('current_user_can')->justReturn(true);

        $_POST['stm_post_language'] = 'en';
        $_POST['stm_translations'] = [
            'nl' => [
                'post_title'   => 'Nederlandse titel',
                'post_name'    => 'nederlandse-titel',
                'post_excerpt' => 'Korte samenvatting',
                'post_content' => '<p>Inhoud</p>',
            ],
        ];

        PostEditor::save_translations(42, (object) ['ID' => 42]);

        $translation = PostEditor::get_post_translation(42, 'nl');

        $this->assertSame('Nederlandse titel', $translation['post_title']);
        $this->assertSame('nederlandse-titel', $translation['post_name']);
        $this->assertSame('Korte samenvatting', $translation['post_excerpt']);
        $this->assertSame('<p>Inhoud</p>', $translation['post_content']);
    }

    public function test_save_translations_updates_existing_row_instead_of_duplicating() {
        Functions\when('current_user_can')->justReturn(true);

        $this->wpdb->seed('wp_stm_post_translations', [
            'post_id' => 42, 'field_name' => 'post_title', 'language_code' => 'nl', 'translation' => 'Oude titel',
        ]);

        $_POST['stm_post_language'] = 'en';
        $_POST['stm_translations'] = [
            'nl' => ['post_title' => 'Nieuwe titel'],
        ];

        PostEditor::save_translations(42, (object) ['ID' => 42]);

        $rows = array_values(array_filter($this->wpdb->all('wp_stm_post_translations'), function ($r) {
            return $r['post_id'] === 42 && $r['field_name'] === 'post_title' && $r['language_code'] === 'nl';
        }));

        $this->assertCount(1, $rows, 'Saving an existing field must update it in place, not insert a duplicate row.');
        $this->assertSame('Nieuwe titel', $rows[0]['translation']);
    }

    public function test_save_translations_deletes_row_when_field_cleared() {
        Functions\when('current_user_can')->justReturn(true);

        $this->wpdb->seed('wp_stm_post_translations', [
            'post_id' => 42, 'field_name' => 'post_title', 'language_code' => 'nl', 'translation' => 'Titel',
        ]);

        $_POST['stm_post_language'] = 'en';
        $_POST['stm_translations'] = [
            'nl' => ['post_title' => ''],
        ];

        PostEditor::save_translations(42, (object) ['ID' => 42]);

        $translation = PostEditor::get_post_translation(42, 'nl');
        $this->assertArrayNotHasKey('post_title', $translation);
    }

    public function test_save_translations_noop_without_capability() {
        Functions\when('current_user_can')->justReturn(false);

        $_POST['stm_post_language'] = 'en';
        $_POST['stm_translations'] = [
            'nl' => ['post_title' => 'Should not be saved'],
        ];

        PostEditor::save_translations(42, (object) ['ID' => 42]);

        $this->assertSame([], $this->wpdb->all('wp_stm_post_translations'));
    }

    // -----------------------------------------------------------------
    // Delete — REST route (API::delete_post_translation) + permission check
    // -----------------------------------------------------------------

    public function test_delete_post_translation_removes_all_fields_for_that_language() {
        $this->wpdb->seed('wp_stm_post_translations', [
            'post_id' => 42, 'field_name' => 'post_title', 'language_code' => 'nl', 'translation' => 'Titel',
        ]);
        $this->wpdb->seed('wp_stm_post_translations', [
            'post_id' => 42, 'field_name' => 'post_content', 'language_code' => 'nl', 'translation' => 'Inhoud',
        ]);
        $this->wpdb->seed('wp_stm_post_translations', [
            'post_id' => 42, 'field_name' => 'post_title', 'language_code' => 'fr', 'translation' => 'Titre',
        ]);
        $this->seedLanguages();

        Functions\when('get_post')->justReturn((object) ['ID' => 42]);

        $request = new FakeRestRequest(['id' => '42', 'lang' => 'nl']);
        $response = API::delete_post_translation($request);

        $this->assertSame(['success' => true, 'deleted' => 2], $response);

        $remaining = $this->wpdb->all('wp_stm_post_translations');
        $this->assertCount(1, $remaining);
        $this->assertSame('fr', $remaining[0]['language_code']);
    }

    public function test_delete_post_translation_returns_404_for_missing_post() {
        Functions\when('get_post')->justReturn(null);

        $request = new FakeRestRequest(['id' => '999', 'lang' => 'nl']);
        $response = API::delete_post_translation($request);

        $this->assertInstanceOf(\WP_Error::class, $response);
        $this->assertSame('not_found', $response->get_error_code());
    }

    public function test_delete_post_translation_returns_400_for_invalid_language_code() {
        Functions\when('get_post')->justReturn((object) ['ID' => 42]);

        $request = new FakeRestRequest(['id' => '42', 'lang' => '123']);
        $response = API::delete_post_translation($request);

        $this->assertInstanceOf(\WP_Error::class, $response);
        $this->assertSame('invalid_language', $response->get_error_code());
    }

    public function test_check_edit_post_permission_reflects_edit_post_capability() {
        $request = new FakeRestRequest(['id' => '42']);

        Functions\when('current_user_can')->alias(function ($cap, $postId = null) {
            return $cap === 'edit_post' && $postId === 42;
        });
        $this->assertTrue(API::check_edit_post_permission($request));

        Functions\when('current_user_can')->justReturn(false);
        $this->assertFalse(API::check_edit_post_permission($request));
    }
}

/**
 * Minimal WP_REST_Request stand-in — array access is all these callbacks use.
 */
class FakeRestRequest implements \ArrayAccess {
    private $params;

    public function __construct(array $params) {
        $this->params = $params;
    }

    public function offsetExists($offset): bool { return isset($this->params[$offset]); }
    public function offsetGet($offset): mixed { return $this->params[$offset] ?? null; }
    public function offsetSet($offset, $value): void { $this->params[$offset] = $value; }
    public function offsetUnset($offset): void { unset($this->params[$offset]); }
}
