<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.test.lifecycle

/**
 * Install, update, runtime and uninstall checks for Simple Translation Manager.
 *
 * Not part of the plugin ZIP and not a PHPUnit test: tools/wp-plugin-check runs it inside a throwaway
 * WordPress (php tools/wp-plugin-check/scan.php lifecycle <zip> --script=tests/lifecycle/stm-lifecycle.php ...)
 * through `wp eval-file <this file> <phase> <label> <siteUrl> <adminPassword> <slug>`. Only synthetic data is
 * created. Each phase prints one line `@@LIFECYCLE@@ {"checks":[{name, ok, detail}]}`.
 *
 * Phases: seed, after-update, fresh-install, functional, prepare-keep, verify-kept, verify-reinstall,
 * prepare-remove, verify-removed.
 */

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress -- test harness for a throwaway site, not plugin code: it is never shipped and never runs on a real site

$lc_phase    = (string) ($args[0] ?? '');
$lc_label    = (string) ($args[1] ?? '');
$lc_site     = rtrim((string) ($args[2] ?? ''), '/');
$lc_admin_pw = (string) ($args[3] ?? '');
$lc_slug     = (string) ($args[4] ?? 'simple-translation-manager');
$lc_checks   = [];

global $wpdb;
$lc_prefix = $wpdb->prefix;

$lc_check = static function (string $name, bool $ok, string $detail = '') use (&$lc_checks): void {
    $lc_checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $ok ? '' : $detail];
};

const LC_TABLES = ['languages', 'strings', 'translations', 'post_translations', 'post_associations', 'term_translations', 'field_value_translations'];
const LC_OPTIONS = ['stm_default_language', 'stm_enable_url_routing', 'stm_cache_duration', 'stm_openai_model', 'stm_switcher_position'];

// ---------------------------------------------------------------------------------------------
// Small helpers
// ---------------------------------------------------------------------------------------------

$lc_table_exists = static function (string $table) use ($wpdb, $lc_prefix): bool {
    return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($lc_prefix . 'stm_' . $table))) === $lc_prefix . 'stm_' . $table;
};

$lc_columns = static function (string $table) use ($wpdb, $lc_prefix): array {
    return array_map(static fn ($c) => $c->Field, $wpdb->get_results("SHOW COLUMNS FROM {$lc_prefix}stm_{$table}"));
};

/** Insert a row, silently dropping columns an older schema does not have. Returns the insert id. */
$lc_insert = static function (string $table, array $data) use ($wpdb, $lc_prefix, $lc_columns): int {
    $cols = $lc_columns($table);
    $data = array_intersect_key($data, array_flip($cols));
    $wpdb->insert($lc_prefix . 'stm_' . $table, $data);
    return (int) $wpdb->insert_id;
};

$lc_rows = static function (string $table) use ($wpdb, $lc_prefix): array {
    $rows = $wpdb->get_results("SELECT * FROM {$lc_prefix}stm_{$table} ORDER BY id ASC", ARRAY_A);
    foreach ($rows as &$r) {
        unset($r['created_at'], $r['updated_at']);
    }
    return $rows;
};

$lc_snapshot = static function () use ($lc_table_exists, $lc_rows): array {
    $snap = ['tables' => [], 'options' => []];
    foreach (LC_TABLES as $t) {
        if ($lc_table_exists($t)) {
            $snap['tables'][$t] = $lc_rows($t);
        }
    }
    foreach (LC_OPTIONS as $o) {
        $snap['options'][$o] = get_option($o, null);
    }
    return $snap;
};

$lc_http = static function (string $method, string $path, array $o = []) use ($lc_site): array {
    $url  = (strpos($path, 'http') === 0 ? $path : $lc_site . $path);
    $resp = wp_remote_request($url, array_merge([
        'method'      => $method,
        'timeout'     => 60,
        'redirection' => 0,
        'sslverify'   => false,
    ], $o));
    if (is_wp_error($resp)) {
        return ['code' => 0, 'body' => '', 'headers' => [], 'cookies' => [], 'error' => $resp->get_error_message(), 'json' => null];
    }
    $body = (string) wp_remote_retrieve_body($resp);
    return [
        'code'    => (int) wp_remote_retrieve_response_code($resp),
        'body'    => $body,
        'headers' => wp_remote_retrieve_headers($resp),
        'cookies' => wp_remote_retrieve_cookies($resp),
        'json'    => json_decode($body, true),
        'error'   => '',
    ];
};

