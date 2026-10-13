<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.tools.provenance.report

declare(strict_types=1);

namespace WpProvenance;

/** Human and CI renderings of a check report. The JSON report itself is the machine format. */
final class Report
{
    /** @param array<string,mixed> $report */
    public static function text(array $report): string
    {
        $s   = $report['subject'];
        $out = 'wp-provenance - ' . $s['slug'] . ' ' . ($s['version'] ?? '?') . ($s['commit'] ? ' (commit ' . substr((string) $s['commit'], 0, 8) . ')' : '')
            . ' - config ' . $report['scan']['config'] . "\n";
        $out .= 'Checked: ' . implode('; ', $report['checks_run']) . "\n";
        foreach ($report['checks_skipped'] as $skip) {
            $out .= 'Skipped: ' . $skip['check'] . ' - ' . $skip['reason'] . "\n";
        }
        $out .= "\n";

        foreach ($report['findings'] as $f) {
            $where = $f['file'] . ($f['line'] > 0 ? ':' . $f['line'] : '');
            $out  .= sprintf("%-7s %s  %s\n", $f['type'], $where, $f['rule']);
            $out  .= '        ' . $f['message'] . "\n";
            $out  .= '        fix: ' . $f['fix'] . "\n";
        }

        $r    = $report['result'];
        $out .= ($report['findings'] ? "\n" : '') . 'Result: ' . $r['errors'] . ' error(s), ' . $r['warnings'] . ' warning(s) - ' . ($r['clean'] ? 'no errors' : 'NOT CLEAN') . "\n";
        return $out;
    }

    /** GitHub Actions workflow commands: one annotation per finding. @param array<string,mixed> $report */
    public static function github(array $report): string
    {
        $out = '';
        foreach ($report['findings'] as $f) {
            $level = $f['type'] === Finding::ERROR ? 'error' : 'warning';
            $msg   = str_replace(["%", "\r", "\n"], ['%25', '%0D', '%0A'], $f['message'] . ' Fix: ' . $f['fix']);
            $out  .= '::' . $level . ' file=' . $f['file'] . ($f['line'] > 0 ? ',line=' . $f['line'] : '') . ',title=' . $f['rule'] . '::' . $msg . "\n";
        }
        return $out;
    }

    /** @param array<string,mixed> $report */
    public static function exitCode(array $report, bool $strict): int
    {
        if ($report['result']['errors'] > 0) {
            return 1;
        }
        return ($strict && $report['result']['warnings'] > 0) ? 1 : 0;
    }
}
