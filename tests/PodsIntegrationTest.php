<?php
/**
 * PHPUnit tests: Pods custom-field translation integration.
 *
 * Covers detection, translatable-field discovery (including exclusion of
 * relationship-shaped field types), the meta box add/render path, the
 * storage round-trip (reusing stm_post_translations via FakeWpdb, same as
 * PostEditor/ElementorIntegration), and the stm_get_pods_translation()
 * template function.
 */

namespace STM\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use STM\PodsIntegration;
use STM\Tests\Fakes\FakeWpdb;

class PodsIntegrationTest extends TestCase {

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
        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('wp_kses')->returnArg(1);
        Functions\when('wp_cache_get')->justReturn(false);
        Functions\when('wp_cache_set')->justReturn(true);
        Functions\when('wp_cache_delete')->justReturn(true);
        Functions\when('get_option')->justReturn('en');
        Functions\when('__')->returnArg(1);
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function seedLanguages() {
        $this->wpdb->seed('wp_stm_languages', [
            'code' => 'en', 'name' => 'English', 'flag_emoji' => '🇬🇧', 'is_active' => 1, 'is_default' => 1, 'order_index' => 1,
        ]);
        $this->wpdb->seed('wp_stm_languages', [
            'code' => 'nl', 'name' => 'Dutch', 'flag_emoji' => '🇳🇱', 'is_active' => 1, 'is_default' => 0, 'order_index' => 2,
        ]);
    }

    /** Registers a fake pods_api() returning the given pod definition for any pod name. */
    private function stubPodsApi(array $pod) {
        $api = new FakePodsApi($pod);
        Functions\when('pods_api')->alias(function () use ($api) { return $api; });
    }

    // -----------------------------------------------------------------
    // Detection — must run before any test mocks pods_api(), since
    // Brain Monkey/Patchwork permanently defines a mocked function for the
    // rest of the process once it's first stubbed.
    // -----------------------------------------------------------------

    public function test_is_pods_active_false_when_pods_not_loaded() {
        $this->assertFalse(PodsIntegration::is_pods_active());
    }

    public function test_is_pods_active_true_when_pods_api_available() {
        $this->stubPodsApi(['name' => 'movie', 'fields' => []]);

        $this->assertTrue(PodsIntegration::is_pods_active());
    }

    // -----------------------------------------------------------------
    // Pod / field discovery
    // -----------------------------------------------------------------

    public function test_get_pod_name_for_post_type_returns_pod_name() {
        $this->stubPodsApi(['name' => 'movie', 'fields' => []]);

        $this->assertSame('movie', PodsIntegration::get_pod_name_for_post_type('movie'));
    }

    public function test_get_pod_name_for_post_type_returns_null_when_not_a_pod() {
        $this->stubPodsApi([]);

        $this->assertNull(PodsIntegration::get_pod_name_for_post_type('post'));
    }

    public function test_get_translatable_fields_includes_text_like_types_only() {
        $this->stubPodsApi([
            'name' => 'movie',
            'fields' => [
                'tagline'   => ['label' => 'Tagline', 'type' => 'text'],
                'synopsis'  => ['label' => 'Synopsis', 'type' => 'wysiwyg'],
                'rating'    => ['label' => 'Rating', 'type' => 'number'],
                'director'  => ['label' => 'Director', 'type' => 'pick'],
                'poster'    => ['label' => 'Poster', 'type' => 'file'],
            ],
        ]);

        $fields = PodsIntegration::get_translatable_fields('movie');

        $this->assertSame(['tagline', 'synopsis'], array_keys($fields));
        $this->assertSame('Tagline', $fields['tagline']['label']);
        $this->assertSame('text', $fields['tagline']['type']);
    }

    public function test_get_translatable_fields_returns_empty_array_for_unknown_pod() {
        $this->stubPodsApi([]);

        $this->assertSame([], PodsIntegration::get_translatable_fields('nonexistent'));
    }

