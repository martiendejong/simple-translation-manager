<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.test.lifecycle-functional

/**
 * The "functional" phase of stm-lifecycle.php: REST permissions for every route (in-process and over
 * HTTP, anonymous included), content that must not leak, frontend pages, admin screens per role,
 * admin form handlers with and without a nonce, and an import/export round trip.
 *
 * Included by stm-lifecycle.php, which provides the helpers and the $lc_* variables.
 */

if (!defined('ABSPATH') || !isset($lc_check)) {
    exit;
}

// phpcs:disable WordPress -- test harness for a throwaway site, not plugin code

// ---- fixtures ---------------------------------------------------------------------------------

$ids      = $lc_seed();
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach (['lc-subscriber', 'lc-editor'] as $login) {
    $old = get_user_by('login', $login);
    if ($old) {
        wp_delete_user($old->ID);
    }
}
$sub_pw   = 'lc-' . bin2hex(random_bytes(8));
$ed_pw    = 'lc-' . bin2hex(random_bytes(8));
$sub_id   = wp_insert_user(['user_login' => 'lc-subscriber', 'user_pass' => $sub_pw, 'user_email' => 'lc-subscriber@example.test', 'role' => 'subscriber']);
$ed_id    = wp_insert_user(['user_login' => 'lc-editor', 'user_pass' => $ed_pw, 'user_email' => 'lc-editor@example.test', 'role' => 'editor']);
$lc_check('synthetic subscriber and editor exist', !is_wp_error($sub_id) && !is_wp_error($ed_id));

$hidden = [];
foreach (['draft' => [], 'private' => [], 'password' => ['post_status' => 'publish', 'post_password' => 'lc-post-password']] as $kind => $extra) {
    $pid = wp_insert_post(array_merge([
        'post_title'   => "Lifecycle {$kind} post",
        'post_name'    => "lifecycle-{$kind}-post",
        'post_content' => "English {$kind} body.",
        'post_status'  => $kind === 'password' ? 'publish' : $kind,
    ], $extra));
    $hidden[$kind] = $pid;
    $lc_insert('post_translations', ['post_id' => $pid, 'field_name' => 'post_title', 'language_code' => 'nl', 'translation' => 'LC-SECRET-' . strtoupper($kind) . '-NL']);
}
$sid_draft = $lc_insert('strings', ['string_key' => 'lc.unpublished', 'context' => 'lifecycle', 'description' => 'has only a draft translation']);
$lc_insert('translations', ['string_id' => $sid_draft, 'language_code' => 'nl', 'translation' => 'LC-DRAFT-TRANSLATION', 'status' => 'draft']);
$post_id = $ids['post'];

// ---- 1. REST permission matrix, in-process: what does each route's permission_callback decide? ----

$expected_public = [
    'GET /stm/v1/languages',
    'GET /stm/v1/strings',
    'GET /stm/v1/strings/(?P<id>\d+)',
    'GET /stm/v1/translations',
    'GET /stm/v1/posts/(?P<id>\d+)/translations',
    'GET /stm/v1/posts/(?P<id>\d+)/slugs',
];

$probes = [];
foreach (rest_get_server()->get_routes('stm/v1') as $pattern => $handlers) {
    if ($pattern === '/stm/v1') {
        continue; // the namespace index WordPress core adds itself
    }
    foreach ($handlers as $h) {
        foreach (array_keys((array) ($h['methods'] ?? [])) as $method) {
            $probes[] = [$method, $pattern, $h];
        }
    }
}
$lc_check('the plugin registers REST routes (at least 20 method/route pairs)', count($probes) >= 20, 'found ' . count($probes));

