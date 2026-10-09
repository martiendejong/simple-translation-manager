<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.bin.provenance

/**
 * Source-provenance tooling for Simple Translation Manager (task 5182).
 *
 * Development-only: this script is excluded from the plugin ZIP (the ZIP is
 * built from the STM_PROV_DIST_PATHS allowlist below). It never runs inside
 * WordPress and never talks to the network.
 *
 * What it is for: STM is GPL software and forks are welcome. When someone
 * asks "where did this code come from?", the recognition points added by
 * task 5182 (SPDX/copyright headers, Source-Id lines, diagnostic codes,
 * numbered design notes, synthetic fixtures) give a human reviewer concrete
 * things to look at. They are indicators for provenance research. They do
 * not prove copying, intent, AI use or infringement, and independently
 * written code that behaves the same way is expected to match none of them.
 *
 * Usage:
 *   php bin/provenance.php check         [repo-root]
 *   php bin/provenance.php apply-headers [repo-root]
 *   php bin/provenance.php manifest      [repo-root] [--out=FILE] [--no-git]
 *   php bin/provenance.php compare       <reference-root> <candidate-root> [--format=json|md] [--out=FILE]
 *   php bin/provenance.php release       [repo-root] --out-dir=DIR [--commit=REF] [--published=YYYY-MM-DD]
 *   php bin/provenance.php verify-release <release-record.json> [--repo=ROOT]
 *   php bin/provenance.php demo          [--format=json|md]
 *
 * manifest and release write only OUTSIDE the repository: the manifest lists
 * every recognition point and must not sit next to the code it describes.
 */

const STM_PROV_HOLDER = 'Martien de Jong';
const STM_PROV_YEAR   = '2026';
const STM_PROV_SPDX   = 'GPL-2.0-or-later';

/** Directories that never contain own source (third-party or generated). */
const STM_PROV_SKIP_DIRS = ['vendor', 'node_modules', 'coverage', '.git', '.phpunit.cache', 'dist'];

/**
 * Own PHP/JS source files, relative to $root, forward slashes, sorted.
 *
 * @return string[]
 */
function stm_prov_source_files(string $root): array
{
    $root  = rtrim(str_replace('\\', '/', $root), '/');
    $found = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            static function (SplFileInfo $current) {
                return !($current->isDir() && in_array($current->getFilename(), STM_PROV_SKIP_DIRS, true));
            }
        )
    );

    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $name = $file->getFilename();
        if (!preg_match('/\.(php|js|mjs)$/', $name) || preg_match('/\.min\.js$/', $name)) {
            continue;
        }
        $found[] = ltrim(substr(str_replace('\\', '/', $file->getPathname()), strlen($root)), '/');
    }

    sort($found);
    return $found;
}

/**
 * Stable per-module source identifier derived from the file path. The
 * identifier is written into the file once (apply-headers) and from then on
 * the header line, not the path, is authoritative - so renaming a file does
 * not change the identity of the module.
 */
function stm_prov_derive_source_id(string $rel): string
{
    $base = preg_replace('/\.(php|js|mjs)$/', '', basename($rel));
    $dir  = dirname($rel);

    if ($rel === 'simple-translation-manager.php') {
        return 'stm.bootstrap';
    }
    if ($rel === 'uninstall.php') {
        return 'stm.uninstall';
    }
    if ($dir === 'includes') {
        $base = preg_replace('/^class-/', '', $base);
        return 'stm.' . $base;
    }
    if ($dir === 'templates') {
        return 'stm.tpl.' . $base;
    }
    if ($dir === 'assets') {
        return 'stm.js.' . $base;
    }
    if ($dir === 'bin') {
        // diff-coverage exists as both .php and .js; keep their identities distinct.
        return 'stm.bin.' . $base . (preg_match('/\.(js|mjs)$/', $rel) ? '-js' : '');
    }
    if ($dir === 'tests' || strpos($dir, 'tests/') === 0) {
        return 'stm.test.' . strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', preg_replace('/\.(php|js)$/', '', substr($rel, 6))));
    }
    if ($dir === 'docs/editors') {
        return 'stm.docs.' . $base;
    }

    return 'stm.misc.' . strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $base));
}

/** @return string[] the three header lines, without comment markers. */
function stm_prov_header_text(string $sourceId): array
{
    return [
        'SPDX-License-Identifier: ' . STM_PROV_SPDX,
        'SPDX-FileCopyrightText: ' . STM_PROV_YEAR . ' ' . STM_PROV_HOLDER,
        'Source-Id: ' . $sourceId,
    ];
}

/**
 * Read SPDX/copyright/Source-Id out of the first 60 lines of a file.
 *
 * @return array{spdx: ?string, copyright: ?string, source_id: ?string}
 */
function stm_prov_parse_header(string $contents): array
{
    $head = implode("\n", array_slice(preg_split('/\R/', $contents), 0, 60));
    $get  = static function (string $pattern) use ($head) {
        return preg_match($pattern, $head, $m) ? trim($m[1]) : null;
    };

    return [
        'spdx'      => $get('/SPDX-License-Identifier:\s*([^\r\n*]+?)\s*(?:\*\/)?\s*$/m'),
        'copyright' => $get('/SPDX-FileCopyrightText:\s*([^\r\n*]+?)\s*(?:\*\/)?\s*$/m'),
        'source_id' => $get('/Source-Id:\s*(stm\.[a-z0-9.\-]+)\s*(?:\*\/)?\s*$/m'),
    ];
}

/**
 * Insert the header into $contents when it is missing. Idempotent.
 * PHP: right after the opening tag (after the plugin-header docblock for the
 * main plugin file, which WordPress parses from the top of the file).
 * JS: at the top, after a shebang line if there is one.
 */
