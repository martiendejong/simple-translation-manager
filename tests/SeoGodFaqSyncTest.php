<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.test.seogodfaqsynctest

/**
 * PHPUnit tests: SEO God FAQ sync (SeoGodFaqSync).
 *
 * What matters: the FAQs of a post are tracked like any other translated field. A change of
 * `_seo_god_faqs` flags the translations stale, whatever route wrote it; machine translations
 * are refreshed, reviewed ones are not; the translation lands in SEO God's own
 * `_seo_god_faqs_{lang}` key; a set a person edits there is taken back as reviewed; and a set
 * somebody already made is never overwritten by a machine translation.
 */

namespace STM\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use STM\SeoGodFaqSync;
use STM\SourceSync;
use STM\Tests\Fakes\FakeWpdb;

class SeoGodFaqSyncTest extends TestCase {

    /** @var FakeWpdb */
    private $wpdb;

    /** @var array post_id => key => value */
    private $meta = [];

    /** @var bool */
    public $is_admin = false;

    /** @var array [hook, args] of every wp_schedule_single_event() call */
    public $scheduled = [];

    /** @var array option name => value */
    public $options = [];

    private $dutch = [
        ['question' => 'Wat kost het?', 'answer' => 'Dat verschilt.', 'category' => 'General'],
        ['question' => 'Hoe boek ik?', 'answer' => 'Neem contact op.'],
    ];

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        global $wpdb;
        $wpdb = new FakeWpdb();
        $this->wpdb = $wpdb;
        $this->meta = [];
        $this->is_admin = false;
        $this->options = [];
        $this->scheduled = [];
        $_GET = [];
        $_COOKIE = [];

        $wpdb->seed('wp_stm_languages', ['code' => 'nl', 'name' => 'Dutch', 'native_name' => 'Nederlands', 'flag_emoji' => '', 'is_active' => 1, 'is_default' => 1, 'order_index' => 1]);
        $wpdb->seed('wp_stm_languages', ['code' => 'en', 'name' => 'English', 'native_name' => 'English', 'flag_emoji' => '', 'is_active' => 1, 'is_default' => 0, 'order_index' => 2]);

        $meta = &$this->meta;
        $self = $this;
        Functions\when('get_post_meta')->alias(function ($id, $key = '', $single = false) use (&$meta) {
            return $meta[$id][$key] ?? '';
        });
        // The real hooks fire inside these; the fakes call the same callbacks.
        Functions\when('update_post_meta')->alias(function ($id, $key, $value) use (&$meta) {
            $meta[$id][$key] = $value;
            SeoGodFaqSync::on_meta_written(1, $id, $key, $value);
            return true;
        });
        Functions\when('delete_post_meta')->alias(function ($id, $key) use (&$meta) {
            unset($meta[$id][$key]);
            SeoGodFaqSync::on_meta_deleted([1], $id, $key, '');
            return true;
        });
        Functions\when('wp_json_encode')->alias(function ($data, $flags = 0) { return json_encode($data, $flags); });
        Functions\when('is_admin')->alias(function () use ($self) { return $self->is_admin; });
        Functions\when('wp_is_post_revision')->justReturn(false);
        Functions\when('wp_next_scheduled')->justReturn(false);
        Functions\when('wp_schedule_single_event')->alias(function ($time, $hook, $args = []) use ($self) { $self->scheduled[] = [$hook, $args]; return true; });
        Functions\when('get_post')->alias(function ($id) { return (object) ['ID' => $id, 'post_title' => 'T', 'post_content' => '', 'post_excerpt' => '', 'post_name' => 't']; });
        Functions\when('get_option')->alias(function ($name, $default = false) use ($self) {
            return $name === 'stm_default_language' ? 'nl' : ($self->options[$name] ?? $default);
        });
        Functions\when('current_time')->justReturn('2026-11-21 12:00:00');
        Functions\when('sanitize_text_field')->returnArg(1);
        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('get_query_var')->justReturn('');
        Functions\when('is_preview')->justReturn(false);
        Functions\when('wp_cache_get')->justReturn(false);
        Functions\when('wp_cache_set')->justReturn(true);
        Functions\when('wp_cache_delete')->justReturn(true);
        Functions\when('wp_cache_incr')->justReturn(1);
        Functions\when('wp_cache_get_last_changed')->justReturn('1');
        Functions\when('seo_god_detect_multilingual')->justReturn('stm');