$decide = static function (int $user_id) use ($probes, $post_id): array {
    wp_set_current_user($user_id);
    $allowed = [];
    foreach ($probes as [$method, $pattern, $h]) {
        $params = [];
        if (preg_match_all('/\(\?P<(\w+)>/', $pattern, $m)) {
            foreach ($m[1] as $name) {
                $params[$name] = $name === 'lang' ? 'nl' : (strpos($pattern, '/posts/') !== false ? $post_id : 999999);
            }
        }
        $request = new WP_REST_Request($method, $pattern);
        $request->set_url_params($params);
        $perm = $h['permission_callback'] ?? null;
        if ($perm === null) {
            $ok = true;
        } else {
            $r  = call_user_func($perm, $request);
            $ok = !is_wp_error($r) && (bool) $r;
        }
        if ($ok) {
            $allowed[] = $method . ' ' . $pattern;
        }
    }
    sort($allowed);
    wp_set_current_user(0);
    return $allowed;
};

$anon_allowed = $decide(0);
$sorted_expected = $expected_public;
sort($sorted_expected);
$lc_check('anonymous visitors can call exactly the documented public read routes', $anon_allowed === $sorted_expected, 'extra: ' . implode('; ', array_diff($anon_allowed, $sorted_expected)) . ' missing: ' . implode('; ', array_diff($sorted_expected, $anon_allowed)));
$lc_check('a subscriber has no more REST rights than an anonymous visitor', $decide((int) $sub_id) === $anon_allowed, 'extra: ' . implode('; ', array_diff($decide((int) $sub_id), $anon_allowed)));
// By design the per-post routes (delete one translation, the Elementor panel) follow edit_post, so an editor may use them on posts they can edit, and nothing else.
$post_scoped = [];
foreach ($probes as [$method, $pattern, $h]) {
    $perm = $h['permission_callback'] ?? null;
    if (is_array($perm) && ($perm[1] ?? '') === 'check_edit_post_permission') {
        $post_scoped[] = $method . ' ' . $pattern;
    }
}
$editor_expected = array_values(array_unique(array_merge($anon_allowed, $post_scoped)));
sort($editor_expected);
$editor_allowed = $decide((int) $ed_id);
$lc_check('an editor may call the public routes plus only the routes that follow edit_post, nothing administrator-only', $editor_allowed === $editor_expected, 'extra: ' . implode('; ', array_diff($editor_allowed, $editor_expected)) . ' missing: ' . implode('; ', array_diff($editor_expected, $editor_allowed)));
$lc_check('the edit_post routes are exactly the post-scoped ones (delete translation, Elementor panel)', $post_scoped !== [] && count(array_filter($post_scoped, static fn ($r) => strpos($r, '/posts/(?P<id>\d+)/') === false)) === 0, implode('; ', $post_scoped));
$admin_allowed = $decide(1);
$lc_check('an administrator may call every route', count($admin_allowed) === count($probes), count($admin_allowed) . ' of ' . count($probes));

// ---- 2. REST over HTTP: anonymous, subscriber, editor, administrator ------------------------------

$sub_cookies = $lc_login('lc-subscriber', $sub_pw);
$ed_cookies  = $lc_login('lc-editor', $ed_pw);
$adm_cookies = $lc_login('scan-admin', $lc_admin_pw);
$lc_check('subscriber, editor and administrator can log in over HTTP', $sub_cookies !== [] && $ed_cookies !== [] && $adm_cookies !== [], 'subscriber=' . count($sub_cookies) . ' editor=' . count($ed_cookies) . ' admin=' . count($adm_cookies));
$sub_nonce = $lc_rest_nonce($sub_cookies);
$ed_nonce  = $lc_rest_nonce($ed_cookies);
$adm_nonce = $lc_rest_nonce($adm_cookies);
$lc_check('a REST nonce is issued to each logged-in role', $sub_nonce !== '' && $ed_nonce !== '' && $adm_nonce !== '');

$r = $lc_rest('GET', '/languages');
$lc_check('anonymous GET /languages answers 200 with a list of languages', $r['code'] === 200 && is_array($r['json']) && count($r['json']) >= 2, 'HTTP ' . $r['code'] . ' ' . substr($r['body'], 0, 120));

