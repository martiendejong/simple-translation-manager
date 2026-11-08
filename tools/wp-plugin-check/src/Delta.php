<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.misc.delta


declare(strict_types=1);

namespace WpPluginCheck;

/** What changed between two scans of the same plugin, matched by finding fingerprint. */
final class Delta
{
    /**
     * @param array<string,mixed> $old
     * @param array<string,mixed> $new
     * @return array<string,mixed>
     */
    public static function compare(array $old, array $new): array
    {
        $comparable = ($old['scan']['status'] ?? '') === 'complete' && ($new['scan']['status'] ?? '') === 'complete'
            && ($old['subject']['slug'] ?? 'a') === ($new['subject']['slug'] ?? 'b');

        $delta = [
            'schema'     => 'wp-plugin-check-delta/1',
            'comparable' => $comparable,
            'reason'     => $comparable ? '' : 'Both scans must be complete and for the same plugin; a failed or partial scan says nothing about what was fixed.',
            'old'        => self::describe($old),
            'new'        => self::describe($new),
            'new_findings'       => [],
            'fixed_findings'     => [],
            'unchanged_findings' => 0,
        ];
        if (!$comparable) {
            return $delta;
        }

        $oldByFp = self::byFingerprint($old['findings']);
        $newByFp = self::byFingerprint($new['findings']);
        foreach ($newByFp as $fp => $finding) {
            if (!isset($oldByFp[$fp])) {
                $delta['new_findings'][] = $finding;
            } else {
                $delta['unchanged_findings']++;
            }
        }
        foreach ($oldByFp as $fp => $finding) {
            if (!isset($newByFp[$fp])) {
                $delta['fixed_findings'][] = $finding;
            }
        }
        return $delta;
    }

    /** @param array<string,mixed> $delta */
    public static function markdown(array $delta): string
    {
        $o = $delta['old'];
        $n = $delta['new'];
        $lines   = [];
        $lines[] = '# Plugin Check delta';
        $lines[] = '';
        $lines[] = "- Before: scan `{$o['scan_id']}` ({$o['status']}), ZIP `" . substr((string) $o['zip_sha256'], 0, 12) . "`, {$o['errors']} errors / {$o['warnings']} warnings";
        $lines[] = "- After:  scan `{$n['scan_id']}` ({$n['status']}), ZIP `" . substr((string) $n['zip_sha256'], 0, 12) . "`, {$n['errors']} errors / {$n['warnings']} warnings";
        if (!$delta['comparable']) {
            $lines[] = '';
            $lines[] = '**Not comparable.** ' . $delta['reason'];
            return implode("\n", $lines) . "\n";
        }
        $lines[] = '';
        $lines[] = sprintf('New: %d, fixed: %d, unchanged: %d', count($delta['new_findings']), count($delta['fixed_findings']), $delta['unchanged_findings']);
        foreach (['new_findings' => 'New findings', 'fixed_findings' => 'Fixed findings'] as $key => $title) {
            if ($delta[$key]) {
                $lines[] = '';
                $lines[] = "## {$title}";
                foreach ($delta[$key] as $f) {
                    $lines[] = "- {$f['type']} `{$f['check']}` {$f['file']}:{$f['line']}";
                }
            }
        }
        return implode("\n", $lines) . "\n";
    }

    /** @return array<string,mixed> */
    private static function describe(array $report): array
    {
        return [
            'scan_id'    => $report['scan']['id'] ?? null,
            'status'     => $report['scan']['status'] ?? 'unknown',
            'zip_sha256' => $report['subject']['zip_sha256'] ?? null,
            'errors'     => $report['result']['errors'] ?? null,
            'warnings'   => $report['result']['warnings'] ?? null,
        ];
    }

    /**
     * @param list<array<string,mixed>> $findings
     * @return array<string,array<string,mixed>>
     */
    private static function byFingerprint(array $findings): array
    {
        $out = [];
        foreach ($findings as $f) {
            $out[$f['fingerprint']] = $f;
        }
        return $out;
    }
}
