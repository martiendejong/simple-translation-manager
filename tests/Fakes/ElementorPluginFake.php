<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.test.fakes-elementorpluginfake
/**
 * Minimal stand-in for the part of Elementor the plugin looks at:
 * \Elementor\Plugin::$instance->preview->is_preview_mode().
 *
 * Loaded on demand (require_once) by the tests that need it, never by the
 * bootstrap: once declared a class stays for the rest of the PHPUnit
 * process, so preview_mode defaults to false and tests flip it back.
 */

namespace Elementor;

if ( ! class_exists( __NAMESPACE__ . '\Plugin', false ) ) {

    class Preview {
        /** @var bool What is_preview_mode() reports. */
        public $preview_mode = false;

        public function is_preview_mode( $post_id = 0 ) {
            return $this->preview_mode;
        }
    }

    class Plugin {
        /** @var Plugin|null */
        public static $instance;

        /** @var Preview */
        public $preview;
    }

    Plugin::$instance          = new Plugin();
    Plugin::$instance->preview = new Preview();
}