function stm_prov_apply_header(string $contents, string $rel): string
{
    $parsed = stm_prov_parse_header($contents);
    if ($parsed['spdx'] !== null && $parsed['copyright'] !== null && $parsed['source_id'] !== null) {
        return $contents;
    }

    $eol = (strpos($contents, "\r\n") !== false) ? "\r\n" : "\n";
    $bom = '';
    if (strncmp($contents, "\xEF\xBB\xBF", 3) === 0) {
        $bom      = "\xEF\xBB\xBF";
        $contents = substr($contents, 3);
    }

    $lines = stm_prov_header_text(stm_prov_derive_source_id($rel));
    $block = implode($eol, array_map(static function ($l) {
        return '// ' . $l;
    }, $lines)) . $eol;

    if (substr($rel, -4) === '.php') {
        if (!preg_match('/^<\?php[ \t]*\r?\n/', $contents, $open)) {
            throw new RuntimeException("{$rel}: expected the file to start with '<?php' on its own line");
        }
        $afterOpen = strlen($open[0]);

        // Main plugin file: keep the WordPress plugin-header docblock first.
        if (preg_match('/\G\/\*\*(?:(?!\*\/).)*?Plugin Name:.*?\*\/\r?\n/s', $contents, $docblock, 0, $afterOpen)) {
            $pos = $afterOpen + strlen($docblock[0]);
            return $bom . substr($contents, 0, $pos) . $eol . $block . substr($contents, $pos);
        }

        return $bom . substr($contents, 0, $afterOpen) . $block . $eol . substr($contents, $afterOpen);
    }

    $shebang = '';
    if (strncmp($contents, '#!', 2) === 0) {
        $nl      = strpos($contents, "\n");
        $shebang = substr($contents, 0, $nl + 1);
        $contents = substr($contents, $nl + 1);
    }

    return $bom . $shebang . $block . $eol . $contents;
}

/**
 * Header problems across the tree. Empty array = clean.
 *
 * @return string[]
 */
function stm_prov_check(string $root): array
{
    $problems = [];
    $seen     = [];

    foreach (stm_prov_source_files($root) as $rel) {
        $parsed = stm_prov_parse_header((string) file_get_contents($root . '/' . $rel));

        if ($parsed['spdx'] !== STM_PROV_SPDX) {
            $problems[] = "{$rel}: SPDX-License-Identifier missing or not " . STM_PROV_SPDX;
        }
        if ($parsed['copyright'] !== STM_PROV_YEAR . ' ' . STM_PROV_HOLDER) {
            $problems[] = "{$rel}: SPDX-FileCopyrightText missing or not '" . STM_PROV_YEAR . ' ' . STM_PROV_HOLDER . "'";
        }
        if ($parsed['source_id'] === null) {
            $problems[] = "{$rel}: Source-Id missing";
        } elseif (isset($seen[$parsed['source_id']])) {
            $problems[] = "{$rel}: Source-Id {$parsed['source_id']} already used by {$seen[$parsed['source_id']]}";
        } else {
            $seen[$parsed['source_id']] = $rel;
        }
    }

    return $problems;
}

// ---------------------------------------------------------------------------
// Recognition-point (marker) collection
// ---------------------------------------------------------------------------

/**
 * Marker kinds and where they are searched. Distributed code (includes/,
 * templates/, assets/, the plugin file) carries the kinds; tests/fixtures/
 * carries the fixture kind. The tooling and the test suite are deliberately
 * not scanned so that sample markers inside them cannot pollute a manifest.
 */
function stm_prov_marker_patterns(): array
{
    return [
        'diag-code'   => '/STM-[EWI]-[A-Z0-9]+(?:-[A-Z0-9]+)+/',
        'design-note' => '/\[(STM-DN-\d{2,3})\]/',
        'symbol-doc'  => '/\[(STM-SYM-\d{2,3})\]/',
    ];
}

function stm_prov_is_distributed_source(string $rel): bool
{
    return (bool) preg_match('#^(includes/|templates/|assets/|simple-translation-manager\.php$|uninstall\.php$)#', $rel);
}

/** Nearest class declared at or above a 0-based line index, or null. */
function stm_prov_class_above(array $lines, int $idx): ?string
{
    for ($i = min($idx, count($lines) - 1); $i >= 0; $i--) {
        if (preg_match('/^\s*(?:final\s+|abstract\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)/', $lines[$i], $m)) {
            return $m[1];
        }
    }
    return null;
}

/**
 * A tag inside a docblock belongs to the declaration the docblock
 * documents, which comes AFTER it. When the 1-based line sits inside such a
 * docblock, return that declaration ("Class::member", a global function, or a
 * class name); null when the line is not inside a docblock or nothing is
 * declared right after it.
 */
function stm_prov_documented_symbol(array $lines, int $lineNo): ?string
{
    $idx  = min($lineNo, count($lines)) - 1;
    $open = null;
    for ($i = $idx; $i >= 0; $i--) {
        if ($i < $idx && strpos($lines[$i], '*/') !== false) {
            return null; // a comment closed above this line: it is not inside a docblock
        }
        if (preg_match('/^\s*\/\*\*/', $lines[$i])) {
            $open = $i;
            break;
        }
    }
    if ($open === null) {
        return null;
    }

    $end = $idx;
    while ($end < count($lines) && strpos($lines[$end], '*/') === false) {
        $end++;
    }

    $limit = min(count($lines), $end + 16);
    for ($n = $end + 1; $n < $limit; $n++) {
        $line = $lines[$n];
        if (trim($line) === '' || preg_match('/^\s*(?:namespace|use)\s/', $line) || preg_match('/^\s*(?:\/\/|#\[)/', $line)) {
            continue;
        }
        if (preg_match('/^\s*(?:final\s+|abstract\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)/', $line, $m)) {
            return $m[1];
        }
        if (preg_match('/^\s*(?:(?:public|protected|private|static|final|abstract)\s+)*(?:function|const)\s+&?([A-Za-z_][A-Za-z0-9_]*)/', $line, $m)) {
            $class = stm_prov_class_above($lines, $n);
            return $class !== null ? $class . '::' . $m[1] : $m[1];
        }
        break;
    }
    return null;
}