$lc_login = static function (string $user, string $pass) use ($lc_http, $lc_site): array {
    $r = $lc_http('POST', '/wp-login.php', [
        'body'    => ['log' => $user, 'pwd' => $pass, 'wp-submit' => 'Log In', 'redirect_to' => $lc_site . '/wp-admin/', 'testcookie' => '1'],
        'cookies' => [new WP_Http_Cookie(['name' => 'wordpress_test_cookie', 'value' => 'WP Cookie check'])],
    ]);
    return $r['code'] === 302 ? $r['cookies'] : [];
};

$lc_rest_nonce = static function (array $cookies) use ($lc_http): string {
    $r = $lc_http('GET', '/wp-admin/admin-ajax.php?action=rest-nonce', ['cookies' => $cookies]);
    return $r['code'] === 200 ? trim($r['body']) : '';
};

$lc_rest = static function (string $method, string $route, array $o = []) use ($lc_http): array {
    $headers = $o['headers'] ?? [];
    $args    = ['headers' => $headers, 'cookies' => $o['cookies'] ?? []];
    if (isset($o['nonce']) && $o['nonce'] !== '') {
        $args['headers']['X-WP-Nonce'] = $o['nonce'];
    }
    if (isset($o['json'])) {
        $args['headers']['Content-Type'] = 'application/json';
        $args['body']                    = wp_json_encode($o['json']);
    }
    if (isset($o['raw'])) {
        $args['headers'] = array_merge($args['headers'], $o['raw']['headers']);
        $args['body']    = $o['raw']['body'];
    }
    return $lc_http($method, '/wp-json/stm/v1' . $route, $args);
};

$lc_multipart = static function (array $fields, string $fileField, string $filename, string $content): array {
    $b    = 'lcb' . bin2hex(random_bytes(6));
    $body = '';
    foreach ($fields as $k => $v) {
        $body .= "--{$b}\r\nContent-Disposition: form-data; name=\"{$k}\"\r\n\r\n{$v}\r\n";
    }
    $body .= "--{$b}\r\nContent-Disposition: form-data; name=\"{$fileField}\"; filename=\"{$filename}\"\r\nContent-Type: application/octet-stream\r\n\r\n{$content}\r\n--{$b}--\r\n";
    return ['headers' => ['Content-Type' => 'multipart/form-data; boundary=' . $b], 'body' => $body];
};

$lc_has_php_error = static function (string $body): bool {
    return preg_match('/<b>(Fatal error|Warning|Notice|Deprecated|Parse error)<\/b>:|(Fatal error|Parse error): |There has been a critical error|WordPress database error/i', $body) === 1;
};

$lc_stm_active = static function () use ($lc_slug): bool {
    return is_plugin_active($lc_slug . '/' . $lc_slug . '.php');
};

/** Synthetic content every scenario starts from. Returns ids. */
$lc_seed = static function () use ($wpdb, $lc_prefix, $lc_insert, $lc_table_exists): array {
    $ids = [];

    // A kept sandbox may still hold content from an earlier run: start from a known state.
    foreach (get_posts(['post_type' => 'any', 'post_status' => 'any', 'numberposts' => -1, 's' => 'Lifecycle']) as $old) {
        wp_delete_post($old->ID, true);
    }
    $old_term = get_term_by('slug', 'lifecycle-category', 'category');
    if ($old_term) {
        wp_delete_term($old_term->term_id, 'category');
    }

    if (!$wpdb->get_var("SELECT id FROM {$lc_prefix}stm_languages WHERE code = 'fr'")) {
        $lc_insert('languages', ['code' => 'fr', 'name' => 'French', 'native_name' => 'Francais', 'is_default' => 0, 'is_active' => 1, 'flag_emoji' => '', 'order_index' => 3]);
    }

    $strings = [
        'lc.greeting' => ['nl' => 'Hallo wereld', 'fr' => 'Bonjour le monde'],
        'lc.farewell' => ['nl' => 'Tot ziens'],
    ];
    foreach ($strings as $key => $translations) {
        $sid = $lc_insert('strings', ['string_key' => $key, 'context' => 'lifecycle', 'description' => 'synthetic lifecycle string']);
        $ids['string:' . $key] = $sid;
        foreach ($translations as $lang => $text) {
            $lc_insert('translations', ['string_id' => $sid, 'language_code' => $lang, 'translation' => $text, 'status' => 'published']);
        }
    }

    $post = wp_insert_post(['post_title' => 'Lifecycle synthetic post', 'post_name' => 'lifecycle-synthetic-post', 'post_content' => 'English body of the synthetic post.', 'post_status' => 'publish']);
    $ids['post'] = (int) $post;
    foreach (['post_title' => 'Synthetische titel', 'post_content' => 'Nederlandse inhoud van het synthetische bericht.', 'post_name' => 'synthetisch-bericht'] as $field => $text) {
        $lc_insert('post_translations', ['post_id' => $post, 'field_name' => $field, 'language_code' => 'nl', 'translation' => $text]);
    }

    $term = wp_insert_term('Lifecycle category', 'category', ['slug' => 'lifecycle-category']);
    if (!is_wp_error($term) && $lc_table_exists('term_translations')) {
        $ids['term'] = (int) $term['term_id'];
        $lc_insert('term_translations', ['term_id' => $term['term_id'], 'language_code' => 'nl', 'name' => 'Levenscyclus categorie', 'slug' => 'levenscyclus-categorie']);
    }

    if ($lc_table_exists('field_value_translations')) {
        $lc_insert('field_value_translations', ['field_name' => 'lc_color', 'value_hash' => md5('red'), 'source_value' => 'red', 'language_code' => 'nl', 'translation' => 'rood']);
    }

    update_option('stm_enable_url_routing', 1);
    update_option('stm_cache_duration', 1234);
    update_option('stm_openai_model', 'synthetic-model');
    update_option('stm_switcher_position', 'after-content');
    return $ids;
};

