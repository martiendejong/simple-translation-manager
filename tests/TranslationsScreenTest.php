<?php
/**
 * PHPUnit tests: task 869enr71g — when a language's translation is missing,
 * the Translation Strings screen should show the default language's text as
 * the input's placeholder, so whoever is managing translations can see what
 * the UI currently displays for that string instead of a generic hint.
 *
 * Covers:
 *   - Admin::get_translation_placeholder(), the pure decision logic.
 *   - The admin-translations.php template itself, rendered directly with a
 *     hand-built $strings/$languages/$translations_map (FakeWpdb's simple
 *     single-table SELECT parser can't represent the aliased/subquery SQL
 *     Admin::page_translations() issues, so the template is exercised the
 *     same way page_translations() would call it — via `include` sharing
 *     the calling scope's local variables — without going through wpdb).
 */

namespace STM\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use STM\Admin;

class TranslationsScreenTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        if (!defined('ABSPATH')) {
            define('ABSPATH', dirname(__DIR__) . '/');
        }
        require_once dirname(__DIR__) . '/includes/class-admin.php';
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Admin::get_translation_placeholder() — pure logic, no WP/DB needed.
    // -----------------------------------------------------------------

    public function test_placeholder_falls_back_to_default_translation_when_missing() {
        $this->assertSame(
            'Home',
            Admin::get_translation_placeholder('Home', false)
        );
    }

    public function test_placeholder_stays_generic_when_default_translation_is_also_missing() {
        $this->assertSame('Translation', Admin::get_translation_placeholder(null, false));
        $this->assertSame('Translation', Admin::get_translation_placeholder('', false));
    }

    public function test_placeholder_stays_generic_for_the_default_language_column_itself() {
        // The default language has no "other" default to fall back to.
        $this->assertSame('Translation', Admin::get_translation_placeholder('Home', true));
        $this->assertSame('Translation', Admin::get_translation_placeholder(null, true));
    }

    // -----------------------------------------------------------------
    // The rendered template.
    // -----------------------------------------------------------------

    private function stubTemplateFunctions() {
        Functions\when('esc_html')->returnArg(1);
        Functions\when('esc_attr')->returnArg(1);
        Functions\when('esc_url')->returnArg(1);
        Functions\when('selected')->justReturn('');
        Functions\when('admin_url')->justReturn('http://example.test/wp-admin/admin-post.php');
        Functions\when('wp_nonce_field')->justReturn('');
        Functions\when('absint')->alias(function ($v) { return abs(intval($v)); });
        Functions\when('add_query_arg')->justReturn('http://example.test/wp-admin/admin.php');
    }

    /** Renders templates/admin-translations.php with the given local vars, exactly as Admin::page_translations() includes it. */
    private function renderTemplate(array $vars) {
        extract($vars);
        ob_start();
        include STM_PLUGIN_DIR . 'templates/admin-translations.php';
        return ob_get_clean();
    }

    /** Isolates one language's <td> cell (up to its closing tag) so assertions can't bleed into a sibling language's cell. */
    private function extractLanguageCell($html, $lang_code) {
        $needle = 'name="language_code" value="' . $lang_code . '"';
        $start = strpos($html, $needle);
        if ($start === false) {
            return '';
        }
        $end = strpos($html, '</td>', $start);
        return substr($html, $start, $end - $start);
    }

    private function baseVars() {
        return [
            'context_filter' => '',
            'search'         => '',
            'contexts'       => ['general'],
            'total_items'    => 1,
            'total_pages'    => 1,
            'current_page'   => 1,
        ];
    }

    public function test_missing_translation_shows_default_language_text_as_placeholder() {
        $this->stubTemplateFunctions();

        $languages = [
            (object) ['code' => 'en', 'flag_emoji' => '🇬🇧'],
            (object) ['code' => 'nl', 'flag_emoji' => '🇳🇱'],
        ];
        $string = (object) ['id' => 1, 'string_key' => 'nav.home', 'context' => 'general', 'translated_count' => 1];
        $translations_map = [
            1 => [
                'en' => (object) ['translation' => 'Home'],
                // 'nl' intentionally has no translation yet.
            ],
        ];

        $html = $this->renderTemplate(array_merge($this->baseVars(), [
            'languages'         => $languages,
            'strings'           => [$string],
            'translations_map'  => $translations_map,
            'default_lang_code' => 'en',
        ]));

        $nl_cell = $this->extractLanguageCell($html, 'nl');
        $en_cell = $this->extractLanguageCell($html, 'en');

        // The Dutch cell's placeholder must show the English (default) text,
        // flagged as a default-language fallback...
        $this->assertStringContainsString('placeholder="Home"', $nl_cell);
        $this->assertStringContainsString('class="stm-placeholder-is-default"', $nl_cell);

        // ...while the English cell (the default language itself) keeps the generic placeholder and no fallback flag.
        $this->assertStringContainsString('placeholder="Translation"', $en_cell);
        $this->assertStringNotContainsString('class="stm-placeholder-is-default"', $en_cell);
    }

    public function test_own_translation_is_shown_instead_of_the_default_language_placeholder() {
        $this->stubTemplateFunctions();

        $languages = [
            (object) ['code' => 'en', 'flag_emoji' => '🇬🇧'],
            (object) ['code' => 'nl', 'flag_emoji' => '🇳🇱'],
        ];
        $string = (object) ['id' => 1, 'string_key' => 'nav.home', 'context' => 'general', 'translated_count' => 2];
        $translations_map = [
            1 => [
                'en' => (object) ['translation' => 'Home'],
                'nl' => (object) ['translation' => 'Startpagina'],
            ],
        ];

        $html = $this->renderTemplate(array_merge($this->baseVars(), [
            'languages'         => $languages,
            'strings'           => [$string],
            'translations_map'  => $translations_map,
            'default_lang_code' => 'en',
        ]));

        $nl_cell = $this->extractLanguageCell($html, 'nl');

        // Dutch already has its own translation: it's shown as the value, and
        // the placeholder is irrelevant/generic — no fallback flag either.
        $this->assertStringContainsString('value="Startpagina"', $nl_cell);
        $this->assertStringNotContainsString('class="stm-placeholder-is-default"', $nl_cell);
    }

    public function test_placeholder_stays_generic_when_default_language_also_has_no_translation() {
        $this->stubTemplateFunctions();

        $languages = [
            (object) ['code' => 'en', 'flag_emoji' => '🇬🇧'],
            (object) ['code' => 'nl', 'flag_emoji' => '🇳🇱'],
        ];
        $string = (object) ['id' => 1, 'string_key' => 'nav.new', 'context' => 'general', 'translated_count' => 0];
        $translations_map = [1 => []]; // Neither language has a translation yet.

        $html = $this->renderTemplate(array_merge($this->baseVars(), [
            'languages'         => $languages,
            'strings'           => [$string],
            'translations_map'  => $translations_map,
            'default_lang_code' => 'en',
        ]));

        $nl_cell = $this->extractLanguageCell($html, 'nl');

        $this->assertStringContainsString('placeholder="Translation"', $nl_cell);
        $this->assertStringNotContainsString('class="stm-placeholder-is-default"', $nl_cell);
    }
}
