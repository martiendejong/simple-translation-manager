<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.test.sourcesynctest

/**
 * PHPUnit tests: source-hash tracking of post translations (task 3520).
 *
 * The behaviour that matters: a translation records the source it was made
 * from; when that source changes the translation is flagged stale; legacy rows
 * without a hash are never flagged; and a metabox save that did not touch a
 * translation must not re-stamp it (which would hide the staleness).
 */

namespace STM\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use STM\API;
use STM\PostEditor;
use STM\SourceSync;
use STM\Tests\Fakes\FakeWpdb;

class SourceSyncTest extends TestCase {

    /** @var FakeWpdb */
    private $wpdb;

    /** @var object The post as WordPress would currently return it. */
    private $post;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        global $wpdb;
        $wpdb = new FakeWpdb();
        $this->wpdb = $wpdb;
        $_POST = [];

        $this->post = (object) [
            'ID'           => 7,
            'post_title'   => 'Welkom',
            'post_content' => 'Hallo wereld',
            'post_excerpt' => '',
            'post_name'    => 'welkom',
        ];
        $post = &$this->post;

        Functions\when('get_post')->alias(function ($id) use (&$post) {
            return (int) $id === $post->ID ? $post : null;
        });
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('apply_filters')->alias(function ($hook, $value) { return $value; });
        Functions\when('do_action')->justReturn(null);
        Functions\when('wp_is_post_revision')->justReturn(false);
        Functions\when('get_option')->justReturn(false);
        Functions\when('current_time')->justReturn('2026-11-19 12:00:00');
        Functions\when('sanitize_text_field')->returnArg(1);
        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('wp_kses_post')->returnArg(1);
        Functions\when('sanitize_title')->returnArg(1);
        Functions\when('wp_cache_get')->justReturn(false);
        Functions\when('wp_cache_set')->justReturn(true);
        Functions\when('wp_cache_delete')->justReturn(true);
        Functions\when('wp_cache_incr')->justReturn(1);
        Functions\when('wp_cache_get_last_changed')->justReturn('1');
        Functions\when('get_current_user_id')->justReturn(1);
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function row($field, $lang, array $extra = []) {
        return $this->wpdb->seed('stm_post_translations', array_merge([
            'post_id'       => 7,
            'field_name'    => $field,
            'language_code' => $lang,
            'translation'   => 'Welcome',
            'source_hash'   => null,
            'status'        => null,
            'stale'         => 0,
        ], $extra));
    }

    private function stored($field, $lang) {
        foreach ($this->wpdb->all('stm_post_translations') as $r) {
            if ($r['field_name'] === $field && $r['language_code'] === $lang) {
                return $r;
            }
        }
        return null;
    }

    public function test_hash_ignores_line_endings_and_outer_whitespace() {
        $this->assertSame(SourceSync::hash("a\r\nb"), SourceSync::hash("  a\nb \n"));
        $this->assertNotSame(SourceSync::hash('a'), SourceSync::hash('b'));
        $this->assertNull(SourceSync::hash(null));
    }

    public function test_aliases_read_the_native_post_column() {
        $this->assertSame('Welkom', SourceSync::source_value(7, 'title'));
        $this->assertSame('Hallo wereld', SourceSync::source_value(7, 'content'));
        $this->assertNull(SourceSync::source_value(99, 'title'));
    }

    public function test_api_write_records_hash_status_and_clears_stale() {
        API::save_post_translations(7, ['post_title' => 'Welcome'], 'en');

        $row = $this->stored('post_title', 'en');
        $this->assertSame(SourceSync::hash('Welkom'), $row['source_hash']);
        $this->assertSame('machine', $row['status']);
        $this->assertSame(0, $row['stale']);
    }

    public function test_changed_source_flags_the_translation_stale() {
        API::save_post_translations(7, ['post_title' => 'Welcome'], 'en');

        $this->post->post_title = 'Welkom bij ons';
        SourceSync::on_save_post(7, $this->post);

        $this->assertSame(1, $this->stored('post_title', 'en')['stale']);
        $this->assertCount(1, SourceSync::get_stale(7));
    }

    public function test_unchanged_source_stays_current() {
        API::save_post_translations(7, ['post_title' => 'Welcome'], 'en');

        SourceSync::on_save_post(7, $this->post);

        $this->assertSame(0, $this->stored('post_title', 'en')['stale']);
    }

    public function test_reverting_the_source_clears_stale() {
        API::save_post_translations(7, ['post_title' => 'Welcome'], 'en');
        $this->post->post_title = 'Anders';
        SourceSync::on_save_post(7, $this->post);
        $this->post->post_title = 'Welkom';
        SourceSync::on_save_post(7, $this->post);

        $this->assertSame(0, $this->stored('post_title', 'en')['stale']);
    }

