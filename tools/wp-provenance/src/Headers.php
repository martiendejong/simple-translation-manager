<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.tools.provenance.headers

declare(strict_types=1);

namespace WpProvenance;

/**
 * SPDX / copyright / Source-Id headers: reading them, checking them against the config, adding them.
 *
 * The three header lines are ordinary licensing practice (the SPDX and REUSE conventions). Source-Id is
 * the one project-specific line: a stable module identifier that survives a file rename.
 */
final class Headers
{
    /** How many lines at the top of a file are searched for the header. */
    public const SEARCH_LINES = 60;

    /**
     * @return array{spdx: ?string, spdx_line: int, copyright: ?string, copyright_line: int, source_id: ?string, source_id_line: int}
     */
    public static function parse(string $contents): array
    {
        $out   = ['spdx' => null, 'spdx_line' => 0, 'copyright' => null, 'copyright_line' => 0, 'source_id' => null, 'source_id_line' => 0];
        $lines = array_slice(preg_split('/\R/', $contents) ?: [], 0, self::SEARCH_LINES);

        foreach ($lines as $i => $line) {
            if ($out['spdx'] === null && preg_match('/SPDX-License-Identifier:\s*([^\r\n*]+?)\s*(?:\*\/)?\s*$/', $line, $m)) {
                $out['spdx']      = trim($m[1]);
                $out['spdx_line'] = $i + 1;
            }
            if ($out['copyright'] === null && preg_match('/SPDX-FileCopyrightText:\s*([^\r\n*]+?)\s*(?:\*\/)?\s*$/', $line, $m)) {
                $out['copyright']      = trim($m[1]);
                $out['copyright_line'] = $i + 1;
            }
            if ($out['source_id'] === null && preg_match('/Source-Id:\s*([A-Za-z0-9._\-]+)\s*(?:\*\/)?\s*$/', $line, $m)) {
                $out['source_id']      = $m[1];
                $out['source_id_line'] = $i + 1;
            }
        }
        return $out;
    }

    /** The command an engineer runs to add missing headers; named in every header finding. */
    private const FIX_APPLY = 'run `apply-headers` of this tool for this config (it only adds missing headers and never changes existing ones)';

    /**
     * Header findings for every own source file.
     *
     * @return list<array<string,mixed>>
     */
    public static function check(Tree $tree): array
    {
        $c        = $tree->config();
        $findings = [];
        $seen     = [];
        $holderOk = '/^\d{4}(?:-\d{4})? ' . preg_quote($c->holder, '/') . '$/';

        foreach ($tree->ownSourceFiles() as $rel) {
            $p = self::parse($tree->read($rel));

            if ($p['spdx'] === null) {
                $findings[] = Finding::make('provenance.header.spdx-missing', Finding::ERROR, $rel, 0,
                    'SPDX-License-Identifier is missing in the first ' . self::SEARCH_LINES . ' lines.',
                    'Add "SPDX-License-Identifier: ' . $c->license . '" as a comment at the top of the file, or ' . self::FIX_APPLY . '.');
            } elseif ($p['spdx'] !== $c->license) {
                $findings[] = Finding::make('provenance.header.spdx-mismatch', Finding::ERROR, $rel, $p['spdx_line'],
                    'SPDX-License-Identifier is "' . $p['spdx'] . '" but this plugin is configured as "' . $c->license . '".',
                    'Correct the header. If the file really is third-party code under another license, list its path in source.third_party_paths of the config and keep its own notice.',
                    '(file scope)', self::lineOf($tree->read($rel), $p['spdx_line']));
            }

            if ($p['copyright'] === null) {
                $findings[] = Finding::make('provenance.header.copyright-missing', Finding::ERROR, $rel, 0,
                    'SPDX-FileCopyrightText is missing in the first ' . self::SEARCH_LINES . ' lines.',
                    'Add "SPDX-FileCopyrightText: ' . $c->year . ' ' . $c->holder . '", or ' . self::FIX_APPLY . '.');
            } elseif (!preg_match($holderOk, $p['copyright'])) {
                $findings[] = Finding::make('provenance.header.copyright-mismatch', Finding::ERROR, $rel, $p['copyright_line'],
                    'SPDX-FileCopyrightText is "' . $p['copyright'] . '", expected "<year> ' . $c->holder . '".',
                    'Correct the line. Do not replace another party\'s notice: if the file is theirs, list it in source.third_party_paths and keep their notice.',
                    '(file scope)', self::lineOf($tree->read($rel), $p['copyright_line']));
            }

            if (!$c->source_id_required) {
                continue;
            }
            if ($p['source_id'] === null) {
                $findings[] = Finding::make('provenance.header.source-id-missing', Finding::ERROR, $rel, 0,
                    'Source-Id is missing in the first ' . self::SEARCH_LINES . ' lines.',
                    'Add "Source-Id: ' . self::deriveSourceId($c, $rel) . '" (a stable module id, written once and never changed by a rename), or ' . self::FIX_APPLY . '.');
            } elseif (strncmp($p['source_id'], $c->source_id_prefix . '.', strlen($c->source_id_prefix) + 1) !== 0) {
                $findings[] = Finding::make('provenance.header.source-id-prefix', Finding::ERROR, $rel, $p['source_id_line'],
                    'Source-Id "' . $p['source_id'] . '" does not start with "' . $c->source_id_prefix . '.".',
                    'Use an id of the form "' . $c->source_id_prefix . '.<module>". Do not rename ids that already shipped; fix the config prefix instead if it is wrong.',
                    '(file scope)', self::lineOf($tree->read($rel), $p['source_id_line']));
            } elseif (isset($seen[$p['source_id']])) {
                $findings[] = Finding::make('provenance.header.source-id-duplicate', Finding::ERROR, $rel, $p['source_id_line'],
                    'Source-Id "' . $p['source_id'] . '" is already used by ' . $seen[$p['source_id']] . '.',
                    'Give this file its own id. If it is a copy of the other file, the copy needs a new id, not the other way round.',
                    '(file scope)', self::lineOf($tree->read($rel), $p['source_id_line']));
            } else {
                $seen[$p['source_id']] = $rel;
            }
        }
        return $findings;
    }