$denied = [401, 403];
foreach ([
    ['POST', '/languages', ['code' => 'zz', 'name' => 'Synthetic', 'native_name' => 'Synthetic']],
    ['POST', '/strings', ['key' => 'lc.anon.write', 'context' => 'lifecycle']],
    ['DELETE', '/strings/' . $ids['string:lc.greeting'], null],
    ['POST', '/translations', ['string_id' => $ids['string:lc.greeting'], 'language_code' => 'nl', 'translation' => 'LC-ANON-WRITE']],
    ['POST', '/import', ['lang' => 'nl', 'translations' => ['lc.anon.import' => 'LC-ANON-IMPORT']]],
    ['GET', '/export?lang=nl', null],
    ['GET', '/export/po?lang=nl', null],
    ['GET', '/export/xliff?source=en&target=nl', null],
    ['POST', '/cache/flush', null],
    ['POST', '/translations/bulk', ['items' => []]],
    ['POST', '/posts/' . $post_id . '/translations', ['lang' => 'nl', 'fields' => ['post_title' => 'LC-ANON-POST-WRITE']]],
    ['POST', '/posts/' . $post_id . '/slugs', ['lang' => 'nl', 'slug' => 'anon-slug']],
] as [$method, $route, $json]) {
    $anon = $lc_rest($method, $route, $json === null ? [] : ['json' => $json]);
    $lc_check("anonymous {$method} /stm/v1{$route} is refused (401/403)", in_array($anon['code'], $denied, true), 'HTTP ' . $anon['code'] . ' ' . substr($anon['body'], 0, 100));
    foreach ([['subscriber', $sub_cookies, $sub_nonce], ['editor', $ed_cookies, $ed_nonce]] as [$role, $cookies, $nonce]) {
        $as = $lc_rest($method, $route, ['cookies' => $cookies, 'nonce' => $nonce] + ($json === null ? [] : ['json' => $json]));
        $lc_check("{$role} {$method} /stm/v1{$route} is refused (403)", in_array($as['code'], $denied, true), 'HTTP ' . $as['code'] . ' ' . substr($as['body'], 0, 100));
    }
}
$still = $wpdb->get_var($wpdb->prepare("SELECT translation FROM {$lc_prefix}stm_translations WHERE string_id = %d AND language_code = 'nl'", $ids['string:lc.greeting']));
$lc_check('the refused anonymous, subscriber and editor writes changed nothing', $still === 'Hallo wereld' && (int) $wpdb->get_var("SELECT COUNT(*) FROM {$lc_prefix}stm_languages WHERE code = 'zz'") === 0 && $wpdb->get_var("SELECT id FROM {$lc_prefix}stm_strings WHERE string_key = 'lc.anon.write'") === null, 'translation is ' . json_encode($still));

// A forged cookie-less request that carries only a nonce must not be treated as logged in.
$forged = $lc_rest('POST', '/languages', ['nonce' => $adm_nonce, 'json' => ['code' => 'zy', 'name' => 'Forged', 'native_name' => 'Forged']]);
$lc_check('a valid administrator nonce without the login cookie does not authenticate', in_array($forged['code'], $denied, true), 'HTTP ' . $forged['code']);

// ---- 3. Content that must not leak through the public routes ---------------------------------------