/** Enclosing "Class::member" for a 1-based line; a docblock tag names what the docblock documents. Best effort. */
function stm_prov_symbol_for_line(array $lines, int $lineNo): string
{
    $documented = stm_prov_documented_symbol($lines, $lineNo);
    if ($documented !== null) {
        return $documented;
    }

    $member = null;
    $class  = null;

    for ($i = min($lineNo, count($lines)) - 1; $i >= 0; $i--) {
        $line = $lines[$i];
        if ($member === null && preg_match('/^\s*(?:(?:public|protected|private|static|final|abstract)\s+)*(?:function|const)\s+&?([A-Za-z_][A-Za-z0-9_]*)/', $line, $m)) {
            $member = $m[1];
        }
        if ($class === null && preg_match('/^\s*(?:final\s+|abstract\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)/', $line, $m)) {
            $class = $m[1];
            break;
        }
    }

    if ($class !== null && $member !== null) {
        return $class . '::' . $member;
    }
    return $member ?? ($class ?? '(file)');
}

/**
 * All recognition points under $root.
 *
 * @return array<int, array{kind: string, id: string, file: string, line: int, symbol: string, context: string}>
 */
function stm_prov_collect_markers(string $root): array
{
    $root    = rtrim(str_replace('\\', '/', $root), '/');
    $markers = [];

    foreach (stm_prov_source_files($root) as $rel) {
        $contents = (string) file_get_contents($root . '/' . $rel);
        $lines    = preg_split('/\R/', $contents);
        $parsed   = stm_prov_parse_header($contents);

        // File-level kinds (any own source file).
        if ($parsed['source_id'] !== null) {
            $markers[] = ['kind' => 'source-id', 'id' => $parsed['source_id'], 'file' => $rel, 'line' => 0, 'symbol' => '(file)', 'context' => ''];
        }
        if ($parsed['copyright'] !== null) {
            $markers[] = ['kind' => 'copyright-header', 'id' => $parsed['copyright'], 'file' => $rel, 'line' => 0, 'symbol' => '(file)', 'context' => ''];
        }

        if (!stm_prov_is_distributed_source($rel)) {
            continue;
        }

        foreach ($lines as $idx => $line) {
            foreach (stm_prov_marker_patterns() as $kind => $pattern) {
                if (!preg_match_all($pattern, $line, $hits)) {
                    continue;
                }
                foreach ($hits[0] as $n => $whole) {
                    $id      = $kind === 'diag-code' ? $whole : $hits[1][$n];
                    $context = '';
                    if ($kind !== 'diag-code') {
                        // Prose that follows the tag, plus the next comment line, for prose matching.
                        $after   = trim(substr($line, strpos($line, $whole) + strlen($whole)));
                        $next    = isset($lines[$idx + 1]) ? trim(preg_replace('#^\s*(//|\*)\s?#', '', $lines[$idx + 1])) : '';
                        $context = stm_prov_normalize_space(rtrim($after . ' ' . $next));
                    }
                    $markers[] = [
                        'kind'    => $kind,
                        'id'      => $id,
                        'file'    => $rel,
                        'line'    => $idx + 1,
                        'symbol'  => stm_prov_symbol_for_line($lines, $idx + 1),
                        'context' => $context,
                    ];
                }
            }
        }
    }

    // Fixtures: any file under tests/fixtures/ that names itself with a fixture id.
    $fixtureDir = $root . '/tests/fixtures';
    if (is_dir($fixtureDir)) {
        foreach (new DirectoryIterator($fixtureDir) as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $contents = (string) file_get_contents($file->getPathname());
            if (preg_match_all('/STM-FX-[A-Z0-9]+(?:-[A-Z0-9]+)*/', $contents, $hits)) {
                foreach (array_unique($hits[0]) as $id) {
                    $markers[] = [
                        'kind'    => 'fixture',
                        'id'      => $id,
                        'file'    => 'tests/fixtures/' . $file->getFilename(),
                        'line'    => 0,
                        'symbol'  => '(fixture)',
                        'context' => '',
                    ];
                }
            }
        }
    }

    return $markers;
}

function stm_prov_normalize_space(string $text): string
{
    return trim(preg_replace('/\s+/', ' ', $text));
}

// ---------------------------------------------------------------------------
// Private manifest
// ---------------------------------------------------------------------------

