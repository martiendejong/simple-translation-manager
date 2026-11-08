<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.tools.provenance.compare

declare(strict_types=1);

namespace WpProvenance;

/**
 * Comparison mode: lists observations about how a candidate tree relates to a reference plugin.
 *
 * It reports WHERE each kind of match is and how strong a signal that kind is, and it never concludes that
 * code was copied. GPL forks, a shared upstream and independent implementations of the same feature are all
 * legitimate explanations of a match, and an absent match does not prove independence either.
 *
 * Kinds of match, by weight:
 *   strong         registered marker spelled the same way; registered marker with only the project prefix changed
 *                  (two or more); a byte-identical file of real size; a lightly edited file (>= 0.85 token
 *                  similarity, at least STRONG_TOKENS tokens)
 *   weak           a single prefix-renamed marker; design-note prose on its own; partial overlap (>= 0.60);
 *                  the reference copyright notice kept (the GPL requires keeping it)
 *   informational  the brand name appears (any plugin may say "works with <brand>"); files too small or
 *                  made only of WordPress boilerplate (every plugin starts the same way)
 *
 * Only strong kinds make the assessment "strong-indicators". Informational kinds never change it.
 */
final class Compare
{
    public const SCHEMA = 'wp-provenance-comparison/1';

    /** A file with fewer tokens than this (after boilerplate is removed) is not evidence of anything. */
    public const MIN_TOKENS = 60;
    /** A lightly-edited-file match counts as strong only with at least this many tokens. */
    public const STRONG_TOKENS = 100;
    public const SIMILAR = 0.85;
    public const PARTIAL = 0.60;

    /** Statements every WordPress plugin contains. Removed before tokens are compared; see README "Edge cases". */
    private const BOILERPLATE = [
        // direct-access guards
        '/if\s*\(\s*!\s*defined\s*\(\s*[\'"]ABSPATH[\'"]\s*\)\s*\)\s*\{?\s*(?:exit|die)\s*(?:\([^)]*\))?\s*;?\s*\}?/i',
        '/defined\s*\(\s*[\'"]ABSPATH[\'"]\s*\)\s*(?:\|\||or)\s*(?:exit|die)\s*(?:\([^)]*\))?\s*;/i',
        '/if\s*\(\s*!\s*defined\s*\(\s*[\'"]WP_UNINSTALL_PLUGIN[\'"]\s*\)\s*\)\s*\{?\s*(?:exit|die)\s*(?:\([^)]*\))?\s*;?\s*\}?/i',
        // text domain and lifecycle hooks
        '/\bload_plugin_textdomain\s*\([^;]*\)\s*;/',
        '/\bregister_(?:activation|deactivation|uninstall)_hook\s*\([^;]*\)\s*;/',
        // the usual hook wiring
        '/\badd_(?:action|filter)\s*\(\s*[\'"](?:plugins_loaded|init|admin_menu|admin_init|rest_api_init|wp_enqueue_scripts|admin_enqueue_scripts)[\'"]\s*,[^;]*\)\s*;/',
        // standard plugin constants
        '/\bdefine\s*\(\s*[\'"][A-Z0-9_]+(?:_VERSION|_PLUGIN_FILE|_PLUGIN_DIR|_PLUGIN_URL|_PATH|_URL|_FILE|_DIR)[\'"]\s*,[^;]*\)\s*;/',
        // loading the plugin's own files
        '/\b(?:require|require_once|include|include_once)\s*(?:\(\s*)?[^;]*[\'"]\/?(?:includes|inc|src|lib)\/[^;]*;/',
    ];

