<?php
/**
 * PHPUnit bootstrap — unit-tests the plugin's PHP classes against Brain
 * Monkey WordPress-function stubs and an in-memory FakeWpdb, so the suite
 * runs on plain `vendor/bin/phpunit` with no MySQL/WordPress install.
 *
 * For a real end-to-end check against a live WordPress install, see
 * tests/wp-integration-smoke.php (run separately, not part of this suite).
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

if (!defined('OBJECT'))  define('OBJECT', 'OBJECT');
if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');
if (!defined('ARRAY_N')) define('ARRAY_N', 'ARRAY_N');

if (!class_exists('WP_Error')) {
    class WP_Error {
        public $errors = [];
        public $error_data = [];

        public function __construct($code = '', $message = '', $data = null) {
            if ($code) {
                $this->errors[$code][] = $message;
                if ($data !== null) {
                    $this->error_data[$code] = $data;
                }
            }
        }

        public function get_error_code() {
            $codes = array_keys($this->errors);
            return $codes ? $codes[0] : '';
        }

        public function get_error_message() {
            $code = $this->get_error_code();
            return $code && isset($this->errors[$code][0]) ? $this->errors[$code][0] : '';
        }

        public function get_error_data($code = null) {
            $code = $code ?: $this->get_error_code();
            return $this->error_data[$code] ?? null;
        }
    }
}

// STM class files carry no ABSPATH guard, so they can be required directly.
$pluginDir = dirname(__DIR__) . '/includes/';
require_once $pluginDir . 'class-security.php';
require_once $pluginDir . 'class-settings.php';
require_once $pluginDir . 'class-database.php';
require_once $pluginDir . 'class-cache.php';
require_once $pluginDir . 'class-api.php';
require_once $pluginDir . 'class-post-editor.php';