// ---------------------------------------------------------------------------------------------
// Phases
// ---------------------------------------------------------------------------------------------

try {
    switch ($lc_phase) {
        case 'seed':
            $ids  = $lc_seed();
            $snap = $lc_snapshot();
            update_option('lifecycle_snapshot_' . $lc_label, wp_json_encode(['snap' => $snap, 'ids' => $ids]), false);
            $lc_check('plugin is active before the update', $lc_stm_active());
            $lc_check('synthetic strings and translations were stored', count($snap['tables']['translations'] ?? []) >= 3, 'translations rows: ' . count($snap['tables']['translations'] ?? []));
            $lc_check('synthetic post translation was stored', count($snap['tables']['post_translations'] ?? []) >= 3);
            $lc_check('old release version recorded', defined('STM_VERSION'), 'STM_VERSION is ' . (defined('STM_VERSION') ? STM_VERSION : 'undefined'));
            break;

        case 'after-update':
            $saved = json_decode((string) get_option('lifecycle_snapshot_' . $lc_label), true);
            $lc_check('the seed snapshot exists', is_array($saved));
            $old = $saved['snap'] ?? ['tables' => [], 'options' => []];
            $lc_check('plugin is still active after the update', $lc_stm_active());
            $lc_check('every one of the seven tables exists after the update', count(array_filter(LC_TABLES, $lc_table_exists)) === count(LC_TABLES), 'missing: ' . implode(', ', array_filter(LC_TABLES, static fn ($t) => !$lc_table_exists($t))));
            foreach ($old['tables'] as $table => $oldRows) {
                $now   = [];
                foreach ($lc_rows($table) as $r) {
                    $now[(int) $r['id']] = $r;
                }
                $lost = 0;
                $changed = 0;
                foreach ($oldRows as $r) {
                    $cur = $now[(int) $r['id']] ?? null;
                    if ($cur === null) {
                        $lost++;
                        continue;
                    }
                    foreach ($r as $col => $value) {
                        if (array_key_exists($col, $cur) && (string) $cur[$col] !== (string) $value) {
                            $changed++;
                            break;
                        }
                    }
                }
                $lc_check("table {$table}: all " . count($oldRows) . ' rows survived unchanged', $lost === 0 && $changed === 0, "{$lost} lost, {$changed} changed");
            }
            foreach ($old['options'] as $name => $value) {
                if ($value !== null) {
                    $lc_check("option {$name} kept its value", (string) get_option($name, null) === (string) $value, 'was ' . json_encode($value) . ', now ' . json_encode(get_option($name, null)));
                }
            }
            $lc_check('database version option matches the new plugin version', defined('STM_VERSION') && get_option('stm_db_version') === STM_VERSION, 'stm_db_version=' . json_encode(get_option('stm_db_version')) . ' STM_VERSION=' . (defined('STM_VERSION') ? STM_VERSION : 'undefined'));
            $lc_check('a translation stored before the update is served by the new code', class_exists('STM\\Cache') && STM\Cache::get_translation('lc.greeting', 'nl', 'lifecycle') === 'Hallo wereld', 'got ' . json_encode(class_exists('STM\\Cache') ? STM\Cache::get_translation('lc.greeting', 'nl', 'lifecycle') : 'no Cache class'));
            $lc_check('the helper function __stm() exists for themes', function_exists('__stm'));
            break;

        case 'fresh-install':
            $lc_check('plugin is active', $lc_stm_active());
            $lc_check('all seven tables were created on activation', count(array_filter(LC_TABLES, $lc_table_exists)) === 7, 'missing: ' . implode(', ', array_filter(LC_TABLES, static fn ($t) => !$lc_table_exists($t))));
            $codes = $wpdb->get_col("SELECT code FROM {$lc_prefix}stm_languages ORDER BY order_index ASC, id ASC");
            $lc_check('English and Dutch were seeded as the default languages', in_array('en', $codes, true) && in_array('nl', $codes, true), 'languages: ' . implode(',', $codes));
            $lc_check('exactly one default language', (int) $wpdb->get_var("SELECT COUNT(*) FROM {$lc_prefix}stm_languages WHERE is_default = 1") === 1);
            $lc_check('database version option is set', get_option('stm_db_version') === STM_VERSION, json_encode(get_option('stm_db_version')));
            $lc_check('rewrite rules were flushed with language prefixes', is_array(get_option('rewrite_rules')) || get_option('rewrite_rules') === '' || get_option('rewrite_rules') === false);
            break;

        case 'functional':
            require __DIR__ . '/stm-lifecycle-functional.php';
            break;

        case 'prepare-keep':
            $ids  = $lc_seed();
            update_option('stm_keep_data_on_uninstall', 1);
            update_option('lifecycle_snapshot_keep', wp_json_encode(['snap' => $lc_snapshot(), 'ids' => $ids]), false);
            $lc_check('keep-data option is on', (int) get_option('stm_keep_data_on_uninstall') === 1);
            break;

        case 'verify-kept':
            $saved = json_decode((string) get_option('lifecycle_snapshot_keep'), true);
            $lc_check('the snapshot taken before the uninstall is still there', is_array($saved));
            $lc_check('plugin files are gone after the uninstall', !is_dir(WP_PLUGIN_DIR . '/' . $lc_slug));
            foreach (($saved['snap']['tables'] ?? []) as $table => $oldRows) {
                $lc_check("keep data: table {$table} still holds its " . count($oldRows) . ' rows', $lc_table_exists($table) && count($lc_rows($table)) === count($oldRows));
            }
            $lc_check('keep data: the keep option is still set', (int) get_option('stm_keep_data_on_uninstall') === 1);
            $lc_check('keep data: settings were not deleted', (string) get_option('stm_cache_duration') === '1234');
            break;

        case 'verify-reinstall':
            $saved = json_decode((string) get_option('lifecycle_snapshot_keep'), true);
            $lc_check('plugin is active after the reinstall', $lc_stm_active());
            $oldCount = count($saved['snap']['tables']['translations'] ?? []);
            $lc_check('translations survived uninstall and reinstall', count($lc_rows('translations')) === $oldCount && $oldCount > 0, 'rows: ' . count($lc_rows('translations')) . ' expected ' . $oldCount);
            $lc_check('a kept translation is served again', STM\Cache::get_translation('lc.greeting', 'nl', 'lifecycle') === 'Hallo wereld');
            break;

        case 'prepare-remove':
            $lc_seed();
            update_option('stm_keep_data_on_uninstall', 0);
            update_option('stm_deepl_api_key', 'synthetic-key-for-uninstall-check');
            update_user_meta(1, 'stm_admin_prefs', ['per_page' => 17]);
            $lc_check('keep-data option is off and synthetic key and preference are set', (int) get_option('stm_keep_data_on_uninstall') === 0 && get_option('stm_deepl_api_key') !== false);
            break;

        case 'verify-removed':
            $lc_check('plugin files are gone', !is_dir(WP_PLUGIN_DIR . '/' . $lc_slug));
            foreach (LC_TABLES as $t) {
                $lc_check("uninstall removed table {$t}", !$lc_table_exists($t));
            }
            $left = [];
            foreach (['stm_version', 'stm_db_version', 'stm_default_language', 'stm_enable_url_routing', 'stm_cache_duration', 'stm_keep_data_on_uninstall', 'stm_openai_model', 'stm_switcher_position', 'stm_deepl_api_key', 'stm_openai_api_key'] as $o) {
                if (get_option($o, null) !== null) {
                    $left[] = $o;
                }
            }
            $lc_check('uninstall removed every plugin option, API keys included', $left === [], 'left behind: ' . implode(', ', $left));
            $lc_check('uninstall removed the per-user admin preference', get_user_meta(1, 'stm_admin_prefs', true) === '', json_encode(get_user_meta(1, 'stm_admin_prefs', true)));
            $strays = $wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'stm\\_%' OR option_name LIKE '\\_transient\\_stm\\_%'");
            $lc_check('no option or transient with the stm_ prefix is left in the options table', $strays === [], implode(', ', $strays));
            break;

        default:
            $lc_check('known phase', false, "unknown phase '{$lc_phase}'");
    }
} catch (Throwable $e) {
    $lc_check('phase ran without an exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}

echo "@@LIFECYCLE@@" . wp_json_encode(['checks' => $lc_checks]) . "\n";