function stm_prov_git(string $root, string $args): ?string
{
    $cmd = 'git -C ' . escapeshellarg($root) . ' ' . $args . ' 2>' . (DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null');
    $out = shell_exec($cmd);
    if ($out === null) {
        return null;
    }
    $out = trim($out);
    return $out === '' ? null : $out;
}

/**
 * Build the private manifest. Pass $withGit = false for a tree that is not
 * a git checkout (the introduction commit and release are then null).
 */
function stm_prov_build_manifest(string $root, bool $withGit = true): array
{
    $root    = rtrim(str_replace('\\', '/', $root), '/');
    $markers = stm_prov_collect_markers($root);
    $version = trim((string) @file_get_contents($root . '/VERSION'));
    $head    = $withGit ? stm_prov_git($root, 'rev-parse HEAD') : null;

    $introCache = [];
    $releaseOf  = static function (?string $sha) use ($root, $withGit) {
        if (!$withGit || $sha === null) {
            return null;
        }
        $tag = stm_prov_git($root, 'tag --contains ' . escapeshellarg($sha) . ' --sort=version:refname');
        if ($tag !== null) {
            return strtok($tag, "\n");
        }
        $atCommit = stm_prov_git($root, 'show ' . escapeshellarg($sha . ':VERSION'));
        return 'unreleased (VERSION at introduction: ' . ($atCommit ?? 'unknown') . ')';
    };

    $out = [];
    foreach ($markers as $marker) {
        $needle = $marker['kind'] === 'design-note' || $marker['kind'] === 'symbol-doc' ? '[' . $marker['id'] . ']' : $marker['id'];
        $key    = $marker['file'] . "\0" . $needle;
        $sha    = null;
        if ($withGit) {
            if (!array_key_exists($key, $introCache)) {
                $log = stm_prov_git($root, 'log --reverse --format=%H -S' . escapeshellarg($needle) . ' -- ' . escapeshellarg($marker['file']));
                $introCache[$key] = $log === null ? null : strtok($log, "\n");
            }
            $sha = $introCache[$key];
        }
        $out[] = $marker + ['introduced_in' => $sha, 'release' => $releaseOf($sha)];
    }

    $modules = [];
    foreach (stm_prov_source_files($root) as $rel) {
        $contents = (string) file_get_contents($root . '/' . $rel);
        $parsed   = stm_prov_parse_header($contents);
        $modules[] = [
            'source_id' => $parsed['source_id'],
            'file'      => $rel,
            'sha256'    => hash('sha256', $contents),
        ];
    }

    return [
        'schema'       => 'stm-provenance-manifest/1',
        'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'plugin'       => ['slug' => 'simple-translation-manager', 'version' => $version, 'commit' => $head],
        'rights_holder' => [
            'name'        => STM_PROV_HOLDER,
            'basis'       => 'Plugin header "Author" and the repository commit history; third-party code (vendor/, node_modules/) is excluded and keeps its own notices.',
            'not_claimed' => 'No other party is named as rights holder; naming ProsperGenics requires confirming that entity first.',
        ],
        'policy'       => 'Recognition points support provenance research. They do not prove copying, intent, AI use or infringement; GPL forks are permitted.',
        'modules'      => $modules,
        'markers'      => $out,
    ];
}

// ---------------------------------------------------------------------------
// Comparison
// ---------------------------------------------------------------------------

/** @return array<string, string> relative path => contents, own PHP/JS of a tree. */
function stm_prov_read_corpus(string $root): array
{
    $root   = rtrim(str_replace('\\', '/', $root), '/');
    $corpus = [];
    foreach (stm_prov_source_files($root) as $rel) {
        $corpus[$rel] = (string) file_get_contents($root . '/' . $rel);
    }
    return $corpus;
}

/**
 * Symmetric normalisation: strip a leading "prefix_" or "Prefix\" segment
 * from identifiers and from words inside string literals. Applied identically
 * to both sides, so a copy whose only change is a different project prefix
 * normalises to the same token stream as the original - without having to
 * know what the new prefix is.
 */
function stm_prov_strip_prefix(string $text): string
{
    $text = preg_replace('/\b[A-Za-z0-9]{2,8}\\\\+(?=[A-Za-z])/', '', $text);        // Vendor\Class, Vendor\\Class
    $text = preg_replace('/\b[A-Za-z0-9]{2,6}_(?=[A-Za-z0-9])/', '', $text);        // prefix_name, PREFIX_NAME
    return $text;
}

/** @return string[] normalised token stream for a PHP source. */
function stm_prov_tokens_php(string $code): array
{
    $tokens = [];
    $skip   = [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE, T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO, T_CLOSE_TAG];

    foreach (token_get_all($code) as $token) {
        if (is_array($token)) {
            if (in_array($token[0], $skip, true)) {
                continue;
            }
            if ($token[0] === T_INLINE_HTML) {
                $tokens[] = stm_prov_strip_prefix(stm_prov_normalize_space($token[1]));
                continue;
            }
            $tokens[] = stm_prov_strip_prefix($token[1]);
        } else {
            $tokens[] = $token;
        }
    }

    return array_values(array_filter($tokens, static function ($t) {
        return $t !== '';
    }));
}

/** @return string[] normalised token stream for a JS source. */
function stm_prov_tokens_js(string $code): array
{
    $pattern = '~("(?:\\\\.|[^"\\\\\n])*"|\'(?:\\\\.|[^\'\\\\\n])*\'|`(?:\\\\.|[^`\\\\])*`)|(//[^\n]*|/\*.*?\*/)|([A-Za-z_$][\w$]*|\d+(?:\.\d+)?|[^\s\w])~s';
    $tokens  = [];

    if (preg_match_all($pattern, $code, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            if (isset($m[2]) && $m[2] !== '') {
                continue; // comment
            }
            $text = ($m[1] ?? '') !== '' ? $m[1] : ($m[3] ?? '');
            if ($text !== '') {
                $tokens[] = stm_prov_strip_prefix($text);
            }
        }
    }

    return $tokens;
}

/** 4-gram shingle set (hashed) for a token stream. */
function stm_prov_shingles(array $tokens, int $size = 4): array
{
    $set = [];
    $n   = count($tokens);
    if ($n < $size) {
        $set[crc32(implode(' ', $tokens))] = true;
        return $set;
    }
    for ($i = 0; $i <= $n - $size; $i++) {
        $set[crc32(implode(' ', array_slice($tokens, $i, $size)))] = true;
    }
    return $set;
}

function stm_prov_jaccard(array $a, array $b): float
{
    if (!$a || !$b) {
        return 0.0;
    }
    $inter = count(array_intersect_key($a, $b));
    $union = count($a) + count($b) - $inter;
    return $union > 0 ? $inter / $union : 0.0;
}

function stm_prov_file_shingles(string $rel, string $contents): array
{
    $tokens = substr($rel, -4) === '.php' ? stm_prov_tokens_php($contents) : stm_prov_tokens_js($contents);
    return stm_prov_shingles($tokens);
}

/**
 * Compare a candidate tree against a reference tree.
 *
 * The report lists observations and never states a conclusion about
 * copying: a GPL fork, a shared upstream, or an independent implementation
 * of the same specification are all legitimate explanations.
 */
function stm_prov_compare(string $referenceRoot, string $candidateRoot): array
{
    $reference = stm_prov_read_corpus($referenceRoot);
    $candidate = stm_prov_read_corpus($candidateRoot);
    $markers   = stm_prov_collect_markers($referenceRoot);

    $candidateAll   = implode("\n", $candidate);
    $candidateSpace = stm_prov_normalize_space($candidateAll);

    $findings = [];
    $noticeSeen = [];
    foreach ($markers as $marker) {
        if ($marker['kind'] === 'fixture') {
            continue; // fixtures are not distributed; they only matter when whole repos are compared
        }

        if ($marker['kind'] === 'copyright-header') {
            // The same notice sits in every file; report it once. GPL requires keeping it, so
            // finding it in a candidate is expected of any compliant fork and is not an indicator.
            if (isset($noticeSeen[$marker['id']])) {
                continue;
            }
            $noticeSeen[$marker['id']] = true;
            $where = [];
            foreach ($candidate as $rel => $contents) {
                if (strpos($contents, 'SPDX-FileCopyrightText: ' . $marker['id']) !== false) {
                    $where[] = $rel;
                }
            }
            $findings[] = [
                'kind' => $marker['kind'], 'id' => $marker['id'], 'reference_file' => $marker['file'], 'symbol' => $marker['symbol'],
                'match' => $where ? 'notice-retained' : 'none', 'candidate_files' => $where,
            ];
            continue;
        }

        $needle = in_array($marker['kind'], ['design-note', 'symbol-doc'], true) ? '[' . $marker['id'] . ']' : $marker['id'];
        $match  = 'none';
        $where  = [];

        foreach ($candidate as $rel => $contents) {
            $hit = $marker['kind'] === 'source-id'
                ? (bool) preg_match('/Source-Id:\s*' . preg_quote($needle, '/') . '(?![a-z0-9.\-])/', $contents)
                : strpos($contents, $needle) !== false;
            if ($hit) {
                $match   = 'verbatim';
                $where[] = $rel;
            }
        }

        if ($match === 'none') {
            // Same marker with a different leading project token (STM-DN-07 -> ZZQ-DN-07, stm.api -> zzq.api).
            $tail = preg_replace('/^(?:\[)?(?:STM|stm)[-._]/', '', $needle);
            if ($tail !== $needle && strlen($tail) >= 5) {
                $core = preg_quote(rtrim($tail, ']'), '/');
                $regex = $marker['kind'] === 'source-id'
                    ? '/Source-Id:\s*(?!stm\.)[a-z0-9]{2,8}\.' . $core . '(?![a-z0-9.\-])/'
                    : '/(?<![A-Za-z0-9])[A-Za-z0-9]{2,8}[-._]' . $core . '(?![A-Za-z0-9])/';
                foreach ($candidate as $rel => $contents) {
                    if (preg_match($regex, $contents)) {
                        $match   = 'renamed';
                        $where[] = $rel;
                    }
                }
            }
        }

        if ($match === 'none' && $marker['context'] !== '' && strlen($marker['context']) >= 30 && strpos($candidateSpace, $marker['context']) !== false) {
            $match = 'prose-only';
        }

        $findings[] = [
            'kind'     => $marker['kind'],
            'id'       => $marker['id'],
            'reference_file' => $marker['file'],
            'symbol'   => $marker['symbol'],
            'match'    => $match,
            'candidate_files' => array_values(array_unique($where)),
        ];
    }

    $candidateShingles = [];
    foreach ($candidate as $rel => $contents) {
        $candidateShingles[$rel] = stm_prov_file_shingles($rel, $contents);
    }
    $candidateHashes = [];
    foreach ($candidate as $rel => $contents) {
        $candidateHashes[hash('sha256', $contents)] = $rel;
    }

    $files = [];
    foreach ($reference as $rel => $contents) {
        if (!stm_prov_is_distributed_source($rel)) {
            continue;
        }
        $hash = hash('sha256', $contents);
        if (isset($candidateHashes[$hash])) {
            $files[] = ['reference_file' => $rel, 'status' => 'byte-identical', 'candidate_file' => $candidateHashes[$hash], 'similarity' => 1.0];
            continue;
        }

        $shingles = stm_prov_file_shingles($rel, $contents);
        $best     = 0.0;
        $bestRel  = null;
        foreach ($candidateShingles as $candRel => $candSet) {
            $score = stm_prov_jaccard($shingles, $candSet);
            if ($score > $best) {
                $best    = $score;
                $bestRel = $candRel;
            }
        }

        if ($best >= 0.90) {
            $status = 'renamed-or-lightly-edited';
        } elseif ($best >= 0.60) {
            $status = 'partial-overlap';
        } else {
            $status = 'no-match';
        }

        $files[] = [
            'reference_file' => $rel,
            'status'         => $status,
            'candidate_file' => $status === 'no-match' ? null : $bestRel,
            'similarity'     => round($best, 3),
        ];
    }

    $count = static function (array $rows, string $field, string $value) {
        return count(array_filter($rows, static function ($r) use ($field, $value) {
            return $r[$field] === $value;
        }));
    };

    $summary = [
        'markers_checked'        => count($findings),
        'markers_verbatim'       => $count($findings, 'match', 'verbatim'),
        'markers_renamed'        => $count($findings, 'match', 'renamed'),
        'markers_prose_only'     => $count($findings, 'match', 'prose-only'),
        'notices_retained'       => $count($findings, 'match', 'notice-retained'),
        'files_checked'          => count($files),
        'files_byte_identical'   => $count($files, 'status', 'byte-identical'),
        'files_renamed_or_edited' => $count($files, 'status', 'renamed-or-lightly-edited'),
        'files_partial_overlap'  => $count($files, 'status', 'partial-overlap'),
    ];

    if ($summary['markers_verbatim'] > 0 || $summary['files_byte_identical'] > 0) {
        $class = 'verbatim-indicators';
    } elseif ($summary['markers_renamed'] > 0 || $summary['markers_prose_only'] > 0 || $summary['files_renamed_or_edited'] > 0) {
        $class = 'prefix-renamed-indicators';
    } elseif ($summary['files_partial_overlap'] > 0) {
        $class = 'partial-overlap-only';
    } elseif ($summary['notices_retained'] > 0) {
        $class = 'copyright-notice-only';
    } else {
        $class = 'no-recognition-points-found';
    }

    return [
        'schema'         => 'stm-provenance-comparison/1',
        'generated_at'   => gmdate('Y-m-d\TH:i:s\Z'),
        'classification' => $class,
        'summary'        => $summary,
        'notice'         => 'Observations for human provenance review. They are not proof of copying, intent, AI use or infringement. '
            . 'GPL-licensed forks are permitted. Independently written code that behaves the same way is expected to match no recognition points, '
            . 'and finding none does not by itself demonstrate independence either.',
        'files'          => $files,
        'markers'        => $findings,
    ];
}

function stm_prov_report_markdown(array $report, string $title = 'Provenance comparison'): string
{
    $s   = $report['summary'];
    $out = "# {$title}\n\n";
    $out .= "Classification: **{$report['classification']}**\n\n";
    $out .= $report['notice'] . "\n\n";
    $out .= "| Measure | Count |\n|---|---|\n";
    foreach ($s as $k => $v) {
        $out .= "| {$k} | {$v} |\n";
    }
    $out .= "\n## Files\n\n| Reference file | Status | Candidate file | Similarity |\n|---|---|---|---|\n";
    foreach ($report['files'] as $f) {
        $out .= "| {$f['reference_file']} | {$f['status']} | " . ($f['candidate_file'] ?? '-') . " | {$f['similarity']} |\n";
    }
    $hits = array_filter($report['markers'], static function ($m) {
        return $m['match'] !== 'none';
    });
    $out .= "\n## Recognition points found in the candidate (" . count($hits) . ' of ' . count($report['markers']) . ")\n\n";
    if ($hits) {
        $out .= "| Kind | Id | Match | Reference location | Candidate files |\n|---|---|---|---|---|\n";
        foreach ($hits as $m) {
            $out .= "| {$m['kind']} | {$m['id']} | {$m['match']} | {$m['reference_file']} ({$m['symbol']}) | " . implode(', ', $m['candidate_files']) . " |\n";
        }
    } else {
        $out .= "None.\n";
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Release record: exact ZIP, commit, SHA-256, publication date
// ---------------------------------------------------------------------------

/**
 * Paths that make up the distributed plugin ZIP. An allowlist, not an exclude
 * list: a new top-level file (a test, a fixture, a script) stays out of the
 * ZIP until someone deliberately adds it here.
 */
const STM_PROV_DIST_PATHS = [
    'simple-translation-manager.php',
    'uninstall.php',
    'readme.txt',
    'includes',
    'templates',
    'assets',
    'docs/editors/pdf', // linked from the Documentation admin screen
];

/** Path fragments that must never appear inside a release ZIP. */
const STM_PROV_ZIP_FORBIDDEN = '#(^|/)(tests|bin|vendor|node_modules|coverage|\.git|\.github|\.phpunit\.cache)(/|$)|(^|/)(AGENT_PROGRESS\.md|phpunit\.xml|phpcs\.xml\.dist|composer\.(json|lock)|package(-lock)?\.json|jest\.config\.js|test-bulk-api\.php)$#';

function stm_prov_is_inside(string $path, string $root): bool
{
    $root = realpath($root);
    $dir  = realpath(dirname($path));
    if ($root === false || $dir === false) {
        return false;
    }
    $root = rtrim(str_replace('\\', '/', $root), '/') . '/';
    $dir  = rtrim(str_replace('\\', '/', $dir), '/') . '/';
    return strpos($dir, $root) === 0;
}

/** Private outputs (manifest, release record, ZIP) must not land in the public repository. */
function stm_prov_assert_outside_repo(string $path, string $root): void
{
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }
    if (stm_prov_is_inside($path, $root)) {
        throw new RuntimeException("Refusing to write {$path}: it is inside the plugin repository. Use a private location outside {$root}.");
    }
}

/**
 * Entry names of a ZIP file, read from its central directory (no ext-zip needed).
 *
 * @return string[]
 */
function stm_prov_zip_entries(string $zipPath): array
{
    $data = (string) file_get_contents($zipPath);
    $eocd = strrpos($data, "PK\x05\x06");
    if ($eocd === false) {
        throw new RuntimeException("{$zipPath} is not a ZIP file (no end-of-central-directory record)");
    }
    $total = unpack('v', substr($data, $eocd + 10, 2))[1];
    $pos   = unpack('V', substr($data, $eocd + 16, 4))[1];
    $names = [];
    for ($i = 0; $i < $total; $i++) {
        if (substr($data, $pos, 4) !== "PK\x01\x02") {
            throw new RuntimeException("{$zipPath}: corrupt central directory entry {$i}");
        }
        $len     = unpack('vname/vextra/vcomment', substr($data, $pos + 28, 6));
        $names[] = substr($data, $pos + 46, $len['name']);
        $pos    += 46 + $len['name'] + $len['extra'] + $len['comment'];
    }
    return $names;
}

/** Problems in a list of ZIP entry names. Empty = fine. @return string[] */
function stm_prov_zip_problems(array $entries): array
{
    $problems = [];
    foreach ($entries as $name) {
        if (preg_match(STM_PROV_ZIP_FORBIDDEN, $name)) {
            $problems[] = "{$name}: development-only file inside the release ZIP";
        }
    }
    if (!in_array('simple-translation-manager/simple-translation-manager.php', $entries, true)) {
        $problems[] = 'simple-translation-manager/simple-translation-manager.php is missing from the ZIP';
    }
    return $problems;
}

function stm_prov_run(string $command, ?int &$code = null): string
{
    $output = [];
    exec($command . ' 2>&1', $output, $code);
    return trim(implode("\n", $output));
}

/** Build the distribution ZIP for $commit with git archive. Same commit, same git => same bytes. */
function stm_prov_build_zip(string $root, string $commit, string $zipPath): void
{
    stm_prov_assert_outside_repo($zipPath, $root);
    $paths = implode(' ', array_map('escapeshellarg', STM_PROV_DIST_PATHS));
    $out   = stm_prov_run(
        'git -C ' . escapeshellarg($root) . ' archive --format=zip --prefix=simple-translation-manager/ -o '
        . escapeshellarg($zipPath) . ' ' . escapeshellarg($commit) . ' -- ' . $paths,
        $code
    );
    if ($code !== 0) {
        throw new RuntimeException("git archive failed: {$out}");
    }
    $problems = stm_prov_zip_problems(stm_prov_zip_entries($zipPath));
    if ($problems) {
        @unlink($zipPath);
        throw new RuntimeException("Release ZIP rejected:\n" . implode("\n", $problems));
    }
}

/** Is a signing key usable on this machine? Reported, never assumed. */
function stm_prov_signing_status(string $root): array
{
    $format = stm_prov_git($root, 'config --get gpg.format') ?? 'openpgp';
    $key    = stm_prov_git($root, 'config --get user.signingkey');
    $gpg    = stm_prov_run('gpg --list-secret-keys --with-colons', $gpgCode);
    $hasGpg = $gpgCode === 0 && preg_match('/^sec:/m', $gpg) === 1;

    $available = ($format === 'ssh') ? ($key !== null) : ($hasGpg || $key !== null);
    return [
        'available' => $available,
        'format'    => $format,
        'detail'    => $available
            ? 'A signing key is configured; the tag signature is verified separately.'
            : 'No signing key (user.signingkey / GPG secret key) is configured on this machine, so the release tag cannot be signed here.',
    ];
}

/** State of the v<version> tag for $commit: missing, unsigned, or signed (verification is separate). */
function stm_prov_tag_status(string $root, string $version, string $commit): array
{
    $tag = 'v' . $version;
    $obj = stm_prov_git($root, 'rev-parse --verify --quiet refs/tags/' . escapeshellarg($tag));
    if ($obj === null) {
        return ['name' => $tag, 'exists' => false, 'points_at' => null, 'signed' => null];
    }
    $target = stm_prov_git($root, 'rev-parse ' . escapeshellarg($tag . '^{commit}'));
    $body   = (string) stm_prov_git($root, 'cat-file -p ' . escapeshellarg($obj));
    return [
        'name'           => $tag,
        'exists'         => true,
        'points_at'      => $target,
        'matches_commit' => $target === $commit,
        'signed'         => (bool) preg_match('/-----BEGIN (PGP|SSH) SIGNATURE-----/', $body),
    ];
}

/**
 * Build the ZIP for $commit and return the release record.
 *
 * @param string $published YYYY-MM-DD the release was (or will be) published
 */
function stm_prov_release(string $root, string $commit, string $zipPath, string $published): array
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $published)) {
        throw new InvalidArgumentException("--published must be YYYY-MM-DD, got '{$published}'");
    }
    $sha = stm_prov_git($root, 'rev-parse --verify ' . escapeshellarg($commit . '^{commit}'));
    if ($sha === null) {
        throw new RuntimeException("Unknown commit '{$commit}'");
    }
    $version = trim((string) stm_prov_git($root, 'show ' . escapeshellarg($sha . ':VERSION')));

    stm_prov_build_zip($root, $sha, $zipPath);

    return [
        'schema'    => 'stm-release-record/1',
        'plugin'    => ['slug' => 'simple-translation-manager', 'version' => $version],
        'commit'    => $sha,
        'tree'      => stm_prov_git($root, 'rev-parse ' . escapeshellarg($sha . '^{tree}')),
        'zip'       => [
            'file'     => basename($zipPath),
            'bytes'    => filesize($zipPath),
            'sha256'   => hash_file('sha256', $zipPath),
            'entries'  => count(stm_prov_zip_entries($zipPath)),
            'built_by' => 'git archive from the commit above, limited to STM_PROV_DIST_PATHS',
        ],
        'published' => $published,
        'tag'       => stm_prov_tag_status($root, $version, $sha),
        'signing'   => stm_prov_signing_status($root),
        'notice'    => 'The SHA-256 identifies this exact file as published. It says nothing about a rewritten, repackaged or modified copy, and it is not evidence of copying.',
    ];
}