$pub = $lc_rest('GET', '/posts/' . $post_id . '/translations');
$lc_check('anonymous: translations of a published post are readable', $pub['code'] === 200 && strpos($pub['body'], 'Synthetische titel') !== false, 'HTTP ' . $pub['code'] . ' ' . substr($pub['body'], 0, 120));
foreach ($hidden as $kind => $pid) {
    $secret = 'LC-SECRET-' . strtoupper($kind) . '-NL';
    foreach (['/translations', '/slugs'] as $suffix) {
        $a = $lc_rest('GET', "/posts/{$pid}{$suffix}");
        $lc_check("anonymous: {$kind} post {$suffix} does not reveal its translations", strpos($a['body'], $secret) === false, 'HTTP ' . $a['code'] . ' ' . substr($a['body'], 0, 120));
        $s = $lc_rest('GET', "/posts/{$pid}{$suffix}", ['cookies' => $sub_cookies, 'nonce' => $sub_nonce]);
        $lc_check("subscriber: {$kind} post {$suffix} does not reveal its translations", strpos($s['body'], $secret) === false, 'HTTP ' . $s['code'] . ' ' . substr($s['body'], 0, 120));
    }
    $e = $lc_rest('GET', "/posts/{$pid}/translations", ['cookies' => $ed_cookies, 'nonce' => $ed_nonce]);
    $lc_check("editor: may read the translations of a {$kind} post they can edit", $e['code'] === 200 && strpos($e['body'], $secret) !== false, 'HTTP ' . $e['code'] . ' ' . substr($e['body'], 0, 120));
}
$str_anon = $lc_rest('GET', '/strings?context=lifecycle');
$str_admin = $lc_rest('GET', '/strings?context=lifecycle', ['cookies' => $adm_cookies, 'nonce' => $adm_nonce]);
$tr_anon = $lc_rest('GET', '/translations?lang=nl');
$lc_check('anonymous: a draft translation is not listed on /strings or /translations', strpos($str_anon['body'], 'LC-DRAFT-TRANSLATION') === false && strpos($tr_anon['body'], 'LC-DRAFT-TRANSLATION') === false, 'strings HTTP ' . $str_anon['code'] . ', translations HTTP ' . $tr_anon['code']);
$lc_check('administrator: the draft translation is listed on /strings', strpos($str_admin['body'], 'LC-DRAFT-TRANSLATION') !== false, 'HTTP ' . $str_admin['code']);

// Hostile input on the public routes must not produce a server error or a database error.
foreach (['/strings?context=' . rawurlencode("x' OR '1'='1"), '/translations?lang=' . rawurlencode("nl' UNION SELECT user_pass FROM {$wpdb->users}-- -"), '/strings/' . rawurlencode('1 OR 1=1'), '/posts/' . rawurlencode('1;DROP TABLE x') . '/translations', '/translations?lang[]=a&lang[]=b', '/strings?context=' . rawurlencode(str_repeat('A', 5000))] as $route) {
    $x = $lc_rest('GET', $route);
    $lc_check("hostile input on GET {$route}: no server error, no hash or SQL error in the body", $x['code'] > 0 && $x['code'] < 500 && stripos($x['body'], '$P$') === false && stripos($x['body'], 'database error') === false, 'HTTP ' . $x['code'] . ' ' . substr($x['body'], 0, 120));
}

// ---- 4. Frontend ---------------------------------------------------------------------------------

$front = [
    '/'                                       => ['Lifecycle synthetic post'],
    '/lifecycle-synthetic-post/'              => ['Lifecycle synthetic post', 'English body of the synthetic post'],
    '/lifecycle-synthetic-post/?lang=nl'      => ['Synthetische titel', 'Nederlandse inhoud'],
    '/nl/lifecycle-synthetic-post/'           => ['Synthetische titel'],
    '/?lang=zz'                               => [],
    '/wp-sitemap.xml'                         => [],
];
foreach ($front as $path => $needles) {
    $p = $lc_http('GET', $path);
    $missing = array_filter($needles, static fn ($n) => strpos($p['body'], $n) === false);
    $lc_check("frontend GET {$path}: 200, expected text present, no PHP error in the page", $p['code'] === 200 && $missing === [] && !$lc_has_php_error($p['body']), 'HTTP ' . $p['code'] . ' ' . $p['error'] . ' missing: ' . implode(' | ', $missing));
}
$nl = $lc_http('GET', '/lifecycle-synthetic-post/?lang=nl');
$set_cookie = $nl['headers']['set-cookie'] ?? [];
$stm_cookies = array_filter((array) $set_cookie, static fn ($c) => strpos((string) $c, 'stm_lang=') === 0);
$lc_check('one stm_lang Set-Cookie header per response (no duplicate cookie)', count($stm_cookies) <= 1, count($stm_cookies) . ' stm_lang cookies');
$en = $lc_http('GET', '/lifecycle-synthetic-post/');
$lc_check('hreflang alternates are advertised for the translated post', strpos($en['body'], 'hreflang="nl"') !== false || strpos($en['body'], "hreflang='nl'") !== false, 'no hreflang="nl" in the English page');
$root = $lc_http('GET', '/wp-json/');
$lc_check('the site REST index lists the stm/v1 namespace', $root['code'] === 200 && in_array('stm/v1', (array) ($root['json']['namespaces'] ?? []), true), 'HTTP ' . $root['code']);

