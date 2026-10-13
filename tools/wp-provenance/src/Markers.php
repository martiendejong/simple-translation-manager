<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.tools.provenance.markers

declare(strict_types=1);

namespace WpProvenance;

/**
 * Recognition points ("markers"): diagnostic codes, numbered design notes, documented constants and the
 * like, as configured per plugin, plus the two built-in file-level kinds (source-id, copyright-header).
 *
 * They are ordinary engineering practice and serve provenance research only. They never prove copying.
 */
final class Markers
{
    /**
     * @return list<array{kind:string,id:string,file:string,line:int,symbol:string,context:string}>
     */
    public static function collect(Tree $tree): array
    {
        $c       = $tree->config();
        $markers = [];

        foreach ($tree->ownSourceFiles() as $rel) {
            $contents = $tree->read($rel);
            $lines    = preg_split('/\R/', $contents) ?: [];
            $parsed   = Headers::parse($contents);

            if ($parsed['source_id'] !== null) {
                $markers[] = ['kind' => 'source-id', 'id' => $parsed['source_id'], 'file' => $rel, 'line' => 0, 'symbol' => '(file)', 'context' => ''];
            }
            if ($parsed['copyright'] !== null) {
                $markers[] = ['kind' => 'copyright-header', 'id' => $parsed['copyright'], 'file' => $rel, 'line' => 0, 'symbol' => '(file)', 'context' => ''];
            }

            foreach ($c->markers as $def) {
                if (!self::inScope($c, $def['scope'], $rel)) {
                    continue;
                }
                array_push($markers, ...self::inLines($def, $rel, $lines));
            }
        }

        // Marker kinds scoped to a directory are searched in every text file there, not only in own source.
        foreach ($c->markers as $def) {
            if (strncmp($def['scope'], 'dir:', 4) !== 0) {
                continue;
            }
            foreach (self::dirFiles($c->pluginRoot(), substr($def['scope'], 4)) as $rel) {
                if (in_array($rel, $tree->ownSourceFiles(), true)) {
                    continue; // already searched above
                }
                $lines = preg_split('/\R/', (string) @file_get_contents($c->pluginRoot() . '/' . $rel)) ?: [];
                array_push($markers, ...self::inLines($def, $rel, $lines));
            }
        }

        return $markers;
    }

    /**
     * @param array<string,mixed> $def
     * @param list<string> $lines
     * @return list<array{kind:string,id:string,file:string,line:int,symbol:string,context:string}>
     */
    private static function inLines(array $def, string $rel, array $lines): array
    {
        $out   = [];
        $regex = Config::regex($def['pattern']);
        $php   = substr($rel, -4) === '.php';

        foreach ($lines as $idx => $line) {
            if (!preg_match_all($regex, $line, $hits, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($hits as $hit) {
                $id      = $hit[$def['id_group']] ?? $hit[0];
                $context = '';
                if ($def['context']) {
                    $whole   = $hit[0];
                    $after   = trim(substr($line, (int) strpos($line, $whole) + strlen($whole)));
                    $next    = isset($lines[$idx + 1]) ? trim((string) preg_replace('#^\s*(//|\*)\s?#', '', $lines[$idx + 1])) : '';
                    $context = self::normalizeSpace(rtrim($after . ' ' . $next));
                }
                $out[] = [
                    'kind'    => $def['kind'],
                    'id'      => $id,
                    'file'    => $rel,
                    'line'    => $idx + 1,
                    'symbol'  => $php ? self::symbolForLine($lines, $idx + 1) : '(file)',
                    'context' => $context,
                ];
            }
        }
        return $out;
    }

    private static function inScope(Config $c, string $scope, string $rel): bool
    {
        if ($scope === 'all') {
            return true;
        }
        if ($scope === 'distributed') {
            return $c->isDistributed($rel);
        }
        $dir = trim(substr($scope, 4), '/');
        return $rel === $dir || strncmp($rel, $dir . '/', strlen($dir) + 1) === 0;
    }

    /** @return list<string> text files below $dir (relative to $root), sorted */
    private static function dirFiles(string $root, string $dir): array
    {
        $base = rtrim($root, '/') . '/' . trim($dir, '/');
        if (!is_dir($base)) {
            return [];
        }
        $out = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)) as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile() && $file->getSize() <= 1048576) {
                $out[] = ltrim(substr(str_replace('\\', '/', $file->getPathname()), strlen(rtrim($root, '/'))), '/');
            }
        }
        sort($out);
        return $out;
    }

    /** The text a registered marker is searched for: `[{id}]` for a bracketed note, the id itself otherwise. */
    public static function needle(array $def, string $id): string
    {
        return str_replace('{id}', $id, $def['needle']);
    }

    /** @return array<string,array<string,mixed>> marker definitions by kind */
    public static function defsByKind(Config $c): array
    {
        $map = [];
        foreach ($c->markers as $def) {
            $map[$def['kind']] = $def;
        }
        return $map;
    }

    public static function normalizeSpace(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    // -- symbol resolution (PHP) --------------------------------------------------------------------------

    /** Nearest class declared at or above a 0-based line index, or null. */
    private static function classAbove(array $lines, int $idx): ?string
    {
        for ($i = min($idx, count($lines) - 1); $i >= 0; $i--) {
            if (preg_match('/^\s*(?:final\s+|abstract\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)/', $lines[$i], $m)) {
                return $m[1];
            }
        }
        return null;
    }

    /**
     * A tag inside a docblock belongs to the declaration the docblock documents, which comes AFTER it.
     * Returns "Class::member", a function or a class name; null when the line is not in such a docblock.
     */
    private static function documentedSymbol(array $lines, int $lineNo): ?string
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
                $class = self::classAbove($lines, $n);
                return $class !== null ? $class . '::' . $m[1] : $m[1];
            }
            break;
        }
        return null;
    }

    /** Enclosing "Class::member" for a 1-based line. Best effort; a docblock tag names what the docblock documents. */
    public static function symbolForLine(array $lines, int $lineNo): string
    {
        $documented = self::documentedSymbol($lines, $lineNo);
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
}