/** Rebuild the ZIP from the recorded commit and compare hashes. */
function stm_prov_verify_release(string $root, array $record): array
{
    $tmp = sys_get_temp_dir() . '/stm-prov-verify-' . bin2hex(random_bytes(4)) . '.zip';
    try {
        $rebuilt = stm_prov_release($root, $record['commit'], $tmp, $record['published']);
    } finally {
        @unlink($tmp);
    }
    return [
        'recorded_sha256' => $record['zip']['sha256'],
        'rebuilt_sha256'  => $rebuilt['zip']['sha256'],
        'match'           => $record['zip']['sha256'] === $rebuilt['zip']['sha256'],
    ];
}

// ---------------------------------------------------------------------------
// Demonstration on temporary fixtures
// ---------------------------------------------------------------------------

function stm_prov_rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}

function stm_prov_copy_tree(string $from, string $to, array $rels): void
{
    foreach ($rels as $rel) {
        $target = $to . '/' . $rel;
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0777, true);
        }
        copy($from . '/' . $rel, $target);
    }
}

/**
 * Source for an independently written module that offers the same observable
 * behaviour as STM's Security helper (validate a language code, sanitise a
 * translation key) with its own structure, names and comments.
 */
function stm_prov_independent_fixture_source(): string
{
    return <<<'PHPSRC'
<?php
// SPDX-License-Identifier: MIT
// SPDX-FileCopyrightText: 2026 Example Fixture Author
// Source-Id: fixture.independent.keys

namespace Acme\Lingua;

final class KeyRules
{
    private const LANG_PATTERN = '/^[a-z]{2,3}$/i';

    public static function isLanguage(string $candidate): bool
    {
        return (bool) preg_match(self::LANG_PATTERN, $candidate);
    }

    public static function slugify(string $raw): string
    {
        $out = strtolower($raw);
        $out = preg_replace('/[^a-z0-9._-]+/', '', $out);
        $out = preg_replace('/\.{2,}/', '.', $out);
        return trim($out, '.');
    }

    public static function pick(array $rows, string $lang, string $fallback): string
    {
        foreach ($rows as $row) {
            if (($row['lang'] ?? '') === $lang && $row['text'] !== '') {
                return $row['text'];
            }
        }
        return $fallback;
    }
}
PHPSRC;
}