    public function test_is_relationship_field_type_true_for_pick_and_file() {
        $this->assertTrue(PodsIntegration::is_relationship_field_type('pick'));
        $this->assertTrue(PodsIntegration::is_relationship_field_type('file'));
        $this->assertTrue(PodsIntegration::is_relationship_field_type('taxonomy'));
    }

    public function test_is_relationship_field_type_false_for_text() {
        $this->assertFalse(PodsIntegration::is_relationship_field_type('text'));
        $this->assertFalse(PodsIntegration::is_relationship_field_type('wysiwyg'));
    }

    // -----------------------------------------------------------------
    // Meta box registration
    // -----------------------------------------------------------------

    public function test_add_meta_box_registers_for_a_post_type_with_translatable_fields() {
        Functions\when('get_post_types')->justReturn(['movie']);
        $this->stubPodsApi([
            'name' => 'movie',
            'fields' => ['tagline' => ['label' => 'Tagline', 'type' => 'text']],
        ]);
        $registered = [];
        Functions\when('add_meta_box')->alias(function ($id, $title, $cb, $post_type) use (&$registered) {
            $registered[] = $post_type;
        });

        PodsIntegration::add_meta_box();

        $this->assertSame(['movie'], $registered);
    }

    public function test_add_meta_box_skips_post_types_with_no_translatable_fields() {
        Functions\when('get_post_types')->justReturn(['page']);
        $this->stubPodsApi(['name' => 'page', 'fields' => ['rating' => ['label' => 'Rating', 'type' => 'number']]]);

        $registered = [];
        Functions\when('add_meta_box')->alias(function ($id, $title, $cb, $post_type) use (&$registered) {
            $registered[] = $post_type;
        });

        PodsIntegration::add_meta_box();

        $this->assertEmpty($registered);
    }

    // -----------------------------------------------------------------
    // Meta box rendering
    // -----------------------------------------------------------------

    private function stubTemplateEscaping() {
        Functions\when('esc_html')->returnArg(1);
        Functions\when('esc_attr')->returnArg(1);
        Functions\when('esc_textarea')->returnArg(1);
        Functions\when('esc_url')->returnArg(1);
        Functions\when('admin_url')->justReturn('http://example.test/wp-admin/admin.php?page=stm-languages');
        Functions\when('wp_nonce_field')->justReturn('');
    }

    public function test_render_meta_box_shows_translatable_fields_as_tabs() {
        $this->seedLanguages(); // en (default) + nl
        $this->stubPodsApi([
            'name' => 'movie',
            'fields' => [
                'tagline' => ['label' => 'Tagline', 'type' => 'text'],
                'director' => ['label' => 'Director', 'type' => 'pick'],
            ],
        ]);
        $this->wpdb->seed('wp_stm_post_translations', [
            'post_id' => 42, 'field_name' => 'tagline', 'language_code' => 'nl', 'translation' => 'Vertaalde tagline',
        ]);
        $this->stubTemplateEscaping();

        ob_start();
        PodsIntegration::render_meta_box((object) ['ID' => 42, 'post_type' => 'movie']);
        $html = ob_get_clean();

        $this->assertStringContainsString('data-lang="nl"', $html);
        $this->assertStringContainsString('Vertaalde tagline', $html);
        $this->assertStringContainsString('stm_pods_translations[nl][tagline]', $html);
        $this->assertStringNotContainsString('[director]', $html, 'A relationship field must never get a text-translation input.');
    }

    public function test_render_meta_box_outputs_nothing_when_pod_has_no_translatable_fields() {
        $this->seedLanguages();
        $this->stubPodsApi(['name' => 'movie', 'fields' => ['rating' => ['label' => 'Rating', 'type' => 'number']]]);
        $this->stubTemplateEscaping();

        ob_start();
        PodsIntegration::render_meta_box((object) ['ID' => 42, 'post_type' => 'movie']);
        $html = ob_get_clean();

        $this->assertSame('', $html);
    }

    // -----------------------------------------------------------------
    // Storage
    // -----------------------------------------------------------------