    /**
     * @param array{boilerplate?:bool} $options boilerplate=false disables the boilerplate removal (used by tests to show it matters)
     * @return array<string,mixed>
     */
    public static function run(Config $c, Tree $reference, string $candidateRoot, array $options = []): array
    {
        $useBoilerplate = $options['boilerplate'] ?? true;
        $candidate      = self::readTree($candidateRoot, $c->extensions, $c->skip_dirs);
        $text           = self::readTexts($candidateRoot, $c->skip_dirs);
        $defs           = Markers::defsByKind($c);

        $candidateSpace = [];
        foreach ($candidate as $rel => $contents) {
            $candidateSpace[$rel] = Markers::normalizeSpace($contents);
        }

        // ---- 1. registered markers ------------------------------------------------------------------
        $markerRows = [];
        $notices    = [];
        foreach (Markers::collect($reference) as $marker) {
            $kind = $marker['kind'];

            if ($kind === 'copyright-header') {
                if (isset($notices[$marker['id']])) {
                    continue; // the same notice sits in every file; report it once
                }
                $where = [];
                foreach ($candidate as $rel => $contents) {
                    $pos = strpos($contents, 'SPDX-FileCopyrightText: ' . $marker['id']);
                    if ($pos !== false) {
                        $where[] = ['file' => $rel, 'line' => substr_count(substr($contents, 0, $pos), "\n") + 1];
                    }
                }
                $notices[$marker['id']] = true;
                $markerRows[] = ['kind' => $kind, 'id' => $marker['id'], 'reference' => self::ref($marker), 'match' => $where ? 'notice-retained' : 'none', 'candidate' => array_slice($where, 0, 5)];
                continue;
            }
            if ($kind !== 'source-id' && (!isset($defs[$kind]) || !$defs[$kind]['compare'])) {
                continue; // e.g. test-fixture ids: they are not distributed
            }

            $needle = Manifest::needleFor($defs, $kind, $marker['id']);
            $where  = self::locate($candidate, $kind === 'source-id'
                ? '/Source-Id:\s*' . preg_quote($needle, '/') . '(?![A-Za-z0-9._\-])/'
                : '/' . preg_quote($needle, '/') . '/');
            $match = $where ? 'verbatim' : 'none';

            if ($match === 'none') {
                $regex = self::renamedRegex($c, $kind, $needle);
                if ($regex !== null) {
                    $where = self::locate($candidate, $regex);
                    $match = $where ? 'renamed' : 'none';
                }
            }

            if ($match === 'none' && strlen($marker['context']) >= 30) {
                foreach ($candidateSpace as $rel => $space) {
                    if (strpos($space, $marker['context']) !== false) {
                        $where[] = ['file' => $rel, 'line' => 0];
                        $match   = 'prose-only';
                        break;
                    }
                }
            }
            $markerRows[] = ['kind' => $kind, 'id' => $marker['id'], 'reference' => self::ref($marker), 'match' => $match, 'candidate' => array_slice($where, 0, 5)];
        }

        // ---- 2. files -------------------------------------------------------------------------------
        $candidateTokens   = [];
        $candidateHashes   = [];
        foreach ($candidate as $rel => $contents) {
            $tokens = self::tokens($rel, $contents, $useBoilerplate);
            if (count($tokens) >= self::MIN_TOKENS) {
                $candidateTokens[$rel] = self::shingles($tokens);
            }
            $candidateHashes[hash('sha256', str_replace("\r\n", "\n", $contents))] = $rel;
        }

        $fileRows = [];
        foreach ($reference->ownSourceFiles() as $rel) {
            if (!$c->isDistributed($rel)) {
                continue; // tests and tooling are not what a copy of the plugin would carry
            }
            $contents = $reference->read($rel);
            $tokens   = self::tokens($rel, $contents, $useBoilerplate);
            $count    = count($tokens);

            if ($count < self::MIN_TOKENS) {
                $fileRows[] = ['reference_file' => $rel, 'status' => 'too-small-or-boilerplate', 'candidate_file' => null, 'similarity' => 0.0, 'tokens' => $count];
                continue;
            }
            $hash = hash('sha256', str_replace("\r\n", "\n", $contents));
            if (isset($candidateHashes[$hash])) {
                $fileRows[] = ['reference_file' => $rel, 'status' => 'byte-identical', 'candidate_file' => $candidateHashes[$hash], 'similarity' => 1.0, 'tokens' => $count];
                continue;
            }

            $shingles = self::shingles($tokens);
            $best     = 0.0;
            $bestRel  = null;
            foreach ($candidateTokens as $candRel => $set) {
                $score = self::jaccard($shingles, $set);
                if ($score > $best) {
                    $best    = $score;
                    $bestRel = $candRel;
                }
            }
            if ($best >= self::SIMILAR) {
                $status = $count >= self::STRONG_TOKENS ? 'renamed-or-lightly-edited' : 'partial-overlap';
            } elseif ($best >= self::PARTIAL) {
                $status = 'partial-overlap';
            } else {
                $status = 'no-match';
            }
            $fileRows[] = ['reference_file' => $rel, 'status' => $status, 'candidate_file' => $status === 'no-match' ? null : $bestRel, 'similarity' => round($best, 3), 'tokens' => $count];
        }

        // ---- 3. brand name (informational only) -----------------------------------------------------
        $brand = [];
        foreach ($c->brand_names as $name) {
            $where = self::locate($text, '/' . preg_quote($name, '/') . '/i');
            if ($where) {
                $brand[] = ['name' => $name, 'locations' => array_slice($where, 0, 5)];
            }
        }

        return self::assess($markerRows, $fileRows, $brand);
    }