        // Brain Monkey records add_filter()/add_action() but never runs the callbacks; this
        // dispatcher does, so the wiring (not only the methods) is under test.
        $hooks = [];
        $add = function ($tag, $callback, $priority = 10, $args = 1) use (&$hooks) {
            $hooks[$tag][] = [$callback, $args];
            return true;
        };
        Functions\when('add_filter')->alias($add);
        Functions\when('add_action')->alias($add);
        Functions\when('apply_filters')->alias(function ($tag, $value, ...$rest) use (&$hooks) {
            foreach ($hooks[$tag] ?? [] as [$callback, $args]) {
                $value = call_user_func_array($callback, array_slice(array_merge([$value], $rest), 0, $args));
            }
            return $value;
        });
        Functions\when('do_action')->alias(function ($tag, ...$rest) use (&$hooks) {
            foreach ($hooks[$tag] ?? [] as [$callback, $args]) {
                call_user_func_array($callback, array_slice($rest, 0, $args));
            }
        });

        SeoGodFaqSync::init();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /** A translator that tags the text, standing in for the translation provider. */
    private function translator($fail_on = null) {
        return function ($text, $source, $target, $context, $post_id) use ($fail_on) {
            if ($fail_on !== null && $text === $fail_on) {
                return ['success' => false, 'translation' => '', 'provider' => '', 'error' => 'quota'];
            }
            return ['success' => true, 'translation' => "[$target] $text", 'provider' => 'fake', 'error' => ''];
        };
    }

    private function row($lang) {
        foreach ($this->wpdb->all('stm_post_translations') as $r) {
            if ($r['field_name'] === SeoGodFaqSync::FIELD && $r['language_code'] === $lang) {
                return $r;
            }
        }
        return null;
    }

    private function setSource(array $faqs) {
        $this->meta[7]['_seo_god_faqs'] = $faqs;
    }

    public function test_source_text_ignores_whitespace_and_drops_empty_entries() {
        $this->setSource($this->dutch);
        $a = SeoGodFaqSync::filter_source_value(null, 7, 'seo_god_faqs');

        $this->setSource([
            ['question' => '  Wat kost het? ', 'answer' => "Dat verschilt.\r\n", 'category' => 'General'],
            ['question' => '', 'answer' => ''],
            ['question' => 'Hoe boek ik?', 'answer' => 'Neem contact op.'],
        ]);
        $b = SeoGodFaqSync::filter_source_value(null, 7, 'seo_god_faqs');

        $this->assertSame(SourceSync::hash($a), SourceSync::hash($b));
        $this->assertSame('x', SeoGodFaqSync::filter_source_value('x', 7, 'post_title'), 'other fields pass through');
    }

    public function test_no_faqs_means_no_source() {
        $this->assertNull(SeoGodFaqSync::filter_source_value(null, 7, 'seo_god_faqs'));
    }

    public function test_sync_creates_the_missing_translation_and_projects_it_into_seo_god() {
        $this->setSource($this->dutch);

        $result = SeoGodFaqSync::sync_post(7, $this->translator());

        $this->assertSame(1, $result['created']);
        $row = $this->row('en');
        $this->assertSame('machine', $row['status']);
        $this->assertSame(0, $row['stale']);
        $this->assertNotNull($row['source_hash']);

        $projected = $this->meta[7]['_seo_god_faqs_en'];
        $this->assertSame('[en] Wat kost het?', $projected[0]['question']);
        $this->assertSame('[en] Neem contact op.', $projected[1]['answer']);
        $this->assertSame('General', $projected[0]['category'], 'categories stay as they are');
    }