    public function test_legacy_rows_without_a_hash_are_never_flagged() {
        $this->row('post_title', 'en');

        $this->post->post_title = 'Totaal anders';
        SourceSync::on_save_post(7, $this->post);

        $this->assertSame(0, $this->stored('post_title', 'en')['stale']);
    }

    public function test_baseline_stamps_legacy_rows_so_later_changes_are_flagged() {
        $this->row('post_title', 'en');

        $this->assertSame(1, SourceSync::stamp_baseline(0, true), 'dry run counts');
        $this->assertNull($this->stored('post_title', 'en')['source_hash'], 'dry run writes nothing');

        SourceSync::stamp_baseline();
        $this->assertSame(SourceSync::hash('Welkom'), $this->stored('post_title', 'en')['source_hash']);
        $this->assertNull($this->stored('post_title', 'en')['status'], 'origin stays unknown');

        $this->post->post_title = 'Nieuw';
        SourceSync::on_save_post(7, $this->post);
        $this->assertSame(1, $this->stored('post_title', 'en')['stale']);
    }

    public function test_revisions_and_autosaves_do_not_flag_anything() {
        API::save_post_translations(7, ['post_title' => 'Welcome'], 'en');
        $this->post->post_title = 'Nieuw';

        Functions\when('wp_is_post_revision')->justReturn(true);
        SourceSync::on_save_post(7, $this->post);

        $this->assertSame(0, $this->stored('post_title', 'en')['stale']);
    }

    public function test_metabox_save_does_not_restamp_an_untouched_translation() {
        // A translation made from the OLD source, still in the metabox form unchanged.
        API::save_post_translations(7, ['post_title' => 'Welcome'], 'en');
        $oldHash = $this->stored('post_title', 'en')['source_hash'];

        $this->post->post_title = 'Welkom bij ons';
        $_POST = [
            'stm_post_language' => 'nl',
            'stm_translations'  => ['en' => ['post_title' => 'Welcome']], // untouched
        ];
        $this->saveFromMetabox();
        SourceSync::on_save_post(7, $this->post);

        $row = $this->stored('post_title', 'en');
        $this->assertSame($oldHash, $row['source_hash'], 'untouched value keeps its old hash');
        $this->assertSame(1, $row['stale']);
    }

    public function test_metabox_edit_by_a_person_is_reviewed_and_current() {
        API::save_post_translations(7, ['post_title' => 'Welcome'], 'en');

        $this->post->post_title = 'Welkom bij ons';
        $_POST = [
            'stm_post_language' => 'nl',
            'stm_translations'  => ['en' => ['post_title' => 'Welcome to us']], // edited
        ];
        $this->saveFromMetabox();
        SourceSync::on_save_post(7, $this->post);

        $row = $this->stored('post_title', 'en');
        $this->assertSame('Welcome to us', $row['translation']);
        $this->assertSame('reviewed', $row['status']);
        $this->assertSame(SourceSync::hash('Welkom bij ons'), $row['source_hash']);
        $this->assertSame(0, $row['stale']);
    }

    public function test_retranslation_overwrites_machine_rows_and_spares_reviewed_ones() {
        API::save_post_translations(7, ['post_title' => 'Welcome'], 'en');
        API::save_post_translations(7, ['post_title' => 'Willkommen'], 'de', SourceSync::STATUS_REVIEWED);
        $this->post->post_title = 'Welkom bij ons';
        SourceSync::on_save_post(7, $this->post);

        $result = SourceSync::retranslate_post(7, function ($text) {
            return ['success' => true, 'translation' => 'Welcome to us', 'provider' => 'test', 'error' => ''];
        });

        $this->assertSame(1, $result['retranslated']);
        $this->assertSame(1, $result['skipped']);
        $en = $this->stored('post_title', 'en');
        $this->assertSame('Welcome to us', $en['translation']);
        $this->assertSame(0, $en['stale']);
        $this->assertSame('machine', $en['status']);

        $de = $this->stored('post_title', 'de');
        $this->assertSame('Willkommen', $de['translation'], 'reviewed text is never overwritten');
        $this->assertSame(1, $de['stale'], 'and stays flagged for a person');
    }

    /** Run PostEditor::save_translations the way WordPress would, past its nonce/permission gate. */
    private function saveFromMetabox() {
        Functions\when('wp_verify_nonce')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('wp_generate_uuid4')->justReturn('uuid');
        $_POST['stm_translations_nonce'] = 'x';
        PostEditor::save_translations(7, $this->post);
    }
}