    /**
     * @param list<array<string,mixed>> $markerRows
     * @param list<array<string,mixed>> $fileRows
     * @param list<array<string,mixed>> $brand
     * @return array<string,mixed>
     */
    private static function assess(array $markerRows, array $fileRows, array $brand): array
    {
        $count = static function (array $rows, string $field, string $value): int {
            return count(array_filter($rows, static function (array $r) use ($field, $value): bool {
                return $r[$field] === $value;
            }));
        };
        $s = [
            'markers_checked'          => count($markerRows),
            'markers_verbatim'         => $count($markerRows, 'match', 'verbatim'),
            'markers_renamed'          => $count($markerRows, 'match', 'renamed'),
            'markers_prose_only'       => $count($markerRows, 'match', 'prose-only'),
            'notices_retained'         => $count($markerRows, 'match', 'notice-retained'),
            'files_checked'            => count($fileRows),
            'files_byte_identical'     => $count($fileRows, 'status', 'byte-identical'),
            'files_renamed_or_edited'  => $count($fileRows, 'status', 'renamed-or-lightly-edited'),
            'files_partial_overlap'    => $count($fileRows, 'status', 'partial-overlap'),
            'files_too_small_or_boilerplate' => $count($fileRows, 'status', 'too-small-or-boilerplate'),
            'brand_name_found'         => count($brand),
        ];

        $strong = [];
        $weak   = [];
        if ($s['markers_verbatim'] > 0) {
            $strong[] = 'registered-marker-verbatim';
        }
        if ($s['markers_renamed'] >= 2) {
            $strong[] = 'registered-marker-prefix-renamed';
        } elseif ($s['markers_renamed'] === 1) {
            $weak[] = 'registered-marker-prefix-renamed (single)';
        }
        if ($s['files_byte_identical'] > 0) {
            $strong[] = 'file-byte-identical';
        }
        if ($s['files_renamed_or_edited'] > 0) {
            $strong[] = 'file-lightly-edited';
        }
        if ($s['markers_prose_only'] > 0) {
            $weak[] = 'design-note-prose-only';
        }
        if ($s['files_partial_overlap'] > 0) {
            $weak[] = 'file-partial-overlap';
        }
        if ($s['notices_retained'] > 0) {
            $weak[] = 'copyright-notice-retained';
        }
        $info = [];
        if ($brand) {
            $info[] = 'brand-name';
        }
        if ($s['files_too_small_or_boilerplate'] > 0) {
            $info[] = 'boilerplate-or-too-small-files-ignored';
        }

        $assessment = $strong ? 'strong-indicators' : ($weak ? 'weak-indicators' : 'none');

        return [
            'schema'       => self::SCHEMA,
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'assessment'   => $assessment,
            'kinds'        => ['strong' => $strong, 'weak' => $weak, 'informational' => $info],
            'summary'      => $s,
            'notice'       => 'Observations for a human provenance review. They are not proof of copying, intent, AI use or infringement. '
                . 'GPL-licensed forks are permitted. A brand name, the same feature or standard WordPress boilerplate never changes the assessment, '
                . 'independently written code that behaves the same way is expected to match nothing, and finding nothing does not by itself demonstrate independence either.',
            'brand_name'   => $brand,
            'files'        => $fileRows,
            'markers'      => $markerRows,
        ];
    }

    /** @param array<string,mixed> $marker */
    private static function ref(array $marker): array
    {
        return ['file' => $marker['file'], 'line' => $marker['line'], 'symbol' => $marker['symbol']];
    }

