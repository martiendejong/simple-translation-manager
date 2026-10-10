<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.misc.fingerprint


declare(strict_types=1);

namespace WpPluginCheck;

/**
 * Stable identity for a finding, built so that it survives code moving around.
 *
 * Identity = plugin slug + check code + file + enclosing symbol + normalised
 * message + the trimmed text of the flagged line. Line and column numbers are
 * NOT part of it, so a finding that only moved keeps its fingerprint. Editing
 * the flagged line itself does change it, which is correct: the code changed.
 *
 * Root cause = check code + file (split by symbol when a group is too large to
 * be one bounded task).
 */
final class Fingerprint
{
    public const MAX_FINDINGS_PER_TASK = 20;

    /**
     * Adds `fingerprint`, `anchor` and `root_cause` to each finding.
     *
     * @param list<array<string,mixed>> $findings  with file (plugin-relative), line, check, message
     * @param array<string,string>      $sources   plugin-relative path => file content
     * @return list<array<string,mixed>>
     */
    public static function apply(string $slug, array $findings, array $sources): array
    {
        $symbols = [];
        $lines   = [];
        $seen    = [];
        $out     = [];

        foreach ($findings as $f) {
            $file = (string) $f['file'];
            if (!isset($lines[$file])) {
                $source        = $sources[$file] ?? null;
                $lines[$file]  = $source === null ? [] : (preg_split('/\R/', $source) ?: []);
                $symbols[$file] = ($source !== null && substr($file, -4) === '.php') ? self::symbolMap($source) : [];
            }
            $line   = (int) ($f['line'] ?? 0);
            $anchor = self::anchorAt($symbols[$file], $line);
            $text   = self::normaliseLine($lines[$file][$line - 1] ?? '');
            $base   = implode('|', [$slug, $f['check'], $file, $anchor, self::normaliseMessage((string) $f['message']), $text]);

            // Identical flagged lines in one symbol are told apart by their order.
            $seen[$base] = ($seen[$base] ?? 0) + 1;

            $f['anchor']      = $anchor;
            $f['fingerprint'] = substr(hash('sha256', $base . '|' . $seen[$base]), 0, 16);
            $f['root_cause']  = $f['check'] . ' @ ' . $file;
            $out[]            = $f;
        }
        return $out;
    }

    /**
     * Groups findings into bounded root-cause groups.
     *
     * @param list<array<string,mixed>> $findings already passed through apply()
     * @return list<array{key:string,check:string,file:string,anchor:string,type:string,findings:list<array<string,mixed>>,total:int}>
     */
    public static function groups(array $findings): array
    {
        $byCause = [];
        foreach ($findings as $f) {
            $byCause[$f['root_cause']][] = $f;
        }

        $groups = [];
        foreach ($byCause as $cause => $members) {
            if (count($members) > self::MAX_FINDINGS_PER_TASK) {
                $bySymbol = [];
                foreach ($members as $m) {
                    $bySymbol[$m['anchor']][] = $m;
                }
                foreach ($bySymbol as $anchor => $sub) {
                    $groups[] = self::group($cause . ' :: ' . $anchor, $sub, (string) $anchor);
                }
            } else {
                $groups[] = self::group($cause, $members, '');
            }
        }

        usort($groups, static function (array $a, array $b): int {
            return [$a['type'] === 'ERROR' ? 0 : 1, $a['key']] <=> [$b['type'] === 'ERROR' ? 0 : 1, $b['key']];
        });
        return $groups;
    }

    /** Stable id of a group, used to find its task again. */
    public static function groupId(string $slug, string $groupKey): string
    {
        return substr(hash('sha256', $slug . '|' . $groupKey), 0, 16);
    }