    public function test_get_post_pods_translations_filters_to_given_field_names() {
        $this->wpdb->seed('wp_stm_post_translations', [
            'post_id' => 42, 'field_name' => 'tagline', 'language_code' => 'nl', 'translation' => 'Vertaald',
        ]);
        $this->wpdb->seed('wp_stm_post_translations', [
            'post_id' => 42, 'field_name' => 'post_title', 'language_code' => 'nl', 'translation' => 'Titel',
        ]);

        $result = PodsIntegration::get_post_pods_translations(42, 'nl', ['tagline']);

        $this->assertSame(['tagline' => 'Vertaald'], $result);
    }

    public function test_save_translations_rejects_without_valid_nonce() {
        Functions\when('wp_verify_nonce')->justReturn(false);
        $_POST['stm_pods_translations_nonce'] = 'bad';
        $_POST['stm_pods_translations'] = ['nl' => ['tagline' => 'Vertaald']];

        PodsIntegration::save_translations(42, (object) ['post_type' => 'movie']);

        $this->assertCount(0, $this->wpdb->all('stm_post_translations'));
    }

    public function test_save_translations_rejects_without_edit_permission() {
        Functions\when('wp_verify_nonce')->justReturn(true);
        Functions\when('current_user_can')->justReturn(false);
        $_POST['stm_pods_translations_nonce'] = 'ok';
        $_POST['stm_pods_translations'] = ['nl' => ['tagline' => 'Vertaald']];

        PodsIntegration::save_translations(42, (object) ['post_type' => 'movie']);

        $this->assertCount(0, $this->wpdb->all('stm_post_translations'));
    }

    public function test_save_translations_persists_translatable_field() {
        Functions\when('wp_verify_nonce')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
        $this->stubPodsApi([
            'name' => 'movie',
            'fields' => ['tagline' => ['label' => 'Tagline', 'type' => 'text']],
        ]);
        $_POST['stm_pods_translations_nonce'] = 'ok';
        $_POST['stm_pods_translations'] = ['nl' => ['tagline' => 'Vertaalde tagline']];

        PodsIntegration::save_translations(42, (object) ['post_type' => 'movie']);

        $rows = $this->wpdb->all('stm_post_translations');
        $this->assertCount(1, $rows);
        $this->assertSame('tagline', $rows[0]['field_name']);
        $this->assertSame('nl', $rows[0]['language_code']);
        $this->assertSame('Vertaalde tagline', $rows[0]['translation']);
    }

    public function test_save_translations_ignores_a_field_the_pod_does_not_declare() {
        Functions\when('wp_verify_nonce')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
        $this->stubPodsApi([
            'name' => 'movie',
            'fields' => ['tagline' => ['label' => 'Tagline', 'type' => 'text']],
        ]);
        $_POST['stm_pods_translations_nonce'] = 'ok';
        // 'not_a_real_field' is not in the pod's own declared field list — must not
        // be writable via this form, even though it passes basic sanitization.
        $_POST['stm_pods_translations'] = ['nl' => ['not_a_real_field' => 'Injected']];

        PodsIntegration::save_translations(42, (object) ['post_type' => 'movie']);

        $this->assertCount(0, $this->wpdb->all('stm_post_translations'));
    }

    public function test_save_translations_deletes_existing_row_when_value_emptied() {
        Functions\when('wp_verify_nonce')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
        $this->stubPodsApi([
            'name' => 'movie',
            'fields' => ['tagline' => ['label' => 'Tagline', 'type' => 'text']],
        ]);
        $this->wpdb->seed('wp_stm_post_translations', [
            'post_id' => 42, 'field_name' => 'tagline', 'language_code' => 'nl', 'translation' => 'Oude tekst',
        ]);
        $_POST['stm_pods_translations_nonce'] = 'ok';
        $_POST['stm_pods_translations'] = ['nl' => ['tagline' => '']];

        PodsIntegration::save_translations(42, (object) ['post_type' => 'movie']);

        $this->assertCount(0, $this->wpdb->all('stm_post_translations'));
    }