    /**
     * Regex that finds a registered marker whose leading project prefix was swapped (STM-DN-07 -> ZZQ-DN-07).
     * Null when the marker carries no configured prefix or its remainder is too short to be distinctive.
     */
    private static function renamedRegex(Config $c, string $kind, string $needle): ?string
    {
        // Source ids are module names ("registry", "api", "sync"): two unrelated plugins that both adopt the
        // Source-Id convention would share some of them, so only an identical id (same prefix) counts.
        if ($kind === 'source-id') {
            return null;
        }
        foreach ($c->prefixes as $prefix) {
            $q = preg_quote($prefix, '/');
            if (!preg_match('/^(\[?)' . $q . '([-._])(.+?)(\]?)$/i', $needle, $m)) {
                continue;
            }
            $tail = $m[3];
            if (strlen($tail) < 5) {
                return null;
            }
            $core = preg_quote($tail, '/');
            if ($m[1] === '[') {
                return '/\[(?!' . $q . '[-._])[A-Za-z0-9]{2,8}[-._]' . $core . '\]/';
            }
            return '/(?<![A-Za-z0-9])(?!' . $q . '[-._])[A-Za-z0-9]{2,8}[-._]' . $core . '(?![A-Za-z0-9])/';
        }
        return null;
    }

    /**
     * @param array<string,string> $files rel => contents
     * @return list<array{file:string,line:int}>
     */
    private static function locate(array $files, string $regex): array
    {
        $out = [];
        foreach ($files as $rel => $contents) {
            if (preg_match_all($regex, $contents, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[0] as [$hit, $offset]) {
                    $out[] = ['file' => $rel, 'line' => substr_count(substr($contents, 0, (int) $offset), "\n") + 1];
                }
            }
        }
        return $out;
    }

    /**
     * Candidate source files, rel => contents.
     *
     * @param list<string> $extensions
     * @param list<string> $skipDirs
     * @return array<string,string>
     */
    private static function readTree(string $root, array $extensions, array $skipDirs): array
    {
        $out = [];
        foreach (self::walk($root, $extensions, $skipDirs, 2097152) as $rel) {
            if (!preg_match('/\.min\.[a-z]+$/i', $rel)) {
                $out[$rel] = (string) file_get_contents(rtrim($root, '/\\') . '/' . $rel);
            }
        }
        return $out;
    }

    /** Candidate text files (source, readme, docs, translations) for the brand-name search. @return array<string,string> */
    private static function readTexts(string $root, array $skipDirs): array
    {
        $out = [];
        foreach (self::walk($root, ['php', 'js', 'mjs', 'txt', 'md', 'json', 'pot', 'po', 'html', 'css'], $skipDirs, 524288) as $rel) {
            $out[$rel] = (string) file_get_contents(rtrim($root, '/\\') . '/' . $rel);
        }
        return $out;
    }

