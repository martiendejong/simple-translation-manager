<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.test.sourceconventionstest

/**
 * PHPUnit tests: the project-bound naming convention (task 5182).
 *
 * Everything STM puts into a namespace it shares with WordPress and other
 * plugins - global functions, constants, custom hooks, option names, and
 * browser globals - carries the stm_ / STM_ prefix (or lives in the STM\
 * namespace). This test records that as it is today, so a new generic name
 * cannot slip in unnoticed. It deliberately renames nothing: the existing
 * prefixed names are public API (hooks, options, REST routes, tables).
 */

namespace STM\Tests;

use PHPUnit\Framework\TestCase;

class SourceConventionsTest extends TestCase {

    /** Names STM reads but does not own: WordPress core or a named third-party plugin. */
    private const FOREIGN_NAMES = [
        'page_on_front', 'show_on_front', 'widget_title', 'seo_god_content_language',
    ];

    /** Helper functions that are STM's own but are spelled like gettext (__ / _e). */
    private const GETTEXT_STYLE = ['__stm', '_e_stm'];

    /** @return array<string, string> relative path => contents, for the distributed PHP sources */
    private function phpSources(): array {
        $root = dirname(__DIR__);
        $out  = [];
        foreach (array_merge(glob($root . '/includes/*.php'), [$root . '/simple-translation-manager.php', $root . '/uninstall.php']) as $file) {
            $out[ltrim(str_replace($root, '', str_replace('\\', '/', $file)), '/')] = (string) file_get_contents($file);
        }
        return $out;
    }

    public function test_global_functions_and_constants_are_stm_prefixed() {
        foreach ($this->phpSources() as $rel => $source) {
            preg_match_all('/^(?:if \(!function_exists\(\'([A-Za-z0-9_]+)\'\)\) \{\s*)?function ([A-Za-z0-9_]+)\s*\(/m', $source, $m, PREG_SET_ORDER);
            foreach ($m as $hit) {
                $name = $hit[2];
                $this->assertTrue(
                    strpos($name, 'stm_') === 0 || in_array($name, self::GETTEXT_STYLE, true),
                    "{$rel}: global function {$name}() is not stm_-prefixed"
                );
            }

            preg_match_all("/define\\(\\s*'([A-Za-z0-9_]+)'/", $source, $d);
            foreach ($d[1] as $const) {
                $this->assertStringStartsWith('STM_', $const, "{$rel}: constant {$const} is not STM_-prefixed");
            }
        }
    }

    public function test_custom_hooks_and_option_names_are_stm_prefixed() {
        foreach ($this->phpSources() as $rel => $source) {
            preg_match_all("/\\b(?:do_action|apply_filters|get_option|update_option|delete_option|add_option)\\(\\s*'([^']+)'/", $source, $m);
            foreach ($m[1] as $name) {
                if (in_array($name, self::FOREIGN_NAMES, true)) {
                    continue;
                }
                $this->assertStringStartsWith('stm_', $name, "{$rel}: hook/option '{$name}' is not stm_-prefixed");
            }
        }
    }

    public function test_javascript_adds_nothing_to_the_global_scope() {
        foreach (glob(dirname(__DIR__) . '/assets/*.js') as $file) {
            $source = (string) file_get_contents($file);
            $rel    = 'assets/' . basename($file);

            $this->assertDoesNotMatchRegularExpression('/^(?:var|let|const|function)\s/m', $source, "{$rel}: declares a top-level (global) binding");
            preg_match_all('/\bwindow\.([A-Za-z_$][\w$]*)\s*=(?!=)/', $source, $m);
            foreach ($m[1] as $global) {
                $this->assertStringStartsWith('stm', $global, "{$rel}: window.{$global} is not stm-prefixed");
            }
        }
    }
}