    public function test_a_change_of_the_default_set_flags_the_translation_stale_by_any_route() {
        $this->setSource($this->dutch);
        SeoGodFaqSync::sync_post(7, $this->translator());

        // Written by SEO God's front-end editor or API: no save_post involved.
        $changed = $this->dutch;
        $changed[0]['answer'] = 'Vanaf 2000 euro.';
        $this->meta[7]['_seo_god_faqs'] = $changed;
        SeoGodFaqSync::on_meta_written(1, 7, '_seo_god_faqs', $changed);

        $this->assertSame(1, $this->row('en')['stale']);
    }

    public function test_stale_machine_translation_is_refreshed_and_reprojected() {
        $this->setSource($this->dutch);
        SeoGodFaqSync::sync_post(7, $this->translator());

        $changed = $this->dutch;
        $changed[0]['answer'] = 'Vanaf 2000 euro.';
        $this->setSource($changed);
        SeoGodFaqSync::on_meta_written(1, 7, '_seo_god_faqs', $changed);

        $result = SeoGodFaqSync::sync_post(7, $this->translator());

        $this->assertSame(1, $result['retranslated']);
        $this->assertSame(0, $this->row('en')['stale']);
        $this->assertSame('[en] Vanaf 2000 euro.', $this->meta[7]['_seo_god_faqs_en'][0]['answer']);
    }