    /** @return list<string> */
    private static function walk(string $root, array $extensions, array $skipDirs, int $maxBytes): array
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        if (!is_dir($root)) {
            throw new \RuntimeException("Not a directory: {$root}");
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            static function (\SplFileInfo $current) use ($skipDirs) {
                return !($current->isDir() && in_array($current->getFilename(), $skipDirs, true));
            }
        ));
        $found = [];
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile() && $file->getSize() <= $maxBytes && in_array(strtolower($file->getExtension()), $extensions, true)) {
                $found[] = ltrim(substr(str_replace('\\', '/', $file->getPathname()), strlen($root)), '/');
            }
        }
        sort($found);
        return $found;
    }

    // -- tokens and similarity -------------------------------------------------------------------------

    /** @return list<string> */
    public static function tokens(string $rel, string $contents, bool $boilerplate = true): array
    {
        if (strtolower(pathinfo($rel, PATHINFO_EXTENSION)) === 'php') {
            if ($boilerplate) {
                $contents = (string) preg_replace(self::BOILERPLATE, '', $contents);
            }
            return self::tokensPhp($contents);
        }
        return self::tokensJs($contents);
    }

    /**
     * Strip a leading "prefix_" or "Prefix\" segment from identifiers and string words. Applied identically to
     * both sides, so a copy whose only change is the project prefix normalises to the same token stream as the
     * original without having to know what the new prefix is.
     */
    private static function stripPrefix(string $text): string
    {
        $text = (string) preg_replace('/\b[A-Za-z0-9]{2,8}\\\\+(?=[A-Za-z])/', '', $text);
        return (string) preg_replace('/\b[A-Za-z0-9]{2,6}_(?=[A-Za-z0-9])/', '', $text);
    }

    /** @return list<string> */
    private static function tokensPhp(string $code): array
    {
        $tokens = [];
        $skip   = [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE, T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO, T_CLOSE_TAG];
        foreach (token_get_all($code) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], $skip, true)) {
                    continue;
                }
                $tokens[] = self::stripPrefix($token[0] === T_INLINE_HTML ? Markers::normalizeSpace($token[1]) : $token[1]);
            } else {
                $tokens[] = $token;
            }
        }
        return array_values(array_filter($tokens, static function (string $t): bool {
            return $t !== '';
        }));
    }

    /** @return list<string> */
    private static function tokensJs(string $code): array
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
                    $tokens[] = self::stripPrefix($text);
                }
            }
        }
        return $tokens;
    }

    /** @param list<string> $tokens @return array<int,bool> hashed 4-gram set */
    private static function shingles(array $tokens, int $size = 4): array
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

    /** @param array<int,bool> $a @param array<int,bool> $b */
    private static function jaccard(array $a, array $b): float
    {
        if (!$a || !$b) {
            return 0.0;
        }
        $inter = count(array_intersect_key($a, $b));
        $union = count($a) + count($b) - $inter;
        return $union > 0 ? $inter / $union : 0.0;
    }

    /** @param array<string,mixed> $report */
    public static function markdown(array $report, string $title = 'Provenance comparison'): string
    {
        $out  = "# {$title}\n\n";
        $out .= 'Assessment: **' . $report['assessment'] . "**\n\n";
        $out .= 'Strong kinds: ' . ($report['kinds']['strong'] ? implode(', ', $report['kinds']['strong']) : '-') . "  \n";
        $out .= 'Weak kinds: ' . ($report['kinds']['weak'] ? implode(', ', $report['kinds']['weak']) : '-') . "  \n";
        $out .= 'Informational: ' . ($report['kinds']['informational'] ? implode(', ', $report['kinds']['informational']) : '-') . "\n\n";
        $out .= $report['notice'] . "\n\n| Measure | Count |\n|---|---|\n";
        foreach ($report['summary'] as $k => $v) {
            $out .= "| {$k} | {$v} |\n";
        }
        $out .= "\n## Files\n\n| Reference file | Status | Candidate file | Similarity | Tokens |\n|---|---|---|---|---|\n";
        foreach ($report['files'] as $f) {
            $out .= "| {$f['reference_file']} | {$f['status']} | " . ($f['candidate_file'] ?? '-') . " | {$f['similarity']} | {$f['tokens']} |\n";
        }
        $hits = array_values(array_filter($report['markers'], static function (array $m): bool {
            return $m['match'] !== 'none';
        }));
        $out .= "\n## Recognition points found in the candidate (" . count($hits) . ' of ' . count($report['markers']) . ")\n\n";
        if ($hits) {
            $out .= "| Kind | Id | Match | Reference location | Candidate location |\n|---|---|---|---|---|\n";
            foreach ($hits as $m) {
                $where = implode(', ', array_map(static function (array $w): string {
                    return $w['file'] . ($w['line'] ? ':' . $w['line'] : '');
                }, $m['candidate']));
                $out .= "| {$m['kind']} | {$m['id']} | {$m['match']} | {$m['reference']['file']}" . ($m['reference']['line'] ? ':' . $m['reference']['line'] : '') . " ({$m['reference']['symbol']}) | {$where} |\n";
            }
        } else {
            $out .= "None.\n";
        }
        if ($report['brand_name']) {
            $out .= "\n## Brand name (informational, never an indicator)\n\n";
            foreach ($report['brand_name'] as $b) {
                $out .= '- "' . $b['name'] . '" at ' . implode(', ', array_map(static function (array $w): string {
                    return $w['file'] . ':' . $w['line'];
                }, $b['locations'])) . "\n";
            }
        }
        return $out;
    }
}