// ---- 5. Admin screens per role ------------------------------------------------------------------------

$dash = $lc_http('GET', '/wp-admin/', ['cookies' => $adm_cookies]);
preg_match_all('/page=(stm-[a-z-]+)/', $dash['body'], $m);
$slugs = array_values(array_unique($m[1]));
sort($slugs);
$expected_pages = ['stm-dashboard', 'stm-documentation', 'stm-field-values', 'stm-import-export', 'stm-languages', 'stm-settings', 'stm-translations'];
$lc_check('the administrator menu lists all seven STM screens', array_diff($expected_pages, $slugs) === [], 'found: ' . implode(', ', $slugs));
foreach ($expected_pages as $page) {
    $a = $lc_http('GET', '/wp-admin/admin.php?page=' . $page, ['cookies' => $adm_cookies]);
    $lc_check("administrator opens {$page}: 200, a screen is rendered, no PHP error", $a['code'] === 200 && strpos($a['body'], 'class="wrap') !== false && !$lc_has_php_error($a['body']), 'HTTP ' . $a['code'] . ' ' . substr(trim(strip_tags($a['body'])), 0, 120));
    foreach ([['subscriber', $sub_cookies], ['editor', $ed_cookies]] as [$role, $cookies]) {
        $o = $lc_http('GET', '/wp-admin/admin.php?page=' . $page, ['cookies' => $cookies]);
        $lc_check("{$role} cannot open {$page}", in_array($o['code'], [302, 403], true) && strpos($o['body'], 'class="wrap stm') === false, 'HTTP ' . $o['code']);
    }
}
$edit = $lc_http('GET', '/wp-admin/post.php?post=' . $post_id . '&action=edit', ['cookies' => $adm_cookies]);
$lc_check('the post edit screen loads with the plugin active', $edit['code'] === 200 && !$lc_has_php_error($edit['body']), 'HTTP ' . $edit['code']);

// ---- 6. Admin form handlers: nonce and capability ----------------------------------------------------------

$ie_page = $lc_http('GET', '/wp-admin/admin.php?page=stm-import-export', ['cookies' => $adm_cookies]);
$nonce_for = static function (string $html, string $action): string {
    if (preg_match('/name="action"\s+value="' . preg_quote($action, '/') . '"\s*>\s*<input[^>]*name="_wpnonce"[^>]*value="([a-f0-9]+)"/is', $html, $mm)) {
        return $mm[1];
    }
    if (preg_match('/id="_wpnonce"[^>]*value="([a-f0-9]+)"/i', $html, $mm)) {
        return $mm[1];
    }
    return '';
};
$import_nonce = $nonce_for($ie_page['body'], 'stm_import_json');
$lc_check('the import form carries a nonce', $import_nonce !== '', 'no _wpnonce found in the import page');