    public static function normaliseMessage(string $message): string
    {
        $message = html_entity_decode($message, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $message = preg_replace('/\bon line \d+\b/i', 'on line N', $message) ?? $message;
        $message = preg_replace('/\bline \d+\b/i', 'line N', $message) ?? $message;
        return trim(preg_replace('/\s+/', ' ', $message) ?? $message);
    }

    public static function normaliseLine(string $line): string
    {
        return trim(preg_replace('/\s+/', ' ', $line) ?? $line);
    }

    /**
     * @param list<array<string,mixed>> $members
     * @return array{key:string,check:string,file:string,anchor:string,type:string,findings:list<array<string,mixed>>,total:int}
     */
    private static function group(string $key, array $members, string $anchor): array
    {
        $type = 'WARNING';
        foreach ($members as $m) {
            if ($m['type'] === 'ERROR') {
                $type = 'ERROR';
            }
        }
        return [
            'key'      => $key,
            'check'    => (string) $members[0]['check'],
            'file'     => (string) $members[0]['file'],
            'anchor'   => $anchor,
            'type'     => $type,
            'findings' => $members,
            'total'    => count($members),
        ];
    }

    /**
     * Declaration spans of a PHP file: start line, end line, label like Class::method.
     *
     * @return list<array{0:int,1:int,2:string}>
     */
    private static function symbolMap(string $source): array
    {
        $tokens = token_get_all($source);
        $spans  = [];
        $stack  = []; // [label, depth at which it closes, start line, kind]
        $depth  = 0;
        $class  = '';
        $count  = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];
            if (!is_array($t)) {
                if ($t === '{') {
                    if ($stack && !end($stack)[4] && end($stack)[1] === $depth) {
                        $stack[array_key_last($stack)][4] = true;
                    }
                    $depth++;
                } elseif ($t === ';') {
                    // An abstract or interface method has no body: drop it instead of waiting for a "}".
                    if ($stack && !end($stack)[4] && end($stack)[3] === 'function' && end($stack)[1] === $depth) {
                        array_pop($stack);
                    }
                } elseif ($t === '}') {
                    $depth--;
                    if ($stack && end($stack)[4] && end($stack)[1] === $depth) {
                        [$label, , $start, $kind] = array_pop($stack);
                        $spans[] = [$start, self::lineOfToken($tokens, $i), $label];
                        if ($kind === 'class') {
                            $class = '';
                        }
                    }
                }
                continue;
            }
            if ($t[0] === T_CURLY_OPEN || $t[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;
                continue;
            }
            if (in_array($t[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true) && !self::isClassConstant($tokens, $i)) {
                $name = self::nextName($tokens, $i);
                if ($name !== '') {
                    $class   = $name;
                    $stack[] = [$name, $depth, (int) $t[2], 'class', false];
                }
            } elseif ($t[0] === T_FUNCTION) {
                $name = self::nextName($tokens, $i);
                if ($name !== '') {
                    $stack[] = [($class !== '' ? $class . '::' : '') . $name, $depth, (int) $t[2], 'function', false];
                }
            }
        }
        return $spans;
    }

    /** Smallest span that contains the line gives the symbol. */
    private static function anchorAt(array $spans, int $line): string
    {
        $best  = null;
        foreach ($spans as [$start, $end, $label]) {
            if ($line >= $start && $line <= $end && ($best === null || ($end - $start) < ($best[1] - $best[0]))) {
                $best = [$start, $end, $label];
            }
        }
        return $best[2] ?? '(file scope)';
    }

    private static function nextName(array $tokens, int $i): string
    {
        for ($j = $i + 1; $j < count($tokens) && $j < $i + 6; $j++) {
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                return (string) $tokens[$j][1];
            }
            if ($tokens[$j] === '(' ) {
                return ''; // closure
            }
        }
        return '';
    }

    private static function isClassConstant(array $tokens, int $i): bool
    {
        $prev = $i - 1;
        while ($prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_WHITESPACE) {
            $prev--;
        }
        return $prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_DOUBLE_COLON;
    }

    private static function lineOfToken(array $tokens, int $i): int
    {
        for ($j = $i; $j >= 0; $j--) {
            if (is_array($tokens[$j])) {
                return (int) $tokens[$j][2] + substr_count((string) $tokens[$j][1], "\n");
            }
        }
        return 0;
    }
}