    public function test_reviewed_translation_stays_stale_for_a_person() {
        $this->setSource($this->dutch);
        SeoGodFaqSync::store(7, 'en', [['question' => 'What does it cost?', 'answer' => 'It varies.']], SourceSync::STATUS_REVIEWED);

        $changed = $this->dutch;
        $changed[0]['answer'] = 'Vanaf 2000 euro.';
        $this->setSource($changed);
        SeoGodFaqSync::on_meta_written(1, 7, '_seo_god_faqs', $changed);

        $result = SeoGodFaqSync::sync_post(7, $this->translator());

        $this->assertSame(0, $result['retranslated']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(1, $this->row('en')['stale']);
        $this->assertSame('It varies.', $this->meta[7]['_seo_god_faqs_en'][0]['answer'], 'the person\'s text is untouched');
    }

    public function test_a_failing_provider_leaves_the_row_alone_and_reports() {
        $this->setSource($this->dutch);

        $result = SeoGodFaqSync::sync_post(7, $this->translator('Hoe boek ik?'));

        $this->assertSame(0, $result['created']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('quota', $result['errors'][0]);
        $this->assertNull($this->row('en'));
        $this->assertArrayNotHasKey('_seo_god_faqs_en', $this->meta[7], 'nothing half-written');
    }

    public function test_a_language_set_edited_in_seo_god_becomes_a_reviewed_translation() {
        $this->setSource($this->dutch);
        $edited = [['question' => 'What does it cost?', 'answer' => 'It depends.']];

        $this->meta[7]['_seo_god_faqs_en'] = $edited;
        SeoGodFaqSync::on_meta_written(1, 7, '_seo_god_faqs_en', $edited);

        $row = $this->row('en');
        $this->assertSame('reviewed', $row['status']);
        $this->assertSame(0, $row['stale']);
    }

    public function test_seo_god_copy_before_first_edit_seed_is_not_a_translation() {
        $this->setSource($this->dutch);

        $this->meta[7]['_seo_god_faqs_en'] = $this->dutch;
        SeoGodFaqSync::on_meta_written(1, 7, '_seo_god_faqs_en', $this->dutch);

        $this->assertNull($this->row('en'));
    }

    public function test_deleting_the_language_set_removes_the_translation() {
        $this->setSource($this->dutch);
        SeoGodFaqSync::sync_post(7, $this->translator());
        $this->assertNotNull($this->row('en'));

        unset($this->meta[7]['_seo_god_faqs_en']);
        SeoGodFaqSync::on_meta_deleted([1], 7, '_seo_god_faqs_en', '');

        $this->assertNull($this->row('en'));
    }

    public function test_an_existing_language_set_is_never_overwritten_by_a_machine_translation() {
        $this->setSource($this->dutch);
        $mine = [['question' => 'Mine', 'answer' => 'Hand made']];
        $this->meta[7]['_seo_god_faqs_en'] = $mine;

        $this->assertSame([], SeoGodFaqSync::missing_languages(7));
        $result = SeoGodFaqSync::sync_post(7, $this->translator());

        $this->assertSame(0, $result['created']);
        $this->assertSame($mine, $this->meta[7]['_seo_god_faqs_en']);
    }

    public function test_adopt_takes_over_existing_sets_and_dry_run_writes_nothing() {
        $this->setSource($this->dutch);
        $this->meta[7]['_seo_god_faqs_en'] = [['question' => 'Mine', 'answer' => 'Hand made']];

        $this->assertSame(1, SeoGodFaqSync::adopt_existing(7, SourceSync::STATUS_MACHINE, true));
        $this->assertNull($this->row('en'));

        $this->assertSame(1, SeoGodFaqSync::adopt_existing(7, SourceSync::STATUS_REVIEWED));
        $row = $this->row('en');
        $this->assertSame('reviewed', $row['status']);
        $this->assertSame(SourceSync::hash(SeoGodFaqSync::filter_source_value(null, 7, 'seo_god_faqs')), $row['source_hash']);
        $this->assertSame(0, SeoGodFaqSync::adopt_existing(7), 'a second run finds nothing new');
    }

    public function test_adopted_set_is_flagged_when_the_source_changes_later() {
        $this->setSource($this->dutch);
        $this->meta[7]['_seo_god_faqs_en'] = [['question' => 'Mine', 'answer' => 'Hand made']];
        SeoGodFaqSync::adopt_existing(7);

        $changed = $this->dutch;
        $changed[1]['answer'] = 'Bel ons.';
        $this->setSource($changed);
        SeoGodFaqSync::on_meta_written(1, 7, '_seo_god_faqs', $changed);

        $this->assertSame(1, $this->row('en')['stale']);
    }

    public function test_auto_retranslate_option_schedules_the_background_job() {
        $this->options[SourceSync::OPTION_AUTO_RETRANSLATE] = '1';
        $this->setSource($this->dutch);
        SeoGodFaqSync::on_meta_written(1, 7, '_seo_god_faqs', $this->dutch);

        $this->assertSame([[SeoGodFaqSync::CRON_SYNC, [7]]], $this->scheduled);
    }

    public function test_without_the_option_nothing_is_scheduled_or_translated() {
        $this->setSource($this->dutch);
        SeoGodFaqSync::on_meta_written(1, 7, '_seo_god_faqs', $this->dutch);

        $this->assertNull($this->row('en'));
        $this->assertSame([], $this->scheduled);
    }

    public function test_front_end_readers_of_the_default_set_get_the_current_language() {
        $this->setSource($this->dutch);
        SeoGodFaqSync::sync_post(7, $this->translator());

        $_GET['lang'] = 'en';
        $set = apply_filters('get_post_metadata', null, 7, '_seo_god_faqs', true);

        $this->assertSame('[en] Wat kost het?', $set[0]['question']);
    }

    public function test_default_language_admin_and_other_keys_are_untouched() {
        $this->setSource($this->dutch);
        SeoGodFaqSync::sync_post(7, $this->translator());

        // Default language
        $this->assertNull(apply_filters('get_post_metadata', null, 7, '_seo_god_faqs', true));

        // English, but in the admin (SEO God editing screens, AJAX)
        $_GET['lang'] = 'en';
        $this->is_admin = true;
        $this->assertNull(apply_filters('get_post_metadata', null, 7, '_seo_god_faqs', true));

        // English, front end, a different key
        $this->is_admin = false;
        $this->assertNull(apply_filters('get_post_metadata', null, 7, '_seo_god_faq_enabled', true));

        // The source read used for hashing is never swapped for the translation
        $this->assertSame('Wat kost het?', SeoGodFaqSync::read_source_faqs(7)[0]['question']);
    }

    public function test_writing_the_projection_does_not_loop_back_into_the_sync() {
        $this->setSource($this->dutch);
        SeoGodFaqSync::sync_post(7, $this->translator());

        // If the projected meta write were taken for a person's edit, the row would now be 'reviewed'.
        $this->assertSame('machine', $this->row('en')['status']);
    }
}
