<?php
/**
 * PHPUnit tests: FieldValues stale-translation detection (task 3520).
 *
 * stm_field_value_translations has no separate source-hash column — its
 * existing value_hash = md5(source_value) already IS the source hash, so a
 * row goes stale the moment its value_hash is no longer among the hashes of
 * values currently in use for that field. The underlying query joins
 * wp_postmeta/wp_posts, which FakeWpdb's minimal single-table SQL parser
 * cannot execute, so these tests use a tiny canned-result $wpdb stub instead
 * (same rationale as RecordingWpdb's own docblock).
 */

namespace STM\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use STM\FieldValues;

class FieldValuesStaleTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when('sanitize_key')->returnArg(1);
        Functions\when('get_option')->justReturn([]);
        Functions\when('apply_filters')->returnArg(2);
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function stubWpdbWithLiveValues(array $values) {
        global $wpdb;
        $wpdb = new class($values) {
            public $prefix = 'wp_';
            public $postmeta = 'wp_postmeta';
            public $posts = 'wp_posts';
            private $values;

            public function __construct(array $values) {
                $this->values = $values;
            }

            public function prepare($query, ...$args) {
                return $query;
            }

            public function get_results($query) {
                return array_map(function ($v) {
                    return (object) ['value' => $v];
                }, $this->values);
            }
        };
    }

    public function test_translation_is_not_stale_when_its_value_is_still_in_use() {
        $this->stubWpdbWithLiveValues(['Cabriolet', 'Coupe']);

        $hash = md5('Cabriolet');

        $this->assertFalse(FieldValues::is_translation_stale('coachwork', $hash));
    }

    public function test_translation_is_stale_once_the_source_value_is_no_longer_in_use() {
        $this->stubWpdbWithLiveValues(['Coupe']);

        $hash = md5('Cabriolet');

        $this->assertTrue(
            FieldValues::is_translation_stale('coachwork', $hash),
            'A value-translation whose exact wording no longer appears on any post must be reported stale.'
        );
    }

    public function test_editing_the_source_value_everywhere_flags_its_translation_as_stale() {
        // Translation was saved when the field's value was "Cabriolet".
        $originalHash = md5('Cabriolet');
        $this->stubWpdbWithLiveValues(['Cabriolet']);
        $this->assertFalse(FieldValues::is_translation_stale('coachwork', $originalHash));

        // Every post using that field is edited to a new wording.
        $this->stubWpdbWithLiveValues(['Convertible']);

        $this->assertTrue(
            FieldValues::is_translation_stale('coachwork', $originalHash),
            'Editing the source value everywhere it was used must flip the existing translation to stale.'
        );
    }
}
