<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.misc.suppressions


declare(strict_types=1);

namespace WpPluginCheck;

/**
 * Inventory of every PHPCS suppression annotation inside a plugin ZIP.
 *
 * Plugin Check honours `phpcs:ignore` comments, so a clean scan can hide real
 * findings behind blanket suppressions. The inventory makes each suppression
 * visible and flags the ones that are not individually justified:
 *
 *  - file-wide:   `phpcs:ignoreFile` / `@codingStandardsIgnoreFile`
 *  - blanket:     an annotation that names no rule (silences everything)
 *  - unbounded:   `phpcs:disable` with no `phpcs:enable` later in the same file
 *  - wide:        a disable..enable span longer than MAX_SPAN lines
 *  - unjustified: no `-- reason` after the rule list
 */
final class Suppressions
{
    public const MAX_SPAN = 40;

    /**
     * @param array<string,string> $phpFiles relative path => source
     * @return array{total:int,by_rule:array<string,int>,items:list<array<string,mixed>>,problems:list<array<string,mixed>>}
     */
    public static function inventory(array $phpFiles): array
    {
        $items    = [];
        $problems = [];

        ksort($phpFiles);
        foreach ($phpFiles as $file => $source) {
            $open = [];
            foreach (self::comments($source) as [$line, $text]) {
                foreach (self::directives($text) as $d) {
                    $item = ['file' => $file, 'line' => $line + $d['line_offset']] + [
                        'kind'   => $d['kind'],
                        'rules'  => $d['rules'],
                        'reason' => $d['reason'],
                    ];

                    if ($d['kind'] === 'enable') {
                        $items[] = $item;
                        $open    = self::closeSpan($open, $d['rules'], $item['line'], $file, $problems);
                        continue;
                    }

                    $items[] = $item;

                    if ($d['kind'] === 'ignoreFile') {
                        $problems[] = self::problem($item, 'file-wide', 'Suppresses the whole file.');
                        continue;
                    }
                    if ($d['rules'] === []) {
                        $problems[] = self::problem($item, 'blanket', 'Names no rule, so it silences every rule.');
                    }
                    if ($d['reason'] === '') {
                        $problems[] = self::problem($item, 'unjustified', 'No "-- reason" after the rule list.');
                    }
                    if ($d['kind'] === 'disable') {
                        $open[] = $item;
                    }
                }
            }
            foreach ($open as $item) {
                $problems[] = self::problem($item, 'unbounded', 'phpcs:disable without a later phpcs:enable in the same file.');
            }
        }

        $byRule = [];
        foreach ($items as $item) {
            if ($item['kind'] === 'enable') {
                continue;
            }
            foreach ($item['rules'] ?: ['(all rules)'] as $rule) {
                $byRule[$rule] = ($byRule[$rule] ?? 0) + 1;
            }
        }
        ksort($byRule);

        return [
            'total'    => count(array_filter($items, static fn (array $i): bool => $i['kind'] !== 'enable')),
            'by_rule'  => $byRule,
            'items'    => $items,
            'problems' => $problems,
        ];
    }

    /** @return list<array{0:int,1:string}> line number and text of every comment token */
    private static function comments(string $source): array
    {
        $out = [];
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)) {
                $out[] = [(int) $token[2], (string) $token[1]];
            }
        }
        return $out;
    }

    /**
     * @return list<array{kind:string,rules:list<string>,reason:string,line_offset:int}>
     */
    private static function directives(string $comment): array
    {
        $found = [];
        $lines = preg_split('/\R/', $comment) ?: [];
        foreach ($lines as $offset => $text) {
            if (preg_match('/@codingStandardsIgnoreFile\b/', $text)) {
                $found[] = ['kind' => 'ignoreFile', 'rules' => [], 'reason' => '', 'line_offset' => $offset];
                continue;
            }
            if (!preg_match('/phpcs:(ignoreFile|ignore|disable|enable)\b(.*)$/', $text, $m)) {
                continue;
            }
            $rest   = trim(rtrim($m[2], "*/ \t"));
            $reason = '';
            if (preg_match('/^(.*?)\s+--\s+(.*)$/', $rest, $parts)) {
                $rest   = trim($parts[1]);
                $reason = trim($parts[2]);
            } elseif (strpos($rest, '--') === 0) {
                $reason = trim(substr($rest, 2));
                $rest   = '';
            }
            $rules = $rest === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $rest)), static fn (string $r): bool => $r !== ''));
            $found[] = ['kind' => $m[1], 'rules' => $rules, 'reason' => $reason, 'line_offset' => $offset];
        }
        return $found;
    }

    /**
     * @param list<array<string,mixed>> $open
     * @param list<string>              $rules
     * @param list<array<string,mixed>> $problems
     * @return list<array<string,mixed>>
     */
    private static function closeSpan(array $open, array $rules, int $line, string $file, array &$problems): array
    {
        foreach ($open as $index => $item) {
            if ($rules === [] || array_intersect($rules, $item['rules']) || $item['rules'] === []) {
                if ($line - $item['line'] > self::MAX_SPAN) {
                    $problems[] = self::problem($item, 'wide', 'The disable..enable span is ' . ($line - $item['line']) . ' lines (limit ' . self::MAX_SPAN . ').');
                }
                unset($open[$index]);
                break;
            }
        }
        return array_values($open);
    }

    /**
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    private static function problem(array $item, string $problem, string $detail): array
    {
        return ['problem' => $problem, 'detail' => $detail] + $item;
    }
}