$json_file = wp_json_encode(['lang' => 'nl', 'translations' => ['lc.admin.form' => 'Via het beheerformulier']]);
$post_import = static function (array $cookies, string $nonce) use ($lc_http, $lc_multipart, $json_file): array {
    $mp = $lc_multipart(['action' => 'stm_import_json', '_wpnonce' => $nonce], 'stm_import_file', 'lc.json', $json_file);
    return $lc_http('POST', '/wp-admin/admin-post.php', ['cookies' => $cookies, 'headers' => $mp['headers'], 'body' => $mp['body']]);
};
$imported = static fn () => $wpdb->get_var("SELECT t.translation FROM {$lc_prefix}stm_translations t JOIN {$lc_prefix}stm_strings s ON s.id = t.string_id WHERE s.string_key = 'lc.admin.form'");

$r = $post_import([], $import_nonce);
$lc_check('anonymous POST to the import form handler imports nothing', $imported() === null, 'HTTP ' . $r['code']);
$r = $post_import($adm_cookies, '');
$lc_check('administrator POST without a nonce is refused and imports nothing', in_array($r['code'], [400, 403], true) && $imported() === null, 'HTTP ' . $r['code']);
$r = $post_import($sub_cookies, $import_nonce);
$lc_check("subscriber POST with the administrator's nonce is refused and imports nothing", in_array($r['code'], [400, 403], true) && $imported() === null, 'HTTP ' . $r['code']);
$r = $post_import($adm_cookies, $import_nonce);
$lc_check('administrator POST with the nonce imports the file', in_array($r['code'], [200, 302], true) && $imported() === 'Via het beheerformulier', 'HTTP ' . $r['code'] . ' stored=' . json_encode($imported()));

foreach (['stm_export_xliff', 'stm_export_po'] as $action) {
    foreach ([['anonymous', []], ['administrator without nonce', $adm_cookies]] as [$who, $cookies]) {
        $x = $lc_http('POST', '/wp-admin/admin-post.php', ['cookies' => $cookies, 'body' => ['action' => $action, 'lang' => 'nl']]);
        $lc_check("{$who} cannot download an export through admin-post.php?action={$action}", strpos($x['body'], 'Hallo wereld') === false, 'HTTP ' . $x['code']);
    }
}

// ---- 7. Import and export round trips over REST, as administrator --------------------------------------------

$adm = ['cookies' => $adm_cookies, 'nonce' => $adm_nonce];
$translation_of = static fn (string $key, string $lang = 'nl') => $wpdb->get_var($wpdb->prepare("SELECT t.translation FROM {$lc_prefix}stm_translations t JOIN {$lc_prefix}stm_strings s ON s.id = t.string_id WHERE s.string_key = %s AND t.language_code = %s", $key, $lang));

$ex = $lc_rest('GET', '/export?lang=nl', $adm);
$lc_check('JSON export contains the seeded Dutch translation', $ex['code'] === 200 && ($ex['json']['nl']['lc.greeting'] ?? '') === 'Hallo wereld', 'HTTP ' . $ex['code'] . ' ' . substr($ex['body'], 0, 120));
$dry = $lc_rest('POST', '/import?dry_run=true', $adm + ['json' => ['lang' => 'nl', 'translations' => ['lc.dry' => 'Droogloop']]]);
$lc_check('JSON import with dry_run writes nothing', $dry['code'] === 200 && $translation_of('lc.dry') === null, 'HTTP ' . $dry['code'] . ' ' . substr($dry['body'], 0, 120));
$imp = $lc_rest('POST', '/import', $adm + ['json' => ['lang' => 'nl', 'translations' => ['lc.imported' => 'Geimporteerd', 'lc.greeting' => 'Hallo wereld']]]);
$lc_check('JSON import creates a new translation', $imp['code'] === 200 && $translation_of('lc.imported') === 'Geimporteerd', 'HTTP ' . $imp['code'] . ' ' . substr($imp['body'], 0, 160));
$bad = $lc_rest('POST', '/import', $adm + ['json' => ['nonsense' => true]]);
$lc_check('JSON import of an unrecognised format is a clean 400', $bad['code'] === 400, 'HTTP ' . $bad['code']);