/**
 * Build the three demonstration candidates from a reference checkout and
 * return their comparison reports. All files live in a temp directory that
 * is removed again.
 *
 * @param string[] $sample Relative paths of the reference files to copy.
 * @return array{literal: array, renamed: array, independent: array}
 */
function stm_prov_demo(string $referenceRoot, array $sample = []): array
{
    $sample = $sample ?: ['includes/class-security.php', 'includes/class-cache.php', 'includes/class-hreflang.php', 'includes/class-database.php'];
    $base   = sys_get_temp_dir() . '/stm-prov-demo-' . bin2hex(random_bytes(4));
    $ref    = $base . '/reference';
    $lit    = $base . '/literal-copy';
    $ren    = $base . '/prefix-renamed-copy';
    $ind    = $base . '/independent';

    try {
        stm_prov_copy_tree($referenceRoot, $ref, $sample);
        stm_prov_copy_tree($referenceRoot, $lit, $sample);
        stm_prov_copy_tree($referenceRoot, $ren, $sample);

        // Prefix-only change: project prefix in every spelling the sources use, nothing else.
        foreach ($sample as $rel) {
            $path = $ren . '/' . $rel;
            $text = (string) file_get_contents($path);
            $text = str_replace(['STM_', 'stm_', 'namespace STM;', 'STM\\', '[STM]', 'STM-', 'stm.'], ['ZZQ_', 'zzq_', 'namespace ZZQ;', 'ZZQ\\', '[ZZQ]', 'ZZQ-', 'zzq.'], $text);
            file_put_contents($path, $text);
        }

        mkdir($ind . '/includes', 0777, true);
        file_put_contents($ind . '/includes/key-rules.php', stm_prov_independent_fixture_source());

        return [
            'literal'     => stm_prov_compare($ref, $lit),
            'renamed'     => stm_prov_compare($ref, $ren),
            'independent' => stm_prov_compare($ref, $ind),
        ];
    } finally {
        stm_prov_rrmdir($base);
    }
}