    private static function lineOf(string $contents, int $line): string
    {
        $lines = preg_split('/\R/', $contents) ?: [];
        return $line > 0 ? (string) ($lines[$line - 1] ?? '') : '';
    }

    /**
     * Stable module id for a file that has none yet: prefix + path, lower-case. `includes/class-api.php`
     * becomes `<prefix>.api`. Config `source.source_id.overrides` wins; a taken id gets a numeric suffix.
     *
     * @param list<string> $taken ids already in use
     */
    public static function deriveSourceId(Config $c, string $rel, array $taken = []): string
    {
        $overrides = $c->source_id_overrides;
        if (isset($overrides[$rel])) {
            return (string) $overrides[$rel];
        }

        $ext   = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
        $parts = explode('/', (string) preg_replace('/\.[A-Za-z0-9]+$/', '', $rel));
        $base  = (string) preg_replace('/^class-/', '', (string) array_pop($parts));
        if ($parts === ['includes']) {
            $parts = [];
        }
        $segments = array_map(static function (string $s): string {
            return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), '-');
        }, array_merge($parts, [$base]));

        $id = $c->source_id_prefix . '.' . implode('.', array_filter($segments, 'strlen')) . ($ext !== 'php' ? '-' . $ext : '');
        if (in_array($id, $taken, true)) {
            for ($n = 2; in_array($id . '-' . $n, $taken, true); $n++) {
            }
            $id .= '-' . $n;
        }
        return $id;
    }

    /**
     * Insert the three header lines into $contents when any is missing. Idempotent; never rewrites existing lines.
     *
     * PHP: right after the opening tag, or after the plugin-header docblock of the main file (WordPress reads
     * the plugin header from the top of the file). JS: at the top, after a shebang line if there is one.
     *
     * @param list<string> $taken
     */
    public static function apply(Config $c, string $contents, string $rel, array $taken = []): string
    {
        $parsed = self::parse($contents);
        if ($parsed['spdx'] !== null && $parsed['copyright'] !== null && ($parsed['source_id'] !== null || !$c->source_id_required)) {
            return $contents;
        }

        $eol = (strpos($contents, "\r\n") !== false) ? "\r\n" : "\n";
        $bom = '';
        if (strncmp($contents, "\xEF\xBB\xBF", 3) === 0) {
            $bom      = "\xEF\xBB\xBF";
            $contents = substr($contents, 3);
        }

        $lines = [];
        if ($parsed['spdx'] === null) {
            $lines[] = 'SPDX-License-Identifier: ' . $c->license;
        }
        if ($parsed['copyright'] === null) {
            $lines[] = 'SPDX-FileCopyrightText: ' . $c->year . ' ' . $c->holder;
        }
        if ($parsed['source_id'] === null && $c->source_id_required) {
            $lines[] = 'Source-Id: ' . self::deriveSourceId($c, $rel, $taken);
        }
        $block = implode($eol, array_map(static function (string $l): string {
            return '// ' . $l;
        }, $lines)) . $eol;

        if (strtolower(pathinfo($rel, PATHINFO_EXTENSION)) === 'php') {
            if (!preg_match('/^<\?php[ \t]*\r?\n/', $contents, $open)) {
                throw new \RuntimeException("{$rel}: expected the file to start with '<?php' on its own line");
            }
            $afterOpen = strlen($open[0]);

            if (preg_match('/\G\/\*\*(?:(?!\*\/).)*?Plugin Name:.*?\*\/\r?\n/s', $contents, $docblock, 0, $afterOpen)) {
                $pos = $afterOpen + strlen($docblock[0]);
                return $bom . substr($contents, 0, $pos) . $eol . $block . substr($contents, $pos);
            }
            return $bom . substr($contents, 0, $afterOpen) . $block . $eol . substr($contents, $afterOpen);
        }

        $shebang = '';
        if (strncmp($contents, '#!', 2) === 0) {
            $nl       = (int) strpos($contents, "\n");
            $shebang  = substr($contents, 0, $nl + 1);
            $contents = substr($contents, $nl + 1);
        }
        return $bom . $shebang . $block . $eol . $contents;
    }
}
