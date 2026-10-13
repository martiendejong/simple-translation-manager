<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.tools.provenance.finding

declare(strict_types=1);

namespace WpProvenance;

/**
 * A finding is: where (file, line), which rule, what is wrong, and how to fix it.
 *
 * The shape follows the findings of the Plugin Check scanner of task 5144 (`check`, `type`, `severity`,
 * `file`, `line`, `message`, `anchor`, `fingerprint`, `root_cause`), so the same importer can turn both
 * into Work tasks. `rule` repeats `check`; `fix` is specific to this tool.
 *
 * The fingerprint is computed exactly like the one of #5144: it does not contain line numbers, so a
 * finding that only moved keeps its identity and a second scan never produces a "new" finding.
 */
final class Finding
{
    public const ERROR   = 'ERROR';
    public const WARNING = 'WARNING';

    /**
     * @return array<string,mixed>
     */
    public static function make(string $rule, string $type, string $file, int $line, string $message, string $fix, string $anchor = '(file scope)', string $lineText = ''): array
    {
        return [
            'rule'     => $rule,
            'check'    => $rule,
            'type'     => $type,
            'severity' => $type === self::ERROR ? 10 : 5,
            'file'     => $file,
            'line'     => $line,
            'column'   => 0,
            'message'  => $message,
            'fix'      => $fix,
            'anchor'   => $anchor,
            '_line_text' => $lineText,
        ];
    }

    /**
     * Adds `fingerprint` and `root_cause` and returns the findings in a stable order.
     *
     * @param list<array<string,mixed>> $findings
     * @return list<array<string,mixed>>
     */
    public static function seal(string $slug, array $findings): array
    {
        usort($findings, static function (array $a, array $b): int {
            return [$a['type'] === self::ERROR ? 0 : 1, $a['file'], $a['line'], $a['rule'], $a['message']]
                <=> [$b['type'] === self::ERROR ? 0 : 1, $b['file'], $b['line'], $b['rule'], $b['message']];
        });

        $seen = [];
        $out  = [];
        foreach ($findings as $f) {
            $base = implode('|', [$slug, $f['check'], $f['file'], $f['anchor'], self::normaliseMessage((string) $f['message']), self::normaliseLine((string) ($f['_line_text'] ?? ''))]);
            $seen[$base] = ($seen[$base] ?? 0) + 1;

            unset($f['_line_text']);
            $f['fingerprint'] = substr(hash('sha256', $base . '|' . $seen[$base]), 0, 16);
            $f['root_cause']  = $f['check'] . ' @ ' . $f['file'];
            $out[]            = $f;
        }
        return $out;
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
     * What changed between two reports of the same plugin, matched by fingerprint. An importer creates
     * a Work task only for `new_findings`; scanning an unchanged tree twice therefore yields none.
     *
     * @param array<string,mixed> $old
     * @param array<string,mixed> $new
     * @return array<string,mixed>
     */
    public static function delta(array $old, array $new): array
    {
        $comparable = ($old['subject']['slug'] ?? 'a') === ($new['subject']['slug'] ?? 'b')
            && ($old['scan']['status'] ?? '') === 'complete' && ($new['scan']['status'] ?? '') === 'complete';

        $byFp = static function (array $report): array {
            $map = [];
            foreach ($report['findings'] ?? [] as $f) {
                $map[(string) $f['fingerprint']] = $f;
            }
            return $map;
        };
        $oldMap = $byFp($old);
        $newMap = $byFp($new);

        $delta = [
            'schema'             => 'wp-provenance-delta/1',
            'comparable'         => $comparable,
            'reason'             => $comparable ? '' : 'Both reports must be complete and for the same plugin.',
            'new_findings'       => [],
            'fixed_findings'     => [],
            'unchanged_findings' => 0,
        ];
        if (!$comparable) {
            return $delta;
        }
        foreach ($newMap as $fp => $f) {
            if (isset($oldMap[$fp])) {
                $delta['unchanged_findings']++;
            } else {
                $delta['new_findings'][] = $f;
            }
        }
        foreach ($oldMap as $fp => $f) {
            if (!isset($newMap[$fp])) {
                $delta['fixed_findings'][] = $f;
            }
        }
        return $delta;
    }
}
