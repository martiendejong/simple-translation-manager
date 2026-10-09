<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.test.upgradekeepstranslationstest

/**
 * PHPUnit tests: installing or updating the plugin keeps stored translations
 * (task 5182). Database::maybe_upgrade() / create_tables() only hand additive
 * CREATE TABLE IF NOT EXISTS statements to dbDelta(), activation only
 * inserts languages that are missing, and a failing schema step is logged
 * with its diagnostic code instead of taking the site down.
 *
 * Covers design notes STM-DN-01 (additive upgrades) and STM-DN-02
 * (log-don't-throw). dbDelta() is the recording stub in
 * tests/wp-stubs/wp-admin/includes/upgrade.php.
 */

namespace STM\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use STM\Database;
use STM\Tests\Fakes\FakeWpdb;

class UpgradeKeepsTranslationsTest extends TestCase {

    /** @var FakeWpdb */
    private $wpdb;

    /** @var array<string, mixed> */
    private $options = [];

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        $GLOBALS['stm_test_dbdelta_log']   = [];
        $GLOBALS['stm_test_dbdelta_throw'] = false;
        $this->options = [];

        global $wpdb;
        $wpdb = new FakeWpdb();
        $this->wpdb = $wpdb;

        $this->wpdb->seed('wp_stm_languages', ['code' => 'en', 'name' => 'English (edited by the site owner)', 'native_name' => 'English', 'is_default' => 1, 'is_active' => 1, 'flag_emoji' => '', 'order_index' => 1]);
        $this->wpdb->seed('wp_stm_strings', ['id' => 7, 'string_key' => 'fx.quay.lamplighter.count', 'context' => 'quayfx']);
        $this->wpdb->seed('wp_stm_translations', ['id' => 70, 'string_id' => 7, 'language_code' => 'nl', 'translation' => 'De lantaarnopsteker telt zeven motten.', 'status' => 'published']);
        $this->wpdb->seed('wp_stm_post_translations', ['id' => 1, 'post_id' => 12, 'field_name' => 'post_title', 'language_code' => 'nl', 'translation' => 'Havenlicht']);

        $options = &$this->options;
        Functions\when('get_option')->alias(function ($name, $default = false) use (&$options) {
            return array_key_exists($name, $options) ? $options[$name] : $default;
        });
        Functions\when('update_option')->alias(function ($name, $value) use (&$options) {
            $options[$name] = $value;
            return true;
        });
        Functions\when('apply_filters')->returnArg(2);
    }

    protected function tearDown(): void {
        $GLOBALS['stm_test_dbdelta_throw'] = false;
        Monkey\tearDown();
        parent::tearDown();
    }

    private function snapshot(): array {
        return [
            'languages'    => $this->wpdb->all('stm_languages'),
            'strings'      => $this->wpdb->all('stm_strings'),
            'translations' => $this->wpdb->all('stm_translations'),
            'post'         => $this->wpdb->all('stm_post_translations'),
        ];
    }

    public function test_update_from_an_older_version_runs_additive_schema_statements_only() {
        $this->options[Database::OPTION_DB_VERSION] = '1.0.0';
        $before = $this->snapshot();

        Database::maybe_upgrade();

        $statements = $GLOBALS['stm_test_dbdelta_log'];
        $this->assertCount(7, $statements, 'one dbDelta call per STM table');
        foreach ($statements as $sql) {
            $this->assertMatchesRegularExpression('/^CREATE TABLE IF NOT EXISTS wp_stm_[a-z_]+ \(/', ltrim($sql));
            $this->assertDoesNotMatchRegularExpression('/\b(DROP|TRUNCATE|DELETE|RENAME)\b/i', $sql, 'an update must never remove or rewrite data');
        }
        $this->assertSame(STM_VERSION, $this->options[Database::OPTION_DB_VERSION], 'schema version is recorded after the upgrade');
        $this->assertSame($before, $this->snapshot(), 'every stored string, translation and language survives the update');
    }

    public function test_current_schema_version_does_no_schema_work() {
        $this->options[Database::OPTION_DB_VERSION] = STM_VERSION;

        Database::maybe_upgrade();

        $this->assertSame([], $GLOBALS['stm_test_dbdelta_log']);
    }

    public function test_activation_only_adds_languages_that_are_missing() {
        Database::seed_default_languages();

        $byCode = [];
        foreach ($this->wpdb->all('stm_languages') as $row) {
            $byCode[$row['code']] = $row;
        }
        $this->assertSame('English (edited by the site owner)', $byCode['en']['name'], 'an existing language row is left alone');
        $this->assertArrayHasKey('nl', $byCode, 'the missing default language is added');
        $this->assertCount(2, $byCode);
    }

    public function test_a_failing_schema_step_is_logged_with_its_code_and_not_thrown() {
        $GLOBALS['stm_test_dbdelta_throw'] = true;
        $logFile = tempnam(sys_get_temp_dir(), 'stm-log-');
        $previous = ini_set('error_log', $logFile);

        try {
            Database::create_tables();
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
        }

        $log = (string) file_get_contents($logFile);
        @unlink($logFile);

        $this->assertStringContainsString('[STM] [STM-E-DB-CREATE-TABLES-SCHEMA]', $log);
        $this->assertStringContainsString('simulated dbDelta failure', $log);
    }
}