    public function test_save_translations_ignores_invalid_language_code() {
        Functions\when('wp_verify_nonce')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
        $this->stubPodsApi([
            'name' => 'movie',
            'fields' => ['tagline' => ['label' => 'Tagline', 'type' => 'text']],
        ]);
        $_POST['stm_pods_translations_nonce'] = 'ok';
        $_POST['stm_pods_translations'] = ['not-a-lang!' => ['tagline' => 'x']];

        PodsIntegration::save_translations(42, (object) ['post_type' => 'movie']);

        $this->assertCount(0, $this->wpdb->all('stm_post_translations'));
    }

    public function test_save_translations_no_ops_when_post_type_is_not_a_pod() {
        Functions\when('wp_verify_nonce')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
        $this->stubPodsApi([]);
        $_POST['stm_pods_translations_nonce'] = 'ok';
        $_POST['stm_pods_translations'] = ['nl' => ['tagline' => 'x']];

        PodsIntegration::save_translations(42, (object) ['post_type' => 'post']);

        $this->assertCount(0, $this->wpdb->all('stm_post_translations'));
    }

    // -----------------------------------------------------------------
    // Template function: stm_get_pods_translation()
    // -----------------------------------------------------------------

    public function test_stm_get_pods_translation_returns_stored_translation() {
        $this->wpdb->seed('wp_stm_post_translations', [
            'post_id' => 42, 'field_name' => 'tagline', 'language_code' => 'nl', 'translation' => 'Vertaalde tagline',
        ]);

        $result = stm_get_pods_translation('movie', 'tagline', 'nl', 42);

        $this->assertSame('Vertaalde tagline', $result);
    }

    public function test_stm_get_pods_translation_falls_back_to_live_pods_field_value() {
        Functions\when('pods_field')->justReturn('Live tagline');

        $result = stm_get_pods_translation('movie', 'tagline', 'nl', 42);

        $this->assertSame('Live tagline', $result);
    }

    public function test_stm_get_pods_translation_falls_back_to_post_meta_when_pods_field_returns_nothing() {
        // Pods is active (pods_field exists) but has no value for this field —
        // must still fall through to the raw post meta rather than returning null.
        Functions\when('pods_field')->justReturn(null);
        Functions\when('get_post_meta')->justReturn('Meta tagline');

        $result = stm_get_pods_translation('movie', 'tagline', 'nl', 42);

        $this->assertSame('Meta tagline', $result);
    }

    public function test_stm_get_pods_translation_passes_through_relationship_value_untouched() {
        // A 'pick' relationship field's live value is an array of related IDs/objects —
        // stm_get_pods_translation() must hand it back as-is, never coerce it to a string.
        Functions\when('pods_field')->justReturn([12, 34]);

        $result = stm_get_pods_translation('movie', 'director', 'nl', 42);

        $this->assertSame([12, 34], $result);
    }

    public function test_stm_get_pods_translation_defaults_post_id_to_current_post() {
        Functions\when('get_the_ID')->justReturn(99);
        Functions\when('pods_field')->justReturn('Current post tagline');

        $result = stm_get_pods_translation('movie', 'tagline', 'nl');

        $this->assertSame('Current post tagline', $result);
    }

    public function test_stm_get_pods_translation_returns_empty_string_without_a_post_id() {
        Functions\when('get_the_ID')->justReturn(false);

        $this->assertSame('', stm_get_pods_translation('movie', 'tagline', 'nl'));
    }
}

/**
 * Minimal stand-in for Pods' own PodsAPI object — only the one method this
 * integration calls, `load_pod()`, which normally accepts `['name' => ...]`
 * and returns the pod's definition array (or an empty array when no pod by
 * that name exists).
 */
class FakePodsApi {
    private $pod;

    public function __construct(array $pod) {
        $this->pod = $pod;
    }

    public function load_pod(array $args = []) {
        return $this->pod;
    }
}