$po = $lc_rest('GET', '/export/po?lang=nl', $adm);
$po_text = is_string($po['json']) ? $po['json'] : $po['body'];
$lc_check('PO export contains the seeded translation', $po['code'] === 200 && strpos($po_text, 'Hallo wereld') !== false, 'HTTP ' . $po['code']);
$mp = $lc_multipart(['lang' => 'nl'], 'file', 'lc.po', str_replace('Hallo wereld', 'Hallo wereld (bewerkt)', $po_text));
$ipo = $lc_rest('POST', '/import/file', $adm + ['raw' => $mp]);
$lc_check('PO import updates the translation', $ipo['code'] === 200 && $translation_of('lc.greeting') === 'Hallo wereld (bewerkt)', 'HTTP ' . $ipo['code'] . ' now=' . json_encode($translation_of('lc.greeting')) . ' ' . substr($ipo['body'], 0, 120));

$xl = $lc_rest('GET', '/export/xliff?source=en&target=nl', $adm);
$xl_text = is_string($xl['json']) ? $xl['json'] : $xl['body'];
$dom = new DOMDocument();
$lc_check('XLIFF export is well-formed XML and contains the seeded key', $xl['code'] === 200 && @$dom->loadXML($xl_text) && strpos($xl_text, 'lc.greeting') !== false, 'HTTP ' . $xl['code'] . ' ' . substr($xl_text, 0, 120));
$mp = $lc_multipart([], 'file', 'lc.xliff', str_replace('Hallo wereld (bewerkt)', 'Hallo wereld (xliff)', $xl_text));
$ixl = $lc_rest('POST', '/import/file', $adm + ['raw' => $mp]);
$lc_check('XLIFF import updates the translation', $ixl['code'] === 200 && $translation_of('lc.greeting') === 'Hallo wereld (xliff)', 'HTTP ' . $ixl['code'] . ' now=' . json_encode($translation_of('lc.greeting')) . ' ' . substr($ixl['body'], 0, 160));

// An external entity in an XLIFF file must never pull a local file into the database.
$secret_file = rtrim(sys_get_temp_dir(), '/\\') . '/lc-xxe-secret.txt';
file_put_contents($secret_file, 'LC-XXE-SECRET-VALUE');
$xxe = '<?xml version="1.0" encoding="UTF-8"?><!DOCTYPE xliff [<!ENTITY xxe SYSTEM "file://' . str_replace('\\', '/', $secret_file) . '">]>'
    . '<xliff version="1.2" xmlns="urn:oasis:names:tc:xliff:document:1.2"><file source-language="en" target-language="nl" datatype="plaintext" original="stm"><body>'
    . '<trans-unit id="lc.xxe"><source>lc.xxe</source><target>&xxe;</target></trans-unit></body></file></xliff>';
$mp  = $lc_multipart([], 'file', 'xxe.xliff', $xxe);
$ixx = $lc_rest('POST', '/import/file', $adm + ['raw' => $mp]);
$leaked = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$lc_prefix}stm_translations WHERE translation LIKE '%LC-XXE-SECRET%'");
@unlink($secret_file);
$lc_check('XLIFF import does not resolve external entities (no local file content stored)', $leaked === 0, 'HTTP ' . $ixx['code'] . ', rows containing the secret: ' . $leaked);