// ---------------------------------------------------------------------------
// CLI
// ---------------------------------------------------------------------------

function stm_prov_cli(array $argv): int
{
    $command = $argv[1] ?? '';
    $args    = array_slice($argv, 2);
    $opts    = [];
    $pos     = [];
    foreach ($args as $arg) {
        if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m)) {
            $opts[$m[1]] = $m[2] ?? true;
        } else {
            $pos[] = $arg;
        }
    }
    $root = rtrim($pos[0] ?? dirname(__DIR__), '/\\');

    switch ($command) {
        case 'check':
            $problems = stm_prov_check($root);
            if ($problems) {
                fwrite(STDERR, implode("\n", $problems) . "\n");
                return 1;
            }
            echo 'OK: ' . count(stm_prov_source_files($root)) . " own source files carry SPDX, copyright and a unique Source-Id.\n";
            return 0;

        case 'apply-headers':
            $changed = 0;
            foreach (stm_prov_source_files($root) as $rel) {
                $before = (string) file_get_contents($root . '/' . $rel);
                $after  = stm_prov_apply_header($before, $rel);
                if ($after !== $before) {
                    file_put_contents($root . '/' . $rel, $after);
                    $changed++;
                }
            }
            echo "Headers added to {$changed} file(s).\n";
            return 0;

        case 'manifest':
            $manifest = stm_prov_build_manifest($root, !isset($opts['no-git']));
            $json     = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
            if (!empty($opts['out'])) {
                stm_prov_assert_outside_repo($opts['out'], $root);
                file_put_contents($opts['out'], $json);
                echo 'Manifest written: ' . $opts['out'] . ' (' . count($manifest['markers']) . " markers, " . count($manifest['modules']) . " modules)\n";
            } else {
                echo $json;
            }
            return 0;

        case 'compare':
            if (count($pos) < 2) {
                fwrite(STDERR, "Usage: php bin/provenance.php compare <reference-root> <candidate-root> [--format=json|md] [--out=FILE]\n");
                return 1;
            }
            $report = stm_prov_compare($pos[0], $pos[1]);
            $text   = (($opts['format'] ?? 'json') === 'md')
                ? stm_prov_report_markdown($report)
                : json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
            if (!empty($opts['out'])) {
                file_put_contents($opts['out'], $text);
            } else {
                echo $text;
            }
            return 0;

        case 'release':
            if (empty($opts['out-dir'])) {
                fwrite(STDERR, "Usage: php bin/provenance.php release [repo-root] --out-dir=DIR [--commit=REF] [--published=YYYY-MM-DD]\n");
                return 1;
            }
            $commit  = is_string($opts['commit'] ?? null) ? $opts['commit'] : 'HEAD';
            $date    = is_string($opts['published'] ?? null) ? $opts['published'] : gmdate('Y-m-d');
            $version = trim((string) stm_prov_git($root, 'show ' . escapeshellarg($commit . ':VERSION')));
            $dir     = rtrim($opts['out-dir'], '/\\');
            $record  = stm_prov_release($root, $commit, $dir . '/simple-translation-manager-' . $version . '.zip', $date);
            $recordFile = $dir . '/release-record-' . $version . '.json';
            stm_prov_assert_outside_repo($recordFile, $root);
            file_put_contents($recordFile, json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
            echo "ZIP:    {$record['zip']['file']} ({$record['zip']['bytes']} bytes, {$record['zip']['entries']} entries)\n";
            echo "SHA256: {$record['zip']['sha256']}\n";
            echo "Commit: {$record['commit']}  Published: {$record['published']}\n";
            echo 'Tag:    ' . $record['tag']['name'] . ($record['tag']['exists'] ? ($record['tag']['signed'] ? ' (signed)' : ' (exists, NOT signed)') : ' (does not exist yet)') . "\n";
            echo 'Signing available on this machine: ' . ($record['signing']['available'] ? 'yes' : 'NO - ' . $record['signing']['detail']) . "\n";
            return 0;

        case 'verify-release':
            $recordFile = $pos[0] ?? '';
            if ($recordFile === '' || !is_file($recordFile)) {
                fwrite(STDERR, "Usage: php bin/provenance.php verify-release <release-record.json> [--repo=ROOT]\n");
                return 1;
            }
            $result = stm_prov_verify_release(is_string($opts['repo'] ?? null) ? $opts['repo'] : dirname(__DIR__), json_decode((string) file_get_contents($recordFile), true));
            echo ($result['match'] ? 'MATCH' : 'MISMATCH') . ": recorded {$result['recorded_sha256']}, rebuilt {$result['rebuilt_sha256']}\n";
            return $result['match'] ? 0 : 2;

        case 'demo':
            $reports = stm_prov_demo(dirname(__DIR__));
            foreach ($reports as $name => $report) {
                if (($opts['format'] ?? 'md') === 'json') {
                    echo json_encode([$name => $report], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
                } else {
                    echo stm_prov_report_markdown($report, "Demo: {$name}") . "\n";
                }
            }
            return 0;
    }

    fwrite(STDERR, "Usage: php bin/provenance.php <check|apply-headers|manifest|compare|release|verify-release|demo> [args]\n");
    return 1;
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    try {
        exit(stm_prov_cli($argv));
    } catch (Throwable $e) {
        fwrite(STDERR, 'error: ' . $e->getMessage() . "
");
        exit(1);
    }
}
