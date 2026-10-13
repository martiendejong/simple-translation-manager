<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.misc.checkcommand

declare(strict_types=1);

namespace WpPluginCheck;

/**
 * php scan.php check <plugin.zip> --reports-dir=DIR [--board=ID --api=URL --key-file=FILE --apply --max-new=N] [scan options]
 *
 * The one entry point a person, a Jengo action, a pull request check or a release check calls:
 * it runs the same scan as `scan`, keeps the report in the report store (one folder per scan),
 * compares it with the previous complete scan of the same plugin, and, when a board is given,
 * imports it (a dry run unless --apply). The exit code is the scan's: 0 clean, 1 findings,
 * 2 scan failed, 3 partial.
 */
final class CheckCommand
{
    /**
     * @param list<string>              $positional
     * @param array<string,string|bool> $options
     * @param callable|null             $scan      test seam: fn(string $zip, array $scanOptions): array report; null runs the real scanner
     * @param callable|null             $transport test seam for the Jengo Work calls
     */
    public static function run(array $positional, array $options, string $toolDir, ?callable $scan = null, ?callable $transport = null): int
    {
        $zip = $positional[0] ?? '';
        if ($zip === '' || empty($options['reports-dir']) || !is_string($options['reports-dir'])) {
            fwrite(STDERR, "Usage: php scan.php check <plugin.zip> --reports-dir=DIR [--board=ID] [--api=URL] [--key-file=FILE] [--apply] [--max-new=N] [--commit=SHA] [--php=BIN] [--mariadb-bin=DIR] [--work-dir=DIR]\n");
            return 64;
        }
        $slug  = (new ZipContent(new ZipFile($zip)))->slug();
        $sha   = (string) hash_file('sha256', $zip);
        $store = new ReportStore($options['reports-dir']);
        $outDir = $store->reportDir($slug, gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(3)), $sha);

        $scanOptions = [
            'out_dir'      => $outDir,
            'commit'       => is_string($options['commit'] ?? null) ? $options['commit'] : null,
            'checks'       => is_string($options['checks'] ?? null) ? $options['checks'] : null,
            'keep_sandbox' => !empty($options['keep-sandbox']),
        ];
        $report = $scan !== null ? $scan($zip, $scanOptions) : Cli::newScanner($options, $toolDir)->scan($zip, $scanOptions);

        $previous = $store->latestComplete($slug, (string) ($report['scan']['id'] ?? ''));
        $delta    = null;
        if ($previous !== null) {
            $d     = Delta::compare($previous['report'], $report);
            $delta = [
                'comparable' => $d['comparable'],
                'since_scan' => $d['old']['scan_id'] ?? null,
                'new'        => count($d['new_findings']),
                'fixed'      => count($d['fixed_findings']),
                'unchanged'  => $d['unchanged_findings'],
            ];
        }

        $import = null;
        $tasks  = null;
        if (is_string($options['board'] ?? null) && $options['board'] !== '') {
            if (Importer::rejectReason($report) === null) {
                $api    = new JengoWork(
                    is_string($options['api'] ?? null) ? $options['api'] : ImportCommand::DEFAULT_API,
                    Importer::readKey(is_string($options['key-file'] ?? null) ? $options['key-file'] : null),
                    $transport
                );
                $import = (new Importer($api, $options['board'], is_string($options['status'] ?? null) ? $options['status'] : 'backlog'))
                    ->run($report, !empty($options['apply']), is_string($options['max-new'] ?? null) ? max(0, (int) $options['max-new']) : 25);
                $tasks  = $api->listTasks($options['board']);
            } else {
                $import = ['accepted' => false, 'reason' => Importer::rejectReason($report), 'mode' => 'none'];
            }
        }

        echo json_encode([
            'scan'   => [
                'status'     => $report['scan']['status'],
                'reason'     => $report['scan']['status_reason'],
                'scan_id'    => $report['scan']['id'],
                'zip_sha256' => $report['subject']['zip_sha256'] ?? $sha,
                'errors'     => $report['result']['errors'] ?? null,
                'warnings'   => $report['result']['warnings'] ?? null,
                'clean'      => $report['result']['clean'] ?? false,
                'report'     => $outDir . '/report.json',
            ],
            'delta'  => $delta,
            'import' => $import,
            'status' => StatusCommand::build($store->reports($slug), $slug, $tasks),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        return Scanner::exitCode($report);
    }
}