$mp  = $lc_multipart(['lang' => 'nl'], 'file', 'evil.php', '<?php echo "x";');
$evil = $lc_rest('POST', '/import/file', $adm + ['raw' => $mp]);
$lc_check('an upload with an unsupported extension is refused with 400', $evil['code'] === 400, 'HTTP ' . $evil['code']);
$mp  = $lc_multipart(['lang' => 'nl'], 'file', 'broken.xliff', '<xliff><unclosed>');
$broken = $lc_rest('POST', '/import/file', $adm + ['raw' => $mp]);
$lc_check('a malformed XLIFF file does not crash the site (no 5xx)', $broken['code'] > 0 && $broken['code'] < 500, 'HTTP ' . $broken['code'] . ' ' . substr($broken['body'], 0, 120));
foreach ([['anonymous', []], ['subscriber', ['cookies' => $sub_cookies, 'nonce' => $sub_nonce]]] as [$who, $auth]) {
    $mp = $lc_multipart(['lang' => 'nl'], 'file', 'lc.po', "msgid \"lc.denied\"\nmsgstr \"nope\"\n");
    $x  = $lc_rest('POST', '/import/file', $auth + ['raw' => $mp]);
    $lc_check("{$who} cannot upload an import file", in_array($x['code'], $denied, true) && $translation_of('lc.denied') === null, 'HTTP ' . $x['code']);
}

// ---- 8. Language and string management over REST ---------------------------------------------------------------

$new = $lc_rest('POST', '/languages', $adm + ['json' => ['code' => 'de', 'name' => 'German', 'native_name' => 'Deutsch', 'flag_emoji' => '', 'order_index' => 9]]);
$lc_check('administrator can add a language', $new['code'] === 200 && !empty($new['json']['id']), 'HTTP ' . $new['code'] . ' ' . substr($new['body'], 0, 120));
$bad = $lc_rest('POST', '/languages', $adm + ['json' => ['code' => 'toolongcode', 'name' => 'X', 'native_name' => 'X']]);
$lc_check('an invalid language code is a clean 400', $bad['code'] === 400, 'HTTP ' . $bad['code']);
if (!empty($new['json']['id'])) {
    $del = $lc_rest('DELETE', '/languages/' . (int) $new['json']['id'], $adm);
    $lc_check('administrator can delete the added language', in_array($del['code'], [200, 204], true) && (int) $wpdb->get_var("SELECT COUNT(*) FROM {$lc_prefix}stm_languages WHERE code = 'de'") === 0, 'HTTP ' . $del['code'] . ' ' . substr($del['body'], 0, 120));
}
$newstr = $lc_rest('POST', '/strings', $adm + ['json' => ['key' => 'lc.rest.created', 'context' => 'lifecycle']]);
$lc_check('administrator can create a string', $newstr['code'] === 200 || $newstr['code'] === 201, 'HTTP ' . $newstr['code'] . ' ' . substr($newstr['body'], 0, 120));
$badstr = $lc_rest('POST', '/strings', $adm + ['json' => ['key' => "bad key <script>alert(1)</script>", 'context' => 'lifecycle']]);
$markup = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$lc_prefix}stm_strings WHERE string_key LIKE '%<%' OR string_key LIKE '%>%'");
$lc_check('a string key with markup is either refused or stored without the markup', $badstr['code'] === 400 || ($badstr['code'] === 200 && $markup === 0), 'HTTP ' . $badstr['code'] . ', keys with markup stored: ' . $markup);

// Post-scoped delete: an editor may remove one translation of a post they can edit, an anonymous visitor may not.
$del_anon = $lc_rest('DELETE', '/posts/' . $post_id . '/translations/nl');
$lc_check('anonymous DELETE of a post translation is refused', in_array($del_anon['code'], $denied, true), 'HTTP ' . $del_anon['code']);
$del_sub = $lc_rest('DELETE', '/posts/' . $post_id . '/translations/nl', ['cookies' => $sub_cookies, 'nonce' => $sub_nonce]);
$lc_check('subscriber DELETE of a post translation is refused', in_array($del_sub['code'], $denied, true), 'HTTP ' . $del_sub['code']);
$still_there = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$lc_prefix}stm_post_translations WHERE post_id = %d AND language_code = 'nl'", $post_id));
$lc_check('the refused deletes removed nothing', $still_there >= 3, 'rows left: ' . $still_there);

wp_set_current_user(0);
